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

    /**
     * Tests parse
     *
     * This test checks handling of certain special charaters like _ which can be part of bnode name.
     */
    public function testParseBlankNodes()
    {
        $nquads = new NQuads();

        /*
         * type 1 - just a letter
         */
        $blankNodeType = '_:b <http://ex/1> "Test" .'.PHP_EOL;

        $result = $nquads->parse($blankNodeType);
        self::assertCount(1, $result);

        /*
         * type 2 - letter + number
         */
        $blankNodeType = '_:b1 <http://ex/1> "Test" .'.PHP_EOL;

        $result = $nquads->parse($blankNodeType);
        self::assertCount(1, $result);

        /*
         * type 3 containing _
         */
        $blankNodeType = '_:b_1 <http://ex/1> "Test" .'.PHP_EOL;

        $result = $nquads->parse($blankNodeType);
        self::assertCount(1, $result);

        /*
         * type 4: containing .
         */
        $blankNodeType = '_:b.1 <http://ex/1> "Test" .'.PHP_EOL;

        $result = $nquads->parse($blankNodeType);
        self::assertCount(1, $result);

        /*
         * type 5: containing -
         */
        $blankNodeType = '_:b-1 <http://ex/1> "Test" .'.PHP_EOL;
        $result = $nquads->parse($blankNodeType);
        self::assertCount(1, $result);
    }

    /**
     * Parser has to fail if a - is at the beginning of the blank node label.
     *
     * @expectedException \ML\JsonLD\Exception\InvalidQuadException
     * @see https://www.w3.org/TR/n-quads/#BNodes
     */
    public function testParseBlankNodesDashAtTheBeginning()
    {
        $blankNodeType1 = '_:-b1 <http://ex/1> "Test" .'.PHP_EOL;

        $nquads = new NQuads();
        $nquads->parse($blankNodeType1);
    }

    /**
     * Parser has to fail if a . is at the beginning of the blank node label.
     *
     * @expectedException \ML\JsonLD\Exception\InvalidQuadException
     * @see https://www.w3.org/TR/n-quads/#BNodes
     */
    public function testParseBlankNodesPointAtTheBeginning()
    {
        $blankNodeType1 = '_:.b1 <http://ex/1> "Test" .'.PHP_EOL;

        $nquads = new NQuads();
        $nquads->parse($blankNodeType1);
    }

    /**
     * Parser has to fail if a . is at the end of the blank node label.
     *
     * @expectedException \ML\JsonLD\Exception\InvalidQuadException
     * @see https://www.w3.org/TR/n-quads/#BNodes
     */
    public function testParseBlankNodesPointAtTheEnd()
    {
        $blankNodeType1 = '_:b1. <http://ex/1> "Test" .'.PHP_EOL;

        $nquads = new NQuads();
        $nquads->parse($blankNodeType1);
    }

    /**
     * Parser has to fail if a _ is at the beginning of the blank node label.
     *
     * @expectedException \ML\JsonLD\Exception\InvalidQuadException
     * @see https://www.w3.org/TR/n-quads/#BNodes
     */
    public function testParseBlankNodesUnderlineAtTheBeginning()
    {
        $blankNodeType1 = '_:_b1 <http://ex/1> "Test" .'.PHP_EOL;

        $nquads = new NQuads();
        $nquads->parse($blankNodeType1);
    }
}
