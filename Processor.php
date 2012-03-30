<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;
require("Exception\SyntaxException.php");
require("Exception\ParseException.php");
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
    /** A list of all defined keywords */
    private static $keywords = array('@context', '@id', '@value', '@language',
                                     '@type', '@container', '@list', '@set', '@graph');

    /** The base IRI */
    private $baseiri = null;

    /**
     * Compares two values by their length and then lexicographically.
     *
     * If two strings have different lenghts, the shorter one will be considered
     * less than the other. If they have the same lenght, they are compared
     * lexicographically.
     *
     * @param mixed $a Value A
     * @param mixed $a Value B
     *
     * @return int If value A is shorter than value B, -1 will be returned; if it's
     *             longer 1 will be returned. If both values have the same lenght
     *             and value A is considered lexicographically less, -1 will be
     *             returned, if they are equal 0 will be returned, otherwise 1
     *             will be returned.
     */
    public static function compare($a, $b)
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
     * @param string $baseiri    The base IRI
     */
    public function __construct($baseiri = null)
    {
        $this->baseiri = $baseiri;
    }

    /**
     * Parses a JSON-LD document to a PHP value.
     *
     * @param  string $document A JSON-LD document
     *
     * @return mixed  A PHP value
     *
     * @throws ParseException If the JSON-LD document is not valid
     */
    public function parse($document)
    {
        if (function_exists('mb_detect_encoding') && false === mb_detect_encoding($document, 'UTF-8', true)) {
            throw new ParseException('The JSON-LD document does not appear to be valid UTF-8.');
        }

        if (function_exists('mb_internal_encoding') && ((int) ini_get('mbstring.func_overload')) & 2) {
            $mbEncoding = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
        }

        $error = null;
        $data = json_decode($document, false, 512, JSON_UNESCAPED_SLASHES);

        if (isset($mbEncoding)) {
            mb_internal_encoding($mbEncoding);
        }

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

        return empty($data) ? null : $data;
    }

    /**
     * Expands a JSON-LD document.
     *
     * @param mixed  $element    A JSON-LD element to be expanded
     * @param array  $activectx  The active context
     * @param string $activeprty The active property
     *
     * @return mixed  A PHP value
     *
     * @throws ParseException If the JSON-LD document is not valid
     */
    public function expand(&$element, $activectx = array(), $activeprty = null)
    {
        // TODO Spec: Define precedence @graph, @value
        // TODO Add support for keyword aliasing

        if (is_array($element))
        {
            $result = array();
            foreach($element as &$item)
            {
                $this->expand($item, $activectx);
                if(false === is_null($item))
                {
                    $result[] = $item;
                }
            }

            $element = $result;
        }
        elseif (is_object($element))
        {
            if (property_exists($element, '@context'))
            {
                $this->processContext($element->{'@context'}, $activectx);
                unset($element->{'@context'});
            }

            // Preprocess object to restore aliased-keywords
            foreach ($element as $property => $value)
            {
                if (isset($activectx['@aliases'][$property]))
                {
                    $keyword = $activectx['@aliases'][$property];
                    unset($element->{$property});

                    if (property_exists($element, $keyword))
                    {
                        // if the keyword already exists, merge it with this property's value
                        if (is_array($element[$keyword]))
                        {
                            $element->{$keyword}[] = $value;
                        }
                        else
                        {
                            $element->{$keyword} = array($element->{$keyword}, $value);
                        }
                    }
                    else
                    {
                        $element->{$keyword} = $value;
                    }
                }
            }

            // TODO Define precedence
            if (property_exists($element, '@graph'))
            {
                // TODO Check for invalid other properties
                $this->expand($element->{'@graph'}, $activectx);
                $element = $element->{'@graph'};
                return;
            }

            // TODO Check @value (with @type), @language, @list, @set

            // TODO Check @container -> invalid?

            // Process all other properties
            $properties = get_object_vars($element);
            foreach ($properties as $property => &$value)
            {
                $activeprty = $property;

                if (is_null($value))
                {
                    unset($element->{$property});
                    continue;
                }
                elseif(is_object($value))
                {
                    if (((property_exists($value, '@value')) && (false === isset($value->{'@value'}))) ||
                        ((property_exists($value, '@list')) && (false === isset($value->{'@list'}))) ||
                        ((property_exists($value, '@set')) && (false === isset($value->{'@set'}))))
                    {
                        unset($element->{$property});
                        continue;
                    }
                    elseif (property_exists($value, '@set') && isset($value->{'@set'}))
                    {
                        $value = $value->{'@set'};
                    }
                }

                if ('@id' == $property)
                {
                    if (is_string($value))
                    {
                        $element->{'@id'} = $this->expandIri($value, $activectx, true);
                    }
                    else
                    {
                        // TODO Spec, add other clause.. either ignore or throw exception
                        throw new SyntaxException('Invalid value for @id detected.',
                                                  json_encode($element, true));
                    }
                }
                elseif ('@type' == $property)
                {
                    // TODO Check what to do if @type can't be converted to absolute IRI
                    if (is_string($value))
                    {
                        $element->{'@type'} = $this->expandIri($value, $activectx);
                        if (false == property_exists($element, '@value'))
                        {
                            $element->{'@type'} = array($element->{'@type'});
                        }
                    }
                    elseif (is_array($value))
                    {
                        if (property_exists($element, '@value'))
                        {
                            throw new SyntaxException(
                                    'Detected @type with an value of an array in an element where @value is present as well.',
                                    var_export($element, true));
                        }

                        $element->{'@type'} = array();
                        foreach ($value as $item)
                        {
                            if (false === is_string($item))
                            {
                                throw new SyntaxException(
                                        'Invalid value in @type array detected. Expected string, got ' .
                                        var_export($item, true));
                            }
                            $element->{'@type'}[] = $this->expandIri($item, $activectx);
                        }
                    }
                    else
                    {
                        throw new SyntaxException(
                            'Invalid value for @type detected. Expected string or array, got ' .
                            var_export($element->{'@type'}, true));
                    }
                }
                elseif ('@list' == $property)
                {
                    // TODO Check for other keywords and throw exception??
                    if (false === is_array($value))
                    {
                        $value = array($value);
                    }

                    $result = array();
                    foreach ($value as &$item)
                    {
                        $this->expand($item, $activectx, $activeprty);
                        if(false === is_null($item))
                        {
                            $result[] = $item;
                        }
                    }
                    $element->{'@list'} = $result;
                }
                elseif (('@value' == $property) || ('@language' == $property))
                {
                    // nothing to do, already expanded
                    continue;
                }
                else
                {
                    $property = $this->expandIri($property, $activectx);

                    // TODO Check if this check is enough
                    // TODO Spec Update spec accordingly
                    if ((false === strpos($property, ':')) &&
                        (false == in_array($property, self::$keywords)))
                    {
                        // if the property doesn't contain a colon it is not a valid
                        // absolute IRI and thus plain JSON that isn't further processed
                        // but dropped

                        // TODO Check this, ISSUE-56
                        unset($element->{$activeprty});
                        continue;
                    }

                    // Remove un-expanded property
                    // TODO Check this
                    if ($property !== $activeprty)
                    {
                        // value is still referenced in $value
                        unset($element->{$activeprty});
                    }
                    else
                    {
                        // remove value as it will be merged in again
                        unset($element->{$property});
                    }

                    // Create expanded property and make sure it's an array
                    if (false === property_exists($element, $property))
                    {
                        $element->{$property} = array();
                    }
                    elseif (false === is_array($element->{$property}))
                    {
                        $element->{$property} = array($element->{$property});
                    }

                    if (is_array($value))
                    {
                        // if @list coercion transform to object and merge to prop
                        // otherwise process each item recursively and merge to prop
                        $result = array();

                        foreach ($value as $key => &$item)
                        {
                            $this->expand($item, $activectx, $activeprty);
                            if (false === is_null($item))
                            {
                                $result[] = $item;
                            }
                        }
                        $element->{$property} = array_merge($element->{$property}, $result);
                    }
                    elseif (is_object($value))
                    {
                        $this->expand($value, $activectx, $activeprty);
                        $element->{$property}[] = $value;
                    }
                    else
                    {
                        $value = $this->expandValue($value, $activeprty, $activectx);
                        if (isset($value))
                        {
                            $element->{$property}[] = $value;
                        }
                    }
                }
            }
        }
        else
        {
            $element = $this->expandValue($element, $activeprty, $activectx);
        }
    }

    /**
     * Expands a JSON-LD value.
     *
     * The value can be of any scalar type (i.e., not an object or array)
     *
     * @param mixed  $value      The value to be expanded
     * @param mixed  $activeprty The active property
     * @param array  $activectx  The active context
     *
     * @return StdClass  The expanded value in object form
     */
    protected function expandValue($value, $activeprty, $activectx)
    {
        // TODO Check if $value can really just be a scalar type!

        if (isset($activectx[$activeprty]['@type']))
        {
            // TODO 1) If value is a number and the active property is the target of typed literal
            // coercion to xsd:integer or xsd:double, expand the value into an object with two key-value pairs.
            // The first key-value pair will be @value and the string representation of value as defined in the
            // section Data Round Tripping. The second key-value pair will be @type and the associated coercion
            // datatype expanded according to the IRI Expansion rules.

            // TODO Do I need to check if $value is a scalar??
            if (is_object($value) || is_array($value))
            {
                throw new \Exception('A type coerced array or object was found.');
            }

            if ('@id' == $activectx[$activeprty]['@type'])
            {
                $obj = new \stdClass();
                $obj->{'@id'} = $this->expandIri($value, $activectx, true);
                return $obj;
            }
            else
            {
                // TODO Add special cases for xsd:double, xsd:integer!?
                $obj = new \stdClass();
                $obj->{'@value'} = $value;
                $obj->{'@type'} = $activectx[$activeprty]['@type'];  // TODO Make sure types are already expanded
                return $obj;
            }
        }

        if (is_string($value))
        {
            $language = @$activectx['@language'];
            if (isset($activeprty['@language']))
            {
                $language = $activeprty['@language'];
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
     * Expands a JSON-LD IRI to an absolute IRI.
     *
     * @param mixed  $value        The value to be expanded to an absolute IRI
     * @param array  $activectx    The active context
     * @param bool   $relativeIri  Specifies if $value should be treated as relative
     *                             IRI as fallback or not
     *
     * @return StdClass  The expanded value in object form
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
            // TODO Spec Handle relative IRIs properly
            return $this->baseiri . $value;
        }

        // can't expand it, return as is
        return $value;
    }

    /**
     * Compacts a JSON-LD document.
     *
     * @param mixed  $element    A JSON-LD element to be compacted
     * @param array  $activectx  The active context
     * @param string $activeprty The active property
     * @param bool   $optimize   If set to true, the JSON-LD processor is allowed optimize
     *                           the passed context to produce even compacter representations
     *
     * @return mixed  A PHP value
     *
     * @throws ParseException If the JSON-LD document is not valid
     */
    public function compact(&$element, $activectx = array(), $activeprty = null, $optimize = false)
    {
        if (is_array($element))
        {
            foreach ($element as &$item)
            {
                $this->compact($item, $activectx, $activeprty, $optimize);
            }

            // TODO Spec Add this optimization to spec
            if (1 == count($element) &&
                ((false == isset($activectx[$activeprty]['@container'])) ||
                 (('@set' != $activectx[$activeprty]['@container']) /*&&    // TODO Spec This is not how it's currently done
                  ('@list' != $activectx[$activeprty]['@container'])*/)))
            {
                $element = $element[0];
            }
        }
        elseif (is_object($element))
        {
            // TODO Check why I have to put this here
            if (isset($activectx[$activeprty]['@type']) &&
                property_exists($element, '@value') && property_exists($element, '@type') &&
                (($element->{'@type'} == $activectx[$activeprty]['@type'])))
            {
                $element = $element->{'@value'};
                return;
            }


            foreach ($element as $property => $value)
            {
                // TODO Handle keyword aliases
                // TODO Spec Handle keyword aliases
                if (('@id' == $property) || ('@type' == $property))
                {
                    // Make sure the value is always an array, this will optimized
                    // away again
                    if (is_string($value))
                    {
                        $element->{$property} = array($value);
                    }

                    // TODO Update spec, it compacted recursively
                    foreach ($element->{$property} as $key => &$iri)
                    {
                        if (is_string($iri))
                        {
                            // TODO Transform to relative IRIs by default??
                            $iri = $this->compactIri($iri, $activectx, $optimize);
                        }
                        else
                        {
                            // TODO Check this, should not be possible!
                            throw new SyntaxException('Detected invalid value of @id or @type. Must be a string or an array of strings.',
                                                      json_encode($element));
                        }
                    }

                    if (1 === count($element->{$property}))
                    {
                        $element->{$property} = $element->{$property}[0];
                    }
                }
                // TODO Spec This is not in the spec at this place as @list objects in an array are not expected
                elseif (('@list' == $property) && (1 === count(get_object_vars($element))) &&
                        isset($activectx[$activeprty]['@container']) && ('@list' == $activectx[$activeprty]['@container']))
                {
                    // TODO Spec Make last part of the following sentence clearer: Otherwise, if value contains only a @list property, and the active property is subject to list coercion, the compacted value is the result of performing this algorithm on that value.
                    $element = $element->{'@list'};
                    $this->compact($element, $activeprty, $activectx, $optimize);
                }
                else
                {
                    if (false == in_array($property, self::$keywords))
                    {
                        // TODO Make this step in the spec clearer
                        $activeprty = $this->compactIri($property, $activectx, $optimize);

                        if ($activeprty != $property)
                        {
                            unset($element->{$property});

                            if (property_exists($element, $activeprty))
                            {
                                // TODO Spec Handle term collissions
                                if (false === is_array($element->{$activeprty}))
                                {
                                    $element->{$activeprty} = array($element->{$activeprty});
                                }
                                $element->{$activeprty}[] = $value;
                            }
                            else
                            {
                                $element->{$activeprty} = $value;
                            }

                            $property = $activeprty;
                        }
                    }


                    if (is_object($value))
                    {
                        // This code should never be reached
                        $numberProperties = count(get_object_vars($value));

                        if (property_exists($value, '@value') ||
                            (property_exists($value, '@id') && (1 === $numberProperties)))
                        {
                            $element->{$property} = $this->compactValue($value, $activeprty, $activectx, $optimize);
                        }
                        else if (property_exists($value, '@list') && (1 === $numberProperties) &&
                                 isset($activectx[$activeprty]['@container']) && ('@list' == $activectx[$activeprty]['@container']))
                        {
                            // TODO Spec Make last part of the following sentence clearer: Otherwise, if value contains only a @list property, and the active property is subject to list coercion, the compacted value is the result of performing this algorithm on that value.
                            $element->{$property} = $this->compactValue($value->{'@list'}, $activeprty, $activectx, $optimize);
                        }
                        else
                        {
                            $this->compact($element->{$property}, $activectx, $activeprty, $optimize);
                        }
                    }
                    elseif (is_array($value))
                    {
                        $this->compact($element->{$property}, $activectx, $activeprty, $optimize);
                    }
                }
            }
        }
    }

    /**
     * Compacts an absolute IRI to the shortest matching term or compact IRI.
     *
     * Please note that this method requires the active context to be sorted already
     * (with {@link compare()}).
     *
     * @param mixed  $value         The value to be expanded to an absolute IRI
     * @param array  $activectx     The active context
     * @param bool   $toRelativeIri Specifies whether $value should be treated
     *                              transformed to a relative IRI if possible
     *
     * @return StdClass  The expanded value in object form
     */
    private function compactIri($value, $activectx, $toRelativeIri = false)
    {
        // TODO Handle "to relative IRIs" or remove it.
        $compactIris = array();

        foreach ($activectx as $term => $definition)
        {
            if (isset($definition['@id']))
            {
                if ($value == $definition['@id'])
                {
                    return $term;
                }

                if (0 === substr_compare($value, $definition['@id'], 0, strlen($definition['@id'])))
                {
                    $compactIris[] = $term . ':' . substr($value, strlen($definition['@id']));
                }
            }
        }

        if (count($compactIris) > 0)
        {
            usort($compactIris, array($this, 'compare'));
            return $compactIris[0];
        }

        return $value;
    }

    /**
     * Compacts an expanded JSON-LD value.
     *
     * @param mixed  $value      The expanded value to be compacted
     * @param mixed  $activeprty The active property
     * @param array  $activectx  The active context
     * @param bool   $toRelativeIri Specifies whether $value should be treated
     *                              transformed to a relative IRI if possible
     *
     * @return mixed The compacted value
     */
    protected function compactValue($value, $activeprty, $activectx, $toRelativeIri)
    {
        // TODO Handle "to relative IRIs" or remove it.
          // TODO Spec This check is not in the spec
        if (property_exists($value, '@value') && (is_bool($value->{'@value'}) || is_numeric($value->{'@value'})))
        {
            // TODO Remove this completely from the spec??
die('#####################################################################################');
            // TODO Check if term has other type coercion
            return $value->{'@value'};
        }
        elseif (isset($activectx[$activeprty]['@type']))
        {
            // TODO Check if @id exists
            if (('@id' == $activectx[$activeprty]['@type']) && property_exists($value, '@id'))
            {
                return $this->compactIri($value->{'@id'}, $activectx, $toRelativeIri);
            }
            // TODO Check that @value exists and that types match
            elseif (property_exists($value, '@value') && property_exists($value, '@type') &&
                    (($value->{'@type'} == $activectx[$activeprty]['@type'])))
            {
                echo $value->{'@type'};
                return $value->{'@value'};
            }
        }
        elseif (property_exists($value, '@id'))
        {
            $value->{'@id'} = $this->compactIri($value->{'@id'}, $activectx, $toRelativeIri);
            return $value;
        }
        elseif (property_exists($value, '@value') &&
                ((1 === count(get_object_vars($value))) ||
                  (property_exists($value, '@language') && isset($activectx[$activeprty]['@language']) &&
                   ($value->{'@language'} == $activectx[$activeprty]['@language']))))
        {
            return $value->{'@value'};
        }
        elseif (property_exists($value, '@list')) /*&&
                isset($activectx[$activeprty]['@container']) &&
                ('@list' == $activectx[$activeprty]['@container']))*/

        {
die('~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~++++++++++++++++++++++++++++++++++++++++++++++');
            return $value->{'@list'};
        }
        elseif (property_exists($value, '@type'))
        {
            $value->{'@type'} = $this->compactIri($value->{'@type'}, $activectx, $toRelativeIri);
            return $value;
        }

        return $value;
    }

    /**
     * Expands compact IRIs in the context
     *
     * @param string $value      The (compact) IRI that should be expanded
     * @param array  $loclctx    The local context
     * @param array  $activectx  The active context
     * @param array  $path       A path of already processed terms
     *
     * @throws SyntaxException If a JSON-LD syntax error is detected
     */
    protected function contextIriExpansion($value, $loclctx, $activectx, $path = array())
    {
        // TODO Rename this method??
        // TODO And, more important, check that it's doing the right thing
        // TODO Spec Add this to spec?

        if (strpos($value, ':') === false)
            return $value;  // not prefix:suffix

        list($prefix, $suffix) = explode(':', $value, 2);

        if (in_array($prefix, $path))
        {
            throw new SyntaxException('Cycle in context definition detected: ' .
                                      join(' -> ', $path) . ' -> ' . $path[0]);
        }
        else
        {
            $path[] = $prefix;
        }

        if (property_exists($loclctx, $prefix))
        {
            return $this->contextIriExpansion($loclctx->{$prefix}, $loclctx, $activectx, $path) . $suffix;
        }

        if (array_key_exists($prefix, $activectx))
        {
            // all values in the active context have already been expanded
            return $activectx[$prefix]['@id'] . $suffix;
        }

        return $value;
    }

    /**
     * Processes a local context to update the active context.
     *
     * @param array  $loclctx    The local context
     * @param array  $activectx  The active context
     *
     * @throws ProcessException If processing of the JSON-LD document failed
     */
    public function processContext($loclctx, &$activectx)
    {
        // TODO Make sure that all @id's are absolute IRIs?
        // TODO Spec Do we need to check that we end up with an absolute IRI?

        foreach ($loclctx as $key => $value)
        {
            if (is_null($value))
            {
                unset($activectx[$key]);
                unset($activectx['@aliases'][$key]);  // TODO create keyword mapping
            }
            elseif (is_string($value))
            {
                // either IRI or prefix:suffix
                $expanded = $this->contextIriExpansion($value, $loclctx, $activectx);
                $loclctx->{$key} = $expanded;

                if (in_array($expanded, self::$keywords))
                {
                    // if it's a keyword alias, add it to special map and remove from active context
                    // TODO create keyword mapping
                    $activectx['@aliases'][$key] = $expanded;
                    unset($activectx[$key]);
                }
                else
                {
                    // term definitions can't be modified but just be replaced
                    $activectx[$key] = array('@id' => $expanded);
                }
            }
            elseif (is_object($value))
            {
                // term definitions can't be modified but just be replaced
                unset($activectx[$key]);
                $loclctx->{$key} = clone $loclctx->{$key};

                if (isset($value->{'@id'}))
                {
                    $expanded = $this->contextIriExpansion($value->{'@id'}, $loclctx, $activectx);
                    $loclctx->{$key}->{'@id'} = $expanded;
                    $activectx[$key]['@id'] = $expanded;
                }

                if (property_exists($value, '@type'))
                {
                    $expanded = $this->contextIriExpansion($value->{'@type'}, $loclctx, $activectx);

                    if(!is_null($expanded))
                    {
                        $loclctx->{$key}->{'@type'} = $expanded;
                        $activectx[$key]['@type'] = $expanded;
                    }
                }
                elseif (property_exists($value, '@language') && is_string($value->{'@language'}))
                {
                    // language tagging applies just to untyped literals
                    $activectx[$key]['@language'] = $value->{'@language'};
                }

                if (property_exists($value, '@container'))
                {
                    if (('@set' == $value->{'@container'}) || ('@list' == $value->{'@container'}))
                    {
                        $activectx[$key]['@container'] = $value->{'@container'};
                    }
                }
            }
            else
            {
                throw new ProcessException('Found invalid value in context.',
                                           $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES));
            }
        }
    }
}
