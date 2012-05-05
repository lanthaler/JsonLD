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
                                     '@container', '@list', '@set', '@graph');

    /** The base IRI */
    private $baseiri = null;

    /**
     * Adds a property to an object if it doesn't exist yet
     *
     * If the property already exists, an exception is thrown as the existing
     * value would be lost.
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
                "Colliding \"$property\" properties detected.",
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
     */
    private static function mergeIntoProperty(&$object, $property, $value, $alwaysArray = false)
    {
        if (property_exists($object, $property))
        {
            if (false === is_array($object->{$property}))
            {
                $object->{$property} = array($object->{$property});
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
                $object->{$property} = array($value);
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
     * If two strings have different lenghts, the shorter one will be
     * considered less than the other. If they have the same lenght, they
     * are compared lexicographically.
     *
     * @param mixed $a Value A.
     * @param mixed $a Value B.
     *
     * @return int If value A is shorter than value B, -1 will be returned; if it's
     *             longer 1 will be returned. If both values have the same lenght
     *             and value A is considered lexicographically less, -1 will be
     *             returned, if they are equal 0 will be returned, otherwise 1
     *             will be returned.
     */
    private static function compare($a, $b)
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
     * Constructor
     *
     * @param string $baseiri The base IRI.
     */
    public function __construct($baseiri = null)
    {
        $this->baseiri = $baseiri;
    }

    /**
     * Parses a JSON-LD document to a PHP value
     *
     * @param  string $document A JSON-LD document.
     *
     * @return mixed  A PHP value.
     *
     * @throws ParseException If the JSON-LD document is not valid.
     */
    public function parse($document)
    {
        if (function_exists('mb_detect_encoding') &&
            (false === mb_detect_encoding($document, 'UTF-8', true)))
        {
            throw new ParseException('The JSON-LD document does not appear to be valid UTF-8.');
        }

        $error = null;
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
            default:
                throw new ParseException('Unknown error while parsing JSON.');
                break;
        }

        return (empty($data)) ? null : $data;
    }

    /**
     * Expands a JSON-LD document
     *
     * @param mixed  $element    A JSON-LD element to be expanded.
     * @param array  $activectx  The active context.
     * @param string $activeprty The active property.
     *
     * @return mixed The expanded document.
     *
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If the expansion failed.
     * @throws ParseException   If a remote context couldn't be processed.
     */
    public function expand(&$element, $activectx = array(), $activeprty = null)
    {
        if (is_array($element))
        {
            $result = array();
            foreach($element as &$item)
            {
                $this->expand($item, $activectx, $activeprty);

                // Check for lists of lists
                if ((isset($activectx[$activeprty]['@container']) &&
                     ('@list' == $activectx[$activeprty]['@container'])) ||
                    ('@list' == $activeprty))
                {
                    if (is_array($item) || (is_object($item) && property_exists($item, '@list')))
                    {
                        throw new SyntaxException(
                            "List of lists detected in property \"$activeprty\".",
                            $element);
                    }
                }
                if (false === is_null($item))
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

        if (false == is_object($element))
        {
            $element = $this->expandValue($element, $activeprty, $activectx);
            return;
        }

        // $element is an object, try to process local context
        if (property_exists($element, '@context'))
        {
            $this->processContext($element->{'@context'}, $activectx);
            unset($element->{'@context'});
        }

        // Process properties
        $properties = get_object_vars($element);
        foreach ($properties as $property => &$value)
        {   // Remove property from object..
            unset($element->{$property});

            // It will be re-added later using the expanded IRI
            $activeprty = $property;
            $property = $this->expandIri($property, $activectx, false);

            // Remove properties with null values (except @value as we need
            // it to determine what @type means) and all properties that are
            // neither keywords nor valid IRIs (i.e., they don't contain a
            // colon) since we drop unmapped JSON
            if ((is_null($value) && ('@value' != $property)) ||
                ((false === strpos($property, ':')) &&
                 (false == in_array($property, self::$keywords))))
            {
                // TODO Check if this is enough (see ISSUE-56)
                continue;
            }

            if ('@id' == $property)
            {
                if (is_string($value))
                {
                    $value = $this->expandIri($value, $activectx, true);
                    self::setProperty($element, $property, $value);
                    continue;
                }
                else
                {
                    throw new SyntaxException(
                        'Invalid value for @id detected (must be a string).',
                        $element);
                }
            }
            elseif ('@type' == $property)  // TODO Handle @graph here as well
            {
                if (is_string($value))
                {
                    // TODO Check if this expands relative IRI to full absolute IRI if no context passed (in @value objects)
                    $value = $this->expandIri($value, $activectx);
                    self::setProperty($element, $property, $value);
                }
                elseif (is_array($value))
                {
                    $result = array();
                    foreach ($value as $item)
                    {
                        if (false === is_string($item))
                        {
                            throw new SyntaxException(
                                'Invalid value in @type array detected (must be a string).',
                                $value);
                        }
                        $result[] = $this->expandIri($item, $activectx);
                    }

                    // Don't keep empty arrays
                    if (count($result) >= 1)
                    {
                        self::mergeIntoProperty($element, $property, $result, true);
                    }
                }
                else
                {
                    throw new SyntaxException(
                        'Invalid value for @type detected (must be a string or array).',
                        $value);
                }

                continue;
            }
            elseif (('@value' == $property) || ('@language' == $property))
            {
                if (is_object($value) || is_array($value))
                {
                    throw new SyntaxException(
                        "Invalid value for $property detected (must be a scalar).",
                        $value);
                }

                self::setProperty($element, $property, $value);
                continue;
            }
            elseif (('@list' == $property) || ('@set' == $property) || ('@graph' == $property))
            {
                $this->expand($value, $activectx, $property);

                if (false == is_array($value))
                {
                    $value = array($value);
                }

                // @set is optimized away after the whole object has been processed
                self::setProperty($element, $property, $value);
                continue;
            }
            else
            {
                // Expand value
                $this->expand($value, $activectx, $activeprty);

                // ... and re-add it to the object if the expanded value is not null
                if (false == is_null($value))
                {
                    // If property has an @list container, and value is not yet an
                    // expanded @list-object, transform it to one
                    if (isset($activectx[$activeprty]['@container']) &&
                        ('@list' == $activectx[$activeprty]['@container']) &&
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

                    self::mergeIntoProperty($element, $property, $value, true);
                }
            }
        }


        // All properties have been processed. Make sure the result is valid
        // and optimize object where possible
        $numProps = count(get_object_vars($element));

        if (property_exists($element, '@value'))
        {
            if (($numProps > 2) ||
                ((2 == $numProps) &&
                    (false == property_exists($element, '@language')) &&
                    (false == property_exists($element, '@type'))))
            {
                new SyntaxException(
                    'Detected an @value object that contains additional data.',
                    $element);
            }
            elseif (property_exists($element, '@type') && (false == is_string($element->{'@type'})))
            {
                throw new SyntaxException(
                    'Invalid value for @type detected (must be a string).',
                    $element);
            }
            elseif (property_exists($element, '@language') && (false == is_string($element->{'@language'})))
            {
                throw new SyntaxException(
                    'Invalid value for @language detected (must be a string).',
                    $element);
            }
            elseif ((1 == $numProps) || (is_null($element->{'@value'})))
            {
                // object has just an @value property or is null, can be replaced with that value
                $element = $element->{'@value'};
            }

            return;
        }

        // Not an @value object, make sure @type is an array
        if (property_exists($element, '@type') && (false == is_array($element->{'@type'})))
        {

            $element->{'@type'} = array($element->{'@type'});
        }

        if (($numProps > 1) && (property_exists($element, '@list') || property_exists($element, '@set')))
        {
            new SyntaxException(
                'An object with a @list or @set property can\'t contain other properties.',
                $element);
        }
        elseif (property_exists($element, '@set'))
        {
            // @set objects can be optimized away as they are just syntactic sugar
            $element = $element->{'@set'};
        }
        elseif (($numProps == 1) && property_exists($element, '@language'))
        {
            // if there's just @language and nothing else, drop whole object
            $element = null;
        }
    }

    /**
     * Expands a JSON-LD value
     *
     * The value can be of any scalar type (i.e., not an object or array).
     *
     * @param mixed $value      The value to be expanded.
     * @param mixed $activeprty The active property.
     * @param array $activectx  The active context.
     *
     * @return object The expanded value in object form.
     */
    private function expandValue($value, $activeprty, $activectx)
    {
        if (isset($activectx[$activeprty]['@type']))
        {
            if ('@id' == $activectx[$activeprty]['@type'])
            {
                $obj = new \stdClass();
                $obj->{'@id'} = $this->expandIri($value, $activectx, true);
                return $obj;
            }
            else
            {
                $obj = new \stdClass();
                $obj->{'@value'} = $value;
                $obj->{'@type'} = $activectx[$activeprty]['@type'];
                return $obj;
            }
        }

        if (is_string($value))
        {
            $language = null;

            if (isset($activectx[$activeprty]) && array_key_exists('@language', $activectx[$activeprty]))
            {
                $language = $activectx[$activeprty]['@language'];
            }
            elseif (isset($activectx['@language']))
            {
                $language = $activectx['@language'];
            }

            if(isset($language))
            {
                $obj = new \stdClass();
                $obj->{'@value'} = $value;
                $obj->{'@language'} = $language;
                return $obj;
            }
        }

        return $value;
    }

    /**
     * Expands a JSON-LD IRI to an absolute IRI
     *
     * @param mixed  $value        The value to be expanded to an absolute IRI.
     * @param array  $activectx    The active context.
     * @param bool   $relativeIri  Specifies if $value should be treated as
     *                             relative IRI as fallback or not.
     *
     * @return string The expanded IRI.
     */
    private function expandIri($value, $activectx, $relativeIri = false)
    {
        // TODO Handle relative IRIs

        if (array_key_exists($value, $activectx) && isset($activectx[$value]['@id']))
        {
            return $activectx[$value]['@id'];
        }

        if (false !== ($colon = strpos($value, ':')))
        {
            if ('://' == substr($value, $colon, 3))
            {
                // Safety measure to prevent reassigned of, e.g., http://
                return $value;
            }
            else
            {
                $prefix = substr($value, 0, $colon);
                if ('_' == $prefix)
                {
                    // it is a named blank node
                    return $value;
                }
                elseif (array_key_exists($prefix, $activectx) && isset($activectx[$prefix]['@id']))
                {
                    // compact IRI
                    return $activectx[$prefix]['@id'] . substr($value, $colon + 1);
                }
            }
        }
        elseif (true == $relativeIri)
        {
            // TODO Handle relative IRIs properly
            return $this->baseiri . $value;
        }

        // can't expand it, return as is
        return $value;
    }

    /**
     * Compacts a JSON-LD document
     *
     * @param mixed  $element    A JSON-LD element to be compacted.
     * @param array  $activectx  The active context.
     * @param string $activeprty The active property.
     * @param bool   $optimize   If set to true, the JSON-LD processor is allowed optimize
     *                           the passed context to produce even compacter representations.
     *
     * @return mixed The compacted JSON-LD document.
     */
    public function compact(&$element, $activectx = array(), $activeprty = null, $optimize = false)
    {
        if (is_array($element))
        {
            $result = array();
            foreach ($element as &$item)
            {
                $this->compact($item, $activectx, $activeprty, $optimize);
                if (false == is_null($item))
                {
                    $result[] = $item;
                }
            }
            $element = $result;

            // If there's just one entry and that has no @list or @set container,
            // optimize the array away
            if (is_array($element) && (1 == count($element)) &&
                ((false == isset($activectx[$activeprty]['@container'])) ||
                 (('@set' != $activectx[$activeprty]['@container']) &&
                  ('@list' != $activectx[$activeprty]['@container']))))
            {
                $element = $element[0];
            }
        }
        elseif (is_object($element))
        {
            // Otherwise, compact all properties
            $properties = get_object_vars($element);
            foreach ($properties as $property => &$value)
            {
                // Remove property from object it will be re-added later using the compacted IRI
                unset($element->{$property});

                if (in_array($property, self::$keywords))
                {
                    // Keywords can just be aliased but no other settings apply so no need
                    // to pass the value
                    $activeprty = $this->compactIri($property, $activectx, null, $optimize);

                    if (('@id' == $property) || ('@type' == $property) || ('@graph' == $property))
                    {
                        // TODO Should we really automatically compact the value of @id?
                        if (is_string($value))
                        {
                            // TODO Transform @id to relative IRIs by default??
                            $value = $this->compactIri($value, $activectx, null, $optimize);
                        }
                        else
                        {
                            // Must be @graph or @type, while @type requires all values to be strings,
                            // @graph values can be (expanded) objects as well
                            if ('@graph' == $property)
                            {
                                $def = $this->getTermDefinition('@graph', $activectx);

                                foreach ($value as $key => &$item)
                                {
                                    // TODO Transform to relative IRIs by default??
                                    $item = $this->compactValueWithDef($item, $def['@type'],
                                                                       $def['@language'], $activectx);
                                }

                                $this->compact($value, $activectx, $activeprty, $optimize);
                            }
                            else
                            {
                                foreach ($value as $key => &$iri)
                                {
                                    // TODO Transform to relative IRIs by default??
                                    $iri = $this->compactIri($iri, $activectx, null, $optimize);
                                }
                            }

                            if (is_array($value) && (1 == count($value)))
                            {
                                $value = $value[0];
                            }
                        }
                    }
                    else
                    {
                        $this->compact($value, $activectx, $activeprty, $optimize);
                    }

                    self::setProperty($element, $activeprty, $value);

                    // ... continue with next property
                    continue;
                }

                // After expansion, the value of all properties is in array form
                // TODO Remove this, this should really never appear!
                if (false == is_array($value))
                {
                    throw new SyntaxException('Detected a property whose value is not an array: ' . $property, $element);
                }


                // Make sure that empty arrays are preserved
                if (0 == count($value))
                {
                    $activeprty = $this->compactIri($property, $activectx, null, $optimize);
                    self::mergeIntoProperty($element, $activeprty, $value);

                    // ... continue with next property
                    continue;
                }


                // Compact every item in value separately as they could map to different terms
                foreach ($value as &$val)
                {
                    $activeprty = $this->compactIri($property, $activectx, $val, $optimize);

                    if (is_object($val))
                    {
                        // TODO Handle @list here
                        if (property_exists($val, '@list'))
                        {
                            $def = $this->getTermDefinition($activeprty, $activectx);

                            foreach ($val->{'@list'} as &$listItem)
                            {
                                $listItem = $this->compactValueWithDef($listItem, $def['@type'], $def['@language'], $activectx);
                            }

                            if ('@list' == $def['@container'])
                            {
                                $val = $val->{'@list'};

                                // a term can just hold one list if it has a @list container (we don't support lists of lists)
                                self::setProperty($element, $activeprty, $val);

                                continue; // ... continue with next value
                            }
                        }
                        else
                        {
                            $val = $this->compactValue($activeprty, $val, $activectx);
                        }

                        $this->compact($val, $activectx, $activeprty, $optimize);
                    }

                    // Merge value back into resulting object making sure that value is always an array if a container is set
                    self::mergeIntoProperty($element, $activeprty, $val,
                                            isset($activectx[$activeprty]['@container']));
                }
            }
        }
    }

    /**
     * Compacts an absolute IRI to the shortest matching term or compact IRI.
     *
     * @param mixed  $iri           The IRI to be compacted.
     * @param array  $activectx     The active context.
     * @param mixed  $value         The value of the property to compact.
     * @param bool   $toRelativeIri Specifies whether $value should be
     *                              transformed to a relative IRI as fallback.
     *
     * @return string The compacted IRI.
     */
    public function compactIri($iri, $activectx, $value = null, $toRelativeIri = false)
    {
        // TODO Handle $toRelativeIri or remove it
        $compactIris = array($iri);

        // Calculate rank of full IRI
        $highestRank = $this->calculateTermRank($iri, $value, $activectx);

        foreach ($activectx as $term => $definition)
        {
            if (isset($definition['@id']))  // TODO Will anything else ever be in the context??
            {
                if ($iri == $definition['@id'])
                {
                    $rank = 1;  // a term is always preferred to (compact) IRIs

                    if (false == is_null($value))
                    {
                        $rank = $this->calculateTermRank($term, $value, $activectx);
                    }

                    if ($rank > $highestRank)
                    {
                        $compactIris = array();
                        $highestRank = $rank;
                    }

                    if ($rank == $highestRank)
                    {
                        $compactIris[] = $term;
                    }
                }

                // TODO Should we really prevent empty suffixes?
                // If no matching terms have been found yet, store compact IRI if it doesn't exist as term at the same time
                if ((strlen($iri) > strlen($definition['@id'])) &&
                    (0 === substr_compare($iri, $definition['@id'], 0, strlen($definition['@id']))))
                {
                    $compactIri = $term . ':' . substr($iri, strlen($definition['@id']));

                    if (false == isset($activectx[$compactIri]))
                    {
                        $rank = $this->calculateTermRank($compactIri, $value, $activectx);
                        if ($rank > $highestRank)
                        {
                            $compactIris = array();
                            $highestRank = $rank;
                        }

                        if ($rank == $highestRank)
                        {
                            $compactIris[] = $compactIri;
                        }
                    }
                }
            }
        }

        // Sort matches
        usort($compactIris, array($this, 'compare'));

        return $compactIris[0];  // there is always at least one entry: the passed IRI
    }

    /**
     * Compacts a value.
     *
     * @param string $term  The term to which the value belongs.
     * @param mixed  $value The value to compact.
     * @param array  $activectx     The active context.
     *
     * @return mixed The compacted value.
     */
    public function compactValue($term, $value, $activectx)
    {
        if (false == isset($activectx[$term]))
        {
            // TODO Throw exception
        }

        $def = $this->getTermDefinition($term, $activectx);

        return $this->compactValueWithDef($value, $def['@type'], $def['@language'], $activectx);
    }

    /**
     * Compacts a value according a passed type and language setting.
     *
     * This method is used as an internal, more efficient version of the
     * {@link compactValue()} method since it doesn't look up the term
     * defintion itself. This is useful for processing lists, e.g.
     *
     * @param mixed  $value    The value to compact.
     * @param string $type     The type that applies (or null).
     * @param string $language The language that applies (or null).
     * @param array  $activectx     The active context.
     *
     * @return mixed The compacted value.
     *
     * @see compactValue()
     */
    private function compactValueWithDef($value, $type, $language, $activectx)
    {
        // Check if resulting value would be in expanded form
        if (is_object($value))
        {
            // Check @value objects
            if (property_exists($value, '@value'))
            {
                if (property_exists($value, '@type'))
                {
                    if ($value->{'@type'} !== $type)
                    {
                        // expanded form since types don't match, leave as is
                        return $value;
                    }
                    elseif (is_null($type) && is_string($value->{'@value'}) &&
                            (false == is_null($language)))
                    {
                        // expanded form since languages don't match (@type = null)
                        $result = clone $value;
                        unset($result->{'@type'});
                        $result->{'@language'} = $language;

                        return $result;
                    }
                    else
                    {
                        // not in expanded form: types match && (lang = null || not string)
                        return $value->{'@value'};
                    }
                }
                elseif (property_exists($value, '@language'))
                {
                    if ($value->{'@language'} !== $language)
                    {
                        // expanded form since languages don't match, return as is
                        return $value;
                    }
                    elseif (isset($type))
                    {
                        // expanded form since term has type mapping, plain literal
                        $result = clone $value;
                        unset($result->{'@language'});

                        return $result;
                    }
                    else
                    {
                        // not in expanded form: languages match && no type mapping
                        return $value->{'@value'};
                    }
                }
            }

            // Check @id objects (@id is only property)
            if (property_exists($value, '@id') && (1 == count(get_object_vars($value))))
            {
                if ('@id' == $type)
                {
                    return $this->compactIri($value->{'@id'}, $activectx);
                }
                else
                {
                    return $value;
                }
            }

            return $value;
        }

        if (is_array($value))
        {
            throw new SyntaxException('Array of arrays detected.');
        }


        // It is a scalar with no type and language mapping
        if (false == is_null($type))
        {
            // expanded form since term has type mapping
            $result = new \stdClass();
            // TODO Compact @value -> support aliases??
            $result->{'@value'} = $value;

            return $result;
        }
        elseif (is_string($value) && (false == is_null($language)))
        {
            // expanded form since there's a language mapping (either in term or context)
            $result = new \stdClass();
            // TODO Compact @value -> support aliases??
            $result->{'@value'} = $value;

            return $result;
        }

        return $value;
    }

    /**
     * Calculate term rank
     *
     * When selecting among multiple possible terms for a given property it
     * is possible that multiple terms match but differ in their @type,
     * @container, or @language settings. The purpose of this method is to
     * take a term (or IRI) and a value and calculate a rank to find the
     * best matching term, i.e., the one that minimizes the need for the
     * expanded object form.
     *
     * @param string $term       The term whose rank should be calculated.
     * @param mixed  $value      The value of the property to rank the term for.
     * @param array  $activectx  The active context.
     *
     * @return int Returns the term rank.
     */
    private function calculateTermRank($term, $value, $activectx)
    {
        $rank = 0;

        $def = $this->getTermDefinition($term, $activectx);

        if (array_key_exists($term, $activectx))
        {
            $rank++;   // a term is preferred to (compact) IRIs
        }

        // Check if resulting value would be in expanded form
        if (is_object($value) && property_exists($value, '@list'))
        {
            if ('@list' == $def['@container'])
            {
                $rank++;
            }

            foreach ($value->{'@list'} as $item)
            {
                if (is_object($this->compactValueWithDef($item, $def['@type'], $def['@language'], $activectx)))
                {
                    $rank--;
                }
                else
                {
                    $rank++;
                }
            }
        }
        else
        {
            if ('@list' == $def['@container'])
            {
                $rank -= 3;
            }
            elseif ('@set' == $def['@container'])
            {
                $rank++;
            }

            if (false == is_null($value))
            {
                if (is_object($this->compactValueWithDef($value, $def['@type'], $def['@language'], $activectx)))
                {
                    $rank--;
                }
                else
                {
                    $rank++;
                }
            }
        }

        return $rank;
    }

    /**
     * Returns the type and language mapping as well as the container of the
     * specified term.
     *
     * The result will be in the form
     * <pre>
     *   array('@type'      => type or null,
     *         '@language'  => language or null,
     *         '@container' => container or null)
     * </pre>
     *
     * @param string $term       The term whose information should be retrieved.
     * @param array  $activectx  The active context.
     *
     * @return array Returns an associative array containing the term definition.
     */
    private function getTermDefinition($term, $activectx)
    {
        $def = array('@type'      => null,
                     '@language'  => (isset($activectx['@language']))
                        ? $activectx['@language']
                        : null,
                     '@container' => null);


        if (in_array($term, self::$keywords))
        {
            $def['@language'] = null;
            if (('@id' == $term) || ('@type' == $term) || ('@graph' == $term))
            {
                $def['@type'] = '@id';
            }

            return $def;
        }
        elseif (false == isset($activectx[$term]))
        {
            return $def;
        }


        if (isset($activectx[$term]['@type']))
        {
            $def['@type'] = $activectx[$term]['@type'];
            $def['@language'] = null;
        }
        elseif (array_key_exists('@language', $activectx[$term]))  // could be null
        {
            $def['@language'] = $activectx[$term]['@language'];
        }

        if (isset($activectx[$term]['@container']))
        {
            $def['@container'] = $activectx[$term]['@container'];
        }

        return $def;
    }

    /**
     * Expands compact IRIs in the context
     *
     * @param string $iri        The IRI that should be expanded.
     * @param array  $loclctx    The local context.
     * @param array  $activectx  The active context.
     * @param array  $path       A path of already processed terms.
     *
     * @return string Returns the expanded IRI.
     *
     * @throws ProcessException If a cycle is detected while expanding the IRI.
     */
    private function contextIriExpansion($iri, $loclctx, $activectx, $path = array())
    {
        if (in_array($iri, $path))
        {
            throw new ProcessException(
                'Cycle in context definition detected: ' . join(' -> ', $path) . ' -> ' . $path[0],
                $loclctx);
        }
        else
        {
            $path[] = $iri;

            if (count($path) >= self::CONTEXT_MAX_IRI_RECURSIONS)
            {
                throw new ProcessException(
                    'Too many recursions in term definition: ' . join(' -> ', $path) . ' -> ' . $path[0],
                    $loclctx);
            }
        }

        if (isset($loclctx->{$iri}))
        {
            if (is_string($loclctx->{$iri}))
            {
                return $this->contextIriExpansion($loclctx->{$iri}, $loclctx, $activectx, $path);
            }
            elseif (property_exists($loclctx->{$iri}, '@id'))
            {
                return $this->contextIriExpansion($loclctx->{$iri}->{'@id'}, $loclctx, $activectx, $path);
            }
        }

        if (array_key_exists($iri, $activectx) && array_key_exists('@id', $activectx[$iri]))
        {
            // all values in the active context have already been expanded
            return $activectx[$iri]['@id'];
        }

        if (false !== strpos($iri, ':'))
        {
            list($prefix, $suffix) = explode(':', $iri, 2);

            $prefix = $this->contextIriExpansion($prefix, $loclctx, $activectx, $path);

            // If prefix contains a colon, we have successfully expanded it
            if (false !== strpos($prefix, ':'))
            {
                return $prefix . $suffix;
            }
        }


        // Couldn't expand it, return as is
        return $iri;
    }

    /**
     * Processes a local context to update the active context
     *
     * @param array  $loclctx    The local context.
     * @param array  $activectx  The active context.
     *
     * @throws ProcessException If processing of the context failed.
     * @throws ParseException   If a remote context couldn't be processed.
     */
    public function processContext($loclctx, &$activectx)
    {
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
                // Reset to the initial context, i.e., an empty array (TODO see ISSUE-80)
                $activectx = array();
            }
            elseif (is_object($context))
            {
                foreach ($context as $key => $value)
                {
                    if (is_null($value))
                    {
                        unset($activectx[$key]);
                        continue;
                    }

                    if (in_array($key, self::$keywords))
                    {
                        if ('@language' == $key)
                        {
                            if (false == is_string($value))
                            {
                                throw new SyntaxException(
                                    'The value of @language must be a string.',
                                    $context);
                            }

                            $activectx[$key] = $value;
                        }

                        // Keywords can't be altered
                        continue;
                    }

                    if (is_string($value))
                    {
                        $expanded = $this->contextIriExpansion($value, $context, $activectx);

                        if ((false == in_array($expanded, self::$keywords)) && (false === strpos($expanded, ':')))
                        {
                            throw new SyntaxException("Failed to expand $expanded to an absolute IRI.",
                                                      $loclctx);
                        }

                        $context->{$key} = $expanded;
                        $activectx[$key] = array('@id' => $expanded);
                    }
                    elseif (is_object($value))
                    {
                        $context->{$key} = clone $context->{$key};  // make sure we don't modify the passed context

                        if (isset($value->{'@id'}))
                        {
                            $expanded = $this->contextIriExpansion($value->{'@id'}, $context, $activectx);

                            if ((false == in_array($expanded, self::$keywords)) && (false === strpos($expanded, ':')))
                            {
                                throw new SyntaxException("Failed to expand $expanded to an absolute IRI.",
                                                          $loclctx);
                            }

                            $context->{$key}->{'@id'} = $expanded;
                            $activectx[$key] = array('@id' => $expanded);

                            if (in_array($expanded, self::$keywords))
                            {
                                // if it's an aliased keyword, we ignore all other properties
                                // TODO Should we throw an exception if there are other properties?
                                continue;
                            }
                        }
                        else
                        {
                            // term definitions can't be modified but just be replaced
                            // TODO Check this if we allow IRI -> null mapping!
                            unset($activectx[$key]);
                        }

                        if (property_exists($value, '@type'))
                        {
                            $expanded = $this->contextIriExpansion($value->{'@type'}, $context, $activectx);

                            if (('@id' != $expanded) && (false === strpos($expanded, ':')))
                            {
                                throw new SyntaxException("Failed to expand $expanded to an absolute IRI.",
                                                          $loclctx);
                            }

                            $context->{$key}->{'@type'} = $expanded;
                            $activectx[$key]['@type'] = $expanded;

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

                            // Note the else. Language tagging applies just to untyped literals
                            $activectx[$key]['@language'] = $value->{'@language'};
                        }

                        if (property_exists($value, '@container'))
                        {
                            if (('@set' == $value->{'@container'}) || ('@list' == $value->{'@container'}))
                            {
                                $activectx[$key]['@container'] = $value->{'@container'};
                            }
                        }

                        // Try to set @id if it's not set, this is required for term definitions using compact IRIs
                        if (false == isset($activectx[$key]['@id']))
                        {
                            $expanded = $this->contextIriExpansion($key, $context, $activectx);

                            if (('@id' != $expanded) && (false === strpos($expanded, ':')))
                            {
                                // the term is not mapped to an IRI and can't be interpreted as an IRI itself,
                                // drop the whole definition
                                unset($activectx[$key]);
                            }
                            else
                            {
                                $activectx[$key]['@id'] = $expanded;
                            }
                        }
                    }
                }
            }
            else
            {
                $remoteContext = JSONLD::parse($context);

                if (is_object($remoteContext) && property_exists($remoteContext, '@context'))
                {
                    $this->processContext($remoteContext, $activectx);
                }
                else
                {
                    throw new ProcessException('Remote context "' . $context . '" is invalid.');
                }
            }
        }
    }
}
