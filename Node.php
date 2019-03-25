<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as JsonObject;

/**
 * A Node represents a node in a JSON-LD graph.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Node implements NodeInterface, JsonLdSerializable
{
    /** The @type constant. */
    const TYPE = '@type';

    /**
     * @var GraphInterface The graph the node belongs to.
     */
    private $graph;

    /**
     * @var string The ID of the node
     */
    private $id;

    /**
     * @var array An associative array holding all properties of the node except it's ID
     */
    private $properties = array();

    /**
     * An associative array holding all reverse properties of this node, i.e.,
     * a pointers to all nodes that link to this node.
     *
     * @var array
     */
    private $revProperties = array();

    /**
     * Constructor
     *
     * @param GraphInterface $graph The graph the node belongs to.
     * @param null|string    $id    The ID of the node.
     */
    public function __construct(GraphInterface $graph, $id = null)
    {
        $this->graph = $graph;
        $this->id = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function setType($type)
    {
        if ((null !== $type) && !($type instanceof NodeInterface)) {
            if (is_array($type)) {
                foreach ($type as $val) {
                    if ((null !== $val) && !($val instanceof NodeInterface)) {
                        throw new \InvalidArgumentException('type must be null, a Node, or an array of Nodes');
                    }
                }
            } else {
                throw new \InvalidArgumentException('type must be null, a Node, or an array of Nodes');
            }
        }

        $this->setProperty(self::TYPE, $type);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addType(NodeInterface $type)
    {
        $this->addPropertyValue(self::TYPE, $type);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeType(NodeInterface $type)
    {
        $this->removePropertyValue(self::TYPE, $type);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return $this->getProperty(self::TYPE);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesWithThisType()
    {
        if (null === ($nodes = $this->getReverseProperty(self::TYPE))) {
            return array();
        }

        return (is_array($nodes)) ? $nodes : array($nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function getGraph()
    {
        return $this->graph;
    }

    /**
     * {@inheritdoc}
     */
    public function removeFromGraph()
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
                if ($value instanceof NodeInterface) {
                    $this->removePropertyValue($property, $value);
                }
            }
        }

        $g = $this->graph;
        $this->graph = null;

        $g->removeNode($this);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isBlankNode()
    {
        return ((null === $this->id) || ('_:' === substr($this->id, 0, 2)));
    }

    /**
     * {@inheritdoc}
     */
    public function setProperty($property, $value)
    {
        if (null === $value) {
            $this->removeProperty($property);
        } else {
            $this->doMergeIntoProperty((string) $property, array(), $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
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

        return $this;
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

        $normalizedValue = $this->normalizePropertyValue($value);

        foreach ($existingValues as $existing) {
            if ($this->equalValues($existing, $normalizedValue)) {
                return;
            }
        }

        $existingValues[] = $normalizedValue;

        if (1 === count($existingValues)) {
            $existingValues = $existingValues[0];
        }

        $this->properties[$property] = $existingValues;

        if ($normalizedValue instanceof NodeInterface) {
            $value->addReverseProperty($property, $this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeProperty($property)
    {
        if (!isset($this->properties[(string) $property])) {
            return $this;
        }

        $values = is_array($this->properties[(string) $property])
            ? $this->properties[(string) $property]
            : array($this->properties[(string) $property]);

        foreach ($values as $value) {
            if ($value instanceof NodeInterface) {
                $value->removeReverseProperty((string) $property, $this);
            }
        }

        unset($this->properties[(string) $property]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removePropertyValue($property, $value)
    {
        if (!$this->isValidPropertyValue($value) || !isset($this->properties[(string) $property])) {
            return $this;
        }

        $normalizedValue = $this->normalizePropertyValue($value);

        $values =& $this->properties[(string) $property];

        if (!is_array($this->properties[(string) $property])) {
            $values = array($values);
        }

        for ($i = 0, $length = count($values); $i < $length; $i++) {
            if ($this->equalValues($values[$i], $normalizedValue)) {
                if ($normalizedValue instanceof NodeInterface) {
                    $normalizedValue->removeReverseProperty((string) $property, $this);
                }

                unset($values[$i]);
                break;
            }
        }

        if (0 === count($values)) {
            unset($this->properties[(string) $property]);

            return $this;
        }

        $this->properties[(string) $property] = array_values($values); // re-index the array

        if (1 === count($this->properties[(string) $property])) {
            $this->properties[(string) $property] = $this->properties[(string) $property][0];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * {@inheritdoc}
     */
    public function getProperty($property)
    {
        return (isset($this->properties[(string) $property]))
            ? $this->properties[(string) $property]
            : null;
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
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
     * {@inheritdoc}
     */
    public function equals(NodeInterface $other)
    {
        return $this === $other;
    }

    /**
     * {@inheritdoc}
     */
    public function toJsonLd($useNativeTypes = true)
    {
        $node = new \stdClass();

        // Only label blank nodes if other nodes point to it
        if ((false === $this->isBlankNode()) || (count($this->getReverseProperties()) > 0)) {
            $node->{'@id'} = $this->getId();
        }

        $properties = $this->getProperties();

        foreach ($properties as $prop => $values) {
            if (false === is_array($values)) {
                $values = array($values);
            }

            if (self::TYPE === $prop) {
                $node->{'@type'} = array();
                foreach ($values as $val) {
                    $node->{'@type'}[] = $val->getId();
                }

                continue;
            }

            $node->{$prop} = array();

            foreach ($values as $value) {
                if ($value instanceof NodeInterface) {
                    $ref = new \stdClass();
                    $ref->{'@id'} = $value->getId();
                    $node->{$prop}[] = $ref;
                } elseif (is_object($value)) {  // language-tagged string or typed value
                    $node->{$prop}[] = $value->toJsonLd($useNativeTypes);
                } else {
                    $val = new JsonObject();
                    $val->{'@value'} = $value;
                    $node->{$prop}[] = $val;
                }
            }

        }

        return $node;
    }

    /**
     * Add a reverse property.
     *
     * @param string        $property The name of the property.
     * @param NodeInterface $node     The node which has a property pointing
     *                                to this node instance.
     */
    protected function addReverseProperty($property, NodeInterface $node)
    {
        $this->revProperties[$property][$node->getId()] = $node;
    }

    /**
     * Remove a reverse property.
     *
     * @param string        $property The name of the property.
     * @param NodeInterface $node     The node which has a property pointing
     *                                to this node instance.
     */
    protected function removeReverseProperty($property, NodeInterface $node)
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
        return (is_scalar($value) ||
               (is_object($value) &&
                ((($value instanceof NodeInterface) && ($value->getGraph() === $this->graph)) ||
                 ($value instanceof Value))));
    }

    /**
     * Normalizes a property value by converting scalars to Value objects.
     *
     * @param  mixed $value The value to normalize.
     *
     * @return NodeInterface|Value The normalized value.
     */
    protected function normalizePropertyValue($value)
    {
        if (false === is_scalar($value)) {
            return $value;
        }

        return Value::fromJsonLd((object) array('@value' => $value));
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
