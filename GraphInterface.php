<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * JSON-LD graph interface
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface GraphInterface
{
    /**
     * Creates a new node which is linked to this document
     *
     * If a blank node identifier or an invalid ID is passed, the ID will be
     * ignored and a new blank node identifier unique to the document is
     * created for the node.
     *
     * If there exists already a node with the passed ID in the document,
     * that node will be returned instead of creating a new one.
     *
     * @param null|string $id              The ID of the node.
     * @param bool        $preserveBnodeId If set to false, blank nodes are
     *                                     relabeled to avoid collisions;
     *                                     otherwise the blank node identifier
     *                                     is preserved.
     *
     * @return Node The newly created node.
     */
    public function createNode($id = null, $preserveBnodeId = false);

    /**
     * Removes a node from the document
     *
     * This will also eliminate all references to the node within the
     * document.
     *
     * @param NodeInterface $node The node to remove from the document.
     *
     * @return self
     */
    public function removeNode(NodeInterface $node);

    /**
     * Get all nodes
     *
     * @return Node[] Returns an array containing all nodes defined in the
     *                document.
     */
    public function getNodes();

    /**
     * Get a node by ID
     *
     * @param string $id The ID of the node to retrieve.
     *
     * @return Node|null Returns the node if found; null otherwise.
     */
    public function getNode($id);

    /**
     * Get nodes by type
     *
     * @param string|Node $type The type
     *
     * @return Node[] Returns an array containing all nodes of the specified
     *                type in the document.
     */
    public function getNodesByType($type);

    /**
     * Check whether the document already contains a node with the
     * specified ID
     *
     * @param string|Node $id The node ID to check. Blank node identifiers
     *                        will always return false except a node instance
     *                        which is part of the document will be passed
     *                        instead of a string.
     *
     * @return bool Returns true if the document contains a node with the
     *              specified ID; false otherwise.
     */
    public function containsNode($id);

    /**
     * Get the document the node belongs to
     *
     * @return null|DocumentInterface Returns the document the node belongs
     *                                to or null if the node doesn't belong
     *                                to any document.
     */
    public function getDocument();

    /**
     * Removes the graph from the document
     *
     * @return self
     */
    public function removeFromDocument();

    /**
     * Merges the specified graph into the current graph
     *
     * @param GraphInterface $graph The graph that should be merged into the
     *                              current graph.
     *
     * @return self
     */
    public function merge(GraphInterface $graph);
}
