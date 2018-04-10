<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as JsonLDObject;

/**
 * Value is the abstract base class used for typed values and
 * language-tagged strings.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
abstract class Value implements JsonLdSerializable
{
    /**
     * The value in the form of a string
     *
     * @var string
     */
    protected $value;

    /**
     * Set the value
     *
     * @param string $value The value.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If the value is not a string.
     */
    public function setValue($value)
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('value must be a string.');
        }

        $this->value = $value;

        return $this;
    }

    /**
     * Get the value
     *
     * @return string The value.
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Create a LanguageTaggedString or TypedValue from a JSON-LD element
     *
     * If the passed value element can't be transformed to a language-tagged
     * string or a typed value false is returned.
     *
     * @param JsonLDObject $element The JSON-LD element
     *
     * @return false|LanguageTaggedString|TypedValue The parsed object
     */
    public static function fromJsonLd(JsonLDObject $element)
    {
        if (false === property_exists($element, '@value')) {
            return false;
        }

        $value = $element->{'@value'};
        $type = (property_exists($element, '@type'))
            ? $element->{'@type'}
            : null;
        $language = (property_exists($element, '@language'))
            ? $element->{'@language'}
            : null;

        if (is_int($value) || is_float($value)) {
            if (($value != (int) $value) || (RdfConstants::XSD_DOUBLE === $type)) {
                $value = preg_replace('/(0{0,14})E(\+?)/', 'E', sprintf('%1.15E', $value));

                if ((null === $type) && (null === $language)) {
                    return new TypedValue($value, RdfConstants::XSD_DOUBLE);
                }
            } else {
                $value = sprintf('%d', $value);
                if ((null === $type) && (null === $language)) {
                    return new TypedValue($value, RdfConstants::XSD_INTEGER);
                }
            }
        } elseif (is_bool($value)) {
            $value = ($value) ? 'true' : 'false';

            if ((null === $type) && (null === $language)) {
                return new TypedValue($value, RdfConstants::XSD_BOOLEAN);
            }
        } elseif (false === is_string($value)) {
            return false;
        }

        // @type gets precedence
        if ((null === $type) && (null !== $language)) {
            return new LanguageTaggedString($value, $language);
        }

        return new TypedValue($value, (null === $type) ? RdfConstants::XSD_STRING : $type);
    }

    /**
     * Compares this instance to the specified value.
     *
     * @param mixed $other The value this instance should be compared to.
     *
     * @return bool Returns true if the passed value is the same as this
     *              instance; false otherwise.
     */
    abstract public function equals($other);
}
