<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * A generic interface for nodes in a JSON-LD graph.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface NodeInterface
{
    /**
     * Get ID
     *
     * @return string|null The ID of the node or null.
     */
    public function getId();

    /**
     * Set the node type
     *
     * @param null|NodeInterface|array[NodeInterface] The type(s) of this node.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If type is not null, a Node or an
     *                                   array of Nodes.
     */
    public function setType($type);

    /**
     * Add a type to this node
     *
     * @param NodeInterface The type to add.
     *
     * @return self
     */
    public function addType(NodeInterface $type);

    /**
     * Remove a type from this node
     *
     * @param NodeInterface The type to remove.
     *
     * @return self
     */
    public function removeType(NodeInterface $type);

    /**
     * Get node type
     *
     * @return null|NodeInterface|NodeInterface[] Returns the type(s) of this node.
     */
    public function getType();

    /**
     * Get the nodes which have this node as their type
     *
     * This will return all nodes that link to this Node instance via the
     * @type (rdf:type) property.
     *
     * @return NodeInterface[] Returns the node(s) having this node as their
     *                         type.
     */
    public function getNodesWithThisType();

    /**
     * Get the graph the node belongs to
     *
     * @return null|GraphInterface Returns the graph the node belongs to or
     *                             null if the node doesn't belong to any graph.
     */
    public function getGraph();

    /**
     * Removes the node from the graph
     *
     * This will also remove all references to and from other nodes in this
     * node's graph.
     *
     * @return self
     */
    public function removeFromGraph();

    /**
     * Is this node a blank node
     *
     * A blank node is a node whose identifier has just local meaning. It has
     * therefore a node identifier with the prefix <code>_:</code> or no
     * identifier at all.
     *
     * @return bool Returns true if the node is a blank node, otherwise false.
     */
    public function isBlankNode();

    /**
     * Set a property of the node
     *
     * If the value is or contains a reference to a node which is not part
     * of the graph, the referenced node will added to the graph as well.
     * If the referenced node is already part of another graph a copy of the
     * node will be created and added to the graph.
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array. Use null to remove the property.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If value is an array or an object
     *                                   which is neither a language-tagged
     *                                   string nor a typed value or a node.
     */
    public function setProperty($property, $value);

    /**
     * Adds a value to a property of the node
     *
     * If the value already exists, it won't be added again, i.e., there
     * won't be any duplicate property values.
     *
     * If the value is or contains a reference to a node which is not part
     * of the graph, the referenced node will added to the graph as well.
     * If the referenced node is already part of another graph a copy of the
     * node will be created and added to the graph.
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If value is an array or an object
     *                                   which is neither a language-tagged
     *                                   string nor a typed value or a node.
     */
    public function addPropertyValue($property, $value);

    /**
     * Removes a property and all it's values
     *
     * @param string $property The name of the property to remove.
     *
     * @return self
     */
    public function removeProperty($property);

    /**
     * Removes a property value
     *
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property. This MUST NOT be
     *                         an array.
     *
     * @return self
     */
    public function removePropertyValue($property, $value);

    /**
     * Get the properties of this node
     *
     * @return array Returns an associative array containing all properties
     *               of this node. The key is the property name whereas the
     *               value is the property's value.
     */
    public function getProperties();

    /**
     * Get the value of a property
     *
     * @param string $property The name of the property.
     *
     * @return mixed Returns the value of the property or null if the
     *               property doesn't exist.
     */
    public function getProperty($property);

    /**
     * Get the reverse properties of this node
     *
     * @return array Returns an associative array containing all reverse
     *               properties of this node. The key is the property name
     *               whereas the value is an array of nodes linking to this
     *               instance via that property.
     */
    public function getReverseProperties();

    /**
     * Get the nodes of a reverse property
     *
     * This will return all nodes that link to this Node instance via the
     * specified property.
     *
     * @param string $property The name of the reverse property.
     *
     * @return null|NodeInterface|NodeInterface[] Returns the node(s) pointing
     *                                            to this instance via the specified
     *                                            property or null if no such node exists.
     */
    public function getReverseProperty($property);

    /**
     * Compares this node object to the specified value.
     *
     * @param mixed $other The value this instance should be compared to.
     *
     * @return bool Returns true if the passed value is the same as this
     *              instance; false otherwise.
     */
    public function equals(NodeInterface $other);
}
