<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\ParseException;

/**
 * JsonLD offers convenience methods to load, process, and dump JSON-LD.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * @api
 */
class JsonLD
{
    /**
     * Parses JSON-LD.
     *
     * The parse method, when supplied with a JSON-LD stream (string or
     * file), will do its best to convert JSON-LD into a PHP representation.
     *
     *  Usage:
     *  <code>
     *   $document = JsonLD::parse('document.jsonld');
     *   print_r($document);
     *  </code>
     *
     * @param string $document Path to a JSON-LD document or a string
     *                         containing a JSON-LD document.
     *
     * @return mixed The JSON-LD document converted to a PHP representation.
     *
     * @throws ParseException If the JSON-LD is not valid
     *
     * @api
     */
    static public function parse($document)
    {
        // TODO Allow to pass an IRI?

        // if input is a file, process it
        $file = '';
        if ((strpos($document, "{") === false) && (strpos($document, "[") === false) && is_file($document)) {
            if (false === is_readable($document)) {
                throw new ParseException(sprintf('Unable to parse "%s" as the file is not readable.', $document));
            }
            $file = $document;
            $document = file_get_contents($file);
        }

        $jsonld = new Processor();

        try {
            return $jsonld->parse($document);
        } catch (ParseException $e) {
            if ($file) {
                $e->setParsedFile($file);
            }

            throw $e;
        }
    }

    /**
     * Expands a JSON-LD document.
     *
     * The parse method, when supplied with a JSON-LD stream (string or
     * file), will do its best to convert JSON-LD into a PHP array.
     *
     *  Usage:
     *  <code>
     *   $expanded = JsonLD::expand('document.jsonld');
     *   print_r($expanded);
     *  </code>
     *
     * @param string $document Path to a JSON-LD document or a string
     *                         containing a JSON-LD document
     * @param string $baseiri  The base IRI
     *
     * @return array The expanded JSON-LD document
     *
     * @throws ParseException   If the JSON-LD document couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If processing of the JSON-LD document failed.
     *
     * @api
     */
    static public function expand($document, $baseiri = null)
    {
        // TODO Document other exceptions that are thrown

        // TODO $document can be an IRI, if so overwrite $baseiri accordingly!?
        $document = self::parse($document);

        $processor = new Processor($baseiri);
        $activectx = array();

        $processor->expand($document, $activectx);

        if (false === is_array($document))
        {
            $document = array($document);
        }

        return $document;
    }

    /**
     * Compacts a JSON-LD document.
     *
     * The parse method, when supplied with a JSON-LD stream (string or
     * file), will do its best to convert JSON-LD into a PHP array.
     *
     *  Usage:
     *  <code>
     *   $compacted = JsonLD::compact('document.jsonld');
     *   print_r($compacted);
     *  </code>
     *
     * @param string $document Path to a JSON-LD document or a string
     *                         containing a JSON-LD document
     * @param mixed  $context  The context to use to compact the passed document
     * @param string $baseiri  The base IRI
     * @param bool   $optimize If set to true, the JSON-LD processor is allowed optimize
     *                         the passed context to produce even compacter representations
     *
     * @return mixed The compacted JSON-LD document
     *
     * @throws ParseException   If the JSON-LD document couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If processing of the JSON-LD document failed.
     *
     * @api
     */
    static public function compact($document, $context, $baseiri = null, $optimize = false)
    {
        // TODO Document other exceptions that are thrown

        // TODO $document can be an IRI, if so overwrite $baseiri accordingly!?
        $document = self::expand($document);

        $processor = new Processor($baseiri);

        // TODO Support contexts that are passed in the form of an IRI
        $activectx = array();
        $processor->processContext($context, $activectx);

        if (0 == count($activectx))
        {
            // passed context was empty, just return expanded document
            return (1 === count($document)) ? $document[0] : $document;
        }

        // Sort active context as compact() requires this
        uksort($activectx, array('ML\JsonLD\Processor', 'compare'));

        $processor->compact($document, $activectx, null, $optimize);

        // TODO Spec add context to result
        if (is_array($document))
        {
            $compactedDocument = new \stdClass();
            $compactedDocument->{'@context'} = $context;
            $compactedDocument->{'@set'} = $document;  // TODO Handle @set aliases!?

            return $compactedDocument;
        }
        else
        {
            $document->{'@context'} = $context;
        }

        return $document;
    }

    /**
     * Converts a PHP value to a JSON-LD string.
     *
     * The dump method will do its best to convert the supplied value into
     * a JSON-LD string.
     *
     * @param mixed $value  The value to convert.
     * @param bool  $pretty Use whitespace in returned string to format it?
     *
     * @return string A JSON-LD string.
     *
     * @api
     */
    static public function toString($value, $pretty = false)
    {
        $options = JSON_UNESCAPED_SLASHES;
        if ($pretty)
        {
            $options |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $options);
    }
}
