<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\Processor;
use ML\JsonLD\Document;
use ML\JsonLD\Node;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;


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
     * Create the Document to test.
     */
    protected function setUp()
    {
        $json = <<<JSON_LD_DOCUMENT
{
  "@context": {
    "ex": "http://vocab.com/",
    "ex:lang": { "@language": "en" },
    "ex:typed": { "@type": "ex:type/datatype" },
    "node": "ex:type/node"
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
      "@type": "node",
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

        $this->document = Document::load($json, array('base' => 'http://example.com/node/index.jsonld'));
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

        $nodes = $this->document->getNodes();
        $this->assertCount(count($nodeIds), $nodes);

        foreach ($nodes as $node) {
            // Is the node's ID valid?
            $this->assertContains($node->getId(), $nodeIds, 'Found unexpected node ID: ' . $node->getId());

            // Is the node of the right type?
            $this->assertInstanceOf('ML\JsonLD\Node', $node);

            // Does the document return the same instance?
            $n = $this->document->getNode($node->getId());
            $this->assertSame($node, $n, 'same instance');
            $this->assertTrue($node->equals($n), 'equals');
            $this->assertSame($this->document, $n->getDocument(), 'linked to document');
        }
    }

    /**
     * Tests whether all nodes are interlinked correctly.
     */
    public function testNodeRelationships()
    {
        $node1 = $this->document->getNode('http://example.com/node/1');
        $node2 = $this->document->getNode('http://example.com/node/2');
        $node3 = $this->document->getNode('http://example.com/node/3');

        $node1_1 = $this->document->getNode('_:b0');
        $node2_1 = $this->document->getNode('_:b1');
        $node2_2 = $this->document->getNode('_:b2');
        $node3_1 = $this->document->getNode('_:b3');

        $nodeType = $this->document->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->document->getNode('http://vocab.com/type/nodeWithAliases');

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
        $node1 = $this->document->getNode('http://example.com/node/1');
        $node2 = $this->document->getNode('http://example.com/node/2');
        $node3 = $this->document->getNode('http://example.com/node/3');

        $node1_1 = $this->document->getNode('_:b0');
        $node2_1 = $this->document->getNode('_:b1');
        $node2_2 = $this->document->getNode('_:b2');
        $node3_1 = $this->document->getNode('_:b3');

        $nodeType = $this->document->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->document->getNode('http://vocab.com/type/nodeWithAliases');

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
        $this->assertFalse($this->document->getNode('http://example.com/node/1')->isBlankNode(), 'n1');
        $this->assertFalse($this->document->getNode('http://example.com/node/2')->isBlankNode(), 'n2');
        $this->assertFalse($this->document->getNode('http://example.com/node/3')->isBlankNode(), 'n3');

        $this->assertTrue($this->document->getNode('_:b0')->isBlankNode(), '_:b0');
        $this->assertTrue($this->document->getNode('_:b1')->isBlankNode(), '_:b1');
        $this->assertTrue($this->document->getNode('_:b2')->isBlankNode(), '_:b2');
        $this->assertTrue($this->document->getNode('_:b3')->isBlankNode(), '_:b3');

        $node = $this->document->createNode();
        $this->assertTrue($node->isBlankNode(), 'new node without ID');

        $node = $this->document->createNode('_:fljdf');
        $this->assertTrue($node->isBlankNode(), 'new node blank node ID');

        $node = $this->document->createNode('http://www.example.com/node/new');
        $this->assertFalse($node->isBlankNode(), 'new node with ID');
    }

    /**
     * Tests if reverse node relationships are updated when a property is updated.
     */
    public function testNodeReverseRelationshipsUpdated()
    {
        $node1 = $this->document->getNode('http://example.com/node/1');
        $node1_1 = $this->document->getNode('_:b0');
        $node2 = $this->document->getNode('http://example.com/node/2');
        $node3 = $this->document->getNode('http://example.com/node/3');

        $nodeType = $this->document->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->document->getNode('http://vocab.com/type/nodeWithAliases');

        $revProperties = $node2->getReverseProperties();
        $this->assertCount(1, $revProperties, 'Check number of node2\'s reverse properties');
        $this->assertSame(array('http://vocab.com/link' => array($node1)), $revProperties, 'Check node2\'s reverse properties');

        $node1->setProperty('http://vocab.com/link', null);
        $this->assertNull($node1->getProperty('http://vocab.com/link'), 'n1 -link-> n2 removed');

        $node1->removePropertyValue('http://vocab.com/contains', $node1_1);
        $this->assertNull($node1->getProperty('http://vocab.com/contains'), 'n1 -contains-> n1.1 removed');

        $this->assertNull($node2->getReverseProperty('http://vocab.com/link'), 'n2 <-link- n1 removed');
        $this->assertNull($node1_1->getReverseProperty('http://vocab.com/contains'), 'n1.1 <-contains- n1 removed');

        $expectedProperties = array(
            Node::TYPE => $this->document->getNode('http://vocab.com/type/node'),
            'http://vocab.com/name' => '1'
        );
        $properties = $node1->getProperties();
        $this->assertCount(2, $properties, 'Check number of properties');
        $this->assertSame($expectedProperties, $properties, 'Check properties');

        $this->assertSame(array($node1, $node3), $nodeType->getNodesWithThisType(), 'n1+n3 <-type- nodeType');
        $this->assertSame($node2, $nodeWithAliasesType->getReverseProperty(Node::TYPE), 'n2 <-type- nodeWithAliases');

        $node1->setType(null);
        $node2->removeType($nodeWithAliasesType);

        $this->assertSame($node3, $nodeType->getReverseProperty(Node::TYPE), 'n3 <-type- nodeType');
        $this->assertSame(array(), $nodeWithAliasesType->getNodesWithThisType(), 'nodeWithAliases removed from n2');
    }

    /**
     * Tests the removal of nodes from the document.
     */
    public function testNodeRemoval()
    {
        // Remove node 1
        $node1 = $this->document->getNode('/node/1');
        $node1_1 = $this->document->getNode('_:b0');
        $node2 = $this->document->getNode('http://example.com/node/2');

        $this->assertTrue($this->document->contains('http://example.com/node/1'), 'node 1 in document?');

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

        $node1->removeFromDocument();

        $this->assertSame(array(), $node2->getReverseProperties(), 'n2 reverse properties');
        $this->assertNull($node2->getReverseProperty('http://vocab.com/link'), 'n2 <-link- n1 removed');

        $this->assertSame(array(), $node1_1->getReverseProperties(), 'n1.1 reverse properties');
        $this->assertNull($node1_1->getReverseProperty('http://vocab.com/contains'), 'n1.1 <-contains- n1 removed');

        $this->assertFalse($this->document->contains('/node/1'), 'node 1 still in document?');
        $this->assertNull($node1->getDocument(), 'node 1\'s document reset?');

        // Remove node 2
        $node2 = $this->document->getNode('http://example.com/node/2');
        $node2_1 = $this->document->getNode('_:b1');
        $node2_2 = $this->document->getNode('_:b2');
        $node3 = $this->document->getNode('/node/3');

        $this->assertTrue($this->document->contains('/node/2'), 'node 2 in document?');

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

        $this->document->remove($node2);

        $this->assertSame(array(), $node3->getReverseProperties(), 'n3 reverse properties');
        $this->assertNull($node3->getReverseProperty('http://vocab.com/link'), 'n3 <-link- n2 removed');

        $this->assertSame(array(), $node2_1->getReverseProperties(), 'n2.1 reverse properties');
        $this->assertNull($node2_1->getReverseProperty('http://vocab.com/contains'), 'n2.1 <-contains- n2 removed');

        $this->assertSame(array(), $node2_2->getReverseProperties(), 'n2.2 reverse properties');
        $this->assertNull($node2_2->getReverseProperty('http://vocab.com/contains'), 'n2.2 <-contains- n2 removed');

        $this->assertFalse($this->document->contains('./2'), 'node 2 still in document?');
    }

    /**
     * Tests the removal of node types from the document.
     */
    public function testNodeTypeRemoval()
    {
        // Remove nodeType
        $node1 = $this->document->getNode('http://example.com/node/1');
        $node3 = $this->document->getNode('/node/3');
        $nodeType = $this->document->getNode('http://vocab.com/type/node');

        $this->assertTrue($this->document->contains('http://vocab.com/type/node'), 'node type in document?');

        $this->assertSame($nodeType, $node1->getType(), 'n1 type');
        $this->assertSame($nodeType, $node3->getType(), 'n3 type');

        $this->assertSame(
            array(Node::TYPE => array($node1, $node3)),
            $nodeType->getReverseProperties(),
            'Check node type\'s reverse properties'
        );

        $this->document->remove($nodeType);

        $this->assertSame(array(), $nodeType->getReverseProperties(), 'node type\'s reverse properties');
        $this->assertSame(array(), $nodeType->getNodesWithThisType(), 'n1+n3 <-type- node type removed');

        $this->assertNull($node1->getType(), 'n1 type removed');
        $this->assertNull($node3->getType(), 'n3 type removed');

        $this->assertFalse($this->document->contains('http://vocab.com/type/node'), 'node type still in document?');
    }

    /**
     * Tests if adding a value maintains uniqueness
     */
    public function testNodePropertyUniqueness()
    {
        // Null
        $node = $this->document->getNode('http://example.com/node/1');
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent');

        $node->addPropertyValue('http://vocab.com/inexistent', null);
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent + null');

        $node->removeProperty('http://vocab.com/inexistent');
        $node->removePropertyValue('http://vocab.com/inexistent', null);
        $this->assertNull($node->getProperty('http://example.com/node/1'), 'inexistent removed');

        // Scalars
        $node = $this->document->getNode('http://example.com/node/1');

        $this->assertSame('1', $node->getProperty('http://vocab.com/name', 'name: initial value'));

        $node->addPropertyValue('http://vocab.com/name', '1');
        $node->addPropertyValue('http://vocab.com/name', null);
        $this->assertSame('1', $node->getProperty('http://vocab.com/name', 'name: still same'));

        $node->addPropertyValue('http://vocab.com/name', 1);
        $this->assertSame(array('1', 1), $node->getProperty('http://vocab.com/name', 'name: new value'));

        $node->removePropertyValue('http://vocab.com/name', 1);
        $this->assertSame('1', $node->getProperty('http://vocab.com/name', 'name: removed new value'));

        // Language-tagged strings
        $node = $this->document->getNode('http://example.com/node/2');
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
        $node = $this->document->getNode('http://example.com/node/2');
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
        $node = $this->document->getNode('http://example.com/node/3');
        $node1 = $this->document->getNode('http://example.com/node/1');

        $value = $node->getProperty('http://vocab.com/link');

        $this->assertInstanceOf('ML\JsonLD\Node', $value, 'node: initial value class');
        $this->assertSame($node1, $value, 'node: initial node');

        $newNode1 = $this->document->createNode();
        $this->assertTrue($this->document->contains($newNode1), 'node: new1 in document');

        $newNode2 = $this->document->createNode('http://example.com/node/new/2');
        $this->assertTrue($this->document->contains($newNode2), 'node: new2 in document');

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
        $node1 = $this->document->getNode('http://example.com/node/1');
        $nodeType = $this->document->getNode('http://vocab.com/type/node');
        $nodeWithAliasesType = $this->document->getNode('http://vocab.com/type/nodeWithAliases');

        $this->assertSame($nodeType, $node1->getType(), 'type: n1 initial type');

        $newType1 = $this->document->createNode();
        $this->assertTrue($this->document->contains($newNode1), 'type: new1 in document');

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
        $document = new Document();
        $newNode = $document->createNode();

        $node1 = $this->document->getNode('http://example.com/node/1');
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
        $node1 = $this->document->getNode('http://example.com/node/1');
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
            $this->document->getNode('http://vocab.com/type/nodeWithAliases'),
            'http://vocab.com/type/aTypeAsString'
        );

        $node1 = $this->document->getNode('http://example.com/node/1');

        $node1->setType($types);
    }

    /**
     * Tests whether it is possible to add an type which is not part of the
     * document
     *
     * @expectedException InvalidArgumentException
     */
    public function testAddTypeNotInDocument()
    {
        $document = new Document();
        $newType = $document->createNode();

        $node1 = $this->document->getNode('http://example.com/node/1');
        $node1->addType($newType);
    }

    /**
     * Tests whether nodes are contained in the document
     */
    public function testContains()
    {
        $node1 = $this->document->getNode('http://example.com/node/1');
        $nodeb_0 = $this->document->getNode('_:b0');

        $this->assertTrue($this->document->contains($node1), 'node1 obj');
        $this->assertTrue($this->document->contains('http://example.com/node/1'), 'node1 IRI');
        $this->assertFalse($this->document->contains('http://example.com/node/X'), 'inexistent IRI');
        $this->assertTrue($this->document->contains($nodeb_0), '_:b0');
        $this->assertFalse($this->document->contains('_:b0'), '_:b0 IRI');
        $this->assertFalse($this->document->contains(new TypedValue('val', 'http://example.com/type')), 'typed value');
    }

    /**
     * Tests whether creating an existing node returns the instance of that node
     */
    public function testCreateExistingNode()
    {
        $node1 = $this->document->getNode('http://example.com/node/1');
        $nodeType = $this->document->getNode('http://vocab.com/type/node');

        $this->assertSame($node1, $this->document->createNode('http://example.com/node/1'));
        $this->assertSame($nodeType, $this->document->createNode('http://vocab.com/type/node'));
    }
}
