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
    /**
     * Constructor
     *
     * @param integer $offset The offset of JSON-LD document (used for line numbers in error messages)
     */
    public function __construct($offset = 0)
    {
        $this->offset = $offset;
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
     * @param mixed  $element    A JSON-LD elemnt to be expanded
     * @param array  $activectx  The active context
     * @param string $baseiri    The base IRI
     *
     * @return mixed  A PHP value
     *
     * @throws ParseException If the JSON-LD document is not valid
     */
    public function expand(&$element, $activectx = array(), $baseiri = '')
    {
        // TODO Spec: Rename value to element to make it distinguishable
        // TODO Spec: Expand doesn't need the active property
        // TODO Spec: Remove local context immediately after processing it
        // TODO Spec: Define precedence @graph, @value
        // TODO Spec: Rename key to property
        // TODO Spec: Indent 2.3 onwards
        // TODO Spec: 2.2.4) Otherwise, if the key is @id or @type and the value is a string, expand the value according to IRI Expansion. -> @type can be array

        if (is_array($element))
        {
            foreach($element as &$item)
            {
                $item = $this->expand($item, $activectx);
            }
        }
        else if (is_object($element))
        {
            if (property_exists($element, '@context'))
            {
                $this->updateActiveContext($activectx, $element->{'@context'});
                unset($element->{'@context'});
            }

            // TODO Define precedence
            if (property_exists($element, '@graph'))
            {
                // TODO Check for invalid other properties
            }

            // TODO Check @value (with @type), @language, @list, @list

            // TODO Check @container -> invalid

            // Process all other properties
            $properties = get_object_vars($element);
            foreach ($properties as $key => $value)
            {
                if (is_null($value))
                {
                    unset($element->{$key});
                    continue;
                }
                elseif(is_object($value))
                {
                    if (((property_exists($value, '@value')) && (false === isset($value->{'@value'}))) ||
                        ((property_exists($value, '@list')) && (false === isset($value->{'@list'}))) ||
                        ((property_exists($value, '@set')) && (false === isset($value->{'@set'}))))
                    {
                        unset($element->{$key});
                        continue;
                    }
                    elseif (property_exists($value, '@set') && isset($value->{'@set'}))
                    {
                        $value = $value->{'@set'};
                    }
                }

                // TODO Add support for keyword aliasing
                if ('@id' == $key)  // TODO Add support for keyword aliasing
                {
                    // TODO without @value
                    if (is_string($value))
                    {
                        $element->{'@id'} = $this->expandIri($value, $activectx, $baseiri);
                    }
                    else
                    {
                        throw new ParseException(
                            'Invalid value for @id detected. Expected string or array, got ' .
                            var_export($element->{'@type'}, true));
                    }
                }
                elseif ('@type' == $key)
                {
                    // TODO without @value
                    if (is_string($element->{'@type'}))
                    {
                        $element->{'@type'} = array($element->{'@type'});
                    }

                    if (is_array($element->{'@type'}))
                    {
                        foreach ($element->{'@type'} as &$iri)
                        {
                            // TODO Check if string
                            $iri = $this->expandIri($iri, $activectx, $baseiri);
                        }
                    }
                    else
                    {
                        throw new ParseException(
                            'Invalid value for @type detected. Expected string or array, got ' .
                            var_export($element->{'@type'}, true));
                    }
                }
                elseif ('@value' == $key)  // TODO Add support for keyword aliasing
                {
                    // nothing to do, already expanded
                    continue;
                }
                else // TODO Check if keyword, if not, continue below
                {
                    // TODO Handle plain-old JSON properties
                    $expandedKey = $this->expandIri($key, $activectx, $baseiri);

                    // Remove un-expanded property
                    if ($key !== $expandedKey)
                    {
                        unset($element->{$key});
                    }

                    // Create expanded property and make sure it's an array
                    if (false === property_exists($element, $expandedKey))
                    {
                        $element->{$expandedKey} = array();
                    }
                    elseif (false === is_array($element->{$expandedKey}))
                    {
                        $element->{$expandedKey} = array($element->{$expandedKey});
                    }

                    if (isset($activectx[$key]['@container']) && ('@list' === $activectx[$key]['@container'])) // TODO check not already in @list form
                    {
                        // TODO Fix this
                        $obj = new \stdClass();
                        $obj->{'@list'} = $value;
                        $element->{$expandedKey}[] = $obj;
                    }


                    // Expand value
                    /*if (is_object($value))
                    {
                        // TODO Check precendence
                        $this->expand($element->{$key}, $activectx, $baseiri);
                    }
                    else*/if (is_array($value))
                    {
                        foreach ($value as $item)
                        {
                            $element->{$expandedKey}[] = $this->expand($item, $key, $activectx, $baseiri);
                        }
                    }
                    else
                    {
                        // TODO Expand if coercion/language taggin etc.
                        $element->{$expandedKey}[] = $this->expandValue($value, $key, $activectx, $baseiri);
                    }
                    // else: key is not defined in context, ignore it and subtree
                }
            }
        }
    }

    /**
     * Expands a JSON-LD value.
     *
     * The value can be of any scalar type (i.e., not an object or array)
     *
     * @param mixed  $value      The value to be expanded to an absolute IRI
     * @param mixed  $activeprty The active property
     * @param array  $activectx  The active context
     * @param string $baseiri    The base IRI
     *
     * @return StdClass  The expanded value in object form
     */
    protected function expandValue($value, $activeprty, $activectx = array(), $baseiri)
    {
        if (isset($activectx[$activeprty]))
        {
            $activeprty = $activectx[$activeprty];

            if (isset($activeprty['@type']))
            {
                // TODO 1) If value is a number and the active property is the target of typed literal coercion to xsd:integer or xsd:double, expand the value into an object with two key-value pairs. The first key-value pair will be @value and the string representation of value as defined in the section Data Round Tripping. The second key-value pair will be @type and the associated coercion datatype expanded according to the IRI Expansion rules.

                if ('@id' == $activeprty['@type'])
                {
                    $obj = new \stdClass();
                    $obj->{'@id'} = $this->expandIri($value, $activectx, $baseiri);
                    return $obj;
                }
                else
                {
                    // TODO Add special cases for xsd:double, xsd:integer!?
                    $obj = new \stdClass();
                    $obj->{'@value'} = $value;
                    $obj->{'@type'} = $activeprty['@type'];  // TODO Make sure types are already expanded
                    return $obj;
                }
            }
            elseif (isset($activeprty['@language']) && is_string($value))  // TODO or global language
            {
                $obj = new \stdClass();
                $obj->{'@value'} = $value;
                $obj->{'@language'} = $activeprty['@language'];
                return $obj;
            }
        }
        return $value;
    }

    /**
     * Expands a JSON-LD IRI to an absolute IRI.
     *
     * @param mixed  $value      The value to be expanded to an absolute IRI
     * @param array  $activectx  The active context
     * @param string $baseiri    The base IRI
     *
     * @return StdClass  The expanded value in object form
     */
    private function expandIri($value, $activectx = array(), $baseiri)
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

        // TODO Handle relative IRIs (add to spec)

        // can't expand it, return as is
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
        // TODO And, more important, check it is doing the right thing

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
            return $activectx[$prefix] . $suffix;
        }

        return $value;
    }

    /**
     * Processes a local context to update the active context.
     *
     * @param array  $activectx  The active context
     * @param array  $loclctx    The local context
     *
     * @throws ProcessException If processing of the JSON-LD document failed
     */
    protected function updateActiveContext(&$activectx, $loclctx)
    {
        // TODO Make sure that all @id's are absolute IRIs
        foreach ($loclctx as $key => $value)
        {
            if (is_null($value))
            {
                unset($activectx[$key]);
            }
            elseif (is_string($value))
            {
                // either IRI or prefix:suffix
                $expanded = $this->contextIriExpansion($value, $loclctx, $activectx);
                $loclctx->{$key} = $expanded;
                $activectx[$key]['@id'] = $expanded;
            }
            elseif (is_object($value))
            {
                if (property_exists($value, '@id'))
                {
                    if (is_null($value->{'@id'}))
                    {
                        unset($activectx[$key]);
                    }
                    else
                    {
                        $expanded = $this->contextIriExpansion($value->{'@id'}, $loclctx, $activectx);
                        $loclctx->{$key}->{'@id'} = $expanded;
                        $activectx[$key]['@id'] = $expanded;
                    }
                }

                if(property_exists($value, '@type'))
                {
                    $expanded = $this->contextIriExpansion($value->{'@type'}, $loclctx, $activectx);

                    if(!is_null($expanded))
                    {
                        $loclctx->{$key}->{'@type'} = $expanded;
                        $activectx[$key]['@type'] = $expanded;
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
