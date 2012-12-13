<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * A Node represents a node in a JSON-LD document.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Node
{
    /** The @type constant. */
    const TYPE = '@type';

    /**
     * The document the node belongs to.
     *
     * @var Document
     */
    private $document;

    /**
     * The ID of the node
     *
     * @var string
     */
    private $id;

    /**
     * An associative array holding all node's properties except it's ID
     *
     * @var array
     */
    private $properties = array();

    /**
     * An associative array holding all reverse properties of this node, i.e.,
     * a pointers to all nodes that link to this Node.
     *
     * @var array
     */
    private $revProperties = array();

    /**
     * Constructor
     *
     * @param Document    $document The document the node belong to.
     * @param null|string $id       The ID of the node.
     */
    public function __construct(Document $document, $id = null)
    {
        $this->document = $document;
        $this->id = $id;
    }

    /**
     * Get ID
     *
     * @return string|null The ID of the node or null.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the node type
     *
     * @param null|Node|array[Node] The type(s) of this node.
     *
     * @throws \InvalidArgumentException If type is not null, a Node or an
     *                                   array of Nodes.
     */
    public function setType($type)
    {
        if ((null !== $type) && !($type instanceof Node)) {
            if (is_array($type)) {
                foreach ($type as $val) {
                    if ((null !== $val) && !($val instanceof Node)) {
                        throw new \InvalidArgumentException('type must be null, a Node, or an array of Nodes');
                    }
                }
            } else {
                throw new \InvalidArgumentException('type must be null, a Node, or an array of Nodes');
            }
        }

        return $this->setProperty(self::TYPE, $type);
    }

    /**
     * Add a type to this node
     *
     * @param Node The type to add.
     */
    public function addType(Node $type)
    {
        return $this->addPropertyValue(self::TYPE, $type);
    }

    /**
     * Remove a type from this node
     *
     * @param Node The type to remove.
     */
    public function removeType(Node $type)
    {
        return $this->removePropertyValue(self::TYPE, $type);
    }

    /**
     * Get node type
     *
     * @return null|Node|array[Node] Returns the type(s) of this node.
     */
    public function getType()
    {
        return $this->getProperty(self::TYPE);
    }

    /**
     * Get the nodes which have this node as their type
     *
     * This will return all nodes that link to this Node instance via the
     * @type (rdf:type) property.
     *
     * @return array[Node] Returns the node(s) having this node as their
     *                     type.
     */
    public function getNodesWithThisType()
    {
        return (isset($this->revProperties[self::TYPE]))
            ? array_values($this->revProperties[self::TYPE])
            : array();
    }

    /**
     * Get the document the node belongs to
     *
     * @return null|Document Returns the document the node belongs to or
     *                       null if the node doesn't belong to any document.
     */
    public function getDocument()
    {
        return $this->document;
    }

    /**
     * Removes the node from the document
     *
     * This will also remove all references to and from other nodes in this
     * node's document.
     */
    public function removeFromDocument()
    {
        // Remove other node's properties and reverse properties pointing to
        // this node
        foreach ($this->revProperties as $property => $nodes) {
            foreach ($nodes as $node) {
                $node->removePropertyValue($property, $this);
            }
        }

        foreach ($this->properties as $property => $values) {
            if (!is_array($values)) {
                $values = array($values);
            }

            foreach ($values as $value) {
                if ($value instanceof Node) {
                    $this->removePropertyValue($property, $value);
                }
            }
        }

        $doc = $this->document;
        $this->document = null;

        $doc->remove($this);
    }

    /**
     * Is this node a blank node
     *
     * A blank node is a node whose identifier has just local meaning. It has
     * therefore a node identifier with the prefix <code>_:</code> or no
     * identifier at all.
     *
     * @return bool Returns true if the node is a blank node, otherwise false.
     */
    public function isBlankNode()
    {
        return ((null === $this->id) || ('_:' === substr($this->id, 0, 2)));
    }

    /**
     * Set a property of the node
     *
     * If the value is or contains a reference to a node which is not part
     * of the document, the referenced node will added to the document as
     * well. If the referenced node is already part of another document a
     * copy of the node will be created and added to the document.
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array. Use null to remove the property.
     *
     * @throws \InvalidArgumentException If value is an array or an object
     *                                   which is neither a language-tagged
     *                                   string nor a typed value or a node.
     */
    public function setProperty($property, $value)
    {
        if (null === $value) {
            $this->removeProperty($property);

            return;
        }

        $this->doMergeIntoProperty((string) $property, array(), $value);
    }

    /**
     * Adds a value to a property of the node
     *
     * If the value already exists, it won't be added again, i.e., there
     * won't be any duplicate property values.
     *
     * If the value is or contains a reference to a node which is not part
     * of the document, the referenced node will added to the document as
     * well. If the referenced node is already part of another document a
     * copy of the node will be created and added to the document.
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array.
     *
     * @throws \InvalidArgumentException If value is an array or an object
     *                                   which is neither a language-tagged
     *                                   string nor a typed value or a node.
     */
    public function addPropertyValue($property, $value)
    {
        $existing = (isset($this->properties[(string) $property]))
            ? $this->properties[(string) $property]
            : array();

        if (!is_array($existing)) {
            $existing = array($existing);
        }

        $this->doMergeIntoProperty((string) $property, $existing, $value);
    }

    /**
     * Merge a value into a set of existing values.
     *
     * @param string $property       The name of the property.
     * @param array  $existingValues The existing values.
     * @param mixed  $value          The value to merge into the existing
     *                               values. This MUST NOT be an array.
     *
     * @throws \InvalidArgumentException If value is an array or an object
     *                                   which is neither a language-tagged
     *                                   string nor a typed value or a node.
     */
    private function doMergeIntoProperty($property, $existingValues, $value)
    {
        // TODO: Handle lists!

        if (null === $value) {
            return;
        }

        if (!$this->isValidPropertyValue($value)) {
            throw new \InvalidArgumentException(
                'value must be a scalar, a node, a language-tagged string, or a typed value'
            );
        }

        foreach ($existingValues as $existing) {
            if ($this->equalValues($existing, $value)) {
                return;
            }
        }

        $existingValues[] = $value;

        if (1 === count($existingValues)) {
            $existingValues = $existingValues[0];
        }

        $this->properties[$property] = $existingValues;

        if ($value instanceof Node) {
            $value->addReverseProperty($property, $this);
        }
    }

    /**
     * Removes a property and all it's values
     *
     * @param string $property The name of the property to remove.
     */
    public function removeProperty($property)
    {
        if (!isset($this->properties[(string) $property])) {
            return;
        }

        $values = is_array($this->properties[(string) $property])
            ? $this->properties[(string) $property]
            : array($this->properties[(string) $property]);

        foreach ($values as $value) {
            if ($value instanceof Node) {
                $value->removeReverseProperty((string) $property, $this);
            }
        }

        unset($this->properties[(string) $property]);
    }

    /**
     * Removes a property value
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array.
     */
    public function removePropertyValue($property, $value)
    {
        if (!$this->isValidPropertyValue($value) || !isset($this->properties[(string) $property])) {
            return;
        }

        $values =& $this->properties[(string) $property];

        if (!is_array($this->properties[(string) $property])) {
            $values = array($values);
        }

        for ($i = 0, $length = count($values); $i < $length; $i++) {
            if ($this->equalValues($values[$i], $value)) {
                if ($value instanceof Node) {
                    $value->removeReverseProperty((string) $property, $this);
                }

                unset($values[$i]);
                break;
            }
        }

        if (0 === count($values)) {
            unset($this->properties[(string) $property]);

            return;
        }

        $this->properties[(string) $property] = array_values($values); // re-index the array

        if (1 === count($this->properties[(string) $property])) {
            $this->properties[(string) $property] = $this->properties[(string) $property][0];
        }
    }

    /**
     * Get the properties of this node
     *
     * @return array Returns an associative array containing all properties
     *               of this node. The key is the property name whereas the
     *               value is the property's value.
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * Get the value of a property
     *
     * @param string $property The name of the property.
     *
     * @return mixed Returns the value of the property or null if the
     *               property doesn't exist.
     */
    public function getProperty($property)
    {
        return (isset($this->properties[(string) $property]))
            ? $this->properties[(string) $property]
            : null;
    }

    /**
     * Get the reverse properties of this node
     *
     * @return array Returns an associative array containing all reverse
     *               properties of this node. The key is the property name
     *               whereas the value is an array of nodes linking to this
     *               instance via that property.
     */
    public function getReverseProperties()
    {
        $result = array();
        foreach ($this->revProperties as $key => $nodes) {
            $result[$key] = array_values($nodes);
        }

        return $result;
    }

    /**
     * Get the nodes of a reverse property
     *
     * This will return all nodes that link to this Node instance via the
     * specified property.
     *
     * @param string                $property The name of the reverse property.
     *
     * @return null|Node|array[Node] Returns the node(s) pointing to this
     *                               instance via the specified property or
     *                               null if no such node exists.
     */
    public function getReverseProperty($property)
    {
        if (!isset($this->revProperties[(string) $property])) {
            return null;
        }

        $result = array_values($this->revProperties[(string) $property]);

        return (1 === count($result))
            ? $result[0]
            : $result;
    }

    /**
     * Compares this Node object to the specified value.
     *
     * @param mixed $other The value this instance should be compared to.
     *
     * @return bool Returns true if the passed value is the same as this
     *              instance; false otherwise.
     */
    public function equals($other)
    {
        return $this === $other;
    }

    /**
     * Add a reverse property.
     *
     * @param string $property The name of the property.
     * @param Node   $node     The node which has a property pointing to this
     *                         Node instance.
     */
    protected function addReverseProperty($property, Node $node)
    {
        $this->revProperties[$property][$node->getId()] = $node;
    }

    /**
     * Remove a reverse property.
     *
     * @param string $property The name of the property.
     * @param Node   $node     The node which has a property pointing to this
     *                         Node instance.
     */
    protected function removeReverseProperty($property, Node $node)
    {
        unset($this->revProperties[$property][$node->getId()]);

        if (0 === count($this->revProperties[$property])) {
            unset($this->revProperties[$property]);
        }
    }

    /**
     * Checks whether a value is a valid property value.
     *
     * @param mixed $value The value to check.
     *
     * @return bool Returns true if the value is a valid property value;
     *              false otherwise.
     */
    protected function isValidPropertyValue($value)
    {
        if (is_scalar($value) || (is_object($value) &&
             ((($value instanceof Node) && ($value->document === $this->document)) ||
              ($value instanceof Value)))) {
            return true;
        }

        return false;
    }

    /**
     * Checks whether the two specified values are the same.
     *
     * Scalars and nodes are checked for identity, value objects for
     * equality.
     *
     * @param mixed $value1 Value 1.
     * @param mixed $value2 Value 2.
     *
     * @return bool Returns true if the two values are equals; otherwise false.
     */
    protected function equalValues($value1, $value2)
    {
        if (gettype($value1) !== gettype($value2)) {
            return false;
        }

        if (is_object($value1) && ($value1 instanceof Value)) {
            return $value1->equals($value2);
        }

        return ($value1 === $value2);
    }
}
