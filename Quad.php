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
 * A quad
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Quad
{
    /**
     * The subject
     *
     * @var IRI
     */
    private $subject;

    /**
     * The property or predicate
     *
     * @var IRI
     */
    private $property;

    /**
     * The object
     *
     * @var Value|IRI
     */
    private $object;

    /**
     * The graph
     *
     * @var IRI
     */
    private $graph;

    /**
     * Constructor
     *
     * @param IRI       $subject  The subject.
     * @param IRI       $property The property.
     * @param Value|IRI $object   The object.
     * @param null|IRI  $graph    The graph.
     *
     * @throws InvalidArgumentException If the object parameter has a wrong type
     */
    public function __construct(IRI $subject, IRI $property, $object, IRI $graph = null)
    {
        $this->subject = $subject;
        $this->property = $property;
        $this->setObject($object);  // use setter which checks the type
        $this->graph = $graph;
    }

    /**
     * Set the subject
     *
     * @param IRI $subject The subject
     */
    public function setSubject(IRI $subject)
    {
        $this->subject = $subject;
    }

    /**
     * Get the subject
     *
     * @return IRI The subject
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Set the property
     *
     * @param IRI $property The property
     */
    public function setProperty(IRI $property)
    {
        $this->property = $property;
    }

    /**
     * Get the property
     *
     * @return IRI The property
     */
    public function getProperty()
    {
        return $this->property;
    }

    /**
     * Set the object
     *
     * @param IRI|Value $object The object
     *
     * @throws InvalidArgumentException If object is of wrong type.
     */
    public function setObject($object)
    {
        if (!($object instanceof IRI) && !($object instanceof Value)) {
            throw new \InvalidArgumentException('Object must be an IRI or Value object');
        }

        $this->object = $object;
    }

    /**
     * Get the object
     *
     * @return IRI|Value The object
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * Set the graph
     *
     * @param null|IRI $graph The graph
     */
    public function setGraph(IRI $graph = null)
    {
        $this->graph = $graph;
    }

    /**
     * Get the graph
     *
     * @return IRI The graph
     */
    public function getGraph()
    {
        return $this->graph;
    }
}
