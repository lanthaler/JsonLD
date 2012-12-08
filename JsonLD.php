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
 */
class JsonLD
{
    /**
     * Parses a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $document = JsonLD::parse('document.jsonld');
     *    print_r($document);
     *  </code>
     *
     * @param string $input Path to a JSON-LD document or a string
     *                      containing a JSON-LD document.
     *
     * @return mixed The JSON-LD document converted to a PHP representation.
     *
     * @throws ParseException If the JSON-LD input document is invalid.
     */
    public static function parse($input)
    {
        if (false == is_string($input))
        {
            // Return as is, is already in processable form
            return $input;
        }

        // if input is a file, process it
        $file = $input;
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

            if (false === ($input = file_get_contents($file, false, $context)))
            {
                throw new ParseException(
                    sprintf('Unable to parse "%s" as the file is not readable.', $file));
            }
        }

        try
        {
            return Processor::parse($input);
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
     * Parses a JSON-LD document and returns it as a {@link Document}.
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $document = JsonLD::getDocument('document.jsonld');
     *  </code>
     *
     * <strong>Please note that currently all data is merged into one graph,
     *   named graphs are not supported yet!</strong>
     *
     * It is possible to configure the processing by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *
     * @param string|array|object $input The JSON-LD document to process.
     * @param null|array|object $options Options to configure the processing.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws ParseException If the JSON-LD input document is invalid.
     */
    public static function getDocument($input, $options = null)
    {
        // TODO $input can be an IRI, if so overwrite base iri accordingly
        $input = self::expand($input, $options);

        $processor = new Processor(self::mergeOptions($options));

        return $processor->getDocument($input);
    }

    /**
     * Expands a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $expanded = JsonLD::expand('document.jsonld');
     *    print_r($expanded);
     *  </code>
     *
     * It is possible to configure the expansion process by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object $input  The JSON-LD document to expand.
     * @param null|array|object $options  Options to configure the expansion
     *                                    process.
     *
     * @return array The expanded JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD input document or context
     *                          couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD input document or context
     *                          contains syntax errors.
     * @throws ProcessException If expanding the JSON-LD document failed.
     */
    public static function expand($input, $options = null)
    {
        // TODO $input can be an IRI, if so overwrite base iri accordingly
        $input = self::parse($input);

        $options = self::mergeOptions($options);

        $processor = new Processor($options);
        $activectx = array();

        if (null !== $options->expandContext)
        {
            $processor->processContext($options->expandContext, $activectx);
        }

        $processor->expand($input, $activectx);

        // optimize away default graph (@graph as the only property at the top-level object)
        if (is_object($input) && property_exists($input, '@graph') &&
            (1 == count(get_object_vars($input))))
        {
            $input = $input->{'@graph'};
        }

        if (false === is_array($input))
        {
            $input = array($input);
        }

        return $input;
    }

    /**
     * Compacts a JSON-LD document according a supplied context
     *
     * Both, the document and context can be supplied directly as strings or
     * by passing a file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
     *    print_r($compacted);
     *  </code>
     *
     * It is possible to configure the compaction process by setting the
     * options parameter accordingly. Available options are:
     *
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *   - <em>optimize</em>      If set to true, the processor is free to optimize
     *                            the result to produce an even compacter
     *                            representation than the algorithm described by
     *                            the official JSON-LD specification.
     *   - <em>compactArrays</em> If set to true, arrays holding just one element
     *                            are compacted to scalars, otherwise the arrays
     *                            are kept as arrays.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object $input The JSON-LD document to compact.
     * @param string|object $context     The context.
     * @param null|array|object $options Options to configure the compaction
     *                                   process.
     *
     * @return mixed The compacted JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD input document or context
     *                          couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD input document or context
     *                          contains syntax errors.
     * @throws ProcessException If compacting the JSON-LD document failed.
     */
    public static function compact($input, $context, $options = null)
    {
        $options = self::mergeOptions($options);

        // TODO $input can be an IRI, if so overwrite $baseiri accordingly!?
        $input = self::expand($input, $options);
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
        $processor = new Processor($options);

        $processor->processContext($context, $activectx);
        $inversectx = $processor->createInverseContext($activectx);
        $processor->compact($input, $activectx, $inversectx, null);

        $compactedDocument = new \stdClass();
        if (null !== $context)
        {
            $compactedDocument->{'@context'} = $context;
        }

        if (is_array($input) && (1 !== count($input)))
        {
            $graphKeyword = (isset($inversectx['@graph']['term']))
                ? $inversectx['@graph']['term']
                : '@graph';
            $compactedDocument->{$graphKeyword} = $input;
        }
        else
        {
            if (is_array($input))
            {
                $input = $input[0];
            }

            $compactedDocument = (object) ((array)$compactedDocument + (array)$input);
        }

        return $compactedDocument;
    }

    /**
     * Flattens a JSON-LD document
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $flattened = JsonLD::flatten('document.jsonld');
     *    print_r($flattened);
     *  </code>
     *
     * It is possible to configure the flattening process by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>     The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object $input  The JSON-LD document to flatten.
     * @param string $graph               The graph whose flattened node definitions should
     *                                    be returned. The default graph is identified by
     *                                    <code>@default</code> and the merged graph by
     *                                    <code>@merged</code>.
     * @param null|array|object $options  Options to configure the expansion
     *                                    process.
     *
     * @return array The flattened JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD input document or context
     *                          couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD input document or context
     *                          contains syntax errors.
     * @throws ProcessException If flattening the JSON-LD document failed.
     */
    public static function flatten($input, $graph = '@merged', $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);

        $processor = new Processor($options);

        return $processor->flatten($input, $graph);
    }

    /**
     * Converts a JSON-LD document to quads
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $quads = JsonLD::toQuads('document.jsonld');
     *    print_r($expanded);
     *  </code>
     *
     * It is possible to configure the extraction process by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object $input  The JSON-LD document to expand.
     * @param null|array|object $options  Options to configure the expansion
     *                                    process.
     *
     * @return array The extracted quads.
     *
     * @throws ParseException   If the JSON-LD input document or context
     *                          couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD input document or context
     *                          contains syntax errors.
     * @throws ProcessException If converting the JSON-LD document to quads failed.
     */
    public static function toQuads($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);

        $processor = new Processor($options);

        $quads = array();
        $processor->toQuads($input, $quads);

        return $quads;
    }

    /**
     * Converts an array of quads to a JSON-LD document
     *
     * Usage:
     *  <code>
     *    $document = JsonLD::fromQuads($quads);
     *    JsonLD::toString($document, true);
     *  </code>
     *
     * It is possible to configure the conversion process by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>           The base IRI of the input document.
     *   - <em>useNativeTypes</em> If set to true, native types are used for
     *                             xsd:integer, xsd:double, and xsd:boolean,
     *                             otherwise typed strings will be used instead.
     *   - <em>useRdfType</em>     If set to true, rdf:type will be used instead
     *                             of @type in document.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param Quad[] $quads              Array of quads.
     * @param null|array|object $options Options to configure the expansion
     *                                   process.
     *
     * @return array The JSON-LD document in expanded form.
     *
     * @throws InvalidQuadException If an invalid quad was detected.
     * @throws ProcessException If converting the quads to a JSON-LD document failed.
     */
    public static function fromQuads(array $quads, $options = null)
    {
        $options = self::mergeOptions($options);

        $processor = new Processor($options);

        return $processor->fromQuads($quads);
    }

    /**
     * Frame a JSON-LD document according a supplied frame
     *
     * Both, the document and context can be supplied directly as strings or
     * by passing a file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $result = JsonLD::frame('document.jsonld', 'frame.jsonldf');
     *    print_r($compacted);
     *  </code>
     *
     * It is possible to configure the framing process by setting the options
     * parameter accordingly. Available options are:
     *
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *   - <em>optimize</em>      If set to true, the processor is free to optimize
     *                            the result to produce an even compacter
     *                            representation than the algorithm described by
     *                            the official JSON-LD specification.
     *   - <em>compactArrays</em> If set to true, arrays holding just one element
     *                            are compacted to scalars, otherwise the arrays
     *                            are kept as arrays.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object $input  The JSON-LD document to compact.
     * @param string|object $frame        The frame.
     * @param null|array|object $options  Options to configure the framing
     *                                    process.
     *
     * @return mixed The resulting JSON-LD document.
     *
     * @throws ParseException   If the JSON-LD input document or context
     *                          couldn't be parsed.
     * @throws SyntaxException  If the JSON-LD input document or context
     *                          contains syntax errors.
     * @throws ProcessException If framing the JSON-LD document failed.
     */
    public static function frame($input, $frame, $options = null)
    {
        $options = self::mergeOptions($options);

        // TODO $input can be an IRI, if so overwrite $baseiri accordingly!?
        $input = self::expand($input, $options);
        $frame = self::parse($frame);

        if (false == is_object($frame))
        {
            throw new SyntaxException('Invalid frame detected. It must be an object.',
                                      $frame);
        }

        $processor = new Processor($options);

        // Prepare result document by saving the frame's context
        $framedDocument = new \stdClass();
        $frameActiveContext = array();
        if (property_exists($frame, '@context'))
        {
            $framedDocument->{'@context'} = $frame->{'@context'};
            $processor->processContext($frame->{'@context'}, $frameActiveContext);
        }
        $frameInverseContext = $processor->createInverseContext($frameActiveContext);

        // Expand the frame
        $processor->expand($frame, array(), null, true);

        // and optimize away default graph (@graph as the only property at the top-level object)
        if (is_object($frame) && property_exists($frame, '@graph') &&
            (1 == count(get_object_vars($frame))))
        {
            $frame = $frame->{'@graph'};
        }

        if (false === is_array($frame))
        {
            $frame = array($frame);
        }

        // Frame the input document
        $result = $processor->frame($input, $frame);

        // Compact the result using the frame's active context
        $processor->compact($result, $frameActiveContext, $frameInverseContext);

        // Make that the result is always an array
        if (false == is_array($result))
        {
            $result = array($result);
        }

        $graphKeyword = (isset($frameInverseContext['@graph']['term']))
            ? $frameInverseContext['@graph']['term']
            : '@graph';
        $framedDocument->{$graphKeyword} = $result;

        return $framedDocument;
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
     */
    public static function toString($value, $pretty = false)
    {
        $options = 0;

        if (PHP_VERSION_ID >= 50400)
        {
            $options |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

            if ($pretty)
            {
                $options |= JSON_PRETTY_PRINT;
            }

            return json_encode($value, $options);
        }
        else
        {
            $result = json_encode($value);
            $result = str_replace('\\/', '/', $result);  // unescape slahes

            // unescape unicode
            return preg_replace_callback(
                '/\\\\u([a-f0-9]{4})/',
                function ($match) {
                    return iconv('UCS-4LE', 'UTF-8', pack('V', hexdec($match[1])));
                },
                $result);
        }
    }

    /**
     * Merge the passed options with the option's default values.
     *
     * @param null|array|object $options The options.
     *
     * @return object The merged options.
     */
    private static function mergeOptions($options)
    {
        $result = (object)array(
            'base' => '',
            'expandContext' => null,
            'compactArrays' => true,
            'optimize' => false,
            'useNativeTypes' => true,
            'useRdfType' => false
        );

        if (is_array($options) || is_object($options))
        {
            $options = (object)$options;
            if (property_exists($options, 'base') && is_string($options->base))
            {
                $result->base = $options->base;
            }
            if (property_exists($options, 'expandContext'))
            {
                if (is_string($options->expandContext)) {
                    $result->expandContext = self::parse($options->expandContext);
                }
                elseif (is_object($options->expandContext))
                {
                    $result->expandContext = $options->expandContext;
                }
            }
            if (property_exists($options, 'compactArrays') && is_bool($options->compactArrays))
            {
                $result->compactArrays = $options->compactArrays;
            }
            if (property_exists($options, 'optimize') && is_bool($options->optimize))
            {
                $result->optimize = $options->optimize;
            }
            if (property_exists($options, 'useNativeTypes') && is_bool($options->useNativeTypes))
            {
                $result->useNativeTypes = $options->useNativeTypes;
            }
            if (property_exists($options, 'useRdfType') && is_bool($options->useRdfType))
            {
                $result->useRdfType = $options->useRdfType;
            }
        }

        return $result;
    }
}
