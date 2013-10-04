<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use stdClass as Object;
use ML\JsonLD\Exception\JsonLdException;
use ML\JsonLD\Exception\InvalidQuadException;
use ML\IRI\IRI;

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
     * @throws JsonLdException
     */
    public static function parse($input)
    {
        if (false === is_string($input)) {
            // Return as is - it has already been parsed
            return $input;
        }

        $document = FileGetContentsLoader::loadDocument($input);

        return $document->document;
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
     *   - <em>base</em>            The base IRI of the input document.
     *   - <em>expandContext</em>   An optional context to use additionally
     *                              to the context embedded in input when
     *                              expanding the input.
     *   - <em>documentFactory</em> The document factory.
     *
     * @param string|array|object $input   The JSON-LD document to process.
     * @param null|array|object   $options Options to configure the processing.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws JsonLdException
     */
    public static function getDocument($input, $options = null)
    {
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
     * @param string|array|object $input   The JSON-LD document to expand.
     * @param null|array|object   $options Options to configure the expansion
     *                                     process.
     *
     * @return array The expanded JSON-LD document.
     *
     * @throws JsonLdException
     */
    public static function expand($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $processor = new Processor($options);
        $activectx = array('@base' => null);

        if (is_string($input)) {
            $remoteDocument = FileGetContentsLoader::loadDocument($input);

            $input = $remoteDocument->document;
            $activectx['@base'] = new IRI($remoteDocument->documentUrl);

            if (null !== $remoteDocument->contextUrl) {
                $processor->processContext($remoteDocument->contextUrl, $activectx);
            }
        }

        if ($options->base) {
            $activectx['@base'] = $options->base;
        }

        if (null !== $options->expandContext) {
            $processor->processContext($options->expandContext, $activectx);
        }

        $processor->expand($input, $activectx);

        // optimize away default graph (@graph as the only property at the top-level object)
        if (is_object($input) && property_exists($input, '@graph') && (1 === count(get_object_vars($input)))) {
            $input = $input->{'@graph'};
        }

        if (false === is_array($input)) {
            $input = (null === $input) ? array() : array($input);
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
     * @param array               $input       The expandedJSON-LD document to
     *                                         compact.
     * @param string|object|array $context     The context.
     * @param null|array|object   $options     Options to configure the
     *                                         compaction process.
     *
     * @return object The compacted JSON-LD document.
     *
     * @throws JsonLdException
     */
    public static function compact($input, $context = null, $options = null)
    {
        $options = self::mergeOptions($options);

        $expanded = self::expand($input, $options);

        return self::doCompact($expanded, $context, $options);
    }

    /**
     * Compacts a JSON-LD document according a supplied context
     *
     * In contrast to {@link compact()} this method assumes that the input
     * has already been expanded.
     *
     * @param array               $input       The expandedJSON-LD document to
     *                                         compact.
     * @param string|object|array $context     The context.
     * @param null|array|object   $options     Options to configure the
     *                                         compaction process.
     * @param bool                $alwaysGraph If set to true, the resulting
     *                                         document will always explicitly
     *                                         contain the default graph at
     *                                         the top-level.
     *
     * @return object The compacted JSON-LD document.
     *
     * @throws JsonLdException
     */
    private static function doCompact($input, $context = null, $options = null, $alwaysGraph = false)
    {
        if (null !== $context) {
            $context = self::parse($context);
        }

        if (is_object($context) && property_exists($context, '@context')) {
            $context = $context->{'@context'};
        }

        if (is_object($context) && (0 === count(get_object_vars($context)))) {
            $context = null;
        } elseif (is_array($context) && (0 === count($context))) {
            $context = null;
        }

        $activectx = array('@base' => $options->base);
        $processor = new Processor($options);

        $processor->processContext($context, $activectx);
        $inversectx = $processor->createInverseContext($activectx);

        $processor->compact($input, $activectx, $inversectx);

        $compactedDocument = new Object();
        if (null !== $context) {
            $compactedDocument->{'@context'} = $context;
        }

        if ((false === is_array($input)) || (0 === count($input))) {
            if (false === $alwaysGraph) {
                $compactedDocument = (object) ((array) $compactedDocument + (array) $input);

                return $compactedDocument;
            }

            if (false === is_array($input)) {
                $input = array($input);
            }
        }

        $graphKeyword = (isset($inversectx['@graph']['term']))
            ? $inversectx['@graph']['term']
            : '@graph';

        $compactedDocument->{$graphKeyword} = $input;

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
     *   - <em>base</em>          The base IRI of the input document.
     *   - <em>expandContext</em> An optional context to use additionally
     *                            to the context embedded in input when
     *                            expanding the input.
     *   - <em>graph</em>         The graph whose flattened representation
     *                            should be returned. The default graph is
     *                            identified by `@default` and the merged
     *                            graph by `@merged`. If `null` is passed,
     *                            all graphs will be returned.
     *
     * The options parameter might be passed as an associative array or an
     * object.
     *
     * @param string|array|object      $input   The JSON-LD document to flatten.
     * @param null|string|object|array $context The context to compact the
     *                                          flattened document. If `null`
     *                                          is passed, the result will
     *                                          not be compacted.
     * @param null|array|object        $options Options to configure the
     *                                          flattening process.
     *
     * @return object The flattened JSON-LD document.
     *
     * @throws JsonLdException
     */
    public static function flatten($input, $context = null, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);

        $processor = new Processor($options);
        $flattened = $processor->flatten($input, $options->graph);

        if (null === $context) {
            return $flattened;
        }

        return self::doCompact($flattened, $context, $options, true);
    }

    /**
     * Converts a JSON-LD document to RDF quads
     *
     * The document can be supplied directly as a string or by passing a
     * file path or an IRI.
     *
     * Usage:
     *  <code>
     *    $quads = JsonLD::toRdf('document.jsonld');
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
     * @param string|array|object $input   The JSON-LD document to expand.
     * @param null|array|object   $options Options to configure the expansion
     *                                    process.
     *
     * @return array The extracted quads.
     *
     * @throws JsonLdException
     */
    public static function toRdf($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $expanded = self::expand($input, $options);

        $processor = new Processor($options);

        return $processor->toRdf($expanded);
    }

    /**
     * Converts an array of RDF quads to a JSON-LD document
     *
     * Usage:
     *  <code>
     *    $document = JsonLD::fromRdf($quads);
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
     * @param Quad[]            $quads   Array of quads.
     * @param null|array|object $options Options to configure the expansion
     *                                   process.
     *
     * @return array The JSON-LD document in expanded form.
     *
     * @throws InvalidQuadException If an invalid quad was detected.
     * @throws JsonLdException      If converting the quads to a JSON-LD document failed.
     */
    public static function fromRdf(array $quads, $options = null)
    {
        $options = self::mergeOptions($options);

        $processor = new Processor($options);

        return $processor->fromRdf($quads);
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
     * @param string|array|object $input   The JSON-LD document to compact.
     * @param string|object       $frame   The frame.
     * @param null|array|object   $options Options to configure the framing
     *                                     process.
     *
     * @return mixed The resulting JSON-LD document.
     *
     * @throws JsonLdException
     */
    public static function frame($input, $frame, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);
        $frame = self::parse($frame);

        if (false === is_object($frame)) {
            throw new JsonLdException(
                JsonLdException::UNSPECIFIED,
                'Invalid frame detected. It must be an object.',
                $frame
            );
        }

        $processor = new Processor($options);

        // Store the frame as $frame gets modified
        $frameContext = new Object();
        if (property_exists($frame, '@context')) {
            $frameContext->{'@context'} = $frame->{'@context'};
        }

        // Expand the frame
        $processor->expand($frame, array(), null, true);

        // and optimize away default graph (@graph as the only property at the top-level object)
        if (is_object($frame) && property_exists($frame, '@graph') && (1 === count(get_object_vars($frame)))) {
            $frame = $frame->{'@graph'};
        }

        if (false === is_array($frame)) {
            $frame = array($frame);
        }

        // Frame the input document
        $result = $processor->frame($input, $frame);

        // Compact the result using the frame's active context
        return self::doCompact($result, $frameContext, $options, true);
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

        if (PHP_VERSION_ID >= 50400) {
            $options |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

            if ($pretty) {
                $options |= JSON_PRETTY_PRINT;
            }

            return json_encode($value, $options);
        } else {
            $result = json_encode($value);
            $result = str_replace('\\/', '/', $result);  // unescape slahes

            // unescape unicode
            return preg_replace_callback(
                '/\\\\u([a-f0-9]{4})/',
                function ($match) {
                    return iconv('UCS-4LE', 'UTF-8', pack('V', hexdec($match[1])));
                },
                $result
            );
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
        $result = (object) array(
            'base' => null,
            'expandContext' => null,
            'compactArrays' => true,
            'optimize' => false,
            'graph' => null,
            'useNativeTypes' => false,
            'useRdfType' => false,
            'produceGeneralizedRdf' => false,
            'documentFactory' => null
        );

        if (is_array($options) || is_object($options)) {
            $options = (object) $options;
            if (isset($options->{'base'})) {
                if (is_string($options->{'base'})) {
                    $result->base = new IRI($options->{'base'});
                } elseif (($options->{'base'} instanceof IRI) && $options->{'base'}->isAbsolute()) {
                    $result->base = clone $options->{'base'};
                } else {
                    throw \InvalidArgumentException('The "base" option must be set to null or an absolute IRI.');
                }
            }
            if (property_exists($options, 'expandContext')) {
                if (is_string($options->expandContext)) {
                    $result->expandContext = self::parse($options->expandContext);
                } elseif (is_object($options->expandContext)) {
                    $result->expandContext = $options->expandContext;
                }

                if (is_object($result->expandContext) && property_exists($result->expandContext, '@context')) {
                    $result->expandContext = $result->expandContext->{'@context'};
                }
            }
            if (property_exists($options, 'compactArrays') && is_bool($options->compactArrays)) {
                $result->compactArrays = $options->compactArrays;
            }
            if (property_exists($options, 'optimize') && is_bool($options->optimize)) {
                $result->optimize = $options->optimize;
            }
            if (property_exists($options, 'graph') && is_string($options->graph)) {
                $result->graph = $options->graph;
            }
            if (property_exists($options, 'useNativeTypes') && is_bool($options->useNativeTypes)) {
                $result->useNativeTypes = $options->useNativeTypes;
            }
            if (property_exists($options, 'useRdfType') && is_bool($options->useRdfType)) {
                $result->useRdfType = $options->useRdfType;
            }
            if (property_exists($options, 'produceGeneralizedRdf') && is_bool($options->produceGeneralizedRdf)) {
                $result->produceGeneralizedRdf = $options->produceGeneralizedRdf;
            }
            if (property_exists($options, 'documentFactory') &&
                ($options->documentFactory instanceof DocumentFactoryInterface)) {
                $result->documentFactory = $options->documentFactory;
            }
        }

        return $result;
    }
}
