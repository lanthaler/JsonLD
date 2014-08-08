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
 * Test the parsing of a JSON-LD document into RDF.
 *
 * @author Dimitri van Hees <dimitri@freshheads.com>
 */
class NQuadsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * The input JSON-LD being used throughout the tests.
     *
     * @var string
     */
    protected $input;

    /**
     * The expected output string using Unicode code points.
     *
     * @var string
     */
    protected $expectedUsingCodePoints;

    /**
     * Create the graph to test.
     */
    protected function setUp()
    {
        $this->input = <<<JSON_LD_DOCUMENT
{
  "@context": {
    "ex": "http://vocab.com/"
  },
  "@id": "ex:id/1",
  "@type": "ex:type/node",
  "ex:name": "Dóróthé"
}
JSON_LD_DOCUMENT;

        $this->expectedUsingCodePoints = <<<NQUADS_STRING
<http://vocab.com/id/1> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://vocab.com/type/node> .
<http://vocab.com/id/1> <http://vocab.com/name> "D\u00F3r\u00F3th\u00E9" .

NQUADS_STRING;
    }

    /**
     * Tests the encoding to Unicode code points.
     */
    public function testToRdfUsingCodePoints()
    {
        $quads = JsonLD::toRdf($this->input);
        $output = (new NQuads(true))->serialize($quads);

        self::assertEquals($this->expectedUsingCodePoints, $output);
    }
}
