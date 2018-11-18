<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as JsonObject;

/**
 * A LanguageTaggedString is a string which is tagged with a language.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
final class LanguageTaggedString extends Value
{
    /**
     * The language code associated with the string. Language codes are tags
     * according to {@link http://tools.ietf.org/html/bcp47 BCP47}.
     *
     * @var string
     */
    private $language;

    /**
     * Constructor
     *
     * @param string $value    The string's value.
     * @param string $language The string's language.
     */
    public function __construct($value, $language)
    {
        $this->setValue($value);
        $this->setLanguage($language);
    }

    /**
     * Set the language
     *
     * @param string $language The language.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If the language is not a string. No
     *                                   further checks are currently done.
     */
    public function setLanguage($language)
    {
        if (!is_string($language)) {
            throw new \InvalidArgumentException('language must be a string.');
        }

        $this->language = $language;

        return $this;
    }

    /**
     * Get the language
     *
     * @return string The language.
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * {@inheritdoc}
     */
    public function toJsonLd($useNativeTypes = true)
    {
        $result = new JsonObject();
        $result->{'@value'} = $this->value;
        $result->{'@language'} = $this->language;

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

        return ($this->value === $other->value) && ($this->language === $other->language);
    }
}
