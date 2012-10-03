<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\IRI\IRI;

/**
 * A Document represents a JSON-LD document.
 *
 * Named graphs are not supported yet.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Document
{
    /**
     * The base IRI
     *
     * @var IRI
     */
    private $baseIri = null;

    /**
     * An associative array holding all nodes in the graph
     *
     * @var array
     */
    protected $nodes = array();

    /**
     * A term map containing terms/prefixes mapped to IRIs. This is similar
     * to a JSON-LD context but ignores all definitions except the IRI.
     *
     * @var array
     */
    protected $termMap = array();

    /**
     * Blank node counter
     *
     * @var int
     */
    private $blankNodeCounter = 0;


    /**
     * Parses a JSON-LD document and returns it as a {@link Document}.
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $document = Document::load('document.jsonld');
     *  </code>
     *
     * <strong>Please note that currently all data is merged into one graph,
     *   named graphs are not supported yet!</strong>
     *
     * It is possible to configure the processing by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>     The base IRI of the input document.
     *
     * @param string|array|object $document The JSON-LD document to process.
     * @param null|array|object $options Options to configure the processing.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws ParseException If the JSON-LD input document is invalid.
     */
    public static function load($document, $options = null)
    {
        return JsonLD::getDocument($document, $options);
    }

    /**
     * Constructor
     *
     * @param null|string|IRI $baseIri The document's base IRI
     */
    public function __construct($baseIri = null)
    {
        $this->baseIri = new IRI($baseIri);
    }

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
     * @param null|string $id The ID of the node.
     * @return Node The newly created node.
     */
    public function createNode($id = null)
    {
        if (!is_string($id) || ('_:' === substr($id, 0, 2)))
        {
            $id = $this->createBlankNodeId();
            $abs_id = $id;
        }
        else
        {
            $id = (string) $this->baseIri->resolve($id);
            if (isset($this->nodes[$id]))
            {
                return $this->nodes[$id];
            }
        }

        return $this->nodes[$id] = new Node($this, $id);
    }

    /**
     * Removes a node from the document
     *
     * This will also eliminate all references to the node within the
     * document.
     *
     * @param Node $node The node to remove from the document.
     */
    public function remove(Node $node)
    {
        if ($node->getDocument() === $this)
        {
            $node->removeFromDocument();
        }

        $id = $node->getId();

        if (!$node->isBlankNode())
        {
            $id = (string) $this->baseIri->resolve($id);
        }

        unset($this->nodes[$id]);
    }

    /**
     * Get all nodes
     *
     * @return array[Node] Returns an array containing all nodes defined in
     *                     the document.
     */
    public function getNodes()
    {
        return array_values($this->nodes);
    }

    /**
     * Get a node by ID
     *
     * @param string $id The ID of the node to retrieve.
     *
     * @return Node|null Returns the node if found; null otherwise.
     */
    public function getNode($id)
    {
        if (!((strlen($id) >= 2) && ('_:' === substr($id, 0, 2))))
        {
            $id = (string) $this->baseIri->resolve($id);
        }

        return isset($this->nodes[$id])
            ? $this->nodes[$id]
            : null;
    }

    /**
     * Check whether the document already contains a node with the
     * specified ID
     *
     * @param string|Node $id The node ID to check. Blank node identifiers
     *                        will always return false except a node instance
     *                        which is part of the document will be passed
     *                        instead of a string.
     * @return bool Returns true if the document contains a node with the
     *              specified ID; false otherwise.
     */
    public function contains($id)
    {
        $node = $id;

        if ($node instanceof Node)
        {
            $id = $node->getId();
        }

        if ((null === $id) || !is_string($id))
        {
            return false;
        }

        if ((strlen($id) >= 2) && ('_:' === substr($id, 0, 2)))
        {
            if (isset($this->nodes[$id]) && ($node === $this->nodes[$id]))
            {
                return true;
            }

            return false;
        }

        $id = (string) $this->baseIri->resolve($id);

        return isset($this->nodes[$id]);
    }

    /**
     * Create a new blank node identifier unique to the document.
     *
     * @return string The new blank node identifier.
     */
    protected function createBlankNodeId()
    {
        return '_:b' . $this->blankNodeCounter++;
    }
}
