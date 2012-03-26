<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\ParseException;
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
     * @param  mixed  $jsonld An already parsed JSON-LD document
     *
     * @return mixed  A PHP value
     *
     * @throws ParseException If the JSON-LD document is not valid
     */
    public function expand(&$jsonld, $activectx = array())
    {
        if (is_object($jsonld))
        {
            if (property_exists($jsonld, '@context'))
            {
                $this->updateActiveContext($activectx, $jsonld->{'@context'});
                unset($jsonld->{'@context'});
            }

            // TODO Define precedence
            if (property_exists($jsonld, '@graph'))
            {
                // TODO Check for invalid other properties
            }

            if (property_exists($jsonld, '@type'))
            {
                // without @value
                // TODO Check if value is string and expand type
            }

            // TODO Check @value (with @type), @language, @list, @list

            // TODO Check @container -> invalid

            // Process all other properties
            foreach ($jsonld as $key => $value)
            {
                if (key_exists($key, $activectx))
                {
                    // Remove un-expanded property
                    if ($key !== $activectx[$key])
                    {
                        unset($jsonld->{$key});
                    }

                    // Create expanded property
                    if (false === property_exists($jsonld, $activectx[$key]['@id']))
                    {
                        $jsonld->{$activectx[$key]['@id']} = array();
                    }

                    // Expand value
                    $this->expand($value, $activectx);
                    $jsonld->{$activectx[$key]['@id']}[] = $value;
                }
                // else: key is not defined in context, ignore it and subtree
            }
        }
        elseif (is_array($jsonld))
        {
            foreach($jsonld as &$item)
            {
                $item = $this->expand($item, $activectx);
            }
        }
    }

    /**
     * Expands compact IRIs in the context
     *
     * @param string $value      the (compact) IRI that should be expanded
     * @param array  $loclctx    the local context
     * @param array  $activectx  the active context
     * @param array  $path       a path of already processed terms
     *
     * @throws ProcessException If the JSON-LD document is not valid
     */
    protected function contextIriExpansion($value, $loclctx, $activectx, $path = array())
    {
        // TODO: Rename this method??

        if (strpos($value, ':') === false)
            return $value;  // not prefix:suffix

        list($prefix, $suffix) = explode(':', $value, 2);

        if (in_array($prefix, $path))
        {
            throw new ProcessException('Cycle in context definition detected: ' .
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
     * Updates the active context with a local context.
     *
     * @param array  $activectx  the active context
     * @param array  $loclctx    the local context
     *
     * @throws ProcessException If the JSON-LD document is not valid
     */
    protected function updateActiveContext(&$activectx, $loclctx)
    {
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
