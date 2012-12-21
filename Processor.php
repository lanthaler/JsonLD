<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as Object;
use ML\JsonLD\Exception\ParseException;
use ML\JsonLD\Exception\SyntaxException;
use ML\JsonLD\Exception\ProcessException;
use ML\JsonLD\Exception\InvalidQuadException;
use ML\IRI\IRI;

/**
 * Processor processes JSON-LD documents as specified by the JSON-LD
 * specification.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class Processor
{
    /** Timeout for retrieving remote documents in seconds */
    const REMOTE_TIMEOUT = 10;

    /** Maximum number of recursion that are allowed to resolve an IRI */
    const CONTEXT_MAX_IRI_RECURSIONS = 10;

    /** Identifier for the default graph as used in the node map */
    const DEFAULT_GRAPH = '@default';

    /** Identifier for the union graph as used in the node map */
    const UNION_GRAPH = '@union';

    /** A list of all defined keywords */
    private static $keywords = array('@context', '@id', '@value', '@language', '@type',
                                     '@container', '@list', '@set', '@graph', '@vocab',
                                     '@annotation', '@null');  // TODO Introduce @null supported just for framing

    /** Framing options keywords */
    private static $framingKeywords = array('@explicit', '@default', '@embed',
                                            //'@omitDefault',     // TODO Is this really needed?
                                            '@embedChildren');  // TODO How should this be called?
                                            // TODO Add @preserve, @null?? Update spec keyword list

    /**
     * The base IRI
     *
     * @var IRI
     */
    private $baseIri = null;

    /**
     * Compact arrays with just one element to a scalar
     *
     * If set to true, arrays holding just one element are compacted to
     * scalars, otherwise the arrays are kept as arrays.
     *
     * @var bool
     */
    private $compactArrays;

    /**
     * Optimize compacted output
     *
     * If set to true, the processor is free to optimize the result to produce
     * an even compacter representation than the algorithm described by the
     * official JSON-LD specification.
     *
     * @var bool
     */
    private $optimize;

    /**
     * Use native types when converting from RDF
     *
     * If set to true, the processor will try to convert datatyped literals
     * to native types instead of using the expanded object form when
     * converting from RDF. xsd:boolean values will be converted to booleans
     * whereas xsd:integer and xsd:double values will be converted to numbers.
     *
     * @var bool
     */
    private $useNativeTypes;

    /**
     * Use rdf:type instead of @type when converting from RDF
     *
     * If set to true, the JSON-LD processor will use the expanded rdf:type
     * IRI as the property instead of @type when converting from RDF.
     *
     * @var bool
     */
    private $useRdfType;

    /** Blank node map */
    private $blankNodeMap = array();

    /** Blank node counter */
    private $blankNodeCounter = 0;

    /**
     * Constructor
     *
     * The options parameter must be passed and all off the following properties
     * have to be set:
     *   - <em>base</em>           The base IRI of the input document.
     *   - <em>compactArrays</em>  If set to true, arrays holding just one element
     *                             are compacted to scalars, otherwise the arrays
     *                             are kept as arrays.
     *   - <em>optimize</em>       If set to true, the processor is free to optimize
     *                             the result to produce an even compacter
     *                             representation than the algorithm described by
     *                             the official JSON-LD specification.
     *   - <em>useNativeTypes</em> If set to true, the processor will try to
     *                             convert datatyped literals to native types
     *                             instead of using the expanded object form
     *                             when converting from RDF. xsd:boolean values
     *                             will be converted to booleans whereas
     *                             xsd:integer and xsd:double values will be
     *                             converted to numbers.
     *   - <em>useRdfType</em>     If set to true, the JSON-LD processor will use
     *                             the expanded rdf:type IRI as the property instead
     *                             of @type when converting from RDF.
     *
     * @param object $options Options to configure the various algorithms.
     */
    public function __construct($options)
    {
        $this->baseIri = new IRI($options->base);
        $this->compactArrays = (bool) $options->compactArrays;
        $this->optimize = (bool) $options->optimize;
        $this->useNativeTypes = (bool) $options->useNativeTypes;
        $this->useRdfType = (bool) $options->useRdfType;
    }

    /**
     * Parses a JSON-LD document to a PHP value
     *
     * @param string $document A JSON-LD document.
     *
     * @return mixed A PHP value.
     *
     * @throws ParseException If the JSON-LD document is not valid.
     */
    public static function parse($document)
    {
        if (function_exists('mb_detect_encoding') &&
            (false === mb_detect_encoding($document, 'UTF-8', true))) {
            throw new ParseException('The JSON-LD document does not appear to be valid UTF-8.');
        }

        $data = json_decode($document, false, 512);

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                // no error
                break;
            case JSON_ERROR_DEPTH:
                throw new ParseException('The maximum stack depth has been exceeded.');
                break;
            case JSON_ERROR_STATE_MISMATCH:
                throw new ParseException('Invalid or malformed JSON.');
                break;
            case JSON_ERROR_CTRL_CHAR:
                throw new ParseException('Control character error (possibly incorrectly encoded).');
                break;
            case JSON_ERROR_SYNTAX:
                throw new ParseException('Syntax error, malformed JSON.');
                break;
            case JSON_ERROR_UTF8:
                throw new ParseException('Malformed UTF-8 characters (possibly incorrectly encoded).');
                break;
            default:
                throw new ParseException('Unknown error while parsing JSON.');
                break;
        }

        return (empty($data)) ? null : $data;
    }

    /**
     * Parses a JSON-LD document and returns it as a Document
     *
     * @param array|object $input The JSON-LD document to process.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws ParseException If the JSON-LD input document is invalid.
     */
    public function getDocument($input)
    {
        // TODO Add support for named graphs
        $nodeMap = new Object();
        $nodeMap->{self::UNION_GRAPH} = new Object();
        $this->generateNodeMap($nodeMap, $input, self::UNION_GRAPH);

        // As we do not support named graphs yet we are currently just
        // interested in the union graph
        $nodeMap = $nodeMap->{self::UNION_GRAPH};

        // We need to keep track of blank nodes as they are renamed when
        // inserted into the Document

        $document = new Document($this->baseIri);
        $nodes = array();

        foreach ($nodeMap as $id => &$item) {
            if (!isset($nodes[$id])) {
                $nodes[$id] = $document->createNode($item->{'@id'});
            }

            $node = $nodes[$id];
            unset($item->{'@id'});

            // Process node type as it needs to be handled differently than
            // other properties
            if (property_exists($item, '@type')) {
                foreach ($item->{'@type'} as $type) {
                    if (!isset($nodes[$type])) {
                        $nodes[$type] = $document->createNode($type);
                    }
                    $node->addType($nodes[$type]);
                }
                unset($item->{'@type'});
            }

            foreach ($item as $property => $value) {
                foreach ($value as $val) {
                    if (property_exists($val, '@value')) {
                        if (property_exists($val, '@type')) {
                            $node->setProperty($property, new TypedValue($val->{'@value'}, $val->{'@type'}));
                        } elseif (property_exists($val, '@language')) {
                            $node->addPropertyValue(
                                $property,
                                new LanguageTaggedString($val->{'@value'}, $val->{'@language'})
                            );
                        } else {
                            $node->addPropertyValue($property, $val->{'@value'});
                        }
                    } elseif (property_exists($val, '@id')) {
                        if (!isset($nodes[$val->{'@id'}])) {
                            $nodes[$val->{'@id'}] = $document->createNode($val->{'@id'});
                        }
                        $node->addPropertyValue($property, $nodes[$val->{'@id'}]);
                    } else {
                        // TODO Handle lists
                        throw new \Exception('Not implemented yet');
                    }
                }
            }
        }

        unset($nodeMap);

        return $document;
    }

    /**
     * Expands a JSON-LD document
     *
     * @param mixed   $element    A JSON-LD element to be expanded.
     * @param array   $activectx  The active context.
     * @param string  $activeprty The active property.
     * @param boolean $frame      True if a frame is being expanded, otherwise false.
     *
     * @return mixed The expanded document.
     *
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If the expansion failed.
     * @throws ParseException   If a remote context couldn't be processed.
     */
    public function expand(&$element, $activectx = array(), $activeprty = null, $frame = false)
    {
        if (is_scalar($element)) {
            $element = $this->expandValue($element, $activectx, $activeprty);

            return;
        }

        if (null === $element) {
            return;
        }

        if (is_array($element)) {
            $result = array();
            foreach ($element as &$item) {
                $this->expand($item, $activectx, $activeprty, $frame);

                // Check for lists of lists
                if (('@list' === $this->getPropertyDefinition($activectx, $activeprty, '@container')) ||
                    ('@list' === $activeprty)) {
                    if (is_array($item) || (is_object($item) && property_exists($item, '@list'))) {
                        throw new SyntaxException("List of lists detected in property \"$activeprty\".", $element);
                    }
                }

                if (is_array($item)) {
                    $result = array_merge($result, $item);
                } elseif (null !== $item) {
                    $result[] = $item;
                }
            }

            $element = $result;

            return;
        }

        // Otherwise it's an object. Process its local context if available
        if (property_exists($element, '@context')) {
            $this->processContext($element->{'@context'}, $activectx);
            unset($element->{'@context'});
        }

        $properties = get_object_vars($element);
        ksort($properties);

        $element = new Object();

        foreach ($properties as $property => $value) {
            $expProperty = $this->expandProperty($property, $activectx);

            if (false === is_array($expProperty)) {
                // Make sure to keep framing keywords if a frame is being expanded
                if ($frame && in_array($expProperty, self::$framingKeywords)) {
                    self::setProperty($element, $expProperty, $value);
                    continue;
                }

                if (in_array($expProperty, self::$keywords)) {
                    // we don't allow overwriting the behavior of keywords,
                    // so if the property expands to one, we treat it as the
                    // keyword itself
                    $property = $expProperty;

                    $this->expandKeywordValue($element, $activeprty, $expProperty, $value, $activectx, $frame);

                    continue;
                } elseif (false === strpos($expProperty, ':')) {
                    // the expanded property is neither a keyword nor an IRI
                    continue;
                }
            }

            $propertyContainer = $this->getPropertyDefinition($activectx, $property, '@container');

            if (is_object($value) && in_array($propertyContainer, array('@language', '@annotation'))) {
                $result = array();

                $value = (array) $value;  // makes it easier to order the key-value pairs
                ksort($value);

                if ('@language' === $propertyContainer) {
                    foreach ($value as $key => $val) {
                        // TODO Make sure key is a valid language tag

                        if (false === is_array($val)) {
                            $val = array($val);
                        }

                        foreach ($val as $item) {
                            if (false === is_string($item)) {
                                throw new SyntaxException(
                                    "Detected invalid value in $property->$key: it must be a string as it " .
                                    "is part of a language map.",
                                    $item
                                );
                            }

                            $result[] = (object) array(
                                '@value' => $item,
                                '@language' => strtolower($key)
                            );
                        }
                    }
                } else {
                    // @container: @annotation
                    foreach ($value as $key => $val) {
                        if (false === is_array($val)) {
                            $val = array($val);
                        }

                        $this->expand($val, $activectx, $activeprty, $frame);

                        foreach ($val as $item) {
                            if (false === property_exists($item, '@annotation')) {
                                $item->{'@annotation'} = $key;
                            }

                            $result[] = $item;
                        }
                    }
                }

                $value = $result;
            } else {
                $this->expand($value, $activectx, $property, $frame);
            }

            // Remove properties with null values
            if (null === $value) {
                continue;
            }

            // If property has an @list container and value is not yet an
            // expanded @list-object, transform it to one
            if (('@list' === $propertyContainer) &&
                ((false === is_object($value) || (false === property_exists($value, '@list'))))) {
                if (false === is_array($value)) {
                    $value = array($value);
                }

                $obj = new Object();
                $obj->{'@list'} = $value;
                $value = $obj;
            }

            if (is_array($expProperty)) {
                // Label all blank nodes to connect duplicates
                $this->labelBlankNodes($value);

                // Create deep copies of the value for each property
                $serialized = serialize($value);

                foreach ($expProperty['@id'] as $item) {
                    $value = unserialize($serialized);
                    self::mergeIntoProperty($element, $item, $value, true);
                }
            } else {
                self::mergeIntoProperty($element, $expProperty, $value, true);
            }
        }

        // All properties have been processed. Make sure the result is valid
        // and optimize object where possible
        $numProps = count(get_object_vars($element));

        // Annotations are allowed everywhere
        if (property_exists($element, '@annotation')) {
            $numProps--;
        }

        if (property_exists($element, '@value')) {
            $numProps--;  // @value
            if (property_exists($element, '@language')) {
                if (false === $frame) {
                    if (false === is_string($element->{'@language'})) {
                        throw new SyntaxException(
                            'Invalid value for @language detected (must be a string).',
                            $element
                        );
                    } elseif (false === is_string($element->{'@value'})) {
                        throw new SyntaxException(
                            'Only strings can be language tagged.',
                            $element
                        );
                    }
                }

                $numProps--;
            } elseif (property_exists($element, '@type')) {
                if ((false === $frame) && (false === is_string($element->{'@type'}))) {
                    throw new SyntaxException(
                        'Invalid value for @type detected (must be a string).',
                        $element
                    );
                }

                $numProps--;
            }

            if ($numProps > 0) {
                throw new SyntaxException('Detected an invalid @value object.', $element);
            } elseif (null === $element->{'@value'}) {
                // object has just an @value property that is null, can be replaced with that value
                $element = $element->{'@value'};
            }

            return;
        }

        // Not an @value object, make sure @type is an array
        if (property_exists($element, '@type') && (false === is_array($element->{'@type'}))) {
            $element->{'@type'} = array($element->{'@type'});
        }
        if (($numProps > 1) && ((property_exists($element, '@list') || property_exists($element, '@set')))) {
            throw new SyntaxException(
                'An object with a @list or @set property can\'t contain other properties.',
                $element
            );
        } elseif (property_exists($element, '@set')) {
            // @set objects can be optimized away as they are just syntactic sugar
            $element = $element->{'@set'};
        } elseif (($numProps === 1) && (false === $frame) && property_exists($element, '@language')) {
            // if there's just @language and nothing else and we are not expanding a frame, drop whole object
            $element = null;
        }
    }

    /**
     * Expands the value of a keyword
     *
     * @param object  $element    The object this property-value pair is part of.
     * @param string  $activeprty The active property.
     * @param string  $keyword    The keyword whose value is being expanded.
     * @param mixed   $value      The value to expand.
     * @param array   $activectx  The active context.
     * @param boolean $frame      True if a frame is being expanded, otherwise false.
     *
     * @throws SyntaxException If the JSON-LD document contains syntax errors.
     */
    private function expandKeywordValue(&$element, $activeprty, $keyword, $value, $activectx, $frame)
    {
        // Ignore all null values except for @value as in that case it is
        // needed to determine what @type means
        if ((null === $value) && ('@value' !== $keyword)) {
            return;
        }

        if ('@id' === $keyword) {
            if (false === is_string($value)) {
                throw new SyntaxException('Invalid value for @id detected (must be a string).', $element);
            }

            $value = $this->expandIri($value, $activectx, true);
            self::setProperty($element, $keyword, $value);

            return;
        }

        if ('@type' === $keyword) {
            if (is_string($value)) {
                $value = $this->expandIri($value, $activectx, true, true);
                self::setProperty($element, $keyword, $value);

                return;
            }

            if (false === is_array($value)) {
                $value = array($value);
            }

            $result = array();

            foreach ($value as $item) {
                if (is_string($item)) {
                    $result[] = $this->expandIri($item, $activectx, true, true);
                } else {
                    if (false === $frame) {
                        throw new SyntaxException("Invalid value for $keyword detected.", $value);
                    }

                    self::mergeIntoProperty($element, $keyword, $item);
                }
            }

            // Don't keep empty arrays
            if (count($result) >= 1) {
                self::mergeIntoProperty($element, $keyword, $result, true);
            }
        }

        if (('@value' === $keyword) || ('@language' === $keyword) || ('@annotation' === $keyword)) {
            if (false === $frame) {
                if (is_array($value) && (1 === count($value))) {
                    $value = $value[0];
                }

                if ('@value' !== $keyword) {
                    if (false === is_string($value)) {
                        throw new SyntaxException("Invalid value for $keyword detected; must be a string.", $value);
                    }
                } elseif ((null !== $value) && (false === is_scalar($value))) {
                    // we need to preserve @value: null to distinguish values form nodes
                    throw new SyntaxException("Invalid value for $keyword detected (must be a scalar).", $value);
                }
            } elseif (false === is_array($value)) {
                $value = array($value);
            }

            self::setProperty($element, $keyword, $value);

            return;
        }

        if (('@set' === $keyword) || ('@list' === $keyword)) {
            $this->expand($value, $activectx, $activeprty, $frame);
            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }

        if ('@graph' === $keyword) {
            $this->expand($value, $activectx, $keyword, $frame);
            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }
    }

    /**
     * Expands a scalar value
     *
     * @param mixed  $value      The value to expand.
     * @param array  $activectx  The active context.
     * @param string $activeprty The active property.
     *
     * @return Object The expanded value.
     */
    private function expandValue($value, $activectx, $activeprty)
    {
        $def = $this->getPropertyDefinition($activectx, $activeprty);

        $result = new Object();

        if ('@id' === $def['@type']) {
            $result->{'@id'} = $this->expandIri($value, $activectx, true);
        } else {
            $result->{'@value'} = $value;

            if (isset($def['@type'])) {
                if ('_:' === substr($def['@type'], 0, 2)) {
                    $result->{'@type'} = $this->getBlankNodeId($def['@type']);
                } else {
                    $result->{'@type'} = $def['@type'];
                }
            } elseif (isset($def['@language']) && is_string($result->{'@value'})) {
                $result->{'@language'} = $def['@language'];
            }
        }

        return $result;
    }

    /**
     * Labels all nodes in an expanded JSON-LD structure with fresh blank node identifiers
     *
     * This method assumes that element and all its children have already been
     * expanded.
     *
     * @param mixed $element The expanded JSON-LD structure whose blank
     *                        nodes should be labeled.
     */
    private function labelBlankNodes(&$element)
    {
        if (is_array($element)) {
            foreach ($element as &$value) {
                $this->labelBlankNodes($value);
            }
        } elseif (is_object($element)) {
            if (property_exists($element, '@value')) {
                return;
            }

            if (property_exists($element, '@list')) {
                $this->labelBlankNodes($element->{'@list'});

                return;
            }

            $properties = array_keys(get_object_vars($element));
            sort($properties);

            if (false === property_exists($element, '@id')) {
                $element->{'@id'} = $this->getBlankNodeId();
            }

            foreach ($properties as $key) {
                $this->labelBlankNodes($element->{$key});
            }
        }

    }

    /**
     * Expand a property to an IRI or a JSON-LD keyword
     *
     * @param mixed $value     The value to be expanded to an absolute IRI.
     * @param array $activectx The active context.
     *
     * @return null|string|string[] If the property could be expanded either
     *                              the IRI(s) or the keyword is returned;
     *                              otherwise null is returned.
     */
    private function expandProperty($value, $activectx)
    {
        if (isset($activectx['@propertyGenerators'][$value])) {
            return $activectx['@propertyGenerators'][$value];
        }

        $result = $this->expandIri($value, $activectx, false, true);

        return $result;
    }

    /**
     * Expands a JSON-LD IRI value (term, compact IRI, IRI) to an absolute
     * IRI and relabels blank nodes
     *
     * This method is nothing else than a wrapper around {@link doExpandIri}
     * ensuring that all blank nodes are relabeled.
     *
     * @param mixed $value         The value to be expanded to an absolute IRI.
     * @param array $activectx     The active context.
     * @param bool  $relativeIri   Specifies whether $value should be treated as
     *                             relative IRI against the base IRI or not.
     * @param bool  $vocabRelative Specifies whether $value is relative to @vocab
     *                             if set or not.
     *
     * @return string The expanded IRI.
     */
    private function expandIri($value, $activectx, $relativeIri = false, $vocabRelative = false)
    {
        $result = $this->doExpandIri($value, $activectx, $relativeIri, $vocabRelative);

        if ('_:' === substr($result, 0, 2)) {
            return $this->getBlankNodeId($result);
        }

        return $result;
    }

    /**
     * Expands a JSON-LD IRI value (term, compact IRI, IRI) to an absolute IRI
     *
     * @param mixed  $value         The value to be expanded to an absolute IRI.
     * @param array  $activectx     The active context.
     * @param bool   $relativeIri   Specifies whether $value should be treated as
     *                              relative IRI against the base IRI or not.
     * @param bool   $vocabRelative Specifies whether $value is relative to @vocab
     *                              if set or not.
     * @param object $localctx      If the IRI is being expanded as part of context
     *                              processing, the current local context has to be
     *                              passed as well.
     * @param array  $path          A path of already processed terms.
     *
     * @return string The expanded IRI.
     */
    private function doExpandIri(
        $value,
        $activectx,
        $relativeIri = false,
        $vocabRelative = false,
        $localctx = null,
        $path = array()
    ) {
        if ((null === $value) || in_array($value, self::$keywords)) {
            return $value;
        }

        if ($localctx) {
            if (in_array($value, $path)) {
                throw new ProcessException(
                    'Cycle in context definition detected: ' . join(' -> ', $path) . ' -> ' . $path[0],
                    $localctx
                );
            } else {
                $path[] = $value;

                if (count($path) >= self::CONTEXT_MAX_IRI_RECURSIONS) {
                    throw new ProcessException(
                        'Too many recursions in term definition: ' . join(' -> ', $path) . ' -> ' . $path[0],
                        $localctx
                    );
                }
            }

            if (isset($localctx->{$value})) {
                if (is_string($localctx->{$value})) {
                    return $this->doExpandIri($localctx->{$value}, $activectx, false, true, $localctx, $path);
                } elseif (isset($localctx->{$value}->{'@id'})) {
                    if (false === is_string($localctx->{$value->{'@id'}})) {
                        throw new SyntaxException(
                            'A term definition must not use a property generator: ' . join(' -> ', $path),
                            $localctx
                        );
                    }

                    return $this->doExpandIri($localctx->{$value}->{'@id'}, $activectx, false, true, $localctx, $path);
                }
            }
        }

        if (array_key_exists($value, $activectx)) {
            return $activectx[$value]['@id'];
        }

        if (false !== strpos($value, ':')) {
            list($prefix, $suffix) = explode(':', $value, 2);

            if (('_' === $prefix) || ('//' === substr($suffix, 0, 2))) {
                // Safety measure to prevent reassigned of, e.g., http://
                // the "_" prefix is reserved for blank nodes and can't be expanded
                return $value;
            }

            if ($localctx) {
                $prefix = $this->doExpandIri($prefix, $activectx, false, true, $localctx, $path);

                // If prefix contains a colon, we have successfully expanded it
                if (false !== strpos($prefix, ':')) {
                    return $prefix . $suffix;
                }
            } elseif (array_key_exists($prefix, $activectx)) {
                // compact IRI
                return $activectx[$prefix]['@id'] . $suffix;
            }
        } else {
            if ($vocabRelative && array_key_exists('@vocab', $activectx)) {
                return $activectx['@vocab'] . $value;
            } elseif ($relativeIri) {
                return (string) $this->baseIri->resolve($value);
            }
        }

        // can't expand it, return as is
        return $value;
    }

    /**
     * Compacts a JSON-LD document
     *
     * Attention: This method must be called with an expanded element,
     * otherwise it might not work.
     *
     * @param mixed  $element    A JSON-LD element to be compacted.
     * @param array  $activectx  The active context.
     * @param array  $inversectx The inverse context.
     * @param string $activeprty The active property.
     *
     * @return mixed The compacted JSON-LD document.
     */
    public function compact(&$element, $activectx = array(), $inversectx = array(), $activeprty = null)
    {
        if (is_array($element)) {
            $result = array();
            foreach ($element as &$item) {
                $this->compact($item, $activectx, $inversectx, $activeprty);
                if (null !== $item) {
                    $result[] = $item;
                }
            }

            if ($this->compactArrays && (1 === count($result))) {
                $element = $result[0];
            } else {
                $element = $result;
            }

            return;
        }

        if (false === is_object($element)) {
            // element is already in compact form, nothing else to do
            return;
        }

        // element is an object
        if (property_exists($element, '@null')) {
            // @null objects are used in framing
            $element = null;

            return;
        }

        if (property_exists($element, '@value') || property_exists($element, '@id')) {
            $def = $this->getPropertyDefinition($activectx, $activeprty);
            $element = $this->compactValue($element, $def, $activectx, $inversectx);

            if (false === is_object($element)) {
                return;
            }
        }

        // Otherwise, compact all properties
        $properties = get_object_vars($element);
        ksort($properties);

        $element = new Object();

        foreach ($properties as $property => $value) {
            // This is necessary as foreach operates on a copy of the array
            // and it might have been modified by property generators
            if (false === array_key_exists($property, $properties)) {
                continue;
            }
            $value = $properties[$property];

            if (in_array($property, self::$keywords)) {
                if ('@id' === $property) {
                    // TODO Transform @id to relative IRIs by default??
                    $value = $this->compactIri($value, $activectx, $inversectx);
                } elseif ('@type' === $property) {
                    if (is_string($value)) {
                        $value = $this->compactIri($value, $activectx, $inversectx, null, true);
                    } else {
                        foreach ($value as $key => &$iri) {
                            // TODO Transform to relative IRIs by default??
                            $iri = $this->compactIri($iri, $activectx, $inversectx, null, true);
                        }

                        if ($this->compactArrays && (1 === count($value))) {
                            $value = $value[0];
                        }
                    }
                } elseif ('@graph' === $property) {
                    $this->compact($value, $activectx, $inversectx, $property);

                    if (false === is_array($value)) {
                        $value = array($value);
                    }
                }

                // Get the keyword alias from the inverse context if available
                $activeprty = (isset($inversectx[$property]['term']))
                    ? $inversectx[$property]['term']
                    : $property;

                self::setProperty($element, $activeprty, $value);

                // ... continue with next property
                continue;
            }

            // Make sure that empty arrays are preserved
            if (0 === count($value)) {
                $activeprty = $this->compactIri($property, $activectx, $inversectx, $value, true);

                if (is_array($activeprty)) {
                    $activeprty = $this->removePropertyGeneratorDuplicates($properties, $property, null, $activectx, $activeprty);
                }

                self::mergeIntoProperty($element, $activeprty, $value);

                // ... continue with next property
                continue;
            }

            // Compact every item in value separately as they could map to different terms
            foreach ($value as $item) {
                $activeprty = $this->compactIri($property, $activectx, $inversectx, $item, true);

                if (is_array($activeprty)) {
                    $activeprty = $this->removePropertyGeneratorDuplicates($properties, $property, $item, $activectx, $activeprty);
                }

                $def = $this->getPropertyDefinition($activectx, $activeprty);

                if (in_array($def['@container'], array('@language', '@annotation'))) {
                    if (false === property_exists($element, $activeprty)) {
                        $element->{$activeprty} = new Object();
                    }

                    $def[$def['@container']] = $item->{$def['@container']};
                    $item = $this->compactValue($item, $def, $activectx, $inversectx);

                    $this->compact($item, $activectx, $inversectx, $activeprty);

                    self::mergeIntoProperty($element->{$activeprty}, $def[$def['@container']], $item);

                    continue;
                }

                if (is_object($item)) {
                    if (property_exists($item, '@list')) {
                        $this->compact($item->{'@list'}, $activectx, $inversectx, $activeprty);

                        if (false === is_array($item->{'@list'})) {
                            $item->{'@list'} = array($item->{'@list'});
                        }

                        if ('@list' === $def['@container']) {
                            $item = $item->{'@list'};

                            // a term can just hold one list if it has a @list container
                            // (we don't support lists of lists)
                            self::setProperty($element, $activeprty, $item);

                            continue;  // ... continue with next value
                        }
                    }

                    $this->compact($item, $activectx, $inversectx, $activeprty);
                }

                // Merge value back into resulting object making sure that value is always
                // an array if a container is set or compactArrays is set to false
                $asArray = ((false === $this->compactArrays) || (false === $def['compactArrays']));

                self::mergeIntoProperty($element, $activeprty, $item, $asArray);
            }
        }
    }

    /**
     * Compacts a value
     *
     * The passed property definition must be an associative array
     * containing the following data:
     *
     * <code>
     *   @type       => type IRI or null
     *   @language   => language code or null
     *   @annotation => annotation string or null
     * </code>
     *
     * @param mixed $value      The value to compact (arrays are not allowed!).
     * @param array $definition The active property's definition.
     * @param array $activectx  The active context.
     * @param array $inversectx The inverse context.
     *
     * @return mixed The compacted value.
     */
    private function compactValue($value, $definition, $activectx, $inversectx)
    {
        if (property_exists($value, '@annotation') &&
            ($value->{'@annotation'} === $definition['@annotation'])) {
            unset($value->{'@annotation'});
        }

        $numProperties = count(get_object_vars($value));

        if (property_exists($value, '@id') && (1 === $numProperties) &&
            ('@id' === $definition['@type'])) {
            return $this->compactIri($value->{'@id'}, $activectx, $inversectx);
        }

        if (property_exists($value, '@value')) {
            $criterion = (isset($value->{'@type'})) ? '@type' : null;
            $criterion = (isset($value->{'@language'})) ? '@language' : $criterion;

            if (null !== $criterion) {
                if ($value->{$criterion} !== $definition[$criterion]) {
                    return $value;
                }

                unset($value->{$criterion});

                return (2 === $numProperties) ? $value->{'@value'} : $value;
            }

            // the object has neither a @type nor a @language property
            // check the active property's definition
            if ((null !== $definition['@language']) && is_string($value->{'@value'})) {
                // if the property is language tagged, we can't compact
                // the value if it is a string
                return $value;
            }

            // we can compact the value
            return (1 === $numProperties) ? $value->{'@value'} : $value;
        }

        return $value;
    }

    /**
     * Compacts an absolute IRI (or aliases a keyword)
     *
     * If a value is passed, this method assumes that a property is being
     * compacted and may return property generators; otherwise only terms,
     * compact IRIs or, depending on the `vocabRelative` flag, relative IRIs
     * are returned. If the IRI couldn't be compacted, the IRI is returned
     * as is.
     *
     * @param mixed $iri           The IRI to be compacted.
     * @param array $activectx     The active context.
     * @param array $inversectx    The inverse context.
     * @param mixed $value         The value of the property to compact. If
     *                             `null` is passed, no property generators
     *                             will be returned.
     * @param bool  $vocabRelative If `true` is passed, this method tries
     *                             to convert the IRI to an IRI relative to
     *                             `@vocab`; otherwise, that fall back
     *                             mechanism is disabled.
     *
     * @return string Returns the compacted IRI on success; othwewise the
     *                IRI is returned as is.
     */
    private function compactIri($iri, $activectx, $inversectx, $value = null, $vocabRelative = false)
    {
        $result = null;

        if (array_key_exists($iri, $inversectx)) {
            if (null !== $value) {
                $valueProfile = $this->getValueProfile($value);

                $path = array();

                if ('@list' === $valueProfile['@container']) {
                    $path[] = array('@list', '@null');
                } else {
                    $path[] = array($valueProfile['@container'], '@set', '@null');
                }

                if (null === $valueProfile['typeLang']) {
                    $path[] = array('@null');
                    $path[] = array('@null');
                } else {
                    $path[] = array($valueProfile['typeLang'], '@null');
                    $path[] = array($valueProfile['typeLangValue'], '@null');  // check this!
                }

                $result = $this->queryInverseContext($inversectx[$iri], $path);

                if (is_string($result) || isset($result['term'])) {
                    return $result;
                }
            } elseif (isset($inversectx[$iri]['term'])) {
                return $inversectx[$iri]['term'];
            }
        }

        // Try to compact to a compact IRI
        $iriLen = strlen($iri);

        foreach ($inversectx as $termIri => $def) {
            $termIriLen = strlen($termIri);
            if (isset($def['term']) && (0 === substr_compare($iri, $termIri, 0, $termIriLen)) &&
                (false !== ($compactIri = substr($iri, $termIriLen)))) {
                $compactIri = $def['term'] . ':' . $compactIri;
                if (false === isset($activectx[$compactIri])) {
                    if (null === $result) {
                        return $compactIri;
                    }

                    $result['term'] = $compactIri;

                    return $result;
                }
            }
        }

        // Last resort, use @vocab if set and the result isn't an empty string
        if ($vocabRelative && isset($activectx['@vocab']) && (0 === strpos($iri, $activectx['@vocab'])) &&
            (false !== ($relativeIri = substr($iri, strlen($activectx['@vocab'])))) &&
            (false === isset($activectx[$relativeIri]))) {
            if (null === $result) {
                return $relativeIri;
            }

            $result['term'] = $relativeIri;

            return $result;
        }

        // IRI couldn't be compacted, return as is
        if (null === $result) {
            return $iri;
        }

        $result['term'] = $iri;

        return $result;
    }

    /**
     * Removes the duplicate values introduced by property generators
     *
     * @param array  $element    An associative array containing the
     *                           property-value pairs of the object being
     *                           currently processed.
     * @param string $property   The IRI of the currently being processed
     *                           property.
     * @param mixed  $value      The currently being processed value for
     *                           whose duplicates should be removed; if this
     *                           is `null`, the existence of the property is
     *                           enough for a match (used for empty arrays)-
     * @param array  $activectx  The active context.
     * @param array  $candidates The property generator candidates as
     *                           returned by the {@link compactIri()} method.
     *
     * @return string Returns the name of the property under which the
     *                currently being processed value should be stored.
     */
    private function removePropertyGeneratorDuplicates(&$element, $property, $value, $activectx, $candidates)
    {
        foreach ($candidates['propGens'] as $propGen) {
            $def = $this->getPropertyDefinition($activectx, $propGen);

            $valueMap = array();

            foreach ($def['@id'] as $iri) {
                if (($iri === $property) || (false === isset($element[$iri]))) {
                    continue;
                }

                if (null === $value) {
                    $valueMap[$iri] = null;
                }

                foreach ($element[$iri] as $key => &$val) {
                    if (self::subtreeEquals($value, $val)) {
                        $valueMap[$iri] = $key;
                    }
                }
            }

            if (count($valueMap) !== (count($def['@id']) - 1)) {
                // value wasn't found for all of the property generator's IRIs,
                // continue with next property generator
                continue;
            }

            foreach ($valueMap as $iri => $key) {
                if (null === $key) {
                    if (0 === count($element[$iri])) {
                        unset($element[$iri]);
                    }
                    continue;
                }

                if (1 === count($element[$iri])) {
                    unset($element[$iri]);
                } else {
                    unset($element[$iri][$key]);
                }
            }

            return $propGen;
        }

        // fall back to term or IRI if none of the property generators matches
        return $candidates['term'];
    }

    /**
     * Verifies whether two JSON-LD subtrees are equal not
     *
     * Please note that two unlabeled blank nodes will never be equal by
     * definition.
     *
     * @param mixed $a The first subtree.
     * @param mixed $b The second subree.
     *
     * @return bool Returns true if the two subtrees are equal; otherwise
     *              false.
     */
    private static function subtreeEquals($a, $b)
    {
        if (gettype($a) !== gettype($b)) {
            return false;
        }

        if (is_scalar($a)) {
            return ($a === $b);
        }

        if (is_array($a)) {
            $len = count($a);

            if ($len !== count($b)) {
                return false;
            }

            // TODO Ignore order for sets?
            for ($i = 0; $i < $len; $i++) {
                if (false === self::subtreeEquals($a[$i], $b[$i])) {
                    return false;
                }
            }

            return true;
        }

        if (!property_exists($a, '@id') &&
            !property_exists($a, '@value') &&
            !property_exists($a, '@list')) {
            // Blank nodes can never match as they can't be identified
            return false;
        }

        $properties = array_keys(get_object_vars($a));

        if (count($properties) !== count(get_object_vars($b))) {
            return false;
        }

        foreach ($properties as $property) {
            if ((false === property_exists($b, $property)) ||
                (false === self::subtreeEquals($a->{$property}, $b->{$property}))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculates a value profile
     *
     * A value profile represent the schema of the value ignoring the
     * concrete value. It is an associative array containing the following
     * keys-value pairs:
     *
     *   * `@container`: the container, defaults to `@set`
     *   * `typeLang`: is set to `@type` for typed values or `@language` for
     *     (language-tagged) strings; for all other values it is set to
     *     `null`
     *   * `typeLangValue`: set to the type of a typed value or the language
     *     of a language-tagged string (`@null` for all other strings); for
     *     all other values it is set to `null`
     *
     * @param mixed $value The value.
     *
     * @return array The value profile.
     */
    private function getValueProfile($value)
    {
        $valueProfile = array(
            '@container' => '@set',
            'typeLang' => null,
            'typeLangValue' => null
        );

        if (false === is_object($value)) {
            return $valueProfile;
        }

        if (property_exists($value, '@annotation')) {
            $valueProfile['@container'] = '@annotation';
        }

        if (property_exists($value, '@id')) {
            $valueProfile['typeLang'] = '@type';
            $valueProfile['typeLangValue'] = '@id';

            return $valueProfile;
        }

        if (property_exists($value, '@value')) {
            if (property_exists($value, '@type')) {
                $valueProfile['typeLang'] = '@type';
                $valueProfile['typeLangValue'] = $value->{'@type'};
            } elseif (property_exists($value, '@language')) {
                $valueProfile['typeLang'] = '@language';
                $valueProfile['typeLangValue'] = $value->{'@language'};

                if (false === property_exists($value, '@annotation')) {
                    $valueProfile['@container'] = '@language';
                }
            } elseif (is_string($value->{'@value'})) {
                $valueProfile['typeLang'] = '@language';
                $valueProfile['typeLangValue'] = '@null';
            }

            return $valueProfile;
        }

        if (property_exists($value, '@list')) {
            $len = count($value->{'@list'});

            if ($len > 0) {
                $valueProfile = $this->getValueProfile($value->{'@list'}[0]);
            }

            if (false === property_exists($value, '@annotation')) {
                $valueProfile['@container'] = '@list';
            }


            for ($i = $len - 1; $i > 0; $i--) {
                $profile = $this->getValueProfile($value->{'@list'}[$i]);

                if (($valueProfile['typeLang'] !== $profile['typeLang']) ||
                    ($valueProfile['typeLangValue'] !== $profile['typeLangValue'])) {
                    $valueProfile['typeLang'] = null;
                    $valueProfile['typeLangValue'] = null;

                    return $valueProfile;
                }
            }
        }

        return $valueProfile;
    }

    /**
     * Queries the inverse context to find the term or property generator(s)
     * for a given query path (= value profile)
     *
     * @param array   $inversectxFrag The inverse context (or a subtree thereof)
     * @param array   $path           The query corresponding to the value profile
     * @param integer $level          The recursion depth.
     *
     * @return null|string|string[] If the IRI maps to one or more property generators
     *                              their terms plus (if available) a term matching the
     *                              IRI that isn't a property generator will be returned;
     *                              if the IRI doesn't map to a property generator but just
     *                              to terms, the best matching term will be returned;
     *                              otherwise null will be returned.
     */
    private function queryInverseContext($inversectxFrag, $path, $level = 0)
    {
        $result = null;

        if (3 === $level) {
            if (isset($inversectxFrag['propGens'])) {
                $result = array('propGens' => $inversectxFrag['propGens']);
            }

            if (isset($inversectxFrag['term'])) {
                if (null === $result) {
                    return $inversectxFrag['term'];
                }

                $result['term'] = $inversectxFrag['term'];
            }

            return $result;
        }

        foreach ($path[$level] as $entry) {
            if (false === isset($inversectxFrag[$entry])) {
                continue;
            }

            $tmpResult = $this->queryInverseContext($inversectxFrag[$entry], $path, $level + 1);

            if (is_string($tmpResult)) {
                if (null === $result) {
                    return $tmpResult;
                }

                $tmpResult = array(
                    'propGens' => array(),
                    'term' => $tmpResult
                );
            }

            if (null === $result) {
                $result = $tmpResult;
            } else {
                foreach ($tmpResult['propGens'] as $propGen) {
                    if (false === in_array($propGen, $result['propGens'])) {
                        $result['propGens'][] = $propGen;
                    }
                }
                if ((false === isset($result['term'])) && isset($tmpResult['term'])) {
                    $result['term'] = $tmpResult['term'];
                }
            }
        }

        return $result;
    }

    /**
     * Returns a property's definition
     *
     * The result will be in the form
     *
     * <code>
     *   array('@type'      => type or null,
     *         '@language'  => language or null,
     *         '@container' => container or null,
     *         'isKeyword'  => true or false)
     * </code>
     *
     * If `$only` is set, only the value of that key of the array
     * above will be returned.
     *
     * @param array  $activectx The active context.
     * @param string $property  The property.
     * @param string $only      If set, only a this element of the definition
     *                          will be returned.
     *
     * @return array|string|null Returns either the property's definition or
     *                           null if not found.
     */
    private function getPropertyDefinition($activectx, $property, $only = null)
    {
        $result = array(
            '@type' => null,
            '@language' => (isset($activectx['@language']))
                ? $activectx['@language']
                : null,
            '@annotation' => null,
            '@container' => null,
            'isKeyword' => false,
            'compactArrays' => true
        );

        if (in_array($property, self::$keywords)) {
            $result['@type'] = (('@id' === $property) || ('@type' === $property))
                ? '@id'
                : null;
            $result['@language'] = null;
            $result['isKeyword'] = true;
            $result['compactArrays'] = (bool) (('@list' !== $property) && ('@graph' !== $property));
        } else {
            $def = null;

            if (isset($activectx['@propertyGenerators'][$property])) {
                $def = $activectx['@propertyGenerators'][$property];
            } elseif (isset($activectx[$property])) {
                $def = $activectx[$property];
            }

            if (null !== $def) {
                $result['@id'] = $def['@id'];

                if (isset($def['@type'])) {
                    $result['@type'] = $def['@type'];
                    $result['@language'] = null;
                } elseif (array_key_exists('@language', $def)) {  // could be null
                    $result['@language'] = $def['@language'];
                }

                if (isset($def['@container'])) {
                    $result['@container'] = $def['@container'];

                    if (('@list' === $def['@container']) || ('@set' === $def['@container'])) {
                        $result['compactArrays'] = false;
                    }
                }
            }
        }

        if ($only) {
            return (isset($result[$only])) ? $result[$only] : null;
        }

        return $result;
    }

    /**
     * Processes a local context to update the active context
     *
     * @param mixed $loclctx    The local context.
     * @param array $activectx  The active context.
     * @param array $remotectxs The already included remote contexts.
     *
     * @throws ProcessException If processing of the context failed.
     * @throws ParseException   If a remote context couldn't be processed.
     */
    public function processContext($loclctx, &$activectx, $remotectxs = array())
    {
        // Initialize variable
        $activectxKey = null;

        if (is_object($loclctx)) {
            $loclctx = clone $loclctx;
        }

        if (false === is_array($loclctx)) {
            $loclctx = array($loclctx);
        }

        foreach ($loclctx as $context) {
            if (null === $context) {
                $activectx = array();
            } elseif (is_object($context)) {
                // make sure we don't modify the passed context
                $context = clone $context;

                if (isset($context->{'@vocab'})) {
                    if ((false === is_string($context->{'@vocab'})) || (false === strpos($context->{'@vocab'}, ':'))) {
                        throw new SyntaxException("The value of @vocab must be an absolute IRI.", $context);
                    }

                    $activectx['@vocab'] = $context->{'@vocab'};
                    unset($context->{'@vocab'});
                }

                if (property_exists($context, '@language')) {
                    if ((null !== $context->{'@language'}) && (false === is_string($context->{'@language'}))) {
                        throw new SyntaxException('The value of @language must be a string.', $context);
                    }

                    $activectx['@language'] = $context->{'@language'};
                    unset($context->{'@language'});
                }

                foreach ($context as $key => $value) {
                    unset($context->{$key});

                    if (in_array($key, self::$keywords)) {
                        // Keywords can't be altered
                        continue;
                    }

                    if (null === $value) {
                        unset($activectx[$key]);
                        $activectx[$key]['@id'] = null;

                        continue;
                    }

                    if (is_string($value)) {
                        $expanded = $this->doExpandIri($value, $activectx, false, true, $context);

                        if ((false === in_array($expanded, self::$keywords)) && (false === strpos($expanded, ':'))) {
                            throw new SyntaxException("Failed to expand $expanded to an absolute IRI.", $loclctx);
                        }

                        $activectx[$key] = array('@id' => $expanded);
                    } elseif (is_object($value)) {
                        unset($activectx[$key]);  // delete previous definition

                        $expanded = null;

                        if (property_exists($value, '@id')) {
                            if (is_array($value->{'@id'})) {
                                // it's a property generator
                                $expanded = array();

                                foreach ($value->{'@id'} as $item) {
                                    $result = $this->doExpandIri($item, $activectx, false, true, $context);

                                    if (false === strpos($result, ':')) {
                                        throw new SyntaxException(
                                            "\"$item\" in \"$key\" couldn't be expanded to an absolute IRI.",
                                            $loclctx
                                        );
                                    }

                                    $expanded[] =  $result;
                                }

                                sort($expanded);
                            } else {
                                $expanded = $this->doExpandIri($value->{'@id'}, $activectx, false, true, $context);
                            }
                        } else {
                            $expanded = $this->doExpandIri($key, $activectx, false, true, $context);
                        }

                        // Keep a reference to the place were we store the information. Property
                        // generators are stored in a separate subtree in the active context
                        if (is_array($expanded)) {
                            // and are removed from the local context as they can't be used
                            // in other term definitions
                            $activectxKey = &$activectx['@propertyGenerators'][$key];
                        } else {
                            $activectxKey = &$activectx[$key];

                            if ((null === $expanded) || in_array($expanded, self::$keywords)) {
                                // if it's an aliased keyword or the IRI is null, we ignore all other properties
                                // TODO Should we throw an exception if there are other properties?
                                $activectxKey = array('@id' => $expanded);

                                continue;
                            } elseif (false === strpos($expanded, ':')) {
                                throw new SyntaxException("Failed to expand \"$key\" to an absolute IRI.", $loclctx);
                            }
                        }

                        $activectxKey = array('@id' => $expanded);

                        if (isset($value->{'@type'})) {
                            $expanded = $this->doExpandIri($value->{'@type'}, $activectx, false, true, $context);

                            if (('@id' !== $expanded) && (false === strpos($expanded, ':'))) {
                                throw new SyntaxException("Failed to expand $expanded to an absolute IRI.", $loclctx);
                            }

                            $activectxKey['@type'] = $expanded;
                        } elseif (property_exists($value, '@language')) {
                            if ((false === is_string($value->{'@language'})) && (null !== $value->{'@language'})) {
                                throw new SyntaxException('The value of @language must be a string.', $context);
                            }

                            // Note the else. Language tagging applies just to term without type coercion
                            $activectxKey['@language'] = $value->{'@language'};
                        }

                        if (isset($value->{'@container'})) {
                            if (in_array($value->{'@container'}, array('@list', '@set', '@language', '@annotation'))) {
                                $activectxKey['@container'] = $value->{'@container'};
                            }
                        }
                    }
                }
            } else {
                $remoteContext = (string) $this->baseIri->resolve($context);
                if (in_array($remoteContext, $remotectxs)) {
                    throw new ProcessException(
                        'Recursive inclusion of remote context: ' . join(' -> ', $remotectxs) . ' -> ' .
                        $remoteContext
                    );
                }
                $remotectxs[] = $remoteContext;

                $remoteContext = JsonLD::parse($remoteContext);

                if (is_object($remoteContext) && property_exists($remoteContext, '@context')) {
                    // TODO Use the context's IRI as base IRI when processing remote contexts (ISSUE-24)
                    $this->processContext($remoteContext->{'@context'}, $activectx, $remotectxs);
                } else {
                    throw new ProcessException('Remote context "' . $context . '" is invalid.', $remoteContext);
                }
            }
        }
    }

    /**
     * Creates an inverse context to simplify IRI compaction
     *
     * The inverse context is a multidimensional array that has the
     * following shape:
     *
     * <code>
     * [container|@null|term]
     *   [@type|@language][typeIRI|languageCode]
     *   [@null][@null]
     *       [term|propGen]
     *           [ array of terms ]
     * </code>
     *
     * @param array $activectx The active context.
     *
     * @return array The inverse context.
     */
    public function createInverseContext($activectx)
    {
        $inverseContext = array();

        $defaultLanguage = isset($activectx['@language']) ? $activectx['@language'] : '@null';
        $propertyGenerators = isset($activectx['@propertyGenerators']) ? $activectx['@propertyGenerators'] : array();

        unset($activectx['@vocab']);
        unset($activectx['@language']);
        unset($activectx['@propertyGenerators']);

        $activectx = array_merge($activectx, $propertyGenerators);
        unset($propertyGenerators);

        // Put every IRI of each term into the inverse context
        foreach ($activectx as $term => $def) {
            if (null === $def['@id']) {
                // this is necessary since some terms can be decoupled from @vocab
                continue;
            }

            $container = (isset($def['@container'])) ? $def['@container'] : '@null';
            $propertyGenerator = 'propGens';  // yes

            if (false === is_array($def['@id'])) {
                $propertyGenerator = 'term';  // no
                $inverseContext[$def['@id']]['term'][] = $term;

                $def['@id'] = array($def['@id']);
            }

            foreach ($def['@id'] as $iri) {
                if (isset($def['@type'])) {
                    $inverseContext[$iri][$container]['@type'][$def['@type']][$propertyGenerator][] = $term;
                } elseif (array_key_exists('@language', $def)) {  // can be null
                    $language = (null === $def['@language']) ? '@null' : $def['@language'];

                    $inverseContext[$iri][$container]['@language'][$language][$propertyGenerator][] = $term;
                } else {
                    // Every untyped term is implicitly set to the default language
                    $inverseContext[$iri][$container]['@language'][$defaultLanguage][$propertyGenerator][] = $term;

                    $inverseContext[$iri][$container]['@null']['@null'][$propertyGenerator][] = $term;
                }
            }
        }

        // Then sort the terms and eliminate all except the lexicographically least;
        // do the same for property generators but only eliminate those expanding
        // to the same IRIs
        foreach ($inverseContext as &$containerBucket) {
            foreach ($containerBucket as $container => &$typeLangBucket) {
                if ('term' === $container) {
                    usort($typeLangBucket, array($this, 'sortTerms'));
                    $typeLangBucket = $typeLangBucket[0];

                    continue;
                }

                foreach ($typeLangBucket as $key => &$values) {
                    foreach ($values as &$termBuckets) {
                        if (isset($termBuckets['term'])) {
                            usort($termBuckets['term'], array($this, 'sortTerms'));

                            $termBuckets['term'] = $termBuckets['term'][0];
                        }

                        if (isset($termBuckets['propGens'])) {
                            usort($termBuckets['propGens'], array($this, 'sortTerms'));
                            $len = count($termBuckets['propGens']);

                            for ($j = count($termBuckets['propGens']) - 1; $j > 0; $j--) {
                                for ($i = $j - 1; $i >= 0; $i--) {
                                    if ($activectx[$termBuckets['propGens'][$i]] ===
                                        $activectx[$termBuckets['propGens'][$j]]) {
                                        array_splice($termBuckets['propGens'], $j, 1);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        uksort($inverseContext, array($this, 'sortTerms'));
        $inverseContext = array_reverse($inverseContext);

        return $inverseContext;
    }

    /**
     * Creates a node map of an expanded JSON-LD document
     *
     * @param object          $nodeMap     The object holding the node map.
     * @param object|object[] $element     An expanded JSON-LD element to
     *                                     be put into the node map
     * @param string          $activegraph The graph currently being processed.
     * @param string          $activeid    The node currently being processed.
     * @param string          $activeprty  The property currently being processed.
     * @param object          $list        The list object if a list is being
     *                                     processed.
     */
    private function generateNodeMap(
        &$nodeMap,
        $element,
        $activegraph = self::DEFAULT_GRAPH,
        $activeid = null,
        $activeprty = null,
        &$list = null
    ) {
        if (is_array($element)) {
            foreach ($element as $item) {
                $this->generateNodeMap($nodeMap, $item, $activegraph, $activeid, $activeprty, $list);
            }

            return;
        }

        // Relabel blank nodes in @type and add a node to the current graph
        if (property_exists($element, '@type')) {
            $types = null;

            if (is_array($element->{'@type'})) {
                $types = &$element->{'@type'};
            } else {
                $types = array(&$element->{'@type'});
            }

            foreach ($types as &$type) {
                if (0 === substr_compare($type, '_:', 0, 2)) {
                    $type = $this->getBlankNodeId($type);
                }

                if (false === property_exists($nodeMap->{$activegraph}, $type)) {
                    $nodeMap->{$activegraph}->{$type} = new Object();
                    $nodeMap->{$activegraph}->{$type}->{'@id'} = $type;
                }
            }
        }

        // Handle value objects
        if (property_exists($element, '@value')) {
            if (null === $activeprty) {
                // This is a free-floating value, we can't store it anywhere, store it
                // under a new blank node identifier in the node map
                $storeAs = $this->getBlankNodeId();
                $nodeMap->{$activegraph}->{$storeAs} = $element;
            } elseif (null === $list) {
                $this->mergeIntoProperty($nodeMap->{$activegraph}->{$activeid}, $activeprty, $element, true, true);
            } else {
                $this->mergeIntoProperty($list, '@list', $element, true, false);
            }
        } elseif (property_exists($element, '@list')) {
            $result = new Object();
            $result->{'@list'} = array();

            $this->generateNodeMap($nodeMap, $element->{'@list'}, $activegraph, $activeid, $activeprty, $result);

            if (null === $activeprty) {
                // This is a free-floating list, we can't store it anywhere, store it
                // under a new blank node identifier in the node map
                $storeAs = $this->getBlankNodeId();
                $nodeMap->{$activegraph}->{$storeAs} = $result;
            } else {
                $this->mergeIntoProperty($nodeMap->{$activegraph}->{$activeid}, $activeprty, $result, true, false);
            }
        // and node objects
        } else {
            $id = null;

            if (false === property_exists($element, '@id')) {
                $id = $this->getBlankNodeId();
            } elseif (0 === substr_compare($element->{'@id'}, '_:', 0, 2)) {
                $id = $this->getBlankNodeId($element->{'@id'});
            } else {
                $id = $element->{'@id'};
            }
            unset($element->{'@id'});

            // Create node in node map if it doesn't exist yet
            if (false === property_exists($nodeMap->{$activegraph}, $id)) {
                $nodeMap->{$activegraph}->{$id} = new Object();
                $nodeMap->{$activegraph}->{$id}->{'@id'} = $id;
            }

            // Add reference to active property
            if (null !== $activeprty) {
                $reference = new Object();
                $reference->{'@id'} = $id;

                if (null === $list) {
                    $this->mergeIntoProperty($nodeMap->{$activegraph}->{$activeid}, $activeprty, $reference, true, true);
                } else {
                    $this->mergeIntoProperty($list, '@list', $reference, true, false);
                }
            }

            if (property_exists($element, '@type')) {
                $this->mergeIntoProperty($nodeMap->{$activegraph}->{$id}, '@type', $element->{'@type'}, true, true);
                unset($element->{'@type'});
            }

            if (property_exists($element, '@annotation')) {
                $this->setProperty($nodeMap->{$activegraph}->{$id}, '@annotation',  $element->{'@annotation'});
                unset($element->{'@annotation'});
            }

            // This node also represent a named graph, process it
            if (property_exists($element, '@graph')) {
                if (self::UNION_GRAPH !== $activegraph) {
                    if (false === property_exists($nodeMap, $id)) {
                        $nodeMap->{$id} = new Object();
                    }

                    $this->generateNodeMap($nodeMap, $element->{'@graph'}, $id);
                } else {
                    $this->generateNodeMap($nodeMap, $element->{'@graph'}, $activegraph);
                }

                unset($element->{'@graph'});
            }

            // Process all other properties in order
            $properties = get_object_vars($element);
            ksort($properties);

            foreach ($properties as $property => $value) {
                if (false === property_exists($nodeMap->{$activegraph}->{$id}, $property)) {
                    $nodeMap->{$activegraph}->{$id}->{$property} = array();
                }

                $this->generateNodeMap($nodeMap, $value, $activegraph, $id, $property, $list);
            }
        }
    }

    /**
     * Generate a new blank node identifier
     *
     * If an identifier is passed, a new blank node identifier is generated
     * for it and stored for subsequent use. Calling the method with the same
     * identifier (except null) will thus always return the same blank node
     * identifier.
     *
     * @param string $id If available, existing blank node identifier.
     *
     * @return string Returns a blank node identifier.
     */
    private function getBlankNodeId($id = null)
    {
        if ((null !== $id) && isset($this->blankNodeMap[$id])) {
            return $this->blankNodeMap[$id];
        }

        $bnode = '_:t' . $this->blankNodeCounter++;
        $this->blankNodeMap[$id] = $bnode;

        return $bnode;
    }

    /**
     * Flattens a JSON-LD document
     *
     * @param mixed  $element A JSON-LD element to be flattened.
     *
     * @return array An array representing the flattened element.
     */
    public function flatten($element)
    {
        $nodeMap = new Object();
        $nodeMap->{self::DEFAULT_GRAPH} = new Object();

        $this->generateNodeMap($nodeMap, $element);

        $defaultGraph = $nodeMap->{self::DEFAULT_GRAPH};
        unset($nodeMap->{self::DEFAULT_GRAPH});

        // Store named graphs in @graph of a the node representing the graph
        // in the default graph
        foreach ($nodeMap as $graphName => $graph) {
            if (!isset($defaultGraph->{$graphName})) {
                $defaultGraph->{$graphName} = new Object();
                $defaultGraph->{$graphName}->{'@id'} = $graphName;
            }

            $graph = (array) $graph;
            ksort($graph);

            $defaultGraph->{$graphName}->{'@graph'} = array_values($graph);
        }

        $defaultGraph = (array) $defaultGraph;
        ksort($defaultGraph);

        return array_values($defaultGraph);
    }

    /**
     * Converts a JSON-LD document to RDF quads
     *
     * The result is an array of arrays each containing a quad:
     *
     * <code>
     * array(
     *   array(id, property, value, graph)
     * )
     * </code>
     *
     * @param mixed  $element    A JSON-LD element to be transformed into quads.
     * @param array  $result     The resulting quads.
     * @param array  $activesubj The active subject.
     * @param string $activeprty The active property.
     * @param string $graph      The graph currently being processed.
     *
     * @return array The extracted quads.
     */
    public function toRdf(&$element, &$result, $activesubj = null, $activeprty = null, $graph = null)
    {
        if (is_array($element)) {
            foreach ($element as &$item) {
                $this->toRdf($item, $result, $activesubj, $activeprty, $graph);
            }

            return;
        }

        if (property_exists($element, '@value')) {
            $value = Value::fromJsonLd($element);
            $result[] = new Quad($activesubj, $activeprty, $value, $graph);

            return;
        } elseif (property_exists($element, '@list')) {
            if (0 === ($len = count($element->{'@list'}))) {
                $result[] = new Quad($activesubj, $activeprty, new IRI(RdfConstants::RDF_NIL), $graph);

                return;
            }

            $first_bn = new IRI($this->getBlankNodeId());
            $result[] = new Quad($activesubj, $activeprty, $first_bn, $graph);

            $i = 0;
            while ($i < $len) {
                $this->toRdf($element->{'@list'}[$i], $result, $first_bn, new IRI(RdfConstants::RDF_FIRST), $graph);

                $i++;
                $rest_bn = ($i < $len)
                    ? new IRI($this->getBlankNodeId())
                    : new IRI(RdfConstants::RDF_NIL);

                $result[] = new Quad($first_bn, new IRI(RdfConstants::RDF_REST), $rest_bn, $graph);
                $first_bn = $rest_bn;
            }

            return;
        }

        $prevsubj = $activesubj;
        if (property_exists($element, '@id')) {
            $activesubj = $element->{'@id'};

            if (0 === strncmp($activesubj, '_:', 2)) {
                $activesubj = $this->getBlankNodeId($activesubj);
            }

            unset($element->{'@id'});
        } else {
            $activesubj = $this->getBlankNodeId();
        }

        $activesubj = new IRI($activesubj);

        if ($prevsubj) {
            $result[] = new Quad($prevsubj, $activeprty, $activesubj, $graph);
        }

        $properties = get_object_vars($element);
        ksort($properties);

        foreach ($properties as $property => $value) {
            if ('@type' === $property) {
                foreach ($value as $val) {
                    $result[] = new Quad($activesubj, new IRI(RdfConstants::RDF_TYPE), new IRI($val), $graph);
                }
                continue;
            } elseif ('@graph' === $property) {
                $this->toRdf($value, $result, null, null, $activesubj);
                continue;
            } elseif (in_array($property, self::$keywords)) {
                continue;
            }

            $activeprty = new IRI($property);

            $this->toRdf($value, $result, $activesubj, $activeprty, $graph);
        }
    }

    /**
     * Converts an array of RDF quads to a JSON-LD document
     *
     * The resulting JSON-LD document will be in expanded form.
     *
     * @param Quad[] $quads The quads to convert
     *
     * @return array The JSON-LD document.
     *
     * @throws InvalidQuadException If the quad is invalid.
     */
    public function fromRdf(array $quads)
    {
        $graphs = array();
        $graphs[self::DEFAULT_GRAPH] = new Object();
        $graphs[self::DEFAULT_GRAPH]->nodeMap = array();
        $graphs[self::DEFAULT_GRAPH]->listMap = array();

        foreach ($quads as $quad) {
            $graphName = self::DEFAULT_GRAPH;

            if ($quad->getGraph()) {
                $graphName = (string) $quad->getGraph();

                // Add a reference to this graph to the default graph if it
                // doesn't exist yet
                if (false === isset($graphs[self::DEFAULT_GRAPH]->nodeMap[$graphName])) {
                    $graphs[self::DEFAULT_GRAPH]->nodeMap[$graphName] =
                        self::objectToJsonLd($quad->getGraph());
                }
            }

            if (false === isset($graphs[$graphName])) {
                $graphs[$graphName] = new Object();
                $graphs[$graphName]->nodeMap = array();
                $graphs[$graphName]->listMap = array();
            }
            $graph = $graphs[$graphName];

            // Subjects and properties are always IRIs (blank nodes are IRIs
            // as well): convert them to a string representation
            $subject = (string) $quad->getSubject();
            $property = (string) $quad->getProperty();
            $object = $quad->getObject();

            // All list nodes are stored in listMap
            if ($property === RdfConstants::RDF_FIRST) {
                if (false === isset($graph->listMap[$subject])) {
                    $graph->listMap[$subject] = new Object();
                }

                $graph->listMap[$subject]->first =
                    self::objectToJsonLd($object, $this->useNativeTypes);

                continue;
            }

            if ($property === RdfConstants::RDF_REST) {
                if (false === ($object instanceof IRI)) {
                    throw new InvalidQuadException(
                        'The value of rdf:rest must be an IRI or blank node.',
                        $quad
                    );
                }

                if (false === isset($graph->listMap[$subject])) {
                    $graph->listMap[$subject] = new Object();
                }

                $graph->listMap[$subject]->rest = (string) $object;

                continue;
            }


            // All other nodes (not list nodes) are stored in nodeMap
            if (false === isset($graph->nodeMap[$subject])) {
                $graph->nodeMap[$subject] =
                    self::objectToJsonLd($quad->getSubject());
            }
            $node = $graph->nodeMap[$subject];


            if (($property === RdfConstants::RDF_TYPE) && (false === $this->useRdfType)) {
                if (false === ($object instanceof IRI)) {
                    throw new InvalidQuadException('The value of rdf:type must be an IRI.', $quad);
                }

                self::mergeIntoProperty($node, '@type', (string) $object, true);
            } else {
                $value = self::objectToJsonLd($object, $this->useNativeTypes);
                self::mergeIntoProperty($node, $property, $value, true);

                // If the object is an IRI or blank node it might be the
                // beginning of a list. Add it to the list map storing a
                // reference to the value in the nodeMap in the entry's
                // "head" property so that we can easily replace it with an
                //  @list object if it turns out to be really a list
                if (property_exists($value, '@id')) {
                    $id = $value->{'@id'};
                    if (false === isset($graph->listMap[$id])) {
                        $graph->listMap[$id] = new Object();
                    }

                    $graph->listMap[$id]->head = $value;
                }
            }
        }

        // Reconstruct @list arrays from linked list structures for each graph
        foreach ($graphs as $graphName => $graph) {
            foreach ($graph->listMap as $subject => $entry) {
                // If this node is a valid list head...
                if (property_exists($entry, 'head') && property_exists($entry, 'first')) {
                    $id = $subject;
                    $value = $entry->head;

                    // ... reconstruct the list
                    $list = array();

                    do {
                        if (false === isset($graph->listMap[$id])) {
                            throw new ProcessException(
                                sprintf('Invalid RDF list reference. "%s" doesn\'t exist.', $id)
                            );
                        }

                        $entry = $graph->listMap[$id];

                        if (false === property_exists($entry, 'first')) {
                            throw new ProcessException(
                                sprintf('Invalid RDF list entry: rdf:first of "%s" missing.', $id)
                            );
                        }
                        if (false === property_exists($entry, 'rest')) {
                            throw new ProcessException(
                                sprintf('Invalid RDF list entry: rdf:rest of "%s" missing.', $id)
                            );
                        }

                        $list[] = $entry->first;

                        $id = $entry->rest;
                    } while (RdfConstants::RDF_NIL !== $id);

                    // and replace the object in the nodeMap with the list
                    unset($value->{'@id'});
                    $value->{'@list'} = $list;
                }
            }
        }

        // Generate the resulting document starting with the default graph
        $document = array();

        $nodes = $graphs[self::DEFAULT_GRAPH]->nodeMap;
        ksort($nodes);

        foreach ($nodes as $id => $node) {
            $document[] = $node;

            // is it a named graph?
            if (isset($graphs[$id])) {
                $node->{'@graph'} = array();

                $graphNodes = $graphs[$id]->nodeMap;
                ksort($nodes);

                foreach ($graphNodes as $gnId => $graphNode) {
                    $node->{'@graph'}[] = $graphNode;
                }
            }
        }

        return $document;
    }

    /**
     * Frames a JSON-LD document according a supplied frame
     *
     * @param object $element A JSON-LD element to be framed.
     * @param mixed  $frame   The frame.
     *
     * @return array $result The framed element in expanded form.
     *
     * @throws ParseException   If the JSON-LD document or context couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document or context contains syntax errors.
     * @throws ProcessException If framing failed.
     */
    public function frame($element, $frame)
    {
        if ((false === is_array($frame)) || (1 !== count($frame)) || (false === is_object($frame[0]))) {
            throw new SyntaxException('The frame is invalid. It must be a single object.', $frame);
        }

        $frame = $frame[0];

        $options = new Object();
        $options->{'@embed'} = true;
        $options->{'@embedChildren'} = true;   // TODO Change this as soon as the tests haven been updated

        foreach (self::$framingKeywords as $keyword) {
            if (property_exists($frame, $keyword)) {
                $options->{$keyword} = $frame->{$keyword};
                unset($frame->{$keyword});
            } elseif (false === property_exists($options, $keyword)) {
                $options->{$keyword} = false;
            }
        }

        $procOptions = new Object();
        $procOptions->base = (string) $this->baseIri;
        $procOptions->compactArrays = $this->compactArrays;
        $procOptions->optimize = $this->optimize;
        $procOptions->useNativeTypes = $this->useNativeTypes;
        $procOptions->useRdfType = $this->useRdfType;

        $processor = new Processor($procOptions);

        $graph = self::UNION_GRAPH;
        if (property_exists($frame, '@graph')) {
            $graph = self::DEFAULT_GRAPH;
        }

        $nodeMap = new Object();
        $nodeMap->{$graph} = new Object();
        $processor->generateNodeMap($nodeMap, $element, $graph);

        unset($processor);

        $result = array();

        foreach ($nodeMap->{$graph} as $node) {
            $this->nodeMatchesFrame($node, $frame, $options, $nodeMap, $graph, $result);
        }

        return $result;
    }

    /**
     * Checks whether a node matches a frame or not.
     *
     * @param object $node    The node.
     * @param object $frame   The frame.
     * @param object $options The current framing options.
     * @param object $nodeMap The node map.
     * @param string $graph   The currently used graph.
     * @param array  $parent  The parent to which matching results should be added.
     * @param array  $path    The path of already processed nodes.
     *
     * @return bool Returns true if the node matches the frame, otherwise false.
     */
    private function nodeMatchesFrame($node, $frame, $options, $nodeMap, $graph, &$parent, $path = array())
    {
        // TODO How should lists be handled? Is the @list required in the frame (current behavior) or not?
        // https://github.com/json-ld/json-ld.org/issues/110
        // TODO Add support for '@omitDefault'?
        $filter = null;
        if (null !== $frame) {
            $filter = get_object_vars($frame);
        }

        $result = new Object();

        // Make sure that @id is always in the result if the node matches the filter
        if (property_exists($node, '@id')) {
            $result->{'@id'} = $node->{'@id'};

            if ((null === $filter) && in_array($node->{'@id'}, $path)) {
                $parent[] = $result;

                return true;
            }

            $path[] = $node->{'@id'};
        }

        // If no filter is specified, simply return the passed node - {} is a wildcard
        if ((null === $filter) || (0 === count($filter))) {
            // TODO What effect should @explicit have with a wildcard match?
            if (is_object($node)) {
                if ((true === $options->{'@embed'}) || (false === property_exists($node, '@id'))) {
                    $this->addMissingNodeProperties($node, $options, $nodeMap, $graph, $result, $path);
                }

                $parent[] = $result;
            } else {
                $parent[] = $node;
            }

            return true;
        }

        foreach ($filter as $property => $validValues) {
            if (is_array($validValues) && (0 === count($validValues))) {
                if (property_exists($node, $property) ||
                    (('@graph' === $property) && isset($result->{'@id'}) &&
                     property_exists($nodeMap, $result->{'@id'}))) {
                    return false;  // [] says that the property must not exist but it does
                }

                continue;
            }

            if (false === property_exists($node, $property)) {
                // The property does not exist, check if it's @graph and the referenced graph exists
                if ('@graph' === $property) {
                    if (isset($result->{'@id'}) && property_exists($nodeMap, $result->{'@id'})) {
                        $result->{'@graph'} = array();
                        $match = false;

                        foreach ($nodeMap->{$result->{'@id'}} as $item) {
                            foreach ($validValues as $validValue) {
                                $match |= $this->nodeMatchesFrame(
                                    $item,
                                    $validValue,
                                    $options,
                                    $nodeMap,
                                    $result->{'@id'},
                                    $result->{'@graph'}
                                );
                            }
                        }

                        if (false === $match) {
                            return false;
                        } else {
                            continue;  // with next property
                        }
                    } else {
                        // the referenced graph doesn't exist
                        return false;
                    }
                }

                // otherwise, look if we have a default value for it
                if (false === is_array($validValues)) {
                    $validValues = array($validValues);
                }

                $defaultFound = false;
                foreach ($validValues as $validValue) {
                    if (is_object($validValue) && property_exists($validValue, '@default')) {
                        if (null === $validValue->{'@default'}) {
                            $result->{$property} = new Object();
                            $result->{$property}->{'@null'} = true;
                        } else {
                            $result->{$property} = $validValue->{'@default'};
                        }
                        $defaultFound = true;
                        break;
                    }
                }

                if (true === $defaultFound) {
                    continue;
                }

                return false;  // required property does not exist and no default value was found
            }

            // Check whether the values of the property match the filter
            $match = false;
            $result->{$property} = array();

            if (false === is_array($validValues)) {
                if ($node->{$property} === $validValues) {
                    $result->{$property} = $node->{$property};
                    continue;
                } else {
                    return false;
                }
            }

            foreach ($validValues as $validValue) {
                if (is_object($validValue)) {
                    // Extract framing options from subframe ($validValue is a subframe)
                    $newOptions = clone $options;
                    unset($newOptions->{'@default'});

                    foreach (self::$framingKeywords as $keyword) {
                        if (property_exists($validValue, $keyword)) {
                            $newOptions->{$keyword} = $validValue->{$keyword};
                            unset($validValue->{$keyword});
                        }
                    }

                    $nodeValues = $node->{$property};
                    if (false === is_array($nodeValues)) {
                        $nodeValues = array($nodeValues);
                    }

                    foreach ($nodeValues as $value) {
                        if (is_object($value) && property_exists($value, '@id')) {
                            $match |= $this->nodeMatchesFrame(
                                $nodeMap->{$graph}->{$value->{'@id'}},
                                $validValue,
                                $newOptions,
                                $nodeMap,
                                $graph,
                                $result->{$property},
                                $path
                            );
                        } else {
                            $match |= $this->nodeMatchesFrame(
                                $value,
                                $validValue,
                                $newOptions,
                                $nodeMap,
                                $graph,
                                $result->{$property},
                                $path
                            );
                        }
                    }
                } elseif (is_array($validValue)) {
                    throw new SyntaxException(
                        "Invalid frame detected. Property \"$property\" must not be an array of arrays.",
                        $frame
                    );
                } else {
                    // This will just catch non-expanded IRIs for @id and @type
                    $nodeValues = $node->{$property};
                    if (false === is_array($nodeValues)) {
                        $nodeValues = array($nodeValues);
                    }

                    if (in_array($validValue, $nodeValues)) {
                        $match = true;
                        $result->{$property} = $node->{$property};
                    }
                }
            }

            if (false === $match) {
                return false;
            }
        }

        // Discard subtree if this object should not be embedded
        if ((false === $options->{'@embed'}) && property_exists($node, '@id')) {
            $result = new Object();
            $result->{'@id'} = $node->{'@id'};
            $parent[] = $result;

            return true;
        }

        // all properties matched the filter, add the properties of the
        // node which haven't been added yet
        if (false === $options->{'@explicit'}) {
            $this->addMissingNodeProperties($node, $options, $nodeMap, $graph, $result, $path);
        }

        $parent[] = $result;

        return true;
    }

    /**
     * Adds all properties from node to result if they haven't been added yet
     *
     * @param object $node    The node whose properties should processed.
     * @param object $options The current framing options.
     * @param object $nodeMap The node map.
     * @param string $graph   The currently used graph.
     * @param array  $result  The object to which the properties should be added.
     * @param array  $path    The path of already processed nodes.
     */
    private function addMissingNodeProperties($node, $options, $nodeMap, $graph, &$result, $path)
    {
        foreach ($node as $property => $value) {
            if (property_exists($result, $property)) {
                continue; // property has already been added
            }

            if (true === $options->{'@embedChildren'}) {
                if (false === is_array($value)) {
                    $result->{$property} = $value;
                    continue;
                }

                $result->{$property} = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if (property_exists($item, '@id')) {
                            $item = $nodeMap->{$graph}->{$item->{'@id'}};
                        }

                        $this->nodeMatchesFrame($item, null, $options, $nodeMap, $graph, $result->{$property}, $path);
                    } else {
                        $result->{$property}[] = $item;
                    }
                }

            } else {
                // TODO Perform deep object copy??
                $result->{$property} = $value;
            }
        }
    }

    /**
     * Adds a property to an object if it doesn't exist yet
     *
     * If the property already exists, an exception is thrown as otherwise
     * the existing value would be lost.
     *
     * @param object $object   The object.
     * @param string $property The name of the property.
     * @param mixed  $value    The value of the property.
     *
     * @throws SyntaxException If the property exists already JSON-LD.
     */
    private static function setProperty(&$object, $property, $value)
    {
        if (property_exists($object, $property)) {
            throw new SyntaxException("Object already contains a property \"$property\".", $object);
        }

        $object->{$property} = $value;
    }

    /**
     * Merges a value into a property of an object
     *
     * @param object $object      The object.
     * @param string $property    The name of the property to which the value should be merged into.
     * @param mixed  $value       The value to merge into the property.
     * @param bool   $alwaysArray If set to true, the resulting property will always be an array.
     * @param bool   $unique      If set to true, the value is only added if it doesn't exist yet.
     */
    private static function mergeIntoProperty(&$object, $property, $value, $alwaysArray = false, $unique = false)
    {
        // No need to add a null value
        if (null === $value) {
            return;
        }

        if (property_exists($object, $property)) {
            if (false === is_array($object->{$property})) {
                $object->{$property} = array($object->{$property});
            }

            if ($unique) {
                foreach ($object->{$property} as $item) {
                    if (self::subtreeEquals($item, $value)) {
                        return;
                    }
                }
            }

            if (false === is_array($value)) {
                $object->{$property}[] = $value;
            } else {
                $object->{$property} = array_merge($object->{$property}, $value);
            }
        } else {
            if ($alwaysArray && (false === is_array($value))) {
                $object->{$property} = array($value);
            } else {
                $object->{$property} = $value;
            }
        }
    }

    /**
     * Compares two values by their length and then lexicographically
     *
     * If two strings have different lengths, the shorter one will be
     * considered less than the other. If they have the same length, they
     * are compared lexicographically.
     *
     * @param mixed $a Value A.
     * @param mixed $b Value B.
     *
     * @return int If value A is shorter than value B, -1 will be returned; if it's
     *             longer 1 will be returned. If both values have the same length
     *             and value A is considered lexicographically less, -1 will be
     *             returned, if they are equal 0 will be returned, otherwise 1
     *             will be returned.
     */
    private static function sortTerms($a, $b)
    {
        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA < $lenB) {
            return -1;
        } elseif ($lenA === $lenB) {
            return strcmp($a, $b);
        } else {
            return 1;
        }
    }

    /**
     * Converts an object to a JSON-LD representation
     *
     * Only {@link IRI IRIs}, {@link LanguageTaggedString language-tagged strings},
     * and {@link TypedValue typed values} are converted by this method. All
     * other objects are returned as-is.
     *
     * @param  $object The object to convert.
     * @param boolean $useNativeTypes If set to true, native types are used
     *                                for xsd:integer, xsd:double, and
     *                                xsd:boolean, otherwise typed strings
     *                                will be used instead.
     *
     * @return mixed The JSON-LD representation of the object.
     */
    private static function objectToJsonLd($object, $useNativeTypes = true)
    {
        if ($object instanceof IRI) {
            $iri = (string) $object;
            $result = new Object();

            // rdf:nil represents the end of a list and is at the same
            // time used to represent empty lists
            if (RdfConstants::RDF_NIL === $iri) {
                $result->{'@list'} = array();

                return $result;
            }

            $result->{'@id'} = $iri;

            return $result;
        } elseif ($object instanceof Value) {
            return $object->toJsonLd($useNativeTypes);
        }

        return $object;
    }
}
