<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\Document;

/**
 * Test the parsing of a JSON-LD document into a Document.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class DocumentTest extends \PHPUnit_Framework_TestCase
{
    /**
     * The document instance being used throughout the tests.
     *
     * @var Document
     */
    protected $document;

    /**
     * Create the document to test.
     */
    protected function setUp()
    {
        $this->document = JsonLD::getDocument(
            dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'dataset.jsonld',
            array('base' => 'http://example.com/dataset.jsonld')
        );
    }


    /**
     * Tests whether all nodes are returned and blank nodes are renamed accordingly.
     */
    public function testGetIri()
    {
        $this->assertEquals(
            'http://example.com/dataset.jsonld',
            $this->document->getIri()
        );
    }

    /**
     * Tests whether all nodes are interlinked correctly.
     */
    public function testGetGraphNames()
    {
        // The blank node graph name _:_:graphBn gets relabeled to _:b0 during node map generation
        $this->assertEquals(
            array('_:b0', 'http://example.com/named-graph'),
            $this->document->getGraphNames()
        );
    }

    /**
     * Tests whether all nodes also have the correct reverse links.
     */
    public function testContainsGraph()
    {
        $this->assertTrue(
            $this->document->containsGraph('/named-graph'),
            'Relative IRI'
        );
        $this->assertTrue(
            $this->document->containsGraph('http://example.com/named-graph'),
            'Absolute IRI'
        );
        $this->assertTrue(
            $this->document->containsGraph('_:b0'),
            'Blank node identifier'
        );

        $this->assertFalse(
            $this->document->containsGraph('http://example.org/not-here'),
            'Non-existent graph'
        );
    }

    /**
     * Tests isBlankNode()
     */
    public function testRemoveGraph()
    {
        $this->document->removeGraph('/named-graph');

        $this->assertFalse(
            $this->document->containsGraph('/named-graph'),
            'Is the removed graph still there?'
        );
    }
}
