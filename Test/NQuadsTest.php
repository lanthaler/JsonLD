<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\Exception\InvalidQuadException;
use ML\JsonLD\JsonLD;
use ML\JsonLD\NQuads;
use PHPUnit\Framework\TestCase;

/**
 * Tests NQuads
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class NQuadsTest extends TestCase
{
    /**
     * Tests that parsing an invalid NQuad file fails
     */
    public function testInvalidParse()
    {
        $this->expectException(InvalidQuadException::class);

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

    /**
     * Tests blank node label parsing
     */
    public function testParseBlankNodes()
    {
        $nquads = new NQuads();

        $this->assertNotEmpty($nquads->parse('_:b <http://ex/1> "Test" .'), 'just a letter');

        $this->assertNotEmpty($nquads->parse('_:b1 <http://ex/1> "Test" .'), 'letter and number');

        $this->assertNotEmpty($nquads->parse('_:_b1 <http://ex/1> "Test" .'), 'beginning _');

        $this->assertNotEmpty($nquads->parse('_:b_1 <http://ex/1> "Test" .'), 'containing _');

        $this->assertNotEmpty($nquads->parse('_:b1_ <http://ex/1> "Test" .'), 'ending _');

        $this->assertNotEmpty($nquads->parse('_:b-1 <http://ex/1> "Test" .'), 'containing -');

        $this->assertNotEmpty($nquads->parse('_:b-1 <http://ex/1> "Test" .'), 'ending -');

        $this->assertNotEmpty($nquads->parse('_:b.1 <http://ex/1> "Test" .'), 'containing .');
    }

    /**
     * Tests that parsing fails for blank node labels beginning with "-"
     */
    public function testParseBlankNodeDashAtTheBeginning()
    {
        $this->expectException(InvalidQuadException::class);

        $nquads = new NQuads();
        $nquads->parse('_:-b1 <http://ex/1> "Test" .');
    }

    /**
     * Tests that parsing fails for blank node labels beginning with "."
     */
    public function testParseBlankNodePeriodAtTheBeginning()
    {
        $this->expectException(InvalidQuadException::class);

        $nquads = new NQuads();
        $nquads->parse('_:.b1 <http://ex/1> "Test" .');
    }

    /**
     * Tests that parsing fails for blank node labels ending with "."
     */
    public function testParseBlankNodePeriodAtTheEnd()
    {
        $this->expectException(InvalidQuadException::class);

        $nquads = new NQuads();
        $nquads->parse('_:b1. <http://ex/1> "Test" .');
    }
}