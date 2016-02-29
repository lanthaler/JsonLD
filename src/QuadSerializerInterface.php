<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * Quad serializer interface
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface QuadSerializerInterface
{
    /**
     * Serializes quads to a string.
     *
     * @param Quad[] $quads Array of quads to be serialized.
     *
     * @return string The serialized quads.
     */
    public function serialize(array $quads);
}
