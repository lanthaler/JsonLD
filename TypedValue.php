<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;


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
     * @param string $type The type.
     */
    public function __construct($value, $type)
    {
        $this->setValue($value);
        $this->setType($type);
    }

    /**
     * Set the type
     *
     * @param string $type The type.
     *
     * @throws \InvalidArgumentException If the type is not a string. No
     *                                   further checks are currently done.
     */
    public function setType($type)
    {
        if (!is_string($type))
        {
            throw new \InvalidArgumentException('type must be a string.');
        }

        $this->type = $type;
    }

    /**
     * Get the type
     *
     * @return string The type.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Compares this Value object to the specified value.
     *
     * @param mixed $value
     * @return bool Returns true if the passed value is the same as this
     *              instance; false otherwise.
     */
    public function equals($other)
    {
        if (get_class($this) !== get_class($other))
        {
            return false;
        }

        return ($this->value === $other->value) && ($this->type === $other->type);

    }
}
