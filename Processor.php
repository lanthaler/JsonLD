<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as JsonObject;
use ML\JsonLD\Exception\JsonLdException;
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

    /**
     * @var array A list of all defined keywords
     */
    private static $keywords = array('@context', '@id', '@value', '@language', '@type',
                                     '@container', '@list', '@set', '@graph', '@reverse',
                                     '@base', '@vocab', '@index', '@null');
                                     // TODO Introduce @null supported just for framing

    /**
     * @var array Framing options keywords
     */
    private static $framingKeywords = array('@explicit', '@default', '@embed',
                                            //'@omitDefault',     // TODO Is this really needed?
                                            '@embedChildren');  // TODO How should this be called?
                                            // TODO Add @preserve, @null?? Update spec keyword list

    /**
     * @var IRI The base IRI
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
     * Use rdf:type instead of \@type when converting from RDF
     *
     * If set to true, the JSON-LD processor will use the expanded rdf:type
     * IRI as the property instead of \@type when converting from RDF.
     *
     * @var bool
     */
    private $useRdfType;

    /**
     * Produce generalized RDF
     *
     * Unless set to true, triples/quads with a blank node predicate are
     * dropped when converting to RDF.
     *
     * @var bool
     */
    private $generalizedRdf;

    /**
     * @var array Blank node map
     */
    private $blankNodeMap = array();

    /**
     * @var integer Blank node counter
     */
    private $blankNodeCounter = 0;

    /**
     * @var DocumentFactoryInterface The factory to create new documents
     */
    private $documentFactory = null;

    /**
     * @var DocumentLoaderInterface The document loader
     */
    private $documentLoader = null;

    /**
     * Constructor
     *
     * The options parameter must be passed and all off the following properties
     * have to be set:
     *
     * <dl>
     *   <dl>base</dl>
     *   <dt>The base IRI.</dt>
     *
     *   <dl>compactArrays</dl>
     *   <dt>If set to true, arrays holding just one element are compacted
     *     to scalars, otherwise the arrays are kept as arrays.</dt>
     *
     *   <dl>optimize</dl>
     *   <dt>If set to true, the processor is free to optimize the result to
     *     produce an even compacter representation than the algorithm
     *     described by the official JSON-LD specification.</dt>
     *
     *   <dl>useNativeTypes</dl>
     *   <dt>If set to true, the processor will try to convert datatyped
     *     literals to native types instead of using the expanded object form
     *     when converting from RDF. <em>xsd:boolean</em> values will be
     *     converted to booleans whereas <em>xsd:integer</em> and
     *     <em>xsd:double</em> values will be converted to numbers.</dt>
     *
     *   <dl>useRdfType</dl>
     *   <dt>If set to true, the JSON-LD processor will use the expanded
     *     <em>rdf:type</em> IRI as the property instead of <em>@type</em>
     *     when converting from RDF.</dt>
     * </dl>
     *
     * @param JsonObject $options Options to configure the various algorithms.
     */
    public function __construct($options)
    {
        $this->baseIri = new IRI($options->base);
        $this->compactArrays = (bool) $options->compactArrays;
        $this->optimize = (bool) $options->optimize;
        $this->useNativeTypes = (bool) $options->useNativeTypes;
        $this->useRdfType = (bool) $options->useRdfType;
        $this->generalizedRdf = (bool) $options->produceGeneralizedRdf;
        $this->documentFactory = $options->documentFactory;
        $this->documentLoader = $options->documentLoader;
    }

    /**
     * Parses a JSON-LD document to a PHP value
     *
     * @param string $document A JSON-LD document.
     *
     * @return mixed A PHP value.
     *
     * @throws JsonLdException If the JSON-LD document is not valid.
     */
    public static function parse($document)
    {
        if (function_exists('mb_detect_encoding') &&
            (false === mb_detect_encoding($document, 'UTF-8', true))) {
            throw new JsonLdException(
                JsonLdException::LOADING_DOCUMENT_FAILED,
                'The JSON-LD document does not appear to be valid UTF-8.'
            );
        }

        $data = json_decode($document, false, 512);

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                break;  // no error
            case JSON_ERROR_DEPTH:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'The maximum stack depth has been exceeded.'
                );
            case JSON_ERROR_STATE_MISMATCH:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'Invalid or malformed JSON.'
                );
            case JSON_ERROR_CTRL_CHAR:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'Control character error (possibly incorrectly encoded).'
                );
            case JSON_ERROR_SYNTAX:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'Syntax error, malformed JSON.'
                );
            case JSON_ERROR_UTF8:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'Malformed UTF-8 characters (possibly incorrectly encoded).'
                );
            default:
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    'Unknown error while parsing JSON.'
                );
        }

        return (empty($data)) ? null : $data;
    }

    /**
     * Parses a JSON-LD document and returns it as a Document
     *
     * @param array|JsonObject $input The JSON-LD document to process.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws JsonLdException If the JSON-LD input document is invalid.
     */
    public function getDocument($input)
    {
        $nodeMap = new JsonObject();
        $nodeMap->{'-' . JsonLD::DEFAULT_GRAPH} = new JsonObject();
        $this->generateNodeMap($nodeMap, $input);

        // We need to keep track of blank nodes as they are renamed when
        // inserted into the Document
        $nodes = array();

        if (null === $this->documentFactory) {
            $this->documentFactory = new DefaultDocumentFactory();
        }

        $document = $this->documentFactory->createDocument($this->baseIri);

        foreach ($nodeMap as $graphName => &$nodes) {
            $graphName = substr($graphName, 1);
            if (JsonLD::DEFAULT_GRAPH === $graphName) {
                $graph = $document->getGraph();
            } else {
                $graph = $document->createGraph($graphName);
            }

            foreach ($nodes as $id => &$item) {
                $node = $graph->createNode($item->{'@id'}, true);
                unset($item->{'@id'});

                // Process node type as it needs to be handled differently than
                // other properties
                // TODO Could this be avoided by enforcing rdf:type instead of @type?
                if (property_exists($item, '@type')) {
                    foreach ($item->{'@type'} as $type) {
                        $node->addType($graph->createNode($type), true);
                    }
                    unset($item->{'@type'});
                }

                foreach ($item as $property => $values) {
                    foreach ($values as $value) {
                        if (property_exists($value, '@value')) {
                            $node->addPropertyValue($property, Value::fromJsonLd($value));
                        } elseif (property_exists($value, '@id')) {
                            $node->addPropertyValue(
                                $property,
                                $graph->createNode($value->{'@id'}, true)
                            );
                        } else {
                            // TODO Handle lists
                            throw new \Exception('Lists are not supported by getDocument() yet');
                        }
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
     * @param mixed       $element    A JSON-LD element to be expanded.
     * @param array       $activectx  The active context.
     * @param null|string $activeprty The active property.
     * @param boolean     $frame      True if a frame is being expanded, otherwise false.
     *
     * @return mixed The expanded document.
     *
     * @throws JsonLdException
     */
    public function expand(&$element, $activectx = array(), $activeprty = null, $frame = false)
    {
        if (is_scalar($element)) {

            if ((null === $activeprty) || ('@graph' === $activeprty)) {
                $element = null;
            } else {
                $element = $this->expandValue($element, $activectx, $activeprty);
            }

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
                        throw new JsonLdException(
                            JsonLdException::LIST_OF_LISTS,
                            "List of lists detected in property \"$activeprty\".",
                            $element
                        );
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

        $element = new JsonObject();

        foreach ($properties as $property => $value) {
            $expProperty = $this->expandIri($property, $activectx, false, true);

            // Make sure to keep framing keywords if a frame is being expanded
            if ($frame && in_array($expProperty, self::$framingKeywords)) {
                // and that the default value is expanded
                if ('@default' === $expProperty) {
                    $this->expand($value, $activectx, $activeprty, $frame);
                }

                self::setProperty($element, $expProperty, $value, JsonLdException::COLLIDING_KEYWORDS);
                continue;
            }

            if (in_array($expProperty, self::$keywords)) {
                if ('@reverse' === $activeprty) {
                    throw new JsonLdException(
                        JsonLdException::INVALID_REVERSE_PROPERTY_MAP,
                        'No keywords or keyword aliases are allowed in @reverse-maps, found ' . $expProperty
                    );
                }
                $this->expandKeywordValue($element, $activeprty, $expProperty, $value, $activectx, $frame);

                continue;
            } elseif (false === strpos($expProperty, ':')) {
                // the expanded property is neither a keyword nor an IRI
                continue;
            }

            $propertyContainer = $this->getPropertyDefinition($activectx, $property, '@container');

            if (is_object($value) && in_array($propertyContainer, array('@language', '@index'))) {
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
                                throw new JsonLdException(
                                    JsonLdException::INVALID_LANGUAGE_MAP_VALUE,
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
                    // @container: @index
                    foreach ($value as $key => $val) {
                        if (false === is_array($val)) {
                            $val = array($val);
                        }

                        $this->expand($val, $activectx, $property, $frame);

                        foreach ($val as $item) {
                            if (false === property_exists($item, '@index')) {
                                $item->{'@index'} = $key;
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

                $obj = new JsonObject();
                $obj->{'@list'} = $value;
                $value = $obj;
            }

            $target = $element;
            if ($this->getPropertyDefinition($activectx, $property, '@reverse')) {
                if (false === property_exists($target, '@reverse')) {
                    $target->{'@reverse'} = new JsonObject();
                }
                $target = $target->{'@reverse'};

                if (false === is_array($value)) {
                    $value = array($value);
                }

                foreach ($value as $val) {
                    if (property_exists($val, '@value') || property_exists($val, '@list')) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_REVERSE_PROPERTY_VALUE,
                            'Detected invalid value in @reverse-map (only nodes are allowed',
                            $val
                        );
                    }
                }
            }

            self::mergeIntoProperty($target, $expProperty, $value, true);
        }

        // All properties have been processed. Make sure the result is valid
        // and optimize it where possible
        $numProps = count(get_object_vars($element));

        // Remove free-floating nodes
        if ((false === $frame) && ((null === $activeprty) || ('@graph' === $activeprty)) &&
            (((0 === $numProps) || property_exists($element, '@value') || property_exists($element, '@list') ||
             ((1 === $numProps) && property_exists($element, '@id'))))) {

            $element = null;
            return;
        }

        // Indexes are allowed everywhere
        if (property_exists($element, '@index')) {
            $numProps--;
        }

        if (property_exists($element, '@value')) {
            $numProps--;  // @value
            if (property_exists($element, '@language')) {
                if (false === $frame) {
                    if (false === is_string($element->{'@language'})) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_LANGUAGE_TAGGED_STRING,
                            'Invalid value for @language detected (must be a string).',
                            $element
                        );
                    }

                    if (false === is_string($element->{'@value'})) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_LANGUAGE_TAGGED_VALUE,
                            'Only strings can be language tagged.',
                            $element
                        );
                    }
                }

                $numProps--;
            } elseif (property_exists($element, '@type')) {
                if ((false === $frame) && ((false === is_string($element->{'@type'})) ||
                    (false === strpos($element->{'@type'}, ':')) ||
                    ('_:' === substr($element->{'@type'}, 0, 2)))) {
                    throw new JsonLdException(
                        JsonLdException::INVALID_TYPED_VALUE,
                        'Invalid value for @type detected (must be an IRI).',
                        $element
                    );
                }

                $numProps--;
            }

            if ($numProps > 0) {
                throw new JsonLdException(
                    JsonLdException::INVALID_VALUE_OBJECT,
                    'Detected an invalid @value object.',
                    $element
                );
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
            throw new JsonLdException(
                JsonLdException::INVALID_SET_OR_LIST_OBJECT,
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
     * @param JsonObject $element    The object this property-value pair is part of.
     * @param string     $activeprty The active property.
     * @param string     $keyword    The keyword whose value is being expanded.
     * @param mixed      $value      The value to expand.
     * @param array      $activectx  The active context.
     * @param boolean    $frame      True if a frame is being expanded, otherwise false.
     *
     * @throws JsonLdException
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
                throw new JsonLdException(
                    JsonLdException::INVALID_ID_VALUE,
                    'Invalid value for @id detected (must be a string).',
                    $element
                );
            }

            $value = $this->expandIri($value, $activectx, true);
            self::setProperty($element, $keyword, $value, JsonLdException::COLLIDING_KEYWORDS);

            return;
        }

        if ('@type' === $keyword) {
            if (is_string($value)) {
                $value = $this->expandIri($value, $activectx, true, true);
                self::setProperty($element, $keyword, $value, JsonLdException::COLLIDING_KEYWORDS);

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
                        throw new JsonLdException(
                            JsonLdException::INVALID_TYPE_VALUE,
                            "Invalid value for $keyword detected.",
                            $value
                        );
                    }

                    self::mergeIntoProperty($element, $keyword, $item);
                }
            }

            // Don't keep empty arrays
            if (count($result) >= 1) {
                self::mergeIntoProperty($element, $keyword, $result, true);
            }
        }

        if (('@value' === $keyword)) {
            if (false === $frame) {
                if ((null !== $value) && (false === is_scalar($value))) {
                    // we need to preserve @value: null to distinguish values form nodes
                    throw new JsonLdException(
                        JsonLdException::INVALID_VALUE_OBJECT_VALUE,
                        "Invalid value for @value detected (must be a scalar).",
                        $value
                    );
                }
            } elseif (false === is_array($value)) {
                $value = array($value);
            }

            self::setProperty($element, $keyword, $value, JsonLdException::COLLIDING_KEYWORDS);

            return;
        }

        if (('@language' === $keyword) || ('@index' === $keyword)) {
            if (false === $frame) {
                if (false === is_string($value)) {
                    throw ('@language' === $keyword)
                        ? new JsonLdException(
                            JsonLdException::INVALID_LANGUAGE_TAGGED_STRING,
                            '@language must be a string',
                            $value
                        )
                        : new JsonLdException(
                            JsonLdException::INVALID_INDEX_VALUE,
                            '@index must be a string',
                            $value
                        );
                }
            } elseif (false === is_array($value)) {
                $value = array($value);
            }

            self::setProperty($element, $keyword, $value, JsonLdException::COLLIDING_KEYWORDS);

            return;
        }

        // TODO Optimize the following code, there's a lot of repetition, only the $activeprty param is changing
        if ('@list' === $keyword) {
            if ((null === $activeprty) || ('@graph' === $activeprty)) {
                return;
            }

            $this->expand($value, $activectx, $activeprty, $frame);

            if (false === is_array($value)) {
                $value = array($value);
            }

            foreach ($value as $val) {
                if (is_object($val) && property_exists($val, '@list')) {
                    throw new JsonLdException(JsonLdException::LIST_OF_LISTS, 'List of lists detected.', $element);
                }
            }

            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }

        if ('@set' === $keyword) {
            $this->expand($value, $activectx, $activeprty, $frame);
            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }

        if ('@reverse' === $keyword) {
            if (false === is_object($value)) {
                throw new JsonLdException(
                    JsonLdException::INVALID_REVERSE_VALUE,
                    'Detected invalid value for @reverse (must be an object).',
                    $value
                );
            }

            $this->expand($value, $activectx, $keyword, $frame);

            // Do not create @reverse-containers inside @reverse containers
            if (property_exists($value, $keyword)) {
                foreach (get_object_vars($value->{$keyword}) as $prop => $val) {
                    self::mergeIntoProperty($element, $prop, $val, true);
                }

                unset($value->{$keyword});
            }

            $value = get_object_vars($value);

            if ((count($value) > 0) && (false === property_exists($element, $keyword))) {
                $element->{$keyword} = new JsonObject();
            }

            foreach ($value as $prop => $val) {
                foreach ($val as $v) {
                    if (property_exists($v, '@value') || property_exists($v, '@list')) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_REVERSE_PROPERTY_VALUE,
                            'Detected invalid value in @reverse-map (only nodes are allowed',
                            $v
                        );
                    }
                    self::mergeIntoProperty($element->{$keyword}, $prop, $v, true);
                }
            }

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
     * @return JsonObject The expanded value.
     */
    private function expandValue($value, $activectx, $activeprty)
    {
        $def = $this->getPropertyDefinition($activectx, $activeprty);

        $result = new JsonObject();

        if ('@id' === $def['@type']) {
            $result->{'@id'} = $this->expandIri($value, $activectx, true);
        } elseif ('@vocab' === $def['@type']) {
            $result->{'@id'} = $this->expandIri($value, $activectx, true, true);
        } else {
            $result->{'@value'} = $value;

            if (isset($def['@type'])) {
                $result->{'@type'} = $def['@type'];
            } elseif (isset($def['@language']) && is_string($result->{'@value'})) {
                $result->{'@language'} = $def['@language'];
            }
        }

        return $result;
    }

    /**
     * Expands a JSON-LD IRI value (term, compact IRI, IRI) to an absolute
     * IRI and relabels blank nodes
     *
     * @param mixed           $value         The value to be expanded to an absolute IRI.
     * @param array           $activectx     The active context.
     * @param bool            $relativeIri   Specifies whether $value should be treated as
     *                                       relative IRI against the base IRI or not.
     * @param bool            $vocabRelative Specifies whether $value is relative to @vocab
     *                                       if set or not.
     * @param null|JsonObject $localctx      If the IRI is being expanded as part of context
     *                                       processing, the current local context has to be
     *                                       passed as well.
     * @param array           $path          A path of already processed terms to detect
     *                                       circular dependencies
     *
     * @return string The expanded IRI.
     */
    private function expandIri(
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
                throw new JsonLdException(
                    JsonLdException::CYCLIC_IRI_MAPPING,
                    'Cycle in context definition detected: ' . join(' -> ', $path) . ' -> ' . $path[0],
                    $localctx
                );
            } else {
                $path[] = $value;

                if (count($path) >= self::CONTEXT_MAX_IRI_RECURSIONS) {
                    throw new JsonLdException(
                        JsonLdException::UNSPECIFIED,
                        'Too many recursions in term definition: ' . join(' -> ', $path) . ' -> ' . $path[0],
                        $localctx
                    );
                }
            }

            if (isset($localctx->{$value})) {
                $nested = null;

                if (is_string($localctx->{$value})) {
                    $nested = $localctx->{$value};
                } elseif (isset($localctx->{$value}->{'@id'})) {
                    $nested = $localctx->{$value}->{'@id'};
                }

                if ($nested && (end($path) !== $nested)) {
                    return $this->expandIri($nested, $activectx, false, true, $localctx, $path);
                }
            }
        }

        // Terms apply only for vocab-relative IRIs
        if ((true === $vocabRelative) && array_key_exists($value, $activectx)) {
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
                $prefix = $this->expandIri($prefix, $activectx, false, true, $localctx, $path);

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
            } elseif (($relativeIri) && (null !== $activectx['@base'])) {
                return (string) $activectx['@base']->resolve($value);
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
     * @param mixed       $element    A JSON-LD element to be compacted.
     * @param array       $activectx  The active context.
     * @param array       $inversectx The inverse context.
     * @param null|string $activeprty The active property.
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

        $inReverse = ('@reverse' === $activeprty);
        $element = new JsonObject();

        foreach ($properties as $property => $value) {
            if (in_array($property, self::$keywords)) {
                if ('@id' === $property) {
                    $value = $this->compactIri($value, $activectx, $inversectx);
                } elseif ('@type' === $property) {
                    if (is_string($value)) {
                        $value = $this->compactIri($value, $activectx, $inversectx, null, true);
                    } else {
                        foreach ($value as &$iri) {
                            $iri = $this->compactIri($iri, $activectx, $inversectx, null, true);
                        }

                        if ($this->compactArrays && (1 === count($value))) {
                            $value = $value[0];
                        }
                    }
                } elseif (('@graph' === $property) || ('@list' === $property)) {
                    $this->compact($value, $activectx, $inversectx, $property);

                    if (false === is_array($value)) {
                        $value = array($value);
                    }
                } elseif ('@reverse' === $property) {
                    $this->compact($value, $activectx, $inversectx, $property);

                    // Move reverse properties out of the map into element
                    foreach (get_object_vars($value) as $prop => $val) {
                        if ($this->getPropertyDefinition($activectx, $prop, '@reverse')) {
                            $alwaysArray = ('@set' === $this->getPropertyDefinition($activectx, $prop, '@container'));
                            self::mergeIntoProperty($element, $prop, $val, $alwaysArray);
                            unset($value->{$prop});
                        }
                    }

                    if (0 === count(get_object_vars($value))) {
                        continue;  // no properties left in the @reverse-map
                    }
                }

                // Get the keyword alias from the inverse context if available
                $activeprty = (isset($inversectx[$property]['term']))
                    ? $inversectx[$property]['term']
                    : $property;

                self::setProperty($element, $activeprty, $value, JsonLdException::COLLIDING_KEYWORDS);

                // ... continue with next property
                continue;
            }

            // handle @null-objects as used in framing
            if (is_object($value) && property_exists($value, '@null')) {
                $activeprty = $this->compactIri($property, $activectx, $inversectx, null, true, $inReverse);

                if (false === property_exists($element, $activeprty)) {
                    $element->{$activeprty} = null;
                }

                continue;
            }

            // Make sure that empty arrays are preserved
            if (0 === count($value)) {
                $activeprty = $this->compactIri($property, $activectx, $inversectx, null, true, $inReverse);

                self::mergeIntoProperty($element, $activeprty, $value);

                // ... continue with next property
                continue;
            }

            // Compact every item in value separately as they could map to different terms
            foreach ($value as $item) {
                $activeprty = $this->compactIri($property, $activectx, $inversectx, $item, true, $inReverse);
                $def = $this->getPropertyDefinition($activectx, $activeprty);

                if (in_array($def['@container'], array('@language', '@index'))) {
                    if (false === property_exists($element, $activeprty)) {
                        $element->{$activeprty} = new JsonObject();
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
                            // a term can just hold one list if it has a @list container
                            // (we don't support lists of lists)
                            self::setProperty(
                                $element,
                                $activeprty,
                                $item->{'@list'},
                                JsonLdException::COMPACTION_TO_LIST_OF_LISTS
                            );

                            continue;  // ... continue with next value
                        } else {
                            $result = new JsonObject();

                            $alias = $this->compactIri('@list', $activectx, $inversectx, null, true);
                            $result->{$alias} = $item->{'@list'};

                            if (isset($item->{'@index'})) {
                                $alias = $this->compactIri('@index', $activectx, $inversectx, null, true);
                                $result->{$alias} = $item->{'@index'};
                            }

                            $item = $result;
                        }
                    } else {
                        $this->compact($item, $activectx, $inversectx, $activeprty);
                    }
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
     *   @type      => type IRI or null
     *   @language  => language code or null
     *   @index     => index string or null
     *   @container => the container: @set, @list, @language, or @index
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
        if ('@index' === $definition['@container']) {
            unset($value->{'@index'});
        }

        $numProperties = count(get_object_vars($value));

        // @id object
        if (property_exists($value, '@id')) {
            if (1 === $numProperties) {
                if ('@id' === $definition['@type']) {
                    return $this->compactIri($value->{'@id'}, $activectx, $inversectx);
                }

                if ('@vocab' === $definition['@type']) {
                    return $this->compactIri($value->{'@id'}, $activectx, $inversectx, null, true);
                }
            }

            return $value;
        }

        // @value object
        $criterion = (isset($value->{'@type'})) ? '@type' : null;
        $criterion = (isset($value->{'@language'})) ? '@language' : $criterion;

        if (null !== $criterion) {
            if ((2 === $numProperties) && ($value->{$criterion} === $definition[$criterion])) {
                return $value->{'@value'};
            }

            return $value;
        }

        // the object has neither a @type nor a @language property
        // check the active property's definition
        if (is_string($value->{'@value'}) && (null !== $definition['@language'])) {
            // if the property is language tagged or there's a default language,
            // we can't compact the value if it is a string
            return $value;
        }

        // we can compact the value
        return (1 === $numProperties) ? $value->{'@value'} : $value;
    }

    /**
     * Compacts an absolute IRI (or aliases a keyword)
     *
     * If the IRI couldn't be compacted, the IRI is returned as is.
     *
     * @param mixed $iri           The IRI to be compacted.
     * @param array $activectx     The active context.
     * @param array $inversectx    The inverse context.
     * @param mixed $value         The value of the property to compact.
     * @param bool  $vocabRelative If `true` is passed, this method tries
     *                             to convert the IRI to an IRI relative to
     *                             `@vocab`; otherwise, that fall back
     *                             mechanism is disabled.
     * @param bool  $reverse       Is the IRI used within a @reverse container?
     *
     * @return string Returns the compacted IRI on success; otherwise the
     *                IRI is returned as is.
     */
    private function compactIri($iri, $activectx, $inversectx, $value = null, $vocabRelative = false, $reverse = false)
    {
        if ((true === $vocabRelative) && array_key_exists($iri, $inversectx)) {
            if (null !== $value) {
                $valueProfile = $this->getValueProfile($value, $inversectx);

                $container = ('@list' === $valueProfile['@container'])
                    ? array('@list', '@null')
                    : array($valueProfile['@container'], '@set', '@null');

                if (null === $valueProfile['typeLang']) {
                    $typeOrLang = array('@null');
                    $typeOrLangValue = array('@null');
                } else {
                    $typeOrLang = array($valueProfile['typeLang'], '@null');
                    $typeOrLangValue = array();

                    if (true === $reverse) {
                        $typeOrLangValue[] = '@reverse';
                    }

                    if (('@type' === $valueProfile['typeLang']) && ('@id' === $valueProfile['typeLangValue'])) {
                        array_push($typeOrLangValue, '@id', '@vocab', '@null');
                    } elseif (('@type' === $valueProfile['typeLang']) &&
                              ('@vocab' === $valueProfile['typeLangValue'])) {
                        array_push($typeOrLangValue, '@vocab', '@id', '@null');
                    } else {
                        $typeOrLangValue = array($valueProfile['typeLangValue'], '@null');
                    }
                }

                $result = $this->queryInverseContext($inversectx[$iri], $container, $typeOrLang, $typeOrLangValue);

                if (null !== $result) {
                    return $result;
                }
            } elseif (isset($inversectx[$iri]['term'])) {
                return $inversectx[$iri]['term'];
            }
        }

        // Compact using @vocab
        if ($vocabRelative && isset($activectx['@vocab']) && (0 === strpos($iri, $activectx['@vocab'])) &&
            (false !== ($vocabIri = substr($iri, strlen($activectx['@vocab'])))) &&
            (false === isset($activectx[$vocabIri]))) {
            return $vocabIri;
        }

        // Try to compact to a compact IRI
        foreach ($inversectx as $termIri => $def) {
            $termIriLen = strlen($termIri);

            if (isset($def['term']) && (0 === strncmp($iri, $termIri, $termIriLen))) {
                $compactIri = substr($iri, $termIriLen);

                if (false !== $compactIri && '' !== $compactIri) {
                    $compactIri = $def['term'] . ':' . $compactIri;

                    if (false === isset($activectx[$compactIri]) ||
                        ((false === $vocabRelative) && ($iri === $activectx[$compactIri]['@id']))) {
                        return $compactIri;
                    }
                }
            }
        }

        // Last resort, convert to a relative IRI
        if ((false === $vocabRelative) && (null !== $activectx['@base'])) {
            return (string) $activectx['@base']->baseFor($iri);
        }

        // IRI couldn't be compacted, return as is
        return $iri;
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
     * @param JsonObject $value      The value.
     * @param array      $inversectx The inverse context.
     *
     * @return array The value profile.
     */
    private function getValueProfile(JsonObject $value, $inversectx)
    {
        $valueProfile = array(
            '@container' => '@set',
            'typeLang' => '@type',
            'typeLangValue' => '@id'
        );

        if (property_exists($value, '@index')) {
            $valueProfile['@container'] = '@index';
        }

        if (property_exists($value, '@id')) {
            if (isset($inversectx[$value->{'@id'}]['term'])) {
                $valueProfile['typeLangValue'] = '@vocab';
            } else {
                $valueProfile['typeLangValue'] = '@id';
            }

            return $valueProfile;
        }

        if (property_exists($value, '@value')) {
            if (property_exists($value, '@type')) {
                $valueProfile['typeLang'] = '@type';
                $valueProfile['typeLangValue'] = $value->{'@type'};
            } elseif (property_exists($value, '@language')) {
                $valueProfile['typeLang'] = '@language';
                $valueProfile['typeLangValue'] = $value->{'@language'};

                if (false === property_exists($value, '@index')) {
                    $valueProfile['@container'] = '@language';
                }
            } else {
                $valueProfile['typeLang'] = '@language';
                $valueProfile['typeLangValue'] = '@null';
            }

            return $valueProfile;
        }

        if (property_exists($value, '@list')) {
            $len = count($value->{'@list'});

            if ($len > 0) {
                $valueProfile = $this->getValueProfile($value->{'@list'}[0], $inversectx);
            }

            if (false === property_exists($value, '@index')) {
                $valueProfile['@container'] = '@list';
            }


            for ($i = $len - 1; $i > 0; $i--) {
                $profile = $this->getValueProfile($value->{'@list'}[$i], $inversectx);

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
     * Queries the inverse context to find the term for a given query
     * path (= value profile)
     *
     * @param array    $inversectx The inverse context (or a subtree thereof)
     * @param string[] $containers
     * @param string[] $typeOrLangs
     * @param string[] $typeOrLangValues
     *
     * @return null|string The best matching term or null if none was found.
     */
    private function queryInverseContext($inversectx, $containers, $typeOrLangs, $typeOrLangValues)
    {
        foreach ($containers as $container) {
            foreach ($typeOrLangs as $typeOrLang) {
                foreach ($typeOrLangValues as $typeOrLangValue) {
                    if (isset($inversectx[$container][$typeOrLang][$typeOrLangValue])) {
                        return $inversectx[$container][$typeOrLang][$typeOrLangValue];
                    }
                }
            }
        }

        return null;
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
     * @param array       $activectx The active context.
     * @param string      $property  The property.
     * @param null|string $only      If set, only this element of the
     *                               definition will be returned.
     *
     * @return array|string|null Returns either the property's definition or
     *                           null if not found.
     */
    private function getPropertyDefinition($activectx, $property, $only = null)
    {
        $result = array(
            '@reverse' => false,
            '@type' => null,
            '@language' => (isset($activectx['@language']))
                ? $activectx['@language']
                : null,
            '@index' => null,
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
            $def = (isset($activectx[$property])) ? $activectx[$property] : null;

            if (null !== $def) {
                $result['@id'] = $def['@id'];
                $result['@reverse'] = $def['@reverse'];

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
     * @throws JsonLdException
     */
    public function processContext($loclctx, &$activectx, $remotectxs = array())
    {
        if (is_object($loclctx)) {
            $loclctx = clone $loclctx;
        }

        if (false === is_array($loclctx)) {
            $loclctx = array($loclctx);
        }

        foreach ($loclctx as $context) {
            if (null === $context) {
                $activectx = array('@base' => $this->baseIri);
            } elseif (is_object($context)) {
                // make sure we don't modify the passed context
                $context = clone $context;

                if (property_exists($context, '@base')) {
                    if (count($remotectxs) > 0) {
                        // do nothing, @base is ignored in a remote context
                    } elseif (null === $context->{'@base'}) {
                        $activectx['@base'] = null;
                    } elseif (false === is_string($context->{'@base'})) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_BASE_IRI,
                            'The value of @base must be an IRI or null.',
                            $context
                        );
                    } else {
                        $base = new IRI($context->{'@base'});
                        if (false === $base->isAbsolute()) {
                            if (null === $activectx['@base']) {
                                throw new JsonLdException(
                                    JsonLdException::INVALID_BASE_IRI,
                                    'The relative base IRI cannot be resolved to an absolute IRI.',
                                    $context
                                );
                            }

                            $activectx['@base'] = $activectx['@base']->resolve($base);
                        } else {
                            $activectx['@base'] = $base;
                        }
                    }

                    unset($context->{'@base'});
                }

                if (property_exists($context, '@vocab')) {
                    if (null === $context->{'@vocab'}) {
                        unset($activectx['@vocab']);
                    } elseif ((false === is_string($context->{'@vocab'})) ||
                              (false === strpos($context->{'@vocab'}, ':'))) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_VOCAB_MAPPING,
                            'The value of @vocab must be an absolute IRI or null.invalid vocab mapping, ',
                            $context
                        );
                    } else {
                        $activectx['@vocab'] = $context->{'@vocab'};
                    }

                    unset($context->{'@vocab'});
                }

                if (property_exists($context, '@language')) {
                    if ((null !== $context->{'@language'}) && (false === is_string($context->{'@language'}))) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_DEFAULT_LANGUAGE,
                            'The value of @language must be a string.',
                            $context
                        );
                    }

                    $activectx['@language'] = $context->{'@language'};
                    unset($context->{'@language'});
                }

                foreach ($context as $key => $value) {
                    unset($context->{$key});
                    unset($activectx[$key]);

                    if (in_array($key, self::$keywords)) {
                        throw new JsonLdException(JsonLdException::KEYWORD_REDEFINITION, null, $key);
                    }

                    if ((null === $value) || is_string($value)) {
                        $value = (object) array('@id' => $value);
                    } elseif (is_object($value)) {
                        $value = clone $value;    // make sure we don't modify context entries
                    } else {
                        throw new JsonLdException(JsonLdException::INVALID_TERM_DEFINITION);
                    }

                    if (property_exists($value, '@reverse')) {
                        if (property_exists($value, '@id')) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_REVERSE_PROPERTY,
                                "Invalid term definition using both @reverse and @id detected",
                                $value
                            );
                        }

                        if (property_exists($value, '@container') &&
                            ('@index' !== $value->{'@container'}) &&
                            ('@set' !== $value->{'@container'})) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_REVERSE_PROPERTY,
                                "Terms using the @reverse feature support only @set- and @index-containers.",
                                $value
                            );
                        }

                        $value->{'@id'} = $value->{'@reverse'};
                        $value->{'@reverse'} = true;
                    } else {
                        $value->{'@reverse'} = false;
                    }

                    if (property_exists($value, '@id')) {
                        if ((null !== $value->{'@id'}) && (false === is_string($value->{'@id'}))) {
                            throw new JsonLdException(JsonLdException::INVALID_IRI_MAPPING, null, $value->{'@id'});
                        }

                        $path = array();
                        if ($key !== $value->{'@id'}) {
                            $path[] = $key;
                        }

                        $expanded = $this->expandIri($value->{'@id'}, $activectx, false, true, $context, $path);

                        if ($value->{'@reverse'} && (false === strpos($expanded, ':'))) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_IRI_MAPPING,
                                "Reverse properties must expand to absolute IRIs, \"$key\" expands to \"$expanded\"."
                            );
                        } elseif ('@context' === $expanded) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_KEYWORD_ALIAS,
                                'Aliases for @context are not supported',
                                $value
                            );
                        }
                    } else {
                        $expanded = $this->expandIri($key, $activectx, false, true, $context);
                    }


                    if ((null === $expanded) || in_array($expanded, self::$keywords)) {
                        // if it's an aliased keyword or the IRI is null, we ignore all other properties
                        // TODO Should we throw an exception if there are other properties?
                        $activectx[$key] = array('@id' => $expanded, '@reverse' => false);

                        continue;
                    } elseif (false === strpos($expanded, ':')) {
                        throw new JsonLdException(
                            JsonLdException::INVALID_IRI_MAPPING,
                            "Failed to expand \"$key\" to an absolute IRI.",
                            $loclctx
                        );
                    }

                    $activectx[$key] = array('@id' => $expanded, '@reverse' => $value->{'@reverse'});

                    if (isset($value->{'@type'})) {
                        if (false === is_string($value->{'@type'})) {
                            throw new JsonLdException(JsonLdException::INVALID_TYPE_MAPPING);
                        }

                        $expanded = $this->expandIri($value->{'@type'}, $activectx, false, true, $context);

                        if (('@id' !== $expanded) && ('@vocab' !== $expanded) &&
                            ((false === strpos($expanded, ':') || (0 === strpos($expanded, '_:'))))) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_TYPE_MAPPING,
                                "Failed to expand $expanded to an absolute IRI.",
                                $loclctx
                            );
                        }

                        $activectx[$key]['@type'] = $expanded;
                    } elseif (property_exists($value, '@language')) {
                        if ((false === is_string($value->{'@language'})) && (null !== $value->{'@language'})) {
                            throw new JsonLdException(
                                JsonLdException::INVALID_LANGUAGE_MAPPING,
                                'The value of @language must be a string or null.',
                                $value
                            );
                        }

                        // Note the else. Language tagging applies just to term without type coercion
                        $activectx[$key]['@language'] = $value->{'@language'};
                    }

                    if (isset($value->{'@container'})) {
                        if (in_array($value->{'@container'}, array('@list', '@set', '@language', '@index'))) {
                            $activectx[$key]['@container'] = $value->{'@container'};
                        } else {
                            throw new JsonLdException(
                                JsonLdException::INVALID_CONTAINER_MAPPING,
                                'A container mapping of ' . $value->{'@container'} . ' is not supported.'
                            );
                        }
                    }
                }
            } elseif (is_string($context)) {
                $remoteContext = new IRI($context);
                if ($remoteContext->isAbsolute()) {
                    $remoteContext = (string) $remoteContext;
                } elseif (null === $activectx['@base']) {
                    throw new JsonLdException(
                        JsonLdException::INVALID_BASE_IRI,
                        'Can not resolve the relative URL of the remote context as no base has been set: ' . $remoteContext
                    );
                } else {
                    $remoteContext = (string) $activectx['@base']->resolve($context);
                }
                if (in_array($remoteContext, $remotectxs)) {
                    throw new JsonLdException(
                        JsonLdException::RECURSIVE_CONTEXT_INCLUSION,
                        'Recursive inclusion of remote context: ' . join(' -> ', $remotectxs) . ' -> ' . $remoteContext
                    );
                }
                $remotectxs[] = $remoteContext;

                try {
                    $remoteContext = $this->loadDocument($remoteContext);
                } catch (JsonLdException $e) {
                    throw new JsonLdException(
                        JsonLdException::LOADING_REMOTE_CONTEXT_FAILED,
                        "Loading $remoteContext failed",
                        null,
                        null,
                        $e
                    );
                }

                if (is_object($remoteContext) && property_exists($remoteContext, '@context')) {
                    // TODO Use the context's IRI as base IRI when processing remote contexts (ISSUE-24)
                    $this->processContext($remoteContext->{'@context'}, $activectx, $remotectxs);
                } else {
                    throw new JsonLdException(
                        JsonLdException::INVALID_REMOTE_CONTEXT,
                        'Remote context "' . $context . '" is invalid.',
                        $remoteContext
                    );
                }
            } else {
                throw new JsonLdException(JsonLdException::INVALID_LOCAL_CONTEXT);
            }
        }
    }

    /**
     * Load a JSON-LD document
     *
     * The document can be supplied directly as string, by passing a file
     * path, or by passing a URL.
     *
     * @param null|string|array|JsonObject $input The JSON-LD document or a path
     *                                            or URL pointing to one.
     *
     * @return mixed The loaded JSON-LD document
     *
     * @throws JsonLdException
     */
    private function loadDocument($input)
    {
        if (false === is_string($input)) {
            // Return as is - it has already been parsed
            return $input;
        }

        $document = $this->documentLoader->loadDocument($input);

        return $document->document;
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

        unset($activectx['@base']);
        unset($activectx['@vocab']);
        unset($activectx['@language']);
        unset($activectx['@propertyGenerators']);

        $activectx = array_merge($activectx, $propertyGenerators);
        unset($propertyGenerators);

        uksort($activectx, array($this, 'sortTerms'));

        // Put every IRI of each term into the inverse context
        foreach ($activectx as $term => $def) {
            if (null === $def['@id']) {
                // this is necessary since some terms can be decoupled from @vocab
                continue;
            }

            $container = (isset($def['@container'])) ? $def['@container'] : '@null';
            $iri = $def['@id'];

            if (false === isset($inverseContext[$iri]['term']) && (false === $def['@reverse'])) {
                $inverseContext[$iri]['term'] = $term;
            }

            $typeOrLang = '@null';
            $typeLangValue = '@null';

            if (true === $def['@reverse']) {
                $typeOrLang = '@type';
                $typeLangValue = '@reverse';
            } elseif (isset($def['@type'])) {
                $typeOrLang = '@type';
                $typeLangValue = $def['@type'];
            } elseif (array_key_exists('@language', $def)) {  // can be null
                $typeOrLang = '@language';
                $typeLangValue = (null === $def['@language']) ? '@null' : $def['@language'];
            } else {
                // Every untyped term is implicitly set to the default language
                if (false === isset($inverseContext[$iri][$container]['@language'][$defaultLanguage])) {
                    $inverseContext[$iri][$container]['@language'][$defaultLanguage] = $term;
                }
            }

            if (false === isset($inverseContext[$iri][$container][$typeOrLang][$typeLangValue])) {
                $inverseContext[$iri][$container][$typeOrLang][$typeLangValue] = $term;
            }
        }

        // Sort the whole inverse context in reverse order, the longest IRI comes first
        uksort($inverseContext, array($this, 'sortTerms'));
        $inverseContext = array_reverse($inverseContext);

        return $inverseContext;
    }

    /**
     * Creates a node map of an expanded JSON-LD document
     *
     * All keys in the node map are prefixed with "-" to support empty strings.
     *
     * @param JsonObject              $nodeMap     The object holding the node map.
     * @param JsonObject|JsonObject[] $element     An expanded JSON-LD element to
     *                                             be put into the node map
     * @param string                  $activegraph The graph currently being processed.
     * @param null|string             $activeid    The node currently being processed.
     * @param null|string             $activeprty  The property currently being processed.
     * @param null|JsonObject         $list        The list object if a list is being
     *                                             processed.
     */
    private function generateNodeMap(
        &$nodeMap,
        $element,
        $activegraph = JsonLD::DEFAULT_GRAPH,
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
                if (0 === strncmp($type, '_:', 2)) {
                    $type = $this->getBlankNodeId($type);
                }
            }
        }

        if (property_exists($element, '@value')) {
            // Handle value objects
            if (null === $list) {
                $this->mergeIntoProperty(
                    $nodeMap->{'-' . $activegraph}->{'-' . $activeid},
                    $activeprty,
                    $element,
                    true,
                    true
                );
            } else {
                $this->mergeIntoProperty($list, '@list', $element, true, false);
            }
        } elseif (property_exists($element, '@list')) {
            // lists
            $result = new JsonObject();
            $result->{'@list'} = array();

            $this->generateNodeMap($nodeMap, $element->{'@list'}, $activegraph, $activeid, $activeprty, $result);
            $this->mergeIntoProperty(
                $nodeMap->{'-' . $activegraph}->{'-' . $activeid},
                $activeprty,
                $result,
                true,
                false
            );
        } else {
            // and node objects
            if (false === property_exists($element, '@id')) {
                $id = $this->getBlankNodeId();
            } elseif (0 === strncmp($element->{'@id'}, '_:', 2)) {
                $id = $this->getBlankNodeId($element->{'@id'});
            } else {
                $id = $element->{'@id'};
            }
            unset($element->{'@id'});

            // Create node in node map if it doesn't exist yet
            if (false === property_exists($nodeMap->{'-' . $activegraph}, '-' . $id)) {
                $node = new JsonObject();
                $node->{'@id'} = $id;
                $nodeMap->{'-' . $activegraph}->{'-' . $id} = $node;
            } else {
                $node = $nodeMap->{'-' . $activegraph}->{'-' . $id};
            }

            // Add reference to active property
            if (is_object($activeid)) {
                $this->mergeIntoProperty($node, $activeprty, $activeid, true, true);
            } elseif (null !== $activeprty) {
                $reference = new JsonObject();
                $reference->{'@id'} = $id;

                if (null === $list) {
                    $this->mergeIntoProperty(
                        $nodeMap->{'-' . $activegraph}->{'-' . $activeid},
                        $activeprty,
                        $reference,
                        true,
                        true
                    );
                } else {
                    $this->mergeIntoProperty($list, '@list', $reference, true, false);
                }
            }

            if (property_exists($element, '@type')) {
                $this->mergeIntoProperty($node, '@type', $element->{'@type'}, true, true);
                unset($element->{'@type'});
            }

            if (property_exists($element, '@index')) {
                $this->setProperty(
                    $node,
                    '@index',
                    $element->{'@index'},
                    JsonLdException::CONFLICTING_INDEXES
                );
                unset($element->{'@index'});
            }

            if (property_exists($element, '@reverse')) {
                $reference = array('@id' => $id);

                // First, add the reverse property to all nodes pointing to this node and then
                // add them to the node mape
                foreach (get_object_vars($element->{'@reverse'}) as $property => $value) {
                    foreach ($value as $val) {
                        $this->generateNodeMap($nodeMap, $val, $activegraph, (object) $reference, $property);
                    }
                }

                unset($element->{'@reverse'});
            }

            // This node also represent a named graph, process it
            if (property_exists($element, '@graph')) {
                if (JsonLD::MERGED_GRAPH !== $activegraph) {
                    if (false === property_exists($nodeMap, '-' . $id)) {
                        $nodeMap->{'-' . $id} = new JsonObject();
                    }

                    $this->generateNodeMap($nodeMap, $element->{'@graph'}, $id);
                } else {
                    $this->generateNodeMap($nodeMap, $element->{'@graph'}, JsonLD::MERGED_GRAPH);
                }

                unset($element->{'@graph'});
            }

            // Process all other properties in order
            $properties = get_object_vars($element);
            ksort($properties);

            foreach ($properties as $property => $value) {
                if (0 === strncmp($property, '_:', 2)) {
                    $property = $this->getBlankNodeId($property);
                }

                if (false === property_exists($node, $property)) {
                    $node->{$property} = array();
                }

                $this->generateNodeMap($nodeMap, $value, $activegraph, $id, $property);
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
     * @param null|string $id If available, existing blank node identifier.
     *
     * @return string Returns a blank node identifier.
     */
    private function getBlankNodeId($id = null)
    {
        if ((null !== $id) && isset($this->blankNodeMap[$id])) {
            return $this->blankNodeMap[$id];
        }

        $bnode = '_:b' . $this->blankNodeCounter++;
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
        $nodeMap = new JsonObject();
        $nodeMap->{'-' . JsonLD::DEFAULT_GRAPH} = new JsonObject();

        $this->generateNodeMap($nodeMap, $element);

        $defaultGraph = $nodeMap->{'-' . JsonLD::DEFAULT_GRAPH};
        unset($nodeMap->{'-' . JsonLD::DEFAULT_GRAPH});

        // Store named graphs in the @graph property of the node representing
        // the graph in the default graph
        foreach ($nodeMap as $graphName => $graph) {
            if (!isset($defaultGraph->{$graphName})) {
                $defaultGraph->{$graphName} = new JsonObject();
                $defaultGraph->{$graphName}->{'@id'} = substr($graphName, 1);
            }

            $graph = (array) $graph;
            ksort($graph);
            $defaultGraph->{$graphName}->{'@graph'} = array_values(
                array_filter($graph, array($this, 'hasNodeProperties'))
            );
        }

        $defaultGraph = (array) $defaultGraph;
        ksort($defaultGraph);

        return array_values(
            array_filter($defaultGraph, array($this, 'hasNodeProperties'))
        );
    }

    /**
     * Converts an expanded JSON-LD document to RDF quads
     *
     * The result is an array of Quads.
     *
     * @param array $document The expanded JSON-LD document to be transformed into quads.
     *
     * @return Quad[] The extracted quads.
     */
    public function toRdf(array $document)
    {
        $nodeMap = new JsonObject();
        $nodeMap->{'-' . JsonLD::DEFAULT_GRAPH} = new JsonObject();

        $this->generateNodeMap($nodeMap, $document);

        $result = array();

        foreach ($nodeMap as $graphName => $graph) {
            $graphName = substr($graphName, 1);
            if (JsonLD::DEFAULT_GRAPH === $graphName) {
                $activegraph = null;
            } else {
                $activegraph = new IRI($graphName);

                if (false === $activegraph->isAbsolute()) {
                    continue;
                }
            }

            foreach ($graph as $subject => $node) {
                $activesubj = new IRI(substr($subject, 1));

                if (false === $activesubj->isAbsolute()) {
                    continue;
                }

                foreach ($node as $property => $values) {
                    if ('@id' === $property) {
                        continue;
                    } elseif ('@type' === $property) {
                        $activeprty = new IRI(RdfConstants::RDF_TYPE);
                        foreach ($values as $value) {
                            $result[] = new Quad($activesubj, $activeprty, new IRI($value), $activegraph);
                        }

                        continue;
                    } elseif ('@' === $property[0]) {
                        continue;
                    }

                    // Exclude triples/quads with a blank node predicate if generalized RDF isn't enabled
                    if ((0 === strncmp($property, '_:', 2)) && (false === $this->generalizedRdf)) {
                        continue;
                    }

                    $activeprty = new IRI($property);
                    if (false === $activeprty->isAbsolute()) {
                        continue;
                    }

                    foreach ($values as $value) {
                        if (property_exists($value, '@list')) {
                            $quads = array();
                            $head = $this->listToRdf($value->{'@list'}, $quads, $activegraph);

                            $result[] = new Quad($activesubj, $activeprty, $head, $activegraph);
                            foreach ($quads as $quad) {
                                $result[] = $quad;
                            }
                        } else {
                            $object = $this->elementToRdf($value);

                            if (null === $object) {
                                continue;
                            }

                            $result[] = new Quad($activesubj, $activeprty, $object, $activegraph);
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Converts a JSON-LD element to a RDF Quad object
     *
     * @param JsonObject $element The element to be converted.
     *
     * @return IRI|TypedValue|LanguageTagged|null The converted element to be used as Quad object.
     */
    private function elementToRdf(JsonObject $element)
    {
        if (property_exists($element, '@value')) {
            return Value::fromJsonLd($element);
        }

        $iri = new IRI($element->{'@id'});

        return $iri->isAbsolute() ? $iri : null;
    }

    /**
     * Converts a JSON-LD list to a linked RDF list (quads)
     *
     * @param array    $entries The list entries
     * @param array    $quads   The array to be used to hold the linked list
     * @param null|IRI $graph   The graph to be used in the constructed Quads
     *
     * @return IRI Returns the IRI of the head of the list
     */
    private function listToRdf(array $entries, array &$quads, IRI $graph = null)
    {
        if (0 === count($entries)) {
            return new IRI(RdfConstants::RDF_NIL);
        }

        $head = new IRI($this->getBlankNodeId());
        $quads[] = new Quad($head, new IRI(RdfConstants::RDF_FIRST), $this->elementToRdf($entries[0]), $graph);

        $bnode = $head;
        for ($i = 1, $len = count($entries); $i < $len; $i++) {
            $next = new IRI($this->getBlankNodeId());

            $quads[] = new Quad($bnode, new IRI(RdfConstants::RDF_REST), $next, $graph);

            $object = $this->elementToRdf($entries[$i]);
            if (null !== $object) {
                $quads[] = new Quad($next, new IRI(RdfConstants::RDF_FIRST), $object, $graph);
            }

            $bnode = $next;
        }

        $quads[] = new Quad($bnode, new IRI(RdfConstants::RDF_REST), new IRI(RdfConstants::RDF_NIL), $graph);

        return $head;
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
        $graphs = new JsonObject();
        $graphs->{JsonLD::DEFAULT_GRAPH} = new JsonObject();
        $usages = new JsonObject();

        foreach ($quads as $quad) {
            $graphName = JsonLD::DEFAULT_GRAPH;

            if ($quad->getGraph()) {
                $graphName = (string) $quad->getGraph();

                // Add a reference to this graph to the default graph if it
                // doesn't exist yet
                if (false === isset($graphs->{JsonLD::DEFAULT_GRAPH}->{$graphName})) {
                    $graphs->{JsonLD::DEFAULT_GRAPH}->{$graphName} =
                        self::objectToJsonLd($quad->getGraph());
                }
            }

            if (false === isset($graphs->{$graphName})) {
                $graphs->{$graphName} = new JsonObject();
            }
            $graph = $graphs->{$graphName};

            // Subjects and properties are always IRIs (blank nodes are IRIs
            // as well): convert them to a string representation
            $subject = (string) $quad->getSubject();
            $property = (string) $quad->getProperty();
            $object = $quad->getObject();

            // All nodes are stored in the node map
            if (false === isset($graph->{$subject})) {
                $graph->{$subject} = self::objectToJsonLd($quad->getSubject());
            }
            $node = $graph->{$subject};

            // ... as are all objects that are IRIs or blank nodes
            if (($object instanceof IRI) && (false === isset($graph->{(string) $object}))) {
                $graph->{(string) $object} = self::objectToJsonLd($object);
            }

            if (($property === RdfConstants::RDF_TYPE) && (false === $this->useRdfType) &&
                ($object instanceof IRI)) {
                self::mergeIntoProperty($node, '@type', (string) $object, true, true);
            } else {
                $value = self::objectToJsonLd($object, $this->useNativeTypes);

                self::mergeIntoProperty($node, $property, $value, true, true);

                // If the object is an IRI or blank node it might be the
                // beginning of a list. Store a reference to its usage so
                // that we can replace it with a list object later
                if ($object instanceof IRI) {
                    $objectStr = (string) $object;

                    // Usages of rdf:nil are stored per graph, while...
                    if (RdfConstants::RDF_NIL == $objectStr) {
                        $graph->{$objectStr}->usages[] = array(
                            'node' => $node,
                            'prop' => $property,
                            'value' => $value);
                    // references to other nodes are stored globally (blank nodes could be shared across graphs)
                    } else {
                        if (!isset($usages->{$objectStr})) {
                            $usages->{$objectStr} = array();
                        }

                        // Make sure that the same triple isn't counted multiple times
                        // TODO Making $usages->{$objectStr} a set would make this code simpler
                        $graphSubjectProperty = $graphName . '|' . $subject . '|' . $property;
                        if (false === isset($usages->{$objectStr}[$graphSubjectProperty])) {
                            $usages->{$objectStr}[$graphSubjectProperty] = array(
                                'graph' => $graphName,
                                'node' => $node,
                                'prop' => $property,
                                'value' => $value);
                        }
                    }
                }
            }
        }

        // Transform linked lists to @list objects
        $this->createListObjects($graphs, $usages);

        // Generate the resulting document starting with the default graph
        $document = array();

        $nodes = get_object_vars($graphs->{JsonLD::DEFAULT_GRAPH});
        ksort($nodes);

        foreach ($nodes as $id => $node) {
            // is it a named graph?
            if (isset($graphs->{$id})) {
                $node->{'@graph'} = array();

                $graphNodes = get_object_vars($graphs->{$id});
                ksort($graphNodes);

                foreach ($graphNodes as $graphNodeId => $graphNode) {
                    // Only add the node when it has properties other than @id
                    if (count(get_object_vars($graphNode)) > 1) {
                        $node->{'@graph'}[] = $graphNode;
                    }
                }
            }

            if (count(get_object_vars($node)) > 1) {
                $document[] = $node;
            }
        }

        return $document;
    }

    /**
     * Reconstruct @list arrays from linked list structures
     *
     * @param  JsonObject $graphs The graph map
     * @param  JsonObject $usages The global node usage map
     */
    private function createListObjects($graphs, $usages)
    {
        foreach ($graphs as $graph) {
            if (false === isset($graph->{RdfConstants::RDF_NIL})) {
                continue;
            }

            $nil = $graph->{RdfConstants::RDF_NIL};

            foreach ($nil->usages as $usage) {
                $u = $usage;

                $node = $u['node'];
                $prop = $u['prop'];
                $head = $u['value'];

                $list = array();
                $listNodes = array();

                while ((RdfConstants::RDF_REST === $prop) &&
                    (1 === count($usages->{$node->{'@id'}})) &&
                    property_exists($node, RdfConstants::RDF_FIRST) &&
                    property_exists($node, RdfConstants::RDF_REST) &&
                    (1 === count($node->{RdfConstants::RDF_FIRST})) &&
                    (1 === count($node->{RdfConstants::RDF_REST})) &&
                    ((3 === count(get_object_vars($node))) ||   // only @id, rdf:first & rdf:next
                        ((4 === count(get_object_vars($node))) &&  // or an additional rdf:type = rdf:List
                        property_exists($node, '@type') &&
                        ($node->{'@type'} === array(RdfConstants::RDF_LIST)))
                    )
                ) {
                    $list[] = reset($node->{RdfConstants::RDF_FIRST});
                    $listNodes[] = $node->{'@id'};


                    $u = reset($usages->{$node->{'@id'}});
                    $node = $u['node'];
                    $prop = $u['prop'];
                    $head = $u['value'];

                    if (0 !== strncmp($node->{'@id'}, '_:', 2)) {
                        break;
                    }
                };

                // The list is nested in another list
                if (RdfConstants::RDF_FIRST === $prop) {
                    // If it is empty, we can't do anything but keep the rdf:nil node
                    if (RdfConstants::RDF_NIL === $head->{'@id'}) {
                        continue;
                    }

                    // ... otherwise we keep the head and convert the rest to @list
                    $head = $graph->{$head->{'@id'}};
                    $head = reset($head->{RdfConstants::RDF_REST});

                    array_pop($list);
                    array_pop($listNodes);
                }

                unset($head->{'@id'});
                $head->{'@list'} = array_reverse($list);

                foreach ($listNodes as $node) {
                    unset($graph->{$node});
                }
            }

            unset($nil->usages);
        }
    }

    /**
     * Frames a JSON-LD document according a supplied frame
     *
     * @param array|JsonObject $element A JSON-LD element to be framed.
     * @param mixed            $frame   The frame.
     *
     * @return array $result The framed element in expanded form.
     *
     * @throws JsonLdException
     */
    public function frame($element, $frame)
    {
        if ((false === is_array($frame)) || (1 !== count($frame)) || (false === is_object($frame[0]))) {
            throw new JsonLdException(
                JsonLdException::UNSPECIFIED,
                'The frame is invalid. It must be a single object.',
                $frame
            );
        }

        $frame = $frame[0];

        $options = new JsonObject();
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

        $procOptions = new JsonObject();
        $procOptions->base = (string) $this->baseIri;  // TODO Check which base IRI to use
        $procOptions->compactArrays = $this->compactArrays;
        $procOptions->optimize = $this->optimize;
        $procOptions->useNativeTypes = $this->useNativeTypes;
        $procOptions->useRdfType = $this->useRdfType;
        $procOptions->produceGeneralizedRdf = $this->generalizedRdf;
        $procOptions->documentFactory = $this->documentFactory;
        $procOptions->documentLoader = $this->documentLoader;

        $processor = new Processor($procOptions);

        $graph = JsonLD::MERGED_GRAPH;
        if (property_exists($frame, '@graph')) {
            $graph = JsonLD::DEFAULT_GRAPH;
        }

        $nodeMap = new JsonObject();
        $nodeMap->{'-' . $graph} = new JsonObject();
        $processor->generateNodeMap($nodeMap, $element, $graph);

        // Sort the node map to ensure a deterministic output
        // TODO Move this to a separate function as basically the same is done in flatten()?
        $nodeMap = (array) $nodeMap;
        foreach ($nodeMap as &$nodes) {
            $nodes = (array) $nodes;
            ksort($nodes);
            $nodes = (object) $nodes;
        }
        $nodeMap = (object) $nodeMap;

        unset($processor);

        $result = array();

        foreach ($nodeMap->{'-' . $graph} as $node) {
            $this->nodeMatchesFrame($node, $frame, $options, $nodeMap, $graph, $result);
        }

        return $result;
    }

    /**
     * Checks whether a node matches a frame or not.
     *
     * @param JsonObject      $node    The node.
     * @param null|JsonObject $frame   The frame.
     * @param JsonObject      $options The current framing options.
     * @param JsonObject      $nodeMap The node map.
     * @param string          $graph   The currently used graph.
     * @param array           $parent  The parent to which matching results should be added.
     * @param array           $path    The path of already processed nodes.
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

        $result = new JsonObject();

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

            // If the property does not exist or is empty
            if ((false === property_exists($node, $property)) || (is_array($node->{$property}) && 0 === count($node->{$property}))) {
                // first check if it's @graph and whether the referenced graph exists
                if ('@graph' === $property) {
                    if (isset($result->{'@id'}) && property_exists($nodeMap, $result->{'@id'})) {
                        $result->{'@graph'} = array();
                        $match = false;

                        foreach ($nodeMap->{'-' . $result->{'@id'}} as $item) {
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
                            $result->{$property} = new JsonObject();
                            $result->{$property}->{'@null'} = true;
                        } else {
                            $result->{$property} = (is_array($validValue->{'@default'}))
                                ? $validValue->{'@default'}
                                : array($validValue->{'@default'});
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
                    $validValue = clone $validValue;
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
                                $nodeMap->{'-' . $graph}->{'-' . $value->{'@id'}},
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
                    throw new JsonLdException(
                        JsonLdException::UNSPECIFIED,
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
            $result = new JsonObject();
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
     * @param JsonObject $node    The node whose properties should processed.
     * @param JsonObject $options The current framing options.
     * @param JsonObject $nodeMap The node map.
     * @param string     $graph   The currently used graph.
     * @param JsonObject $result  The object to which the properties should be added.
     * @param array      $path    The path of already processed nodes.
     */
    private function addMissingNodeProperties($node, $options, $nodeMap, $graph, &$result, $path)
    {
        foreach ($node as $property => $value) {
            if (property_exists($result, $property)) {
                continue; // property has already been added
            }

            if (true === $options->{'@embedChildren'}) {
                if (false === is_array($value)) {
                    $result->{$property} = unserialize(serialize($value));  // create a deep-copy
                    continue;
                }

                $result->{$property} = array();
                foreach ($value as $item) {
                    if (is_object($item)) {
                        if (property_exists($item, '@id')) {
                            $item = $nodeMap->{'-' . $graph}->{'-' . $item->{'@id'}};
                        }

                        $this->nodeMatchesFrame($item, null, $options, $nodeMap, $graph, $result->{$property}, $path);
                    } else {
                        $result->{$property}[] = $item;
                    }
                }

            } else {
                // TODO Perform deep object copy??
                $result->{$property} = unserialize(serialize($value));  // create a deep-copy
            }
        }
    }

    /**
     * Adds a property to an object if it doesn't exist yet
     *
     * If the property already exists, an exception is thrown as otherwise
     * the existing value would be lost.
     *
     * @param JsonObject $object   The object.
     * @param string     $property The name of the property.
     * @param mixed      $value    The value of the property.
     *
     * @throws JsonLdException If the property exists already JSON-LD.
     */
    private static function setProperty(&$object, $property, $value, $errorCode = null)
    {
        if (property_exists($object, $property) &&
            (false === self::subtreeEquals($object->{$property}, $value))) {

            if ($errorCode) {
                throw new JsonLdException(
                    $errorCode,
                    "Object already contains a property \"$property\".",
                    $object
                );
            }

            throw new JsonLdException(
                JsonLdException::UNSPECIFIED,
                "Object already contains a property \"$property\".",
                $object
            );
        }

        $object->{$property} = $value;
    }

    /**
     * Merges a value into a property of an object
     *
     * @param JsonObject $object      The object.
     * @param string     $property    The name of the property to which the value
     *                                should be merged into.
     * @param mixed      $value       The value to merge into the property.
     * @param bool       $alwaysArray If set to true, the resulting property will
     *                                always be an array.
     * @param bool       $unique      If set to true, the value is only added if
     *                                it doesn't exist yet.
     */
    private static function mergeIntoProperty(&$object, $property, $value, $alwaysArray = false, $unique = false)
    {
        // No need to add a null value
        if (null === $value) {
            return;
        }

        if (is_array($value)) {
            // Make sure empty arrays are created since we preserve them in expansion
            if ((0 === count($value)) && (false === property_exists($object, $property))) {
                $object->{$property} = array();
            }

            foreach ($value as $val) {
                static::mergeIntoProperty($object, $property, $val, $alwaysArray, $unique);
            }

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

            $object->{$property}[] = $value;
        } else {
            $object->{$property} = ($alwaysArray) ? array($value) : $value;
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
     * @param JsonObject  $object         The object to convert.
     * @param boolean     $useNativeTypes If set to true, native types are used
     *                                    for xsd:integer, xsd:double, and
     *                                    xsd:boolean, otherwise typed strings
     *                                    will be used instead.
     *
     * @return mixed The JSON-LD representation of the object.
     */
    private static function objectToJsonLd($object, $useNativeTypes = true)
    {
        if ($object instanceof IRI) {
            $result = new JsonObject();
            $result->{'@id'} = (string) $object;

            return $result;
        } elseif ($object instanceof Value) {
            return $object->toJsonLd($useNativeTypes);
        }

        return $object;
    }

    /**
     * Checks whether a node has properties and not just an @id
     *
     * This is used to filter nodes consisting just of an @id-member when
     * flattening and converting from RDF.
     *
     * @param JsonObject $node The node
     *
     * @return boolean True if the node has properties (other than @id),
     *                 false otherwise.
     */
    private function hasNodeProperties($node)
    {
        return (count(get_object_vars($node)) > 1);
    }
}
