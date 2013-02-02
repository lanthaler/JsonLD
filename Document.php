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
class Document implements DocumentInterface
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
     * Parses a JSON-LD document and returns it as a Document
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
     * @param null|array|object   $options  Options to configure the processing.
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
     * @param null|string|IRI $iri The document's IRI
     */
    public function __construct($iri = null)
    {
        $this->baseIri = new IRI($iri);
    }

    /**
     * {@inheritdoc}
     */
    public function createNode($id = null)
    {
        if (!is_string($id) || ('_:' === substr($id, 0, 2))) {
            $id = $this->createBlankNodeId();
            $abs_id = $id;
        } else {
            $id = (string) $this->baseIri->resolve($id);
            if (isset($this->nodes[$id])) {
                return $this->nodes[$id];
            }
        }

        return $this->nodes[$id] = new Node($this, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function removeNode(Node $node)
    {
        if ($node->getDocument() === $this) {
            $node->removeFromDocument();
        }

        $id = $node->getId();

        if (!$node->isBlankNode()) {
            $id = (string) $this->baseIri->resolve($id);
        }

        unset($this->nodes[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodes()
    {
        return array_values($this->nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function getNode($id)
    {
        if (!((strlen($id) >= 2) && ('_:' === substr($id, 0, 2)))) {
            $id = (string) $this->baseIri->resolve($id);
        }

        return isset($this->nodes[$id])
            ? $this->nodes[$id]
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesByType($type)
    {
        if (is_string($type)) {
            if (null === ($type = $this->getNode($type))) {
                return array();
            }
        }

        return $type->getNodesWithThisType();
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        $node = $id;

        if ($node instanceof Node) {
            $id = $node->getId();
        }

        if ((null === $id) || !is_string($id)) {
            return false;
        }

        if ((strlen($id) >= 2) && ('_:' === substr($id, 0, 2))) {
            if (isset($this->nodes[$id]) && ($node === $this->nodes[$id])) {
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
