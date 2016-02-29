<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * The JsonLdSerializable interface
 *
 * Objects implementing JsonLdSerializable can be serialized to JSON-LD.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface JsonLdSerializable
{
    /**
     * Convert to expanded and flattened JSON-LD
     *
     * The result can then be serialized to JSON-LD by {@see JsonLD::toString()}.
     *
     * @param boolean $useNativeTypes If set to true, native types are used
     *                                for xsd:integer, xsd:double, and
     *                                xsd:boolean, otherwise typed strings
     *                                will be used instead.
     *
     * @return mixed Returns data which can be serialized by
     *               {@see JsonLD::toString()} (which is a value of any type
     *               other than a resource) to expanded JSON-LD.
     *
     * @see JsonLD::toString()
     */
    public function toJsonLd($useNativeTypes = true);
}
