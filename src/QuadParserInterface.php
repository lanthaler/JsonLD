<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * Quad parser interface
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface QuadParserInterface
{
    /**
     * Parses quads
     *
     * @param string $input The serialized quads to parse.
     *
     * @return Quad[] An array of extracted quads.
     */
    public function parse($input);
}
