<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\NQuads;

/**
 * Tests NQuads
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class NQuadsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Tests the expansion API
     *
     * @expectedException \ML\JsonLD\Exception\InvalidQuadException
     */
    public function testInvalidParse()
    {
        $nquads = new NQuads();
        $nquads->parse('Invalid NQuads file');
    }

    /**
     * Tests escaping
     */
    public function testEscaping()
    {
        $doc = '<http://example.com>';
        $doc .= ' <http://schema.org/description>';
        $doc .= ' "String with line-break \n and quote (\")" .';
        $doc .= "\n";

        $nquads = new NQuads();
        $parsed = JsonLD::fromRdf($nquads->parse($doc));
        $serialized = $nquads->serialize(JsonLD::toRdf($parsed));

        $this->assertSame($doc, $serialized);
    }
}
