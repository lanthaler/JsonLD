<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as JsonLDObject;
use ML\IRI\IRI;

/**
 * A Document represents a JSON-LD document.
 *
 * Named graphs are not supported yet.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Document implements DocumentInterface, JsonLdSerializable
{
    /**
     * @var IRI The document's IRI
     */
    protected $iri = null;

    /**
     * @var GraphInterface The default graph
     */
    protected $defaultGraph = null;

    /**
     * @var array An associative array holding all named graphs in the document
     */
    protected $namedGraphs = array();

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
     * <strong>Please note that currently all data is merged into the
     *   default graph, named graphs are not supported yet!</strong>
     *
     * It is possible to configure the processing by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>     The base IRI of the input document.
     *
     * @param string|array|JsonLDObject $document The JSON-LD document to process.
     * @param null|array|JsonLDObject   $options  Options to configure the processing.
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
        $this->iri = new IRI($iri);
        $this->defaultGraph = new Graph($this);
    }

    /**
     * {@inheritdoc}
     */
    public function setIri($iri)
    {
        $this->iri = new IRI($iri);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getIri($asObject = false)
    {
        return ($asObject) ? $this->iri : (string) $this->iri;
    }

    /**
     * {@inheritdoc}
     */
    public function createGraph($name)
    {
        $name = (string) $this->iri->resolve($name);

        if (isset($this->namedGraphs[$name])) {
            return $this->namedGraphs[$name];
        }

        return $this->namedGraphs[$name] = new Graph($this, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getGraph($name = null)
    {
        if (null === $name) {
            return $this->defaultGraph;
        }

        $name = (string) $this->iri->resolve($name);

        return isset($this->namedGraphs[$name])
            ? $this->namedGraphs[$name]
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getGraphNames()
    {
        return array_keys($this->namedGraphs);
    }

    /**
     * {@inheritdoc}
     */
    public function containsGraph($name)
    {
        $name = (string) $this->iri->resolve($name);

        return isset($this->namedGraphs[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeGraph($graph = null)
    {
        // The default graph can't be "removed", it can just be reset
        if (null === $graph) {
            $this->defaultGraph = new Graph($this);

            return $this;
        }


        if ($graph instanceof GraphInterface) {
            foreach ($this->namedGraphs as $n => $g) {
                if ($g === $graph) {
                    $name = $n;
                    break;
                }
            }
        } else {
            $name = (string) $this->iri->resolve($graph);
        }

        if (isset($this->namedGraphs[$name])) {
            if ($this->namedGraphs[$name]->getDocument() === $this) {
                $this->namedGraphs[$name]->removeFromDocument();
            }

            unset($this->namedGraphs[$name]);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toJsonLd($useNativeTypes = true)
    {
        $defGraph = $this->defaultGraph->toJsonLd($useNativeTypes);

        if (0 === count($this->namedGraphs)) {
            return $defGraph;
        }

        foreach ($this->namedGraphs as $graphName => $graph) {
            $namedGraph = new JsonLDObject();
            $namedGraph->{'@id'} = $graphName;
            $namedGraph->{'@graph'} = $graph->toJsonLd($useNativeTypes);

            $defGraph[] = $namedGraph;
        }

        $document = new JsonLDObject();
        $document->{'@graph'} = $defGraph;

        return array($document);
    }
}
