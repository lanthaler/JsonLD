<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;


/**
 * Value is the abstract base class used for typed values and
 * language-tagged strings.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
abstract class Value
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
     * @throws \InvalidArgumentException If the value is not a string.
     */
    public function setValue($value)
    {
        if (!is_string($value))
        {
            throw new \InvalidArgumentException('value must be a string.');
        }

        $this->value = $value;
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
     * Compares this Value object to the specified value.
     *
     * @param mixed $value
     * @return bool Returns true if the passed value is the same as this
     *              instance; false otherwise.
     */
    abstract public function equals($other);
}
