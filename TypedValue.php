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
 * A typed value represents a value with an associated type.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
final class TypedValue extends Value
{
    /**
     * The type of the value in the form of an IRI.
     *
     * @var string
     */
    private $type;

    /**
     * Constructor
     *
     * @param string $value The value.
     * @param string $type  The type.
     */
    public function __construct($value, $type)
    {
        $this->setValue($value);
        $this->setType($type);
    }

    /**
     * Set the type
     *
     * For the sake of simplicity, the type is currently just a Node
     * identifier in the form of a string and not a Node reference.
     * This might be changed in the future.
     *
     * @param string $type The type.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If the type is not a string. No
     *                                   further checks are currently done.
     */
    public function setType($type)
    {
        if (!is_string($type)) {
            throw new \InvalidArgumentException('type must be a string.');
        }

        $this->type = $type;

        return $this;
    }

    /**
     * Get the type
     *
     * For the sake of simplicity, the type is currently just a Node
     * identifier in the form of a string and not a Node reference.
     * This might be changed in the future.
     *
     * @return string The type.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function toJsonLd($useNativeTypes = true)
    {
        $result = new JsonLDObject();

        if (RdfConstants::XSD_STRING === $this->type) {
            $result->{'@value'} = $this->value;

            return $result;
        }

        if (true === $useNativeTypes) {
            if (RdfConstants::XSD_BOOLEAN === $this->type) {
                if ('true' === $this->value) {
                    $result->{'@value'} = true;

                    return $result;
                } elseif ('false' === $this->value) {
                    $result->{'@value'} = false;

                    return $result;
                }

            } elseif (RdfConstants::XSD_INTEGER === $this->type) {
                if (preg_match('/^[\+|-]?\d+$/', trim($this->value))) {
                    $result->{'@value'} = intval($this->value);

                    return $result;
                }
            } elseif (RdfConstants::XSD_DOUBLE === $this->type) {
                // TODO Need to handle +/-INF and NaN as well?
                if (preg_match('/^[\+|-]?\d+(?:\.\d*)?(?:[eE][\+|-]?\d+)?$/', trim($this->value))) {
                    $result->{'@value'} = floatval($this->value);

                    return $result;
                }
                // TODO Need to preserve doubles without fraction for round-tripping??
            }
        }

        $result->{'@value'} = $this->value;
        $result->{'@type'} = $this->type;

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function equals($other)
    {
        if (get_class($this) !== get_class($other)) {
            return false;
        }

        return ($this->value === $other->value) && ($this->type === $other->type);

    }
}
