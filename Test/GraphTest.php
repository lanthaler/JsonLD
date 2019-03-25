<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\Document;
use ML\JsonLD\FileGetContentsLoader;
use ML\JsonLD\Graph;
use ML\JsonLD\GraphInterface;
use ML\JsonLD\Node;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;
use ML\JsonLD\RdfConstants;

/**
 * Test the parsing of a JSON-LD document into a Graph.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class GraphTest extends \PHPUnit_Framework_TestCase
{
    /**
     * The graph instance being used throughout the tests.
     *
     * @var GraphInterface
     */
    protected $graph;

    /**
     * The document loader used to parse expected values.
     */
    protected $documentLoader;

    /**
     * Create the graph to test.
     */
    protected function setUp()
    {
        $json = <<<JSON_LD_DOCUMENT
{
  "@context": {
    "ex": "http://vocab.com/",
    "ex:lang": { "@language": "en" },
    "ex:typed": { "@type": "ex:type/datatype" },
    "Node": "ex:type/node"
  },
  "@graph": [
    {
      "@id": "1",
      "@type": "ex:type/node",
      "ex:name": "1",
      "ex:link": { "@id": "./2" },
      "ex:contains": { "ex:nested": "1.1" }
    },
    {
      "@id": "/node/2",
      "ex:name": "2",
      "@type": "ex:type/nodeWithAliases",
      "ex:lang": "language-tagged string",
      "ex:typed": "typed value",
      "ex:link": { "@id": "/node/3" },
      "ex:contains": [
        { "ex:nested": "2.1" },
        { "ex:nested": "2.2" }
      ],
      "ex:aliases": [ "node2", 2 ]
    },
    {
      "@id": "http://example.com/node/3",
      "ex:name": "3",
      "@type": "Node",
      "ex:link": { "@id": "http://example.com/node/1" },
      "ex:contains": {
        "@id": "_:someBlankNode",
        "ex:nested": "3.1"
      },
      "ex:lang": [
        "language-tagged string: en",
        { "@value": "language-tagged string: de", "@language": "de" },
        { "@value": "language-tagged string: en", "@language": "en" }
      ],
      "ex:typed": [
        "typed value",
        { "@value": "typed value", "@language": "ex:/type/otherDataType" },
        { "@value": "typed value", "@language": "ex:/type/datatype" }
      ]
    }
  ]
}
JSON_LD_DOCUMENT;

        $doc = Document::load($json, array('base' => 'http://example.com/node/index.jsonld'));
        $this->graph = $doc->getGraph();
        $this->documentLoader = new FileGetContentsLoader();
    }


    /**
     * Tests whether all nodes are returned and blank nodes are renamed accordingly.
     */
    public function testGetNodes()
    {
        $nodeIds = array(
            'http://example.com/node/1',
            'http://example.com/node/2',
            'http://example.com/node/3',
            '_:b0',
            '_:b1',
            '_:b2',
            '_:b3',
            'http://vocab.com/type/node',
            'http://vocab.com/type/nodeWithAliases'
        );

        $nodes = $this->graph->getNodes();
        $this->assertCount(count($nodeIds), $nodes);

        foreach ($nodes as $node) {
            // Is the node's ID valid?
            $this->assertContains($node->getId(), $nodeIds, 'Found unexpected node ID: ' . $node->getId());

            // Is the node of the right type?
            $this->assertInstanceOf('ML\JsonLD\Node', $node);

            // Does the graph return the same instance?
            $n = $this->graph->getNode($node->getId());
            $this->assertSame($node, $n, 'same instance');
            $this->assertTrue($node->equals($n), 'equals');
            $this->assertSame($this->graph, $n->getGraph(), 'linked to graph');
        }
    }

    /**
     * Tests whether all nodes are interlinked correctly.
     */
    public function testNodeRelationships()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node2 = $this->graph->getNode('http://example.com/node/2');
        $node3 = $this->graph->getNode('http://example.com/node/3');

        $node1_1 = $this->graph->getNode('_:b0');
        $node2_1 = $this->graph->getNode('_:b1');
        $node2_2 = $this->graph->getNode('_:b2');
        $node3_1 = $this->graph->getNode('_:b3');

        $nodeType = $this->graph->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->graph->getNode('http://vocab.com/type/nodeWithAliases');

        $this->assertSame($node2, $node1->getProperty('http://vocab.com/link'), 'n1 -link-> n2');
        $this->assertSame($node1_1, $node1->getProperty('http://vocab.com/contains'), 'n1 -contains-> n1.1');
        $this->assertSame($nodeType, $node1->getType(), 'n1 type');

        $this->assertSame($node3, $node2->getProperty('http://vocab.com/link'), 'n2 -link-> n3');
        $values = $node2->getProperty('http://vocab.com/contains');
        $this->assertCount(2, $values, 'n2 -contains-> 2 nodes');
        $this->assertSame($node2_1, $values[0], 'n2 -contains-> n2.1');
        $this->assertSame($node2_2, $values[1], 'n2 -contains-> n2.1');
        $this->assertSame($nodeWithAliasesType, $node2->getType(), 'n2 type');

        $this->assertSame($node1, $node3->getProperty('http://vocab.com/link'), 'n3 -link-> n1');
        $this->assertSame($node3_1, $node3->getProperty('http://vocab.com/contains'), 'n3 -contains-> n3.1');
        $this->assertSame($nodeType, $node3->getType(), 'n3 type');
    }

    /**
     * Tests whether all nodes also have the correct reverse links.
     */
    public function testNodeReverseRelationships()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node2 = $this->graph->getNode('http://example.com/node/2');
        $node3 = $this->graph->getNode('http://example.com/node/3');

        $node1_1 = $this->graph->getNode('_:b0');
        $node2_1 = $this->graph->getNode('_:b1');
        $node2_2 = $this->graph->getNode('_:b2');
        $node3_1 = $this->graph->getNode('_:b3');

        $nodeType = $this->graph->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->graph->getNode('http://vocab.com/type/nodeWithAliases');

        $this->assertSame($node1, $node2->getReverseProperty('http://vocab.com/link'), 'n2 <-link- n1');
        $this->assertSame($node1, $node1_1->getReverseProperty('http://vocab.com/contains'), 'n1.1 <-contains- n1');

        $this->assertSame($node2, $node3->getReverseProperty('http://vocab.com/link'), 'n3 <-link- n2');
        $this->assertSame($node2, $node2_1->getReverseProperty('http://vocab.com/contains'), 'n2.1 <-contains- n2');
        $this->assertSame($node2, $node2_2->getReverseProperty('http://vocab.com/contains'), 'n2.1 <-contains- n2');

        $this->assertSame($node3, $node1->getReverseProperty('http://vocab.com/link'), 'n1 <-link- n3');
        $this->assertSame($node3, $node3_1->getReverseProperty('http://vocab.com/contains'), 'n3.1 <-contains- n3');

        $this->assertSame(array($node1, $node3), $nodeType->getReverseProperty(Node::TYPE), 'n1+n3 <-type- nodeType');
        $this->assertSame(array($node2), $nodeWithAliasesType->getNodesWithThisType(), 'n2 <-type- nodeWithAliases');
    }

    /**
     * Tests isBlankNode()
     */
    public function testNodeIsBlankNode()
    {
        $this->assertFalse($this->graph->getNode('http://example.com/node/1')->isBlankNode(), 'n1');
        $this->assertFalse($this->graph->getNode('http://example.com/node/2')->isBlankNode(), 'n2');
        $this->assertFalse($this->graph->getNode('http://example.com/node/3')->isBlankNode(), 'n3');

        $this->assertTrue($this->graph->getNode('_:b0')->isBlankNode(), '_:b0');
        $this->assertTrue($this->graph->getNode('_:b1')->isBlankNode(), '_:b1');
        $this->assertTrue($this->graph->getNode('_:b2')->isBlankNode(), '_:b2');
        $this->assertTrue($this->graph->getNode('_:b3')->isBlankNode(), '_:b3');

        $node = $this->graph->createNode();
        $this->assertTrue($node->isBlankNode(), 'new node without ID');

        $node = $this->graph->createNode('_:fljdf');
        $this->assertTrue($node->isBlankNode(), 'new node blank node ID');

        $node = $this->graph->createNode('http://www.example.com/node/new');
        $this->assertFalse($node->isBlankNode(), 'new node with ID');
    }

    /**
     * Tests if reverse node relationships are updated when a property is updated.
     */
    public function testNodeReverseRelationshipsUpdated()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node1_1 = $this->graph->getNode('_:b0');
        $node2 = $this->graph->getNode('http://example.com/node/2');
        $node3 = $this->graph->getNode('http://example.com/node/3');

        $nodeType = $this->graph->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->graph->getNode('http://vocab.com/type/nodeWithAliases');

        $revProperties = $node2->getReverseProperties();
        $this->assertCount(1, $revProperties, 'Check number of node2\'s reverse properties');
        $this->assertSame(
            array('http://vocab.com/link' => array($node1)),
            $revProperties,
            'Check node2\'s reverse properties'
        );

        $node1->setProperty('http://vocab.com/link', null);
        $this->assertNull($node1->getProperty('http://vocab.com/link'), 'n1 -link-> n2 removed');

        $node1->removePropertyValue('http://vocab.com/contains', $node1_1);
        $this->assertNull($node1->getProperty('http://vocab.com/contains'), 'n1 -contains-> n1.1 removed');

        $this->assertNull($node2->getReverseProperty('http://vocab.com/link'), 'n2 <-link- n1 removed');
        $this->assertNull($node1_1->getReverseProperty('http://vocab.com/contains'), 'n1.1 <-contains- n1 removed');

        $expectedProperties = array(
            Node::TYPE => $this->graph->getNode('http://vocab.com/type/node'),
            'http://vocab.com/name' => new TypedValue('1', RdfConstants::XSD_STRING)
        );
        $properties = $node1->getProperties();
        $this->assertCount(2, $properties, 'Check number of properties');
        $this->assertEquals($expectedProperties, $properties, 'Check properties');

        $this->assertSame(array($node1, $node3), $nodeType->getNodesWithThisType(), 'n1+n3 <-type- nodeType');
        $this->assertSame($node2, $nodeWithAliasesType->getReverseProperty(Node::TYPE), 'n2 <-type- nodeWithAliases');

        $node1->setType(null);
        $node2->removeType($nodeWithAliasesType);

        $this->assertSame($node3, $nodeType->getReverseProperty(Node::TYPE), 'n3 <-type- nodeType');
        $this->assertSame(array(), $nodeWithAliasesType->getNodesWithThisType(), 'nodeWithAliases removed from n2');
    }

    /**
     * Tests the removal of nodes from the graph.
     */
    public function testNodeRemoval()
    {
        // Remove node 1
        $node1 = $this->graph->getNode('/node/1');
        $node1_1 = $this->graph->getNode('_:b0');
        $node2 = $this->graph->getNode('http://example.com/node/2');

        $this->assertTrue($this->graph->containsNode('http://example.com/node/1'), 'node 1 in graph?');

        $this->assertSame(
            array('http://vocab.com/link' => array($node1)),
            $node2->getReverseProperties(),
            'Check node2\'s reverse properties'
        );

        $this->assertSame(
            array('http://vocab.com/contains' => array($node1)),
            $node1_1->getReverseProperties(),
            'Check node1.1\'s reverse properties'
        );

        $node1->removeFromGraph();

        $this->assertSame(array(), $node2->getReverseProperties(), 'n2 reverse properties');
        $this->assertNull($node2->getReverseProperty('http://vocab.com/link'), 'n2 <-link- n1 removed');

        $this->assertSame(array(), $node1_1->getReverseProperties(), 'n1.1 reverse properties');
        $this->assertNull($node1_1->getReverseProperty('http://vocab.com/contains'), 'n1.1 <-contains- n1 removed');

        $this->assertFalse($this->graph->containsNode('/node/1'), 'node 1 still in graph?');
        $this->assertNull($node1->getGraph(), 'node 1\'s graph reset?');

        // Remove node 2
        $node2 = $this->graph->getNode('http://example.com/node/2');
        $node2_1 = $this->graph->getNode('_:b1');
        $node2_2 = $this->graph->getNode('_:b2');
        $node3 = $this->graph->getNode('/node/3');

        $this->assertTrue($this->graph->containsNode('/node/2'), 'node 2 in graph?');

        $this->assertSame(
            array('http://vocab.com/link' => array($node2)),
            $node3->getReverseProperties(),
            'Check node3\'s reverse properties'
        );

        $this->assertSame(
            array('http://vocab.com/contains' => array($node2)),
            $node2_1->getReverseProperties(),
            'Check node2.1\'s reverse properties'
        );

        $this->assertSame(
            array('http://vocab.com/contains' => array($node2)),
            $node2_2->getReverseProperties(),
            'Check node2.2\'s reverse properties'
        );

        $this->graph->removeNode($node2);

        $this->assertSame(array(), $node3->getReverseProperties(), 'n3 reverse properties');
        $this->assertNull($node3->getReverseProperty('http://vocab.com/link'), 'n3 <-link- n2 removed');

        $this->assertSame(array(), $node2_1->getReverseProperties(), 'n2.1 reverse properties');
        $this->assertNull($node2_1->getReverseProperty('http://vocab.com/contains'), 'n2.1 <-contains- n2 removed');

        $this->assertSame(array(), $node2_2->getReverseProperties(), 'n2.2 reverse properties');
        $this->assertNull($node2_2->getReverseProperty('http://vocab.com/contains'), 'n2.2 <-contains- n2 removed');

        $this->assertFalse($this->graph->containsNode('./2'), 'node 2 still in graph?');
    }

    /**
     * Tests the removal of node types from the graph.
     */
    public function testNodeTypeRemoval()
    {
        // Remove nodeType
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node3 = $this->graph->getNode('/node/3');
        $nodeType = $this->graph->getNode('http://vocab.com/type/node');

        $this->assertTrue($this->graph->containsNode('http://vocab.com/type/node'), 'node type in graph?');

        $this->assertSame($nodeType, $node1->getType(), 'n1 type');
        $this->assertSame($nodeType, $node3->getType(), 'n3 type');

        $this->assertSame(
            array(Node::TYPE => array($node1, $node3)),
            $nodeType->getReverseProperties(),
            'Check node type\'s reverse properties'
        );

        $this->graph->removeNode($nodeType);

        $this->assertSame(array(), $nodeType->getReverseProperties(), 'node type\'s reverse properties');
        $this->assertSame(array(), $nodeType->getNodesWithThisType(), 'n1+n3 <-type- node type removed');

        $this->assertNull($node1->getType(), 'n1 type removed');
        $this->assertNull($node3->getType(), 'n3 type removed');

        $this->assertFalse($this->graph->containsNode('http://vocab.com/type/node'), 'node type still in graph?');
    }

    /**
     * Tests if adding a value maintains uniqueness
     */
    public function testNodePropertyUniqueness()
    {
        // Null
        $node = $this->graph->getNode('http://example.com/node/1');
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent');

        $node->addPropertyValue('http://vocab.com/inexistent', null);
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent + null');

        $node->removeProperty('http://vocab.com/inexistent');
        $node->removePropertyValue('http://vocab.com/inexistent', null);
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent removed');

        // Scalars
        $node = $this->graph->getNode('http://example.com/node/1');

        $initialNameValue = $node->getProperty('http://vocab.com/name');

        $this->assertEquals(
            new TypedValue('1', RdfConstants::XSD_STRING),
            $node->getProperty('http://vocab.com/name'),
            'name: initial value'
        );

        $node->addPropertyValue('http://vocab.com/name', '1');
        $node->addPropertyValue('http://vocab.com/name', null);
        $this->assertSame($initialNameValue, $node->getProperty('http://vocab.com/name'), 'name: still same');

        $node->addPropertyValue('http://vocab.com/name', 1);
        $this->assertEquals(
            array($initialNameValue, new TypedValue('1', RdfConstants::XSD_INTEGER)),
            $node->getProperty('http://vocab.com/name'),
            'name: new value'
        );

        $node->removePropertyValue('http://vocab.com/name', 1);
        $this->assertSame($initialNameValue, $node->getProperty('http://vocab.com/name'), 'name: removed new value');

        // Language-tagged strings
        $node = $this->graph->getNode('http://example.com/node/2');
        $value = $node->getProperty('http://vocab.com/lang');

        $this->assertInstanceOf('ML\JsonLD\LanguageTaggedString', $value, 'lang: initial value type');
        $this->assertEquals('language-tagged string', $value->getValue(), 'lang: initial value');
        $this->assertEquals('en', $value->getLanguage(), 'lang: initial language');

        $sameLangValue = new LanguageTaggedString('language-tagged string', 'en');
        $this->assertTrue($value->equals($sameLangValue), 'lang: equals same');

        $newLangValue1 = new LanguageTaggedString('language-tagged string', 'de');
        $this->assertFalse($value->equals($newLangValue1), 'lang: equals new1');

        $newLangValue2 = new LanguageTaggedString('other language-tagged string', 'en');
        $this->assertFalse($value->equals($newLangValue2), 'lang: equals new2');

        $node->addPropertyValue('http://vocab.com/lang', $sameLangValue);
        $this->assertSame($value, $node->getProperty('http://vocab.com/lang'), 'lang: still same');

        $node->addPropertyValue('http://vocab.com/lang', $newLangValue1);
        $node->addPropertyValue('http://vocab.com/lang', $newLangValue2);

        $value = $node->getProperty('http://vocab.com/lang');
        $this->assertCount(3, $value, 'lang: count values added');

        $this->assertTrue($sameLangValue->equals($value[0]), 'lang: check values 1');
        $this->assertTrue($newLangValue1->equals($value[1]), 'lang: check values 2');
        $this->assertTrue($newLangValue2->equals($value[2]), 'lang: check values 3');

        $node->removePropertyValue('http://vocab.com/lang', $newLangValue1);
        $value = $node->getProperty('http://vocab.com/lang');
        $this->assertCount(2, $value, 'lang: count value 1 removed again');

        $this->assertTrue($sameLangValue->equals($value[0]), 'lang: check values 1 (2)');
        $this->assertTrue($newLangValue2->equals($value[1]), 'lang: check values 2 (2)');

        // Typed values
        $node = $this->graph->getNode('http://example.com/node/2');
        $value = $node->getProperty('http://vocab.com/typed');

        $this->assertInstanceOf('ML\JsonLD\TypedValue', $value, 'typed: initial value class');
        $this->assertEquals('typed value', $value->getValue(), 'typed: initial value');
        $this->assertEquals('http://vocab.com/type/datatype', $value->getType(), 'typed: initial value type');

        $sameTypedValue = new TypedValue('typed value', 'http://vocab.com/type/datatype');
        $this->assertTrue($value->equals($sameTypedValue), 'typed: equals same');

        $newTypedValue1 = new TypedValue('typed value', 'http://vocab.com/otherType');
        $this->assertFalse($value->equals($newTypedValue1), 'typed: equals new1');

        $newTypedValue2 = new TypedValue('other typed value', 'http://vocab.com/type/datatype');
        $this->assertFalse($value->equals($newTypedValue2), 'typed: equals new2');

        $node->addPropertyValue('http://vocab.com/typed', $sameTypedValue);
        $this->assertSame($value, $node->getProperty('http://vocab.com/typed'), 'typed: still same');

        $node->addPropertyValue('http://vocab.com/typed', $newTypedValue1);
        $node->addPropertyValue('http://vocab.com/typed', $newTypedValue2);

        $value = $node->getProperty('http://vocab.com/typed');
        $this->assertCount(3, $value, 'typed: count values added');

        $this->assertTrue($sameTypedValue->equals($value[0]), 'typed: check values 1');
        $this->assertTrue($newTypedValue1->equals($value[1]), 'typed: check values 2');
        $this->assertTrue($newTypedValue2->equals($value[2]), 'typed: check values 3');

        $node->removePropertyValue('http://vocab.com/typed', $newTypedValue1);
        $value = $node->getProperty('http://vocab.com/typed');
        $this->assertCount(2, $value, 'typed: count value 1 removed again');

        $this->assertTrue($sameTypedValue->equals($value[0]), 'typed: check values 1 (2)');
        $this->assertTrue($newTypedValue2->equals($value[1]), 'typed: check values 2 (2)');

        // Nodes
        $node = $this->graph->getNode('http://example.com/node/3');
        $node1 = $this->graph->getNode('http://example.com/node/1');

        $value = $node->getProperty('http://vocab.com/link');

        $this->assertInstanceOf('ML\JsonLD\Node', $value, 'node: initial value class');
        $this->assertSame($node1, $value, 'node: initial node');

        $newNode1 = $this->graph->createNode();
        $this->assertTrue($this->graph->containsNode($newNode1), 'node: new1 in graph');

        $newNode2 = $this->graph->createNode('http://example.com/node/new/2');
        $this->assertTrue($this->graph->containsNode($newNode2), 'node: new2 in graph');

        $node->addPropertyValue('http://vocab.com/link', $node1);
        $this->assertSame($node1, $node->getProperty('http://vocab.com/link'), 'node: still same');

        $node->addPropertyValue('http://vocab.com/link', $newNode1);
        $node->addPropertyValue('http://vocab.com/link', $newNode2);

        $value = $node->getProperty('http://vocab.com/link');
        $this->assertCount(3, $value, 'node: count values added');

        $this->assertSame($node1, $value[0], 'node: check values 1');
        $this->assertSame($newNode1, $value[1], 'node: check values 2');
        $this->assertSame($newNode2, $value[2], 'node: check values 3');

        $node->removePropertyValue('http://vocab.com/link', $newNode1);
        $value = $node->getProperty('http://vocab.com/link');
        $this->assertCount(2, $value, 'typed: count new node 1 removed again');

        $this->assertTrue($node1->equals($value[0]), 'node: check values 1 (2)');
        $this->assertTrue($newNode2->equals($value[1]), 'node: check values 2 (2)');

        // Node types
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $nodeType = $this->graph->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->graph->getNode('http://vocab.com/type/nodeWithAliases');

        $this->assertSame($nodeType, $node1->getType(), 'type: n1 initial type');

        $newType1 = $this->graph->createNode();
        $this->assertTrue($this->graph->containsNode($newNode1), 'type: new1 in graph');

        $node1->addType($nodeType);
        $this->assertSame($nodeType, $node1->getType(), 'type: n1 type still same');

        $node1->addType($nodeWithAliasesType);
        $node1->addType($newType1);

        $value = $node1->getType();
        $this->assertCount(3, $value, 'type: count values added');

        $this->assertSame($nodeType, $value[0], 'type: check values 1');
        $this->assertSame($nodeWithAliasesType, $value[1], 'type: check values 2');
        $this->assertSame($newType1, $value[2], 'type: check values 3');

        $node1->removeType($nodeWithAliasesType);
        $value = $node1->getType();
        $this->assertCount(2, $value, 'typed: count nodeWithAliasesType removed again');

        $this->assertTrue($nodeType->equals($value[0]), 'type: check values 1 (2)');
        $this->assertTrue($newType1->equals($value[1]), 'type: check values 2 (2)');

    }

    /**
     * Tests whether it is possible to add invalid values
     *
     * @expectedException InvalidArgumentException
     */
    public function testAddInvalidPropertyValue()
    {
        $graph = new Graph();
        $newNode = $graph->createNode();

        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node1->addPropertyValue('http://vocab.com/link', $newNode);
    }

    /**
     * Tests whether it is possible to set the node's type to an invalid
     * value
     *
     * @expectedException InvalidArgumentException
     */
    public function testSetInvalidTypeValue()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node1->setType('http://vocab.com/type/aTypeAsString');
    }

    /**
     * Tests whether it is possible to set the node's type to an invalid
     * value when an array is used.
     *
     * @expectedException InvalidArgumentException
     */
    public function testSetInvalidTypeArray()
    {
        $types = array(
            $this->graph->getNode('http://vocab.com/type/nodeWithAliases'),
            'http://vocab.com/type/aTypeAsString'
        );

        $node1 = $this->graph->getNode('http://example.com/node/1');

        $node1->setType($types);
    }

    /**
     * Tests whether it is possible to add an type which is not part of the
     * graph
     *
     * @expectedException InvalidArgumentException
     */
    public function testAddTypeNotInGraph()
    {
        $graph = new Graph();
        $newType = $graph->createNode();

        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node1->addType($newType);
    }

    /**
     * Tests whether nodes are contained in the graph
     */
    public function testContains()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $nodeb_0 = $this->graph->getNode('_:b0');

        $this->assertTrue($this->graph->containsNode($node1), 'node1 obj');
        $this->assertTrue($this->graph->containsNode('http://example.com/node/1'), 'node1 IRI');
        $this->assertFalse($this->graph->containsNode('http://example.com/node/X'), 'inexistent IRI');
        $this->assertTrue($this->graph->containsNode($nodeb_0), '_:b0');
        $this->assertFalse($this->graph->containsNode('_:b0'), '_:b0 IRI');
        $this->assertFalse($this->graph->containsNode(new TypedValue('val', 'http://example.com/type')), 'typed value');
    }

    /**
     * Tests whether creating an existing node returns the instance of that node
     */
    public function testCreateExistingNode()
    {
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $nodeType = $this->graph->getNode('http://vocab.com/type/node');

        $this->assertSame($node1, $this->graph->createNode('http://example.com/node/1'));
        $this->assertSame($nodeType, $this->graph->createNode('http://vocab.com/type/node'));
    }

    /**
     * Tests the merging of two graphs
     */
    public function testMerge()
    {
        $this->markTestSkipped("Merging graphs doesn't work yet as blank nodes are not relabeled properly");

        $json = <<<JSON_LD_DOCUMENT
{
  "@context": {
    "ex": "http://vocab.com/",
    "node": "ex:type/node"
  },
  "@graph": [
    {
      "@id": "1",
      "@type": "ex:type/node",
      "ex:name": "1",
      "ex:link": { "@id": "./2" },
      "ex:contains": { "ex:nested": "1.1 (graph 2)" }
    },
    {
      "@id": "/node/2",
      "ex:name": "and a different name in graph 2",
      "ex:link": { "@id": "/node/4" },
      "ex:newFromGraph2": "this was added in graph 2"
    },
    {
      "@id": "http://example.com/node/4",
      "ex:name": "node 4 from graph 2"
    }
  ]
}
JSON_LD_DOCUMENT;

        $graph2 = Document::load($json, array('base' => 'http://example.com/node/index.jsonld'))->getGraph();

        // Merge graph2 into graph
        $this->graph->merge($graph2);

        $nodeIds = array(
            'http://example.com/node/1',
            'http://example.com/node/2',
            'http://example.com/node/3',
            'http://example.com/node/4',
            '_:b0',
            '_:b1',
            '_:b2',
            '_:b3',
            '_:b4',
            'http://vocab.com/type/node',
            'http://vocab.com/type/nodeWithAliases'
        );

        $nodes = $this->graph->getNodes();
        $this->assertCount(count($nodeIds), $nodes);

        foreach ($nodes as $node) {
            // Is the node's ID valid?
            $this->assertContains($node->getId(), $nodeIds, 'Found unexpected node ID: ' . $node->getId());

            // Is the node of the right type?
            $this->assertInstanceOf('ML\JsonLD\Node', $node);

            // Does the graph return the same instance?
            $n = $this->graph->getNode($node->getId());
            $this->assertSame($node, $n, 'same instance');
            $this->assertTrue($node->equals($n), 'equals');
            $this->assertSame($this->graph, $n->getGraph(), 'linked to graph');

            // It must not share node objects with graph 2
            $this->assertNotSame($node, $graph2->getNode($node->getId()), 'shared instance between graph and graph 2');
        }

        // Check that the properties have been updated as well
        $node1 = $this->graph->getNode('http://example.com/node/1');
        $node2 = $this->graph->getNode('http://example.com/node/2');
        $node3 = $this->graph->getNode('http://example.com/node/3');
        $node4 = $this->graph->getNode('http://example.com/node/4');

        $this->assertEquals(
            new TypedValue('1', RdfConstants::XSD_STRING),
            $node1->getProperty('http://vocab.com/name'),
            'n1->name'
        );
        $this->assertSame($node2, $node1->getProperty('http://vocab.com/link'), 'n1 -link-> n2');
        $this->assertCount(2, $node1->getProperty('http://vocab.com/contains'), 'n1 -contains-> 2 blank nodes');

        $this->assertEquals(
            array(
                new TypedValue('2', RdfConstants::XSD_STRING),
                new TypedValue('and a different name in graph 2', RdfConstants::XSD_STRING)
            ),
            $node2->getProperty('http://vocab.com/name'),
            'n2->name'
        );

        $this->assertSame(array($node3, $node4), $node2->getProperty('http://vocab.com/link'), 'n2 -link-> n3 & n4');
        $this->assertEquals(
            new TypedValue('this was added in graph 2', RdfConstants::XSD_STRING),
            $node2->getProperty('http://vocab.com/newFromGraph2'),
            'n2->newFromGraph2'
        );

        $this->assertEquals(
            new TypedValue('node 4 from graph 2', RdfConstants::XSD_STRING),
            $node4->getProperty('http://vocab.com/name'),
            'n4->name'
        );

        // Verify that graph 2 wasn't changed
        $nodeIds = array(
            'http://example.com/node/1',
            'http://example.com/node/2',
            '_:b0',                             // ex:contains: { ex:nested }
            'http://example.com/node/4',
            'http://vocab.com/type/node'
        );

        $nodes = $graph2->getNodes();
        $this->assertCount(count($nodeIds), $nodes);

        foreach ($nodes as $node) {
            // Is the node's ID valid?
            $this->assertContains($node->getId(), $nodeIds, 'Found unexpected node ID in graph 2: ' . $node->getId());

            // Is the node of the right type?
            $this->assertInstanceOf('ML\JsonLD\Node', $node);

            // Does the graph return the same instance?
            $n = $graph2->getNode($node->getId());
            $this->assertSame($node, $n, 'same instance (graph 2)');
            $this->assertTrue($node->equals($n), 'equals (graph 2)');
            $this->assertSame($graph2, $n->getGraph(), 'linked to graph (graph 2)');
        }
    }

    /**
     * Tests the serialization of nodes
     */
    public function testSerializeNode()
    {
        $expected = $this->documentLoader->loadDocument(
            '{
                "@id": "http://example.com/node/1",
                "@type": [ "http://vocab.com/type/node" ],
                "http://vocab.com/name": [ { "@value": "1" } ],
                "http://vocab.com/link": [ { "@id": "http://example.com/node/2" } ],
                "http://vocab.com/contains": [ { "@id": "_:b0" } ]
            }'
        );
        $expected = $expected->document;

        $node1 = $this->graph->getNode('http://example.com/node/1');
        $this->assertEquals($expected, $node1->toJsonLd(), 'Serialize node 1');
    }

    /**
     * Tests the serialization of graphs
     */
    public function testSerializeGraph()
    {
        // This is the expanded and flattened version of the test document
        // (the blank node labels have been renamed from _:t... to _:b...)
        $expected = $this->documentLoader->loadDocument(
            '[{
               "@id": "_:b0",
               "http://vocab.com/nested": [{
                  "@value": "1.1"
               }]
            }, {
               "@id": "_:b1",
               "http://vocab.com/nested": [{
                  "@value": "2.1"
               }]
            }, {
               "@id": "_:b2",
               "http://vocab.com/nested": [{
                  "@value": "2.2"
               }]
            }, {
               "@id": "_:b3",
               "http://vocab.com/nested": [{
                  "@value": "3.1"
               }]
            }, {
               "@id": "http://example.com/node/1",
               "@type": ["http://vocab.com/type/node"],
               "http://vocab.com/contains": [{
                  "@id": "_:b0"
               }],
               "http://vocab.com/link": [{
                  "@id": "http://example.com/node/2"
               }],
               "http://vocab.com/name": [{
                  "@value": "1"
               }]
            }, {
               "@id": "http://example.com/node/2",
               "@type": ["http://vocab.com/type/nodeWithAliases"],
               "http://vocab.com/aliases": [{
                  "@value": "node2"
               }, {
                  "@value": 2,
                  "@type": "http://www.w3.org/2001/XMLSchema#integer"
               }],
               "http://vocab.com/contains": [{
                  "@id": "_:b1"
               }, {
                  "@id": "_:b2"
               }],
               "http://vocab.com/lang": [{
                  "@language": "en",
                  "@value": "language-tagged string"
               }],
               "http://vocab.com/link": [{
                  "@id": "http://example.com/node/3"
               }],
               "http://vocab.com/name": [{
                  "@value": "2"
               }],
               "http://vocab.com/typed": [{
                  "@type": "http://vocab.com/type/datatype",
                  "@value": "typed value"
               }]
            }, {
               "@id": "http://example.com/node/3",
               "@type": ["http://vocab.com/type/node"],
               "http://vocab.com/contains": [{
                  "@id": "_:b3"
               }],
               "http://vocab.com/lang": [{
                  "@language": "en",
                  "@value": "language-tagged string: en"
               }, {
                  "@language": "de",
                  "@value": "language-tagged string: de"
               }],
               "http://vocab.com/link": [{
                  "@id": "http://example.com/node/1"
               }],
               "http://vocab.com/name": [{
                  "@value": "3"
               }],
               "http://vocab.com/typed": [{
                  "@type": "http://vocab.com/type/datatype",
                  "@value": "typed value"
               }, {
                  "@language": "ex:/type/otherDataType",
                  "@value": "typed value"
               }, {
                  "@language": "ex:/type/datatype",
                  "@value": "typed value"
               }]
            }, {
               "@id": "http://vocab.com/type/node"
            }, {
               "@id": "http://vocab.com/type/nodeWithAliases"
            }]'
        );
        $expected = $expected->document;

        $this->assertEquals($expected, $this->graph->toJsonLd(false), 'Serialize graph');
    }
}
