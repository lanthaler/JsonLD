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
     * Parses a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     *  Usage:
     *  <code>
     *    $document = JsonLD::parse('document.jsonld');
     *    print_r($document);
     *  </code>
     *
     * @param string $document Path to a JSON-LD document or a string
     *                         containing a JSON-LD document.
     *
     * @return mixed The JSON-LD document converted to a PHP representation.
     *
     * @throws ParseException If the JSON-LD is not valid.
     *
     * @api
     */
    static public function parse($document)
    {
        if (false == is_string($document))
        {
            // Return as is, is already in processable form
            return $document;
        }

        // if input is a file, process it
        $file = $document;
        if (((strpos($file, "{") === false) && (strpos($file, "[") === false)) ||
            @is_readable($file)) {

              $context = stream_context_create(array(
                'http' => array(
                  'method'  => 'GET',
                  'header'  => "Accept: application/ld+json\r\n",
                  'timeout' => Processor::REMOTE_TIMEOUT,
                ),
                'https' => array(
                  'method'  => 'GET',
                  'header'  => "Accept: application/ld+json\r\n",
                  'timeout' => Processor::REMOTE_TIMEOUT,
                )
              ));

            if (false === ($document = file_get_contents($file, false, $context)))
            {
                throw new ParseException(
                    sprintf('Unable to parse "%s" as the file is not readable.', $file));
            }
        }

        try
        {
            $jsonld = new Processor();

            return $jsonld->parse($document);
        }
        catch (ParseException $e)
        {
            if ($file)
            {
                $e->setParsedFile($file);
            }

            throw $e;
        }
    }

    /**
     * Expands a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     *  Usage:
     *  <code>
     *    $expanded = JsonLD::expand('document.jsonld');
     *    print_r($expanded);
     *  </code>
     *
     * @param string $document The JSON-LD document to expand.
     * @param string $baseiri  The base IRI.
     *
     * @return array The expanded JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD document or a remote context couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If processing of the JSON-LD document failed.
     *
     * @api
     */
    static public function expand($document, $baseiri = null)
    {
        // TODO $document can be an IRI, if so overwrite $baseiri accordingly!?
        $document = self::parse($document);

        $processor = new Processor($baseiri);
        $activectx = array();

        $processor->expand($document, $activectx);

        // optimize away default graph (@graph as the only property at the top-level object)
        if (is_object($document) && property_exists($document, '@graph') &&
            (1 == count(get_object_vars($document))))
        {
            $document = $document->{'@graph'};
        }

        if (false === is_array($document))
        {
            $document = array($document);
        }

        return $document;
    }

    /**
     * Compacts a JSON-LD document according a supplied context
     *
     * Both, the document and context can be supplied directly as strings or
     * by passing a file path or an IRI.
     *
     *  Usage:
     *  <code>
     *    $compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
     *    print_r($compacted);
     *  </code>
     *
     * @param mixed  $document The JSON-LD document to compact.
     * @param mixed  $context  The context.
     * @param string $baseiri  The base IRI.
     * @param bool   $optimize If set to true, the JSON-LD processor is allowed optimize
     *                         the passed context to produce even compacter representations.
     *
     * @return mixed The compacted JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD document or context couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document or context contains syntax errors.
     * @throws ProcessException If compaction failed.
     *
     * @api
     */
    static public function compact($document, $context, $baseiri = null, $optimize = false)
    {
        // TODO $document can be an IRI, if so overwrite $baseiri accordingly!?
        $document = self::expand($document, $baseiri);
        $context = self::parse($context);

        if (false == is_object($context) || (false == property_exists($context, '@context')))
        {
            $context = null;
        }
        else
        {
            $context = $context->{'@context'};
        }

        $activectx = array();
        $processor = new Processor($baseiri);

        $processor->processContext($context, $activectx);
        $processor->compact($document, $activectx, null, $optimize);

        $compactedDocument = new \stdClass();
        if (null !== $context)
        {
            $compactedDocument->{'@context'} = $context;
        }

        if (is_array($document))
        {
            $graphKeyword = $processor->compactIri('@graph', $activectx);
            $compactedDocument->{$graphKeyword} = $document;
        }
        else
        {
            $compactedDocument = (object) ((array)$compactedDocument + (array)$document);
        }

        return $compactedDocument;
    }

    /**
     * Flattens a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     *  Usage:
     *  <code>
     *    $flattened = JsonLD::flatten('document.jsonld');
     *    print_r($flattened);
     *  </code>
     *
     * @param string $document The JSON-LD document to expand.
     * @param string $graph    The graph whose flattened node definitions should
     *                         be returned. The default graph is identified by
     *                         <code>@default</code> and the merged graph by
     *                         <code>@merged</code>.
     * @param string $baseiri  The base IRI.
     *
     * @return array The flattened JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD document or a remote context couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD document contains syntax errors.
     * @throws ProcessException If processing of the JSON-LD document failed.
     *
     * @api
     */
    static public function flatten($document, $graph = '@merged', $baseiri = null)
    {
        $document = self::expand($document, $baseiri);

        $processor = new Processor($baseiri);

        return $processor->flatten($document, $graph);
    }

    /**
     * Converts a PHP value to a JSON-LD string.
     *
     * The dump method will do its best to convert the supplied value into
     * a JSON-LD string.
     *
     * @param mixed $value  The value to convert.
     * @param bool  $pretty Use whitespace in returned string to format it
     *                      (this just works in PHP >=5.4)?
     *
     * @return string A JSON-LD string.
     *
     * @api
     */
    static public function toString($value, $pretty = false)
    {
        if (defined('JSON_UNESCAPED_SLASHES'))
        {
            $options = JSON_UNESCAPED_SLASHES;
            if ($pretty)
            {
                $options |= JSON_PRETTY_PRINT;
            }

            return json_encode($value, $options);
        }

        $result = json_encode($value);
        return str_replace('\\/', '/', $result);
    }
}
