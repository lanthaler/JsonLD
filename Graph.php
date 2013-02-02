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
 * A Graph represents a JSON-LD graph.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Graph implements GraphInterface
{
    /**
     * @var DocumentInterface The document this graph belongs to.
     */
    private $document;

    /**
     * @var array An associative array holding all nodes in the graph
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
     * @var int Blank node counter
     */
    private $blankNodeCounter = 0;

    /**
     * Constructor
     *
     * @param DocumentInterface $document The document the graph belongs to.
     */
    public function __construct(DocumentInterface $document = null)
    {
        $this->document = $document;
    }

    /**
     * {@inheritdoc}
     */
    public function createNode($id = null)
    {
        if (!is_string($id) || ('_:' === substr($id, 0, 2))) {
            $id = $this->createBlankNodeId();
        } else {
            $id = (string) $this->resolveIri($id);
            if (isset($this->nodes[$id])) {
                return $this->nodes[$id];
            }
        }

        return $this->nodes[$id] = new Node($this, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function removeNode(NodeInterface $node)
    {
        if ($node->getGraph() === $this) {
            $node->removeFromGraph();
        }

        $id = $node->getId();

        if (!$node->isBlankNode()) {
            $id = (string) $this->resolveIri($id);
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
            $id = (string) $this->resolveIri($id);
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
    public function containsNode($id)
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

        $id = (string) $this->resolveIri($id);

        return isset($this->nodes[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function getDocument()
    {
        return $this->document;
    }

    /**
     * {@inheritdoc}
     */
    public function removeFromDocument()
    {
        $doc = $this->document;
        $this->document = null;

        $doc->removeGraph($this);
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

    /**
     * Resolves an IRI against the document's IRI
     *
     * If the graph isn't attached to a document or the document's IRI is
     * not set, the IRI is returned as-is.
     *
     * @param string|IRI $iri The (relative) IRI to resolve
     *
     * @return IRI The resolved IRI.
     */
    protected function resolveIri($iri)
    {
        if (null === $this->document) {
            $base = new IRI();
        } else {
            $base = $this->document->getIri(true);
        }

        return $base->resolve($iri);
    }
}
