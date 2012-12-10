<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

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

    /** A list of all defined keywords */
    private static $keywords = array('@context', '@id', '@value', '@language', '@type',
                                     '@container', '@list', '@set', '@graph', '@vocab',
                                     '@null', '@annotation');  // TODO Introduce this! Should this just be supported during framing!?

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
     * @return mixed  A PHP value.
     *
     * @throws ParseException If the JSON-LD document is not valid.
     */
    public static function parse($document)
    {
        if (function_exists('mb_detect_encoding') &&
            (false === mb_detect_encoding($document, 'UTF-8', true)))
        {
            throw new ParseException('The JSON-LD document does not appear to be valid UTF-8.');
        }

        $data = json_decode($document, false, 512);

        switch (json_last_error())
        {
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
     * Parses a JSON-LD document and returns it as a {@link Document}
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
        $nodeMap = new \stdClass();
        $this->createNodeMap($nodeMap, $input);
        $this->mergeNodeMapGraphs($nodeMap);

        // As we do not support named graphs yet we are currently just
        // interested in the merged graph
        $nodeMap = $nodeMap->{'@merged'};

        // We need to keep track of blank nodes as they are renamed when
        // inserted into the Document

        $document = new Document($this->baseIri);
        $nodes = array();

        foreach ($nodeMap as $id => &$item)
        {
            if (!isset($nodes[$id]))
            {
                $nodes[$id] = $document->createNode($item->{'@id'});
            }

            $node = $nodes[$id];
            unset($item->{'@id'});

            // Process node type as it needs to be handled differently than
            // other properties
            if (property_exists($item, '@type'))
            {
                foreach ($item->{'@type'} as $type)
                {
                    if (!isset($nodes[$type]))
                    {
                        $nodes[$type] = $document->createNode($type);
                    }
                    $node->addType($nodes[$type]);
                }
                unset($item->{'@type'});
            }

            foreach ($item as $property => $value)
            {
                foreach ($value as $val)
                {
                    if (property_exists($val, '@value'))
                    {
                        if (property_exists($val, '@type'))
                        {
                            $node->setProperty($property, new TypedValue($val->{'@value'}, $val->{'@type'}));
                        }
                        elseif (property_exists($val, '@language'))
                        {
                            $node->addPropertyValue($property, new LanguageTaggedString($val->{'@value'}, $val->{'@language'}));
                        }
                        else
                        {
                            $node->addPropertyValue($property, $val->{'@value'});
                        }
                    }
                    elseif (property_exists($val, '@id'))
                    {
                        if (!isset($nodes[$val->{'@id'}]))
                        {
                            $nodes[$val->{'@id'}] = $document->createNode($val->{'@id'});
                        }
                        $node->addPropertyValue($property, $nodes[$val->{'@id'}]);
                    }
                    else // .. it must be a list
                    {
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
        if (is_array($element))
        {
            $result = array();
            foreach ($element as &$item)
            {
                $this->expand($item, $activectx, $activeprty, $frame);

                // Check for lists of lists
                if (('@list' === $this->getPropertyDefinition($activectx, $activeprty, '@container')) ||
                    ('@list' == $activeprty))
                {
                    if (is_array($item) || (is_object($item) && property_exists($item, '@list')))
                    {
                        throw new SyntaxException(
                            "List of lists detected in property \"$activeprty\".",
                            $element);
                    }
                }
                if (false == is_null($item))
                {
                    if (is_array($item))
                    {
                        $result = array_merge($result, $item);
                    }
                    else
                    {
                        $result[] = $item;
                    }
                }
            }

            $element = $result;
            return;
        }

        if (is_object($element))
        {
            // Try to process local context
            if (property_exists($element, '@context'))
            {
                $this->processContext($element->{'@context'}, $activectx);
                unset($element->{'@context'});
            }

            $properties = get_object_vars($element);
            ksort($properties);

            $element = new \stdClass();

            foreach ($properties as $property => $value)
            {
                $expProperty = $this->expandProperty($property, $activectx);

                if (false === is_array($expProperty))
                {
                    // Make sure to keep framing keywords if a frame is being expanded
                    if ((true == $frame) && in_array($expProperty, self::$framingKeywords))
                    {
                        self::setProperty($element, $expProperty, $value);
                        continue;
                    }

                    if (in_array($expProperty, self::$keywords))
                    {
                        // we don't allow overwriting the behavior of keywords,
                        // so if the property expands to one, we treat it as the
                        // keyword itself
                        $property = $expProperty;

                        $this->expandKeywordValue($element, $activeprty, $expProperty, $value, $activectx, $frame);

                        continue;
                    }
                    elseif (false === strpos($expProperty, ':'))
                    {
                        // the expanded property is neither a keyword nor an IRI
                        continue;
                    }
                }

                // Remove properties with null values
                if (is_null($value))
                {
                    continue;
                }

                $propertyContainer = $this->getPropertyDefinition($activectx, $property, '@container');

                if (in_array($propertyContainer, array('@language', '@annotation')))
                {
                    // Expand language and annotation maps
                    if (false === is_object($value))
                    {
                        throw new SyntaxException(
                            "Invalid value for \"$property\" detected. It must be an object as it is a @language or @annotation container.",
                            $value);
                    }

                    $result = array();

                    if ('@language' === $propertyContainer)
                    {
                        foreach ($value as $key => $val)
                        {
                            if (false === is_array($val))
                            {
                                $val = array($val);
                            }

                            foreach ($val as $item)
                            {
                                if (false === is_string($item))
                                {
                                    throw new SyntaxException(
                                        "Detected invalid value in $property->$key: it must be a string as it is part of a language map.",
                                        $item);
                                }

                                $result[] = (object) array(
                                    '@value' => $item,
                                    '@language' => strtolower($key)
                                );
                            }
                        }
                    }
                    else  // @container: @annotation
                    {
                        foreach ($value as $key => $val)
                        {
                            if (false === is_array($val))
                            {
                                $val = array($val);
                            }

                            $this->expand($val, $activectx, $activeprty, $frame);

                            foreach ($val as $item)
                            {
                                if (false === property_exists($item, '@annotation'))
                                {
                                    $item->{'@annotation'} = $key;
                                }

                                $result[] = $item;
                            }
                        }
                    }

                    $value = $result;
                }
                else
                {
                    // .. and all other values
                    $this->expand($value, $activectx, $property, $frame);
                }

                // Store the expanded value unless it is null
                if (false == is_null($value))
                {
                    // If property has an @list container and value is not yet an
                    // expanded @list-object, transform it to one
                    if (('@list' == $propertyContainer) &&
                        ((false == is_object($value) || (false == property_exists($value, '@list')))))
                    {
                        if (false == is_array($value))
                        {
                            $value = array($value);
                        }

                        $obj = new \stdClass();
                        $obj->{'@list'} = $value;
                        $value = $obj;
                    }


                    if (is_array($expProperty))
                    {
                        // Label all blank nodes to connect duplicates
                        $this->labelBlankNodes($value);

                        // Create deep copies of the value for each property
                        $serialized = serialize($value);

                        foreach ($expProperty['@id'] as $item)
                        {
                            $value = unserialize($serialized);
                            self::mergeIntoProperty($element, $item, $value, true);
                        }
                    }
                    else
                    {
                        self::mergeIntoProperty($element, $expProperty, $value, true);
                    }
                }
            }
        }


        // Expand scalars (scalars != null) to @value objects
        if (is_scalar($element))
        {
            $def = $this->getPropertyDefinition($activectx, $activeprty);
            $obj = new \stdClass();

            if ('@id' === $def['@type'])
            {
                $obj->{'@id'} = $this->expandIri($element, $activectx, true);
            }
            else
            {
                $obj->{'@value'} = $element;

                if (isset($def['@type']))
                {
                    if ('_:' === substr($def['@type'], 0, 2))
                    {
                        $obj->{'@type'} = $this->getBlankNodeId($def['@type']);
                    }
                    else
                    {
                        $obj->{'@type'} = $def['@type'];
                    }
                }
                elseif (isset($def['@language']) && is_string($obj->{'@value'}))
                {
                    $obj->{'@language'} = $def['@language'];
                }
            }

            $element = $obj;

            return;  // nothing more to do.. completely expanded
        }
        elseif (is_null($element))
        {
            return;
        }

        // All properties have been processed. Make sure the result is valid
        // and optimize object where possible
        $numProps = count(get_object_vars($element));

        // Annotations are allowed everywhere
        if (property_exists($element, '@annotation'))
        {
            $numProps--;
        }

        if (property_exists($element, '@value'))
        {
            $numProps--;  // @value
            if (property_exists($element, '@language'))
            {
                if (false === $frame)
                {
                    if (false === is_string($element->{'@language'}))
                    {
                        throw new SyntaxException(
                            'Invalid value for @language detected (must be a string).',
                            $element);
                    }
                    elseif (false === is_string($element->{'@value'}))
                    {
                        throw new SyntaxException(
                            'Only strings can be language tagged.',
                            $element);
                    }
                }

                $numProps--;
            }
            elseif (property_exists($element, '@type'))
            {
                if ((false === $frame) && (false === is_string($element->{'@type'})))
                {
                    throw new SyntaxException(
                        'Invalid value for @type detected (must be a string).',
                        $element);
                }

                $numProps--;
            }

            if ($numProps > 0)
            {
                throw new SyntaxException(
                    'Detected an invalid @value object.',
                    $element);
            }
            elseif (is_null($element->{'@value'}))
            {
                // object has just an @value property that is null, can be replaced with that value
                $element = $element->{'@value'};
            }

            return;
        }

        // Not an @value object, make sure @type is an array
        if (property_exists($element, '@type') && (false == is_array($element->{'@type'})))
        {
            $element->{'@type'} = array($element->{'@type'});
        }
        if (($numProps > 1) && (
            (property_exists($element, '@list') || property_exists($element, '@set'))))
        {
            throw new SyntaxException(
                'An object with a @list or @set property can\'t contain other properties.',
                $element);
        }
        elseif (property_exists($element, '@set'))
        {
            // @set objects can be optimized away as they are just syntactic sugar
            $element = $element->{'@set'};
        }
        elseif (($numProps == 1) && (false == $frame) && property_exists($element, '@language'))
        {
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
        if (is_null($value) && ('@value' !== $keyword))
        {
            return;
        }

        if ('@id' == $keyword)
        {
            if (false === is_string($value))
            {
                throw new SyntaxException(
                    'Invalid value for @id detected (must be a string).',
                    $element);
            }

            $value = $this->expandIri($value, $activectx, true);
            self::setProperty($element, $keyword, $value);

            return;
        }

        if ('@type' == $keyword)
        {
            // TODO Check value space once agreed (see ISSUE-114)

            if (is_string($value))
            {
                $value = $this->expandIri($value, $activectx, true, true);
                self::setProperty($element, $keyword, $value);

                return;
            }

            if (false === is_array($value))
            {
                $value = array($value);
            }

            $result = array();

            foreach ($value as $item)
            {
                // This is an automatic recovery for @type values being node references
                if (is_object($item) && (1 === count(get_object_vars($item))))
                {
                    foreach ($item as $itemKey => $itemValue)
                    {
                        if ('@id' == $this->expandIri($itemKey, $activectx, false, true))
                        {
                            $item = $itemValue;
                        }
                    }
                }

                if (is_string($item))
                {
                    $result[] = $this->expandIri($item, $activectx, true, true);
                }
                else
                {
                    // TODO Check if this is enough!!
                    if (false === $frame)
                    {
                        throw new SyntaxException("Invalid value for $keyword detected.", $value);
                    }

                    self::mergeIntoProperty($element, $keyword, $item);
                }
            }

            // Don't keep empty arrays
            if (count($result) >= 1)
            {
                self::mergeIntoProperty($element, $keyword, $result, true);
            }
        }

        if (('@value' == $keyword) || ('@language' == $keyword) || ('@annotation' == $keyword))
        {
            if (false == $frame)
            {
                if (is_array($value) && (1 == count($value)))
                {
                    $value = $value[0];
                }

                if ('@value' !== $keyword)
                {
                    if (false === is_string($value))
                    {
                        throw new SyntaxException(
                            "Invalid value for $keyword detected; must be a string.",
                            $value);
                    }
                }
                elseif ((null !== $value) && (false === is_scalar($value)))
                {
                    // we need to preserve @value: null to distinguish values form nodes
                    throw new SyntaxException(
                        "Invalid value for $keyword detected (must be a scalar).",
                        $value);
                }
            }
            elseif (false == is_array($value))
            {
                $value = array($value);
            }

            self::setProperty($element, $keyword, $value);

            return;
        }


        if (('@set' === $keyword) || ('@list' === $keyword))
        {
            $this->expand($value, $activectx, $activeprty, $frame);
            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }

        if ('@graph' === $keyword)
        {
            $this->expand($value, $activectx, $keyword, $frame);
            self::mergeIntoProperty($element, $keyword, $value, true);

            return;
        }
    }

    /**
     * Labels all nodes in an expanded JSON-LD structure with fresh blank node identifiers
     *
     * This method assumes that element and all its children have already been
     * expanded.
     *
     * @param  mixed $element The expanded JSON-LD structure whose blank
     *                        nodes should be labeled.
     */
    private function labelBlankNodes(&$element)
    {
        if (is_array($element))
        {
            foreach ($element as &$value)
            {
                $this->labelBlankNodes($value);
            }
        }
        elseif (is_object($element))
        {
            if (property_exists($element, '@value'))
            {
                return;
            }

            if (property_exists($element, '@list'))
            {
                $this->labelBlankNodes($element->{'@list'});

                return;
            }

            $properties = array_keys(get_object_vars($element));

            if (false === property_exists($element, '@id'))
            {
                $element->{'@id'} = $this->getBlankNodeId();
            }

            foreach ($properties as $key)
            {
                $this->labelBlankNodes($element->{$key});
            }
        }

    }

    /**
     * Expand a property to an IRI or a JSON-LD keyword
     *
     * @param mixed  $value         The value to be expanded to an absolute IRI.
     * @param array  $activectx     The active context.
     *
     * @return null|string|string[] If the property could be expanded either
     *                              the IRI(s) or the keyword is returned;
     *                              otherwise null is returned.
     */
    private function expandProperty($value, $activectx)
    {
        if (isset($activectx['@propertyGenerators'][$value]))
        {
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
     * @param mixed  $value         The value to be expanded to an absolute IRI.
     * @param array  $activectx     The active context.
     * @param bool   $relativeIri   Specifies whether $value should be treated as
     *                              relative IRI as fallback or not.
     * @param bool   $vocabRelative Specifies whether $value is relative to @vocab
     *                              if set or not.
     *
     * @return string The expanded IRI.
     */
    private function expandIri($value, $activectx, $relativeIri = false, $vocabRelative = false)
    {
        $result = $this->doExpandIri($value, $activectx, $relativeIri, $vocabRelative);

        if ('_:' === substr($result, 0, 2))
        {
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
     *                              relative IRI as fallback or not.
     * @param bool   $vocabRelative Specifies whether $value is relative to @vocab
     *                              if set or not.
     * @param object $localctx      If the IRI is being expanded as part of context
     *                              processing, the current local context has to be
     *                              passed as well.
     * @param array  $path          A path of already processed terms.
     *
     * @return string The expanded IRI.
     */
    private function doExpandIri($value, $activectx, $relativeIri = false, $vocabRelative = false, $localctx = null, $path = array())
    {
        if (in_array($value, self::$keywords))
        {
            return $value;
        }

        if ($localctx)
        {
            if (in_array($value, $path))
            {
                throw new ProcessException(
                    'Cycle in context definition detected: ' . join(' -> ', $path) . ' -> ' . $path[0],
                    $localctx);
            }
            else
            {
                $path[] = $value;

                if (count($path) >= self::CONTEXT_MAX_IRI_RECURSIONS)
                {
                    throw new ProcessException(
                        'Too many recursions in term definition: ' . join(' -> ', $path) . ' -> ' . $path[0],
                        $localctx);
                }
            }

            if (isset($localctx->{$value}))
            {
                if (is_string($localctx->{$value}))
                {
                    return $this->doExpandIri($localctx->{$value}, $activectx, false, true, $localctx, $path);
                }
                elseif (isset($localctx->{$value}->{'@id'}))
                {
                    if (false === is_string($localctx->{$value->{'@id'}}))
                    {
                        throw new SyntaxException(
                            'A term definition must not use a property generator: ' . join(' -> ', $path),
                            $localctx);
                    }

                    // TODO PropGen Make sure it's not a property generator
                    return $this->doExpandIri($localctx->{$value}->{'@id'}, $activectx, false, true, $localctx, $path);
                }
            }
        }


        if (array_key_exists($value, $activectx))
        {
            return $activectx[$value]['@id'];
        }

        if (false !== strpos($value, ':'))
        {
            list($prefix, $suffix) = explode(':', $value, 2);

            if ('//' == substr($suffix, 0, 2))  // TODO Check this
            {
                // Safety measure to prevent reassigned of, e.g., http://
                return $value;
            }

            if ('_' == $prefix)
            {
                // it is a named blank node
                return $value;
            }
            elseif ($localctx)
            {
                $prefix = $this->doExpandIri($prefix, $activectx, false, true, $localctx, $path);

                // If prefix contains a colon, we have successfully expanded it
                if (false !== strpos($prefix, ':'))
                {
                    return $prefix . $suffix;
                }
            }
            elseif (array_key_exists($prefix, $activectx))
            {
                // compact IRI
                return $activectx[$prefix]['@id'] . $suffix;
            }
        }
        elseif (false == in_array($value, self::$keywords))
        {
            if ((true == $vocabRelative) && array_key_exists('@vocab', $activectx))
            {
                // TODO Handle relative IRIs properly??
                return $activectx['@vocab'] . $value;
            }
            elseif (true == $relativeIri)
            {
                return (string)$this->baseIri->resolve($value);
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
        if (is_array($element))
        {
            $result = array();
            foreach ($element as &$item)
            {
                $this->compact($item, $activectx, $inversectx, $activeprty);
                if (false == is_null($item))
                {
                    $result[] = $item;
                }
            }
            $element = $result;

            // If there's just one entry and the active property is not an
            // @list container, optimize the array away
            if ((true === $this->compactArrays) && (1 == count($element)) &&
                ('@list' !== $this->getPropertyDefinition($activectx, $activeprty, '@container')))
            {
                $element = $element[0];
            }
        }
        elseif (is_object($element))
        {
            // Handle @null objects as used in framing
            if (property_exists($element, '@null'))
            {
                $element = null;
                return;
            }
            elseif (property_exists($element, '@value') || property_exists($element, '@id'))
            {
                $def = $this->getPropertyDefinition($activectx, $activeprty);
                $element = $this->compactValue($element, $def, $activectx, $inversectx);

                if (false === is_object($element))
                {
                    return;
                }
            }

            // Otherwise, compact all properties
            $properties = get_object_vars($element);
            $element = new \stdClass();

            foreach ($properties as $property => $value)
            {
                if (in_array($property, self::$keywords))
                {
                    // Get the keyword alias from the inverse context if available
                    $activeprty = (isset($inversectx[$property]['term']))
                        ? $inversectx[$property]['term']
                        : $property;

                    if ('@id' == $property)
                    {
                        // TODO Transform @id to relative IRIs by default??
                        $value = $this->compactIri($value, $activectx, $inversectx, $this->optimize);
                    }
                    elseif ('@type' == $property)
                    {
                        if (is_string($value))
                        {
                            $value = $this->compactVocabularyIri($value, $activectx, $inversectx);
                        }
                        else
                        {
                            foreach ($value as $key => &$iri)
                            {
                                // TODO Transform to relative IRIs by default??
                                $iri = $this->compactVocabularyIri($iri, $activectx, $inversectx);
                            }

                            if ((true === $this->compactArrays) && (1 === count($value)))
                            {
                                $value = $value[0];
                            }
                        }
                    }
                    elseif ('@graph' == $property)
                    {
                        if ('@graph' == $property)
                        {
                            foreach ($value as $key => &$item)
                            {
                                $this->compact($item, $activectx, $inversectx, null);
                            }
                        }

                        // TODO Should arrays with just one item be compacted for @graph
                    }
                    else
                    {
                        $this->compact($value, $activectx, $inversectx, $activeprty);
                    }

                    self::setProperty($element, $activeprty, $value);

                    // ... continue with next property
                    continue;
                }


                // Make sure that empty arrays are preserved
                if (0 === count($value))
                {
                    $activeprty = $this->compactVocabularyIri($property, $activectx, $inversectx, null, true);
                    self::mergeIntoProperty($element, $activeprty, $value);

                    // ... continue with next property
                    continue;
                }


                // Compact every item in value separately as they could map to different terms
                foreach ($value as &$val)
                {
                    $activeprty = $this->compactVocabularyIri($property, $activectx, $inversectx, $val, true);
                    $def = $this->getPropertyDefinition($activectx, $activeprty);

                    if (in_array($def['@container'], array('@language', '@annotation')))
                    {
                        if (false === property_exists($element, $activeprty))
                        {
                            $element->{$activeprty} = new \stdClass();
                        }

                        $def[$def['@container']] = $val->{$def['@container']};
                        $val = $this->compactValue($val, $def, $activectx, $inversectx);

                        self::mergeIntoProperty($element->{$activeprty}, $def[$def['@container']], $val);

                        continue;
                    }

                    if (is_object($val))
                    {
                        if (property_exists($val, '@list'))
                        {
                            $this->compact($val->{'@list'}, $activectx, $inversectx, $activeprty);

                            if ('@list' == $def['@container'])
                            {
                                $val = $val->{'@list'};

                                // a term can just hold one list if it has a @list container
                                // (we don't support lists of lists)
                                self::setProperty($element, $activeprty, $val);

                                continue;  // ... continue with next value
                            }
                        }
                        else
                        {
                            $this->compact($val, $activectx, $inversectx, $activeprty);
                        }
                    }

                    // Merge value back into resulting object making sure that value is always
                    // an array if a container is set or compactArrays is set to false
                    $asArray = (false === $this->compactArrays);
                    $asArray |= in_array($this->getPropertyDefinition($activectx, $activeprty, '@container'),
                        array('@list', '@set'));

                    self::mergeIntoProperty($element, $activeprty, $val, $asArray);
                }
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
     * @param mixed  $value      The value to compact (arrays are not allowed!).
     * @param array  $definition The active property's definition.
     * @param array  $activectx  The active context.
     * @param array  $inversectx The inverse context.
     *
     * @return mixed The compacted value.
     */
    private function compactValue($value, $definition,$activectx, $inversectx)
    {
        if (property_exists($value, '@annotation') &&
            ($value->{'@annotation'} === $definition['@annotation']))
        {
            unset($value->{'@annotation'});
        }

        $numProperties = count(get_object_vars($value));

        if (property_exists($value, '@id') && (1 === $numProperties) &&
            ('@id' === $definition['@type']))
        {
            return $this->compactIri($value->{'@id'}, $activectx, $inversectx);
        }

        if (property_exists($value, '@value'))
        {
            $criterion = (isset($value->{'@type'})) ? '@type' : null;
            $criterion = (isset($value->{'@language'})) ? '@language' : $criterion;

            if (null !== $criterion)
            {
                if ($value->{$criterion} !== $definition[$criterion])
                {
                    return $value;
                }

                unset($value->{$criterion});

                return (2 === $numProperties) ? $value->{'@value'} : $value;
            }

            // the object has neither a @type nor a @language property
            // check the active property's definition
            if (null !== $definition['@type'])
            {
                // if the property is type coerced, we can't compact the value
                return $value;
            }
            elseif ((null !== $definition['@language']) && is_string($value->{'@value'}))
            {
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
     * Compacts an absolute IRI to the shortest matching term or compact IRI.
     *
     * @param mixed  $iri           The IRI to be compacted.
     * @param array  $activectx     The active context.
     * @param array  $inversectx    The inverse context.
     * @param bool   $toRelativeIri Specifies whether $value should be
     *                              transformed to a relative IRI as fallback.
     *
     * @return string The compacted IRI.
     */
    private function compactIri($iri, $activectx, $inversectx, $toRelativeIri = false)
    {
        // Is there a term defined?
        if (isset($inversectx[$iri]['term']))
        {
            return $inversectx[$iri]['term'];
        }

        // ... or can we construct a compact IRI?
        if (null !== ($result = $this->compactIriToCompactIri($iri, $activectx, $inversectx)))
        {
            return $result;
        }

        // ... otherwise return the IRI as is
        return $iri;
    }

    /**
     * Helper function that compacts an absolute IRI to a compact IRI
     *
     * @param string $iri        The IRI.
     * @param array  $activectx  The active context.
     * @param array  $inversectx The inverse context.
     *
     * @return string|null Returns the compact IRI on success; otherwise null.
     */
    private function compactIriToCompactIri($iri, $activectx, $inversectx)
    {
        $iriLen = strlen($iri);

        foreach ($inversectx as $termIri => $def)
        {
            if (isset($def['term']) && ($iriLen > strlen($termIri)) &&  // prevent empty suffixes
                (0 === substr_compare($iri, $termIri, 0, strlen($termIri))))
            {
                $compactIri = $def['term'] . ':' . substr($iri, strlen($termIri));
                if (false === isset($activectx[$compactIri]))
                {
                    return $compactIri;
                }
            }
        }

        return null;
    }

    /**
     * Compacts a vocabulary relative IRI to a term, compact IRI or property
     * generator
     *
     * Vocabulary relative IRIs are either properties or values of `@type`.
     * Only properties can be compacted to property generators.     *
     *
     * @param mixed  $iri           The IRI to be compacted.
     * @param array  $activectx     The active context.
     * @param array  $inversectx    The inverse context.
     * @param mixed  $value         The value of the property to compact.
     * @param bool   $toRelativeIri Specifies whether $value should be
     *                              transformed to a relative IRI as fallback.
     * @param bool   $propGens      Return property generators or not?
     *
     * @return string The compacted IRI.
     */
    private function compactVocabularyIri($iri, $activectx, $inversectx, $value = null, $propGens = true)
    {
        $result = null;

        if (array_key_exists($iri, $inversectx))
        {
            $defaultLanguage = isset($activectx['@language']) ? $activectx['@language'] : null;

            // TODO Replace value profile with path in general!?
            $valueProfile = $this->getValueProfile($value);

            $path = array(
                $valueProfile['@container'],
                $valueProfile['typeLang'],
                ('@null' === $valueProfile['typeLang']) ? '@null' : $valueProfile[$valueProfile['typeLang']]
            );

            $result = $this->queryInverseContext($inversectx[$iri], $path, $defaultLanguage, $propGens);

            if (null !== $result)
            {
                return $result;
            }
        }

        // Try to compact to a compact IRI
        if (null !== ($result = $this->compactIriToCompactIri($iri, $activectx, $inversectx)))
        {
            return $result;
        }

        // Last resort, use @vocab if set and the result isn't an empty string
        if (isset($activectx['@vocab']) && (0 === strpos($iri, $activectx['@vocab'])) &&
            (false !== ($relativeIri = substr($iri, strlen($activectx['@vocab'])))))
        {
            return $relativeIri;
        }

        // IRI couldn't be compacted, return as is
        return $iri;
    }

    /**
     * Calculates a value profile
     *
     * A value profile represent the schema of the value ignoring the
     * concrete value. It is an associative array containing the following
     * keys-value pairs:
     *
     *   * `@container`: the container, defaults to `@set`
     *   * `@type`: the datatype IRI for typed values, `@id` for IRIs and
     *     blank nodes, or `null` for native types and language-tagged strings
     *   * `@language`: for language-tagged strings the language-code; for
     *     all other values `null`
     *   * `typeLang`: is set to `@type` or `@language` unless they are null;
     *     in that case it is set to `@null`
     *
     * @param mixed $value The value.
     *
     * @return array The value profile.
     */
    private function getValueProfile($value)
    {
        $valueProfile = array(
            '@container' => '@set',
            '@type' => null,
            '@language' => null,
            'typeLang' => '@null'
        );

        if (null === $value)
        {
            return $valueProfile;
        }

        if (property_exists($value, '@annotation'))
        {
            $valueProfile['@container'] = '@annotation';
        }

        if (property_exists($value, '@id'))
        {
            $valueProfile['@type'] = '@id';
            $valueProfile['typeLang'] = '@type';

            return $valueProfile;
        }

        if (property_exists($value, '@value'))
        {
            $valueProfile['@type'] = null;
            $valueProfile['typeLang'] = '@null';

            if (property_exists($value, '@type'))
            {
                $valueProfile['@type'] = $value->{'@type'};
                $valueProfile['typeLang'] = '@type';
            }
            elseif (property_exists($value, '@language'))
            {
                $valueProfile['@language'] = $value->{'@language'};
                $valueProfile['typeLang'] = '@language';
                $valueProfile['@type'] = null;

                if (false === property_exists($value, '@annotation'))
                {
                    $valueProfile['@container'] = '@language';
                }
            }
            elseif (is_string($value->{'@value'}))
            {
                $valueProfile['@language'] = '@null';
                $valueProfile['typeLang'] = '@language';
            }

            return $valueProfile;
        }

        if (property_exists($value, '@list'))
        {
            // It will only recurse one level deep as list of lists are not allowed
            $len = count($value->{'@list'});

            if ($len > 0)
            {
                $valueProfile = $this->getValueProfile($value->{'@list'}[0]);
            }

            if (false === property_exists($value, '@annotation'))
            {
                $valueProfile['@container'] = '@list';
            }


            for ($i = $len - 1; $i > 0; $i--)
            {
                $profile = $this->getValueProfile($value->{'@list'}[$i]);

                if (($valueProfile['@type'] !== $profile['@type']) ||
                    ($valueProfile['@language'] !== $profile['@language']))
                {
                    $valueProfile['@type'] = null;
                    $valueProfile['@language'] = null;
                    $valueProfile['typeLang'] = '@null';

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
     * @param array   $inversectxFrag  The inverse context (or a subtree thereof)
     * @param array   $path            The query corresponding to the value profile
     * @param bool    $propGens        Return property generators or not?
     * @param string  $defaultLanguage If available the default language.
     * @param integer $level           The recursion depth.
     *
     * @return null|string|string[] If the IRI maps to one or more property generators
     *                              their terms plus (if available) a term matching the
     *                              IRI that isn't a property generator will be returned;
     *                              if the IRI doesn't map to a property generator but just
     *                              to terms, the best matching term will be returned;
     *                              otherwise null will be returned.
     */
    private function queryInverseContext($inversectxFrag, $path, $propGens = true, $defaultLanguage = null, $level = 0)
    {
        if (3 === $level)
        {
            if ($propGens && isset($inversectxFrag['propGens']))
            {
                // TODO Also return first matching term as fallback
                return $inversectxFrag['propGens'];
            }
            elseif (isset($inversectxFrag['term']))
            {
                return $inversectxFrag['term'];
            }

            return null;
        }

        if (isset($inversectxFrag[$path[$level]]))
        {
            $result = $this->queryInverseContext($inversectxFrag[$path[$level]], $path, $propGens, $defaultLanguage, $level + 1);
            if (null !== $result)
            {
                return $result;
            }
        }

        // Fall back to @set (for everything but @list) and then @null
        if ('@null' !== $path[$level])
        {
            if ((0 === $level) && ('@list' !== $path[$level]) && isset($inversectxFrag['@set']))
            {
                $result = $this->queryInverseContext($inversectxFrag['@set'], $path, $propGens, $defaultLanguage, $level + 1);
                if (null !== $result)
                {
                    return $result;
                }
            }

            if (isset($inversectxFrag['@null']))
            {
                return $this->queryInverseContext($inversectxFrag['@null'], $path, $propGens, $defaultLanguage, $level + 1);
            }
        }

        return null;
    }

    /**
     * Returns a property's definition
     *
     * The result will be in the form
     * <code>
     *   array('@type'      => type or null,
     *         '@language'  => language or null,
     *         '@container' => container or null,
     *         'isKeyword'  => true or false)
     * </code>
     *
     * If {@link $only} is set, only the value of that key of the array
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
        if (in_array($property, self::$keywords))
        {
            $result = array();
            if (('@id' == $property) || ('@type' == $property) || ('@graph' == $property))
            {
                $result['@type'] = '@id';
            }

            $result['@language'] = null;
            $result['@annotation'] = null;
            $result['@container'] = null;
            $result['isKeyword'] = true;

            return $result;
        }


        $result = array('@type'      => null,
                        '@language'  => (isset($activectx['@language']))
                            ? $activectx['@language']
                            : null,
                        '@annotation' => null,
                        '@container' => null,
                        'isKeyword'  => false);
        $def = null;

        if (isset($activectx['@propertyGenerators'][$property]))
        {
            $def = $activectx['@propertyGenerators'][$property];
        }
        elseif (isset($activectx[$property]))
        {
            $def = $activectx[$property];
        }
        else
        {
            return $result;
        }

        if (isset($def['@type']))
        {
            $result['@type'] = $def['@type'];
            $result['@language'] = null;
        }
        elseif (array_key_exists('@language', $def))  // could be null
        {
            $result['@language'] = $def['@language'];
        }

        if (isset($def['@container']))
        {
            $result['@container'] = $def['@container'];
        }


        if ($only)
        {
            return (isset($result[$only])) ? $result[$only] : null;
        }

        return $result;
    }

    /**
     * Processes a local context to update the active context
     *
     * @param mixed  $loclctx    The local context.
     * @param array  $activectx  The active context.
     *
     * @throws ProcessException If processing of the context failed.
     * @throws ParseException   If a remote context couldn't be processed.
     */
    public function processContext($loclctx, &$activectx)
    {
        // Initialize variable
        $activectxKey = null;

        if (is_object($loclctx))
        {
            $loclctx = clone $loclctx;
        }

        if (false == is_array($loclctx))
        {
            $loclctx = array($loclctx);
        }

        foreach ($loclctx as $context)
        {
            if (is_null($context))
            {
                $activectx = array();
            }
            elseif (is_object($context))
            {
                if (isset($context->{'@vocab'}))
                {
                    if ((false === is_string($context->{'@vocab'})) || (false === strpos($context->{'@vocab'}, ':')))
                    {
                        throw new SyntaxException(
                            "The value of @vocab must be an absolute IRI.",
                            $context);
                    }

                    $activectx['@vocab'] = $context->{'@vocab'};
                    unset($context->{'@vocab'});
                }

                foreach ($context as $key => $value)
                {
                    if (is_null($value))
                    {
                        unset($activectx[$key]);
                        continue;
                    }

                    if ('@language' == $key)
                    {
                        if (false == is_string($value))
                        {
                            throw new SyntaxException(
                                'The value of @language must be a string.',
                                $context);
                        }

                        $activectx[$key] = $value;
                        continue;
                    }

                    if (in_array($key, self::$keywords))
                    {
                        // Keywords can't be altered
                        continue;
                    }

                    if (is_string($value))
                    {
                        $expanded = $this->doExpandIri($value, $activectx, false, true, $context);

                        if ((false === in_array($expanded, self::$keywords)) && (false === strpos($expanded, ':')))
                        {
                            throw new SyntaxException("Failed to expand $expanded to an absolute IRI.",
                                                      $loclctx);
                        }

                        $context->{$key} = $expanded;
                        $activectx[$key] = array('@id' => $expanded);
                    }
                    elseif (is_object($value))
                    {
                        unset($activectx[$key]);  // delete previous definition
                        $context->{$key} = clone $context->{$key};  // make sure we don't modify the passed context

                        $expanded = null;

                        if (isset($value->{'@id'}))
                        {
                            if (is_array($value->{'@id'}))  // is it a property generator?
                            {
                                $expanded = array();

                                foreach ($value->{'@id'} as $item)
                                {
                                    $result = $this->doExpandIri($item, $activectx, false, true, $context);

                                    if (false === strpos($result, ':'))
                                    {
                                        throw new SyntaxException("\"$item\" in \"$key\" couldn't be expanded to an absolute IRI.",
                                                                  $loclctx);
                                    }

                                    $expanded[] =  $result;
                                }

                                sort($expanded);
                            }
                            else
                            {
                                $expanded = $this->doExpandIri($value->{'@id'}, $activectx, false, true, $context);
                            }
                        }
                        else
                        {
                            $expanded = $this->doExpandIri($key, $activectx, false, true, $context);
                        }

                        // Keep a reference to the place were we store the information. Property
                        // generators are stored in a separate subtree in the active context
                        if (is_array($expanded))
                        {
                            // and are removed from the local context as they can't be used
                            // in other term definitions
                            unset($context->{$key});
                            $activectxKey = &$activectx['@propertyGenerators'][$key];
                        }
                        else
                        {
                            $context->{$key}->{'@id'} = $expanded;
                            $activectxKey = &$activectx[$key];

                            if (in_array($expanded, self::$keywords))
                            {
                                // if it's an aliased keyword, we ignore all other properties
                                // TODO Should we throw an exception if there are other properties?
                                $activectxKey = array('@id' => $expanded);
                                continue;
                            }
                            elseif (false === strpos($expanded, ':'))
                            {
                                throw new SyntaxException("Failed to expand \"$key\" to an absolute IRI.",
                                                          $loclctx);
                            }
                        }

                        $activectxKey = array('@id' => $expanded);

                        if (isset($value->{'@type'}))
                        {
                            $expanded = $this->doExpandIri($value->{'@type'}, $activectx, false, true, $context);

                            if (('@id' != $expanded) && (false === strpos($expanded, ':')))
                            {
                                throw new SyntaxException("Failed to expand $expanded to an absolute IRI.",
                                                          $loclctx);
                            }

                            if (property_exists($context, $key)) // otherwise it's a property generator
                            {
                                $context->{$key}->{'@type'} = $expanded;
                            }
                            $activectxKey['@type'] = $expanded;

                            // TODO Throw exception if language is set as well?
                        }
                        elseif (property_exists($value, '@language'))
                        {
                            if ((false == is_string($value->{'@language'})) && (false == is_null($value->{'@language'})))
                            {
                                throw new SyntaxException(
                                    'The value of @language must be a string.',
                                    $context);
                            }

                            // Note the else. Language tagging applies just to term without type coercion
                            $activectxKey['@language'] = $value->{'@language'};
                        }

                        if (isset($value->{'@container'}))
                        {
                            if (in_array($value->{'@container'}, array('@list', '@set', '@language', '@annotation')))
                            {
                                $activectxKey['@container'] = $value->{'@container'};
                            }
                        }
                    }
                }
            }
            else
            {
                // TODO Detect recursive context imports
                $remoteContext = JsonLD::parse((string)$this->baseIri->resolve($context));

                if (is_object($remoteContext) && property_exists($remoteContext, '@context'))
                {
                    $this->processContext($remoteContext->{'@context'}, $activectx);
                }
                else
                {
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
     * @param  array $activectx The active context.
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
        foreach ($activectx as $term => $def)
        {
            $container = (isset($def['@container'])) ? $def['@container'] : '@null';
            $propertyGenerator = 'propGens';  // yes

            if (false === is_array($def['@id']))
            {
                $propertyGenerator = 'term';  // no
                $inverseContext[$def['@id']]['term'][] = $term;

                $def['@id'] = array($def['@id']);
            }

            foreach ($def['@id'] as $iri)
            {
                if (isset($def['@type']))
                {
                    $inverseContext[$iri][$container]['@type'][$def['@type']][$propertyGenerator][] = $term;
                }
                elseif (array_key_exists('@language', $def))  // can be null
                {
                    $language = (null === $def['@language']) ? '@null' : $def['@language'];

                    $inverseContext[$iri][$container]['@language'][$language][$propertyGenerator][] = $term;
                }
                else
                {
                    // Every untyped term is implicitly set to the default language
                    $inverseContext[$iri][$container]['@language'][$defaultLanguage][$propertyGenerator][] = $term;

                    $inverseContext[$iri][$container]['@null']['@null'][$propertyGenerator][] = $term;
                }
            }
        }

        // Then sort the terms and eliminate all except the lexicographically least;
        // do the same for property generators but only eliminate those expanding
        // to the same IRIs
        foreach ($inverseContext as &$containerBucket)
        {
            foreach ($containerBucket as $container => &$typeLangBucket)
            {
                if ('term' === $container)
                {
                    usort($typeLangBucket, array($this, 'sortTerms'));
                    $typeLangBucket = $typeLangBucket[0];

                    continue;
                }

                foreach ($typeLangBucket as $key => &$values)
                {
                    foreach ($values as &$termBuckets)
                    {
                        if (isset($termBuckets['term']))
                        {
                            usort($termBuckets['term'], array($this, 'sortTerms'));

                            $termBuckets['term'] = $termBuckets['term'][0];
                        }

                        if (isset($termBuckets['propGens']))
                        {
                            usort($termBuckets['propGens'], array($this, 'sortTerms'));
                            $len = count($termBuckets['propGens']);

                            for ($j = count($termBuckets['propGens']) - 1; $j > 0; $j--)
                            {
                                for ($i = $j - 1; $i >= 0; $i--)
                                {
                                    if ($activectx[$termBuckets['propGens'][$i]] === $activectx[$termBuckets['propGens'][$j]])
                                    {
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
     * @param object  $nodeMap    The object holding the node map.
     * @param mixed   $element    A JSON-LD element to be flattened.
     * @param string  $parent     The property referencing the passed element.
     * @param boolean $list       Is a list being processed?
     * @param boolean $iriKeyword If set to true, strings are interpreted as IRI.
     * @param string  $graph      The current graph; @default for the default graph.
     */
    private function createNodeMap(&$nodeMap, $element, &$parent = null, $list = false, $iriKeyword = false, $graph = '@default')
    {
        // TODO Make sure all objects are cloned!

        if (is_array($element))
        {
            foreach ($element as $item)
            {
                $this->createNodeMap($nodeMap, $item, $parent, $list, $iriKeyword, $graph);
            }

            return;
        }

        if (is_object($element) && (false === property_exists($element, '@value')))
        {
            // Handle lists
            if (property_exists($element, '@list'))
            {
                $flattenedList = new \stdClass();
                $flattenedList->{'@list'} = array();

                $this->createNodeMap($nodeMap, $element->{'@list'}, $flattenedList->{'@list'}, true, false, $graph);

                $parent[] = $flattenedList;

                return;
            }

            // TODO: Really create bnode for empty objects??

            $id = null;
            if (property_exists($element, '@id'))
            {
                $id = $element->{'@id'};
            }

            // if no @id was found or if it was a blank node and we are not currently
            // merging graphs, assign a new identifier to avoid collisions
            if ((null === $id) || (('@merged' != $graph) && (0 === strncmp($id, '_:', 2))))
            {
                $id = $this->getBlankNodeId($id);
            }

            if (null !== $parent)
            {
                $node = new \stdClass();
                $node->{'@id'} = $id;

                // Just add the node reference if it isn't there yet or it is a list
                if ((true === $list) || (false == in_array($node, $parent)))
                {
                    // TODO In array is not enough as the comparison is not strict enough
                    // "1" and 1 are considered to be the same.
                    $parent[] = $node;
                }
            }

            $node = null;
            if (isset($nodeMap->{$graph}->{$id}))
            {
                $node = $nodeMap->{$graph}->{$id};
            }
            else
            {
                if (false == isset($nodeMap->{$graph}))
                {
                    $nodeMap->{$graph} = new \stdClass();
                }

                $node = new \stdClass();
                $node->{'@id'} = $id;

                $nodeMap->{$graph}->{$id} = $node;
            }


            $properties = get_object_vars($element);
            ksort($properties);

            foreach ($properties as $property => $value)
            {
                if ('@id' === $property)
                {
                    continue;  // we handled @id already
                }

                if ('@type' === $property)
                {
                    $node->{$property} = array();
                    $this->createNodeMap($nodeMap, $value, $node->{$property}, false, true, $graph);

                    continue;
                }

                if ('@graph' === $property)
                {
                    // TODO We don't need a list of nodes in that graph, do we?
                    $null = null;
                    $this->createNodeMap($nodeMap, $value, $null, false, false, $id);

                    continue;
                }

                if (in_array($property, self::$keywords))
                {
                    // Check this! Blank nodes in keywords handled wrong!?
                    self::mergeIntoProperty($node, $property, $value, true, true);
                }
                else
                {
                    if (false === isset($node->{$property}))
                    {
                        $node->{$property} = array();
                    }

                    $this->createNodeMap($nodeMap, $value, $node->{$property}, false, false, $graph);
                }
            }
        }
        else
        {
            // If it's the value is for a keyword which is interpreted as an IRI and the value
            // is a string representing a blank node, re-map it to prevent collisions
            if ((true === $iriKeyword) && is_string($element) && ('@merged' != $graph) && (0 === strncmp($element, '_:', 2)))
            {
                $element = $this->getBlankNodeId($element);
            }

            // If it's not a list, make sure that the value is unique
            if ((false === $list) && (true == in_array($element, $parent)))
            {
                // TODO In array is not enough as the comparison is not strict enough
                // "1" and 1 are considered to be the same.
                return;
            }

            // Element wasn't found, add it
            $parent[] = $element;
        }
    }

    /**
     * Merges the node maps of all graphs in the passed node map into a new
     * <code>@merged</code> node map.
     *
     * @param object $nodeMap The node map whose different graphs should be
     *                        merged into one.
     */
    private function mergeNodeMapGraphs(&$nodeMap)
    {
        $graphs = array_keys((array) $nodeMap);
        foreach ($graphs as $graph)
        {
            $nodes = array_keys((array) $nodeMap->{$graph});
            foreach ($nodes as $node)
            {
                $parent = null;
                $this->createNodeMap($nodeMap, $nodeMap->{$graph}->{$node}, $parent, false, false, '@merged');
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
        if ((null !== $id) && isset($this->blankNodeMap[$id]))
        {
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
     * @param string $graph   The graph whose flattened node definitions should
     *                        be returned. The default graph is identified by
     *                        <code>@default</code> and the merged graph by
     *                        <code>@merged</code>.
     *
     * @return array An array of the flattened node definitions of the specified graph.
     */
    public function flatten($element, $graph = '@merged')
    {
        $nodeMap = new \stdClass();
        $this->createNodeMap($nodeMap, $element);

        if ('@merged' === $graph)
        {
            $this->mergeNodeMapGraphs($nodeMap);
        }

        $flattened = array();

        if (property_exists($nodeMap, $graph))
        {
            foreach ($nodeMap->{$graph} as $value)
            {
                $flattened[] = $value;
            }
        }

        return $flattened;
    }

    /**
     * Converts a JSON-LD document to quads
     *
     * The result is an array of arrays each containing a quad:
     *
     * <code>
     * array(
     *   array(id, property, value, graph)
     * )
     * </code>
     *
     * @param mixed   $element    A JSON-LD element to be transformed into quads.
     * @param array   $result     The resulting quads.
     * @param array   $activesubj The active subject.
     * @param string  $activeprty The active property.
     * @param string  $graph      The graph currently being processed.
     *
     * @return array The extracted quads.
     */
    public function toQuads(&$element, &$result, $activesubj = null, $activeprty = null, $graph = null)
    {
        if (is_array($element))
        {
            foreach ($element as &$item)
            {
                $this->toQuads($item, $result, $activesubj, $activeprty, $graph);
            }

            return;
        }

        if (property_exists($element, '@value'))
        {
            $value = Value::fromJsonLd($element);
            $result[] = new Quad($activesubj, $activeprty, $value, $graph);

            return;
        }
        elseif (property_exists($element, '@list'))
        {
            if (0 === ($len = count($element->{'@list'})))
            {
                $result[] = new Quad($activesubj, $activeprty, new IRI(RdfConstants::RDF_NIL), $graph);

                return;
            }

            $first_bn = new IRI($this->getBlankNodeId());
            $result[] = new Quad($activesubj, $activeprty, $first_bn, $graph);

            $i = 0;
            while ($i < $len)
            {
                $this->toQuads($element->{'@list'}[$i], $result, $first_bn, new IRI(RdfConstants::RDF_FIRST), $graph);

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
        if (property_exists($element, '@id'))
        {
            $activesubj = $element->{'@id'};

            if (0 === strncmp($activesubj, '_:', 2))
            {
                $activesubj = $this->getBlankNodeId($activesubj);
            }

            unset($element->{'@id'});
        }
        else
        {
            $activesubj = $this->getBlankNodeId();
        }

        $activesubj = new IRI($activesubj);

        if ($prevsubj)
        {
            $result[] = new Quad($prevsubj, $activeprty, $activesubj, $graph);;
        }

        $properties = get_object_vars($element);
        ksort($properties);

        foreach ($properties as $property => $value)
        {
            if ('@type' === $property)
            {
                foreach ($value as $val)
                {
                    $result[] = new Quad($activesubj, new IRI(RdfConstants::RDF_TYPE), new IRI($val), $graph);
                }
                continue;
            }
            elseif ('@graph' === $property)
            {
                $this->toQuads($value, $result, null, null, $activesubj);
                continue;
            }
            elseif (in_array($property, self::$keywords))
            {
                continue;
            }

            $activeprty = new IRI($property);

            $this->toQuads($value, $result, $activesubj, $activeprty, $graph);
        }
    }

    /**
     * Converts an array of quads to a JSON-LD document
     *
     * The resulting JSON-LD document will be in expanded form.
     *
     * @param Quad[] $quads The quads to convert
     *
     * @return array The JSON-LD document.
     *
     * @throws InvalidQuadException If the quad is invalid.
     */
    public function fromQuads(array $quads)
    {
        $graphs = array();
        $graphs['@default'] = new \stdClass();
        $graphs['@default']->nodeMap = array();
        $graphs['@default']->listMap = array();

        foreach ($quads as $quad)
        {
            $graphName = '@default';

            if ($quad->getGraph())
            {
                $graphName = (string)$quad->getGraph();

                // Add a reference to this graph to the default graph if it
                // doesn't exist yet
                if (false === isset($graphs['@default']->nodeMap[$graphName]))
                {
                    $graphs['@default']->nodeMap[$graphName] =
                        self::objectToJsonLd($quad->getGraph());
                }
            }

            if (false === isset($graphs[$graphName]))
            {
                $graphs[$graphName] = new \stdClass();
                $graphs[$graphName]->nodeMap = array();
                $graphs[$graphName]->listMap = array();
            }
            $graph = $graphs[$graphName];

            // Subjects and properties are always IRIs (blank nodes are IRIs
            // as well): convert them to a string representation
            $subject = (string)$quad->getSubject();
            $property = (string)$quad->getProperty();
            $object = $quad->getObject();

            // All list nodes are stored in listMap
            if ($property === RdfConstants::RDF_FIRST)
            {
                if (false === isset($graph->listMap[$subject]))
                {
                    $graph->listMap[$subject] = new \stdClass();
                }

                $graph->listMap[$subject]->first =
                    self::objectToJsonLd($object, $this->useNativeTypes);

                continue;
            }

            if ($property === RdfConstants::RDF_REST)
            {
                if (false === ($object instanceof IRI))
                {
                    throw new InvalidQuadException(
                        'The value of rdf:rest must be an IRI or blank node.',
                        $quad
                    );
                }

                if (false === isset($graph->listMap[$subject]))
                {
                    $graph->listMap[$subject] = new \stdClass();
                }

                $graph->listMap[$subject]->rest = (string)$object;

                continue;
            }


            // All other nodes (not list nodes) are stored in nodeMap
            if (false === isset($graph->nodeMap[$subject]))
            {
                $graph->nodeMap[$subject] =
                    self::objectToJsonLd($quad->getSubject());
            }
            $node = $graph->nodeMap[$subject];


            if (($property === RdfConstants::RDF_TYPE) && (false === $this->useRdfType))
            {
                if (false === ($object instanceof IRI))
                {
                    throw new InvalidQuadException(
                        'The value of rdf:type must be an IRI.',
                        $quad);
                }

                self::mergeIntoProperty($node, '@type', (string)$object, true);
            }
            else
            {
                $value = self::objectToJsonLd($object, $this->useNativeTypes);
                self::mergeIntoProperty($node, $property, $value, true);

                // If the object is an IRI or blank node it might be the
                // beginning of a list. Add it to the list map storing a
                // reference to the value in the nodeMap in the entry's
                // "head" property so that we can easily replace it with an
                //  @list object if it turns out to be really a list
                if (property_exists($value, '@id'))
                {
                    $id = $value->{'@id'};
                    if (false === isset($graph->listMap[$id]))
                    {
                        $graph->listMap[$id] = new \stdClass();
                    }

                    $graph->listMap[$id]->head = $value;
                }
            }
        }


        // Reconstruct @list arrays from linked list structures for each graph
        foreach ($graphs as $graphName => $graph)
        {
            foreach ($graph->listMap as $subject => $entry)
            {
                // If this node is a valid list head...
                if (property_exists($entry, 'head') &&
                    property_exists($entry, 'first'))
                {
                    $id = $subject;
                    $value = $entry->head;

                    // ... reconstruct the list
                    $list = array();

                    do
                    {
                        if (false === isset($graph->listMap[$id]))
                        {
                            throw new ProcessException(sprintf(
                                'Invalid RDF list reference. "%s" doesn\'t exist.',
                                $id)
                            );
                        }

                        $entry = $graph->listMap[$id];

                        if (false === property_exists($entry, 'first'))
                        {
                            throw new ProcessException(sprintf(
                                'Invalid RDF list entry: rdf:first of "%s" missing.',
                                $id)
                            );
                        }
                        if (false === property_exists($entry, 'rest'))
                        {
                            throw new ProcessException(sprintf(
                                'Invalid RDF list entry: rdf:rest of "%s" missing.',
                                $id)
                            );
                        }

                        $list[] = $entry->first;

                        $id = $entry->rest;
                    }
                    while (RdfConstants::RDF_NIL !== $id);

                    // and replace the object in the nodeMap with the list
                    unset($value->{'@id'});
                    $value->{'@list'} = $list;
                }
            }
        }


        // Generate the resulting document starting with the default graph
        $document = array();

        $nodes = $graphs['@default']->nodeMap;
        ksort($nodes);

        foreach ($nodes as $id => $node)
        {
            $document[] = $node;

            if (isset($graphs[$id]))  // is this a named graph?
            {
                $node->{'@graph'} = array();

                $graphNodes = $graphs[$id]->nodeMap;
                ksort($nodes);

                foreach ($graphNodes as $gnId => $graphNode)
                {
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
        if ((false == is_array($frame)) || (1 != count($frame)) || (false == is_object($frame[0])))
        {
            throw new SyntaxException('The frame is invalid. It must be a single object.',
                                      $frame);
        }

        $frame = $frame[0];

        $options = new \stdClass();
        $options->{'@embed'} = true;
        $options->{'@embedChildren'} = true;   // TODO Change this as soon as the tests haven been updated

        foreach (self::$framingKeywords as $keyword)
        {
            if (property_exists($frame, $keyword))
            {
                $options->{$keyword} = $frame->{$keyword};
                unset($frame->{$keyword});
            }
            elseif (false == property_exists($options, $keyword))
            {
                $options->{$keyword} = false;
            }
        }

        $procOptions = new \stdClass();
        $procOptions->base = (string)$this->baseIri;
        $procOptions->compactArrays = $this->compactArrays;
        $procOptions->optimize = $this->optimize;
        $procOptions->useNativeTypes = $this->useNativeTypes;
        $procOptions->useRdfType = $this->useRdfType;

        $processor = new Processor($procOptions);

        $nodeMap = new \stdClass();
        $processor->createNodeMap($nodeMap, $element);

        $graph = '@merged';
        if (property_exists($frame, '@graph'))
        {
            $graph = '@default';
        }
        else
        {
            // We need the merged graph, create it
            $processor->mergeNodeMapGraphs($nodeMap);
        }

        unset($processor);

        $result = array();

        foreach ($nodeMap->{$graph} as $node)
        {
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
        if (false == is_null($frame))
        {
            $filter = get_object_vars($frame);
        }

        $result = new \stdClass();

        // Make sure that @id is always in the result if the node matches the filter
        if (property_exists($node, '@id'))
        {
            $result->{'@id'} = $node->{'@id'};

            if (is_null($filter) && in_array($node->{'@id'}, $path))
            {
                $parent[] = $result;

                return true;
            }

            $path[] = $node->{'@id'};
        }

        // If no filter is specified, simply return the passed node - {} is a wildcard
        if (is_null($filter) || (0 === count($filter)))
        {
            // TODO What effect should @explicit have with a wildcard match?
            if (is_object($node))
            {
                if ((true == $options->{'@embed'}) || (false == property_exists($node, '@id')))
                {
                    $this->addMissingNodeProperties($node, $options, $nodeMap, $graph, $result, $path);
                }

                $parent[] = $result;
            }
            else
            {
                $parent[] = $node;
            }

            return true;
        }

        foreach ($filter as $property => $validValues)
        {
            if (is_array($validValues) && (0 === count($validValues)))
            {
                if (property_exists($node, $property) ||
                    (('@graph' == $property) && isset($result->{'@id'}) && property_exists($nodeMap, $result->{'@id'})))
                {
                    return false;  // [] says that the property must not exist but it does
                }

                continue;
            }

            if (false == property_exists($node, $property))
            {
                // The property does not exist, check if it's @graph and the referenced graph exists
                if ('@graph' == $property)
                {
                    if (isset($result->{'@id'}) && property_exists($nodeMap, $result->{'@id'}))
                    {
                        $result->{'@graph'} = array();
                        $match = false;

                        foreach ($nodeMap->{$result->{'@id'}} as $item)
                        {
                            foreach ($validValues as $validValue)
                            {
                                $match |= $this->nodeMatchesFrame($item, $validValue, $options, $nodeMap, $result->{'@id'}, $result->{'@graph'});
                            }
                        }

                        if (false == $match)
                        {
                            return false;
                        }
                        else
                        {
                            continue;  // with next property
                        }
                    }
                    else
                    {
                        // the referenced graph doesn't exist
                        return false;
                    }
                }

                // otherwise, look if we have a default value for it
                if (false == is_array($validValues))
                {
                    $validValues = array($validValues);
                }

                $defaultFound = false;
                foreach ($validValues as $validValue)
                {
                    if (is_object($validValue) && property_exists($validValue, '@default'))
                    {
                        if (is_null($validValue->{'@default'}))
                        {
                            $result->{$property} = new \stdClass();
                            $result->{$property}->{'@null'} = true;
                        }
                        else
                        {
                            $result->{$property} = $validValue->{'@default'};
                        }
                        $defaultFound = true;
                        break;
                    }
                }

                if (true == $defaultFound)
                {
                    continue;
                }

                return false;  // required property does not exist and no default value was found
            }

            // Check whether the values of the property match the filter
            $match = false;
            $result->{$property} = array();

            if (false == is_array($validValues))
            {
                if ($node->{$property} === $validValues)
                {
                    $result->{$property} = $node->{$property};
                    continue;
                }
                else
                {
                    return false;
                }
            }

            foreach ($validValues as $validValue)
            {
                if (is_object($validValue))
                {
                    // Extract framing options from subframe ($validValue is a subframe)
                    $newOptions = clone $options;
                    unset($newOptions->{'@default'});

                    foreach (self::$framingKeywords as $keyword)
                    {
                        if (property_exists($validValue, $keyword))
                        {
                            $newOptions->{$keyword} = $validValue->{$keyword};
                            unset($validValue->{$keyword});
                        }
                    }

                    $nodeValues = $node->{$property};
                    if (false == is_array($nodeValues))
                    {
                        $nodeValues = array($nodeValues);
                    }

                    foreach ($nodeValues as $value)
                    {
                        if (is_object($value) && property_exists($value, '@id'))
                        {
                            $match |= $this->nodeMatchesFrame($nodeMap->{$graph}->{$value->{'@id'}},
                                                              $validValue,
                                                              $newOptions,
                                                              $nodeMap,
                                                              $graph,
                                                              $result->{$property},
                                                              $path);
                        }
                        else
                        {
                            $match |= $this->nodeMatchesFrame($value, $validValue, $newOptions, $nodeMap, $graph, $result->{$property}, $path);
                        }
                    }
                }
                elseif (is_array($validValue))
                {
                    throw new SyntaxException('Invalid frame detected. Property "' . $property .
                                              '" must not be an array of arrays.', $frame);
                }
                else
                {
                    // This will just catch non-expanded IRIs for @id and @type
                    $nodeValues = $node->{$property};
                    if (false == is_array($nodeValues))
                    {
                        $nodeValues = array($nodeValues);
                    }

                    if (in_array($validValue, $nodeValues))
                    {
                        $match = true;
                        $result->{$property} = $node->{$property};
                    }
                }
            }

            if (false == $match)
            {
                return false;
            }
        }

        // Discard subtree if this object should not be embedded
        if ((false == $options->{'@embed'}) && property_exists($node, '@id'))
        {
            $result = new \stdClass();
            $result->{'@id'} = $node->{'@id'};
            $parent[] = $result;

            return true;
        }

        // all properties matched the filter, add the properties of the
        // node which haven't been added yet
        if (false == $options->{'@explicit'})
        {
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
        foreach ($node as $property => $value)
        {
            if (property_exists($result, $property))
            {
                continue; // property has already been added
            }

            if (true == $options->{'@embedChildren'})
            {
                if (false == is_array($value))
                {
                    // TODO In @type this could be node reference, how should that be handled?
                    $result->{$property} = $value;
                    continue;
                }

                $result->{$property} = array();
                foreach ($value as $item)
                {
                    if (is_object($item))
                    {
                        if (property_exists($item, '@id'))
                        {
                            $item = $nodeMap->{$graph}->{$item->{'@id'}};
                        }

                        $this->nodeMatchesFrame($item, null, $options, $nodeMap, $graph, $result->{$property}, $path);
                    }
                    else
                    {
                        $result->{$property}[] = $item;
                    }
                }

            }
            else
            {
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
        if (property_exists($object, $property))
        {
            throw new SyntaxException(
                "Object already contains a property \"$property\".",
                $object);
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
        if (property_exists($object, $property))
        {
            // No need to add a null value
            if (is_null($value))
            {
                return;
            }

            if (false === is_array($object->{$property}))
            {
                $object->{$property} = array($object->{$property});
            }

            if ($unique)
            {
                foreach ($object->{$property} as $item)
                {
                    // TODO Check if this check is enough to check equivalence
                    if ($value == $item)
                    {
                        return;
                    }
                }
            }

            if (false == is_array($value))
            {
                $object->{$property}[] = $value;
            }
            else
            {
                $object->{$property} = array_merge($object->{$property}, $value);
            }
        }
        else
        {
            if ((true == $alwaysArray) && (false == is_array($value)))
            {
                $object->{$property} = array();

                if (false == is_null($value))
                {
                    $object->{$property}[] = $value;
                }
            }
            else
            {
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

        if ($lenA < $lenB)
        {
            return -1;
        }
        elseif ($lenA == $lenB)
        {
            if ($a == $b)
            {
                return 0;
            }
            return ($a < $b) ? -1 : 1;
        }
        else
        {
            return 1;
        }
    }

    /**
     * Converts an object to a JSON-LD representation
     *
     * Only {@link IRI IRIs}, {@LanguageTaggedString language-tagged strings},
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
        if ($object instanceof IRI)
        {
            $iri = (string)$object;
            $result = new \stdClass();

            // rdf:nil represents the end of a list and is at the same
            // time used to represent empty lists
            if (RdfConstants::RDF_NIL === $iri)
            {
                $result->{'@list'} = array();

                return $result;
            }

            $result->{'@id'} = $iri;

            return $result;
        }
        elseif ($object instanceof Value)
        {
            return $object->toJsonLd($useNativeTypes);
        }

        return $object;
    }
}
