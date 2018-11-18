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
 * JsonLD
 *
 * JsonLD implements the algorithms defined by the
 * {@link http://www.w3.org/TR/json-ld-api/ JSON-LD 1.0 API and Processing Algorithms specification}.
 * Its interface is, apart from the usage of Promises, exactly the same as the one
 * defined by the specification.
 *
 * Furthermore, it implements an enhanced version of the
 * {@link http://json-ld.org/spec/latest/json-ld-framing/ JSON-LD Framing 1.0 draft}
 * and an object-oriented interface to access and manipulate JSON-LD documents.
 *
 * @api
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class JsonLD
{
    /** Identifier for the default graph */
    const DEFAULT_GRAPH = '@default';

    /** Identifier for the merged graph */
    const MERGED_GRAPH = '@merged';

    private static $documentLoader = null;

    /**
     * Load and parse a JSON-LD document
     *
     * The document can be supplied directly as string, by passing a file
     * path, or by passing a URL.
     *
     * Usage:
     *
     *     $document = JsonLD::getDocument('document.jsonld');
     *     print_r($document->getGraphNames());
     *
     * It is possible to configure the processing by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>documentFactory</dt>
     *   <dd>The document factory.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array $input   The JSON-LD document to process.
     * @param null|array|JsonObject   $options Options to configure the processing.
     *
     * @return Document The parsed JSON-LD document.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function getDocument($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);

        $processor = new Processor($options);

        return $processor->getDocument($input);
    }

    /**
     * Expand a JSON-LD document
     *
     * The document can be supplied directly as string, by passing a file
     * path, or by passing a URL.
     *
     * Usage:
     *
     *     $expanded = JsonLD::expand('document.jsonld');
     *     print_r($expanded);
     *
     * It is possible to configure the expansion process by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array $input   The JSON-LD document to expand.
     * @param null|array|JsonObject   $options Options to configure the expansion
     *                                         process.
     *
     * @return array The expanded JSON-LD document.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function expand($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $processor = new Processor($options);
        $activectx = array('@base' => null);

        if (is_string($input)) {
            $remoteDocument = $options->documentLoader->loadDocument($input);

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
     * Compact a JSON-LD document according a supplied context
     *
     * Both the document and the context can be supplied directly as string,
     * by passing a file path, or by passing a URL.
     *
     * Usage:
     *
     *     $compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
     *     print_r($compacted);
     *
     * It is possible to configure the compaction process by setting the
     * options parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>optimize</dt>
     *   <dd>If set to true, the processor is free to optimize the result to
     *     produce an even compacter representation than the algorithm
     *     described by the official JSON-LD specification.</dd>
     *
     *   <dt>compactArrays</dt>
     *   <dd>If set to true, arrays holding just one element are compacted
     *     to scalars, otherwise the arrays are kept as arrays.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array      $input   The JSON-LD document to
     *                                              compact.
     * @param null|string|JsonObject|array $context The context.
     * @param null|array|JsonObject        $options Options to configure the
     *                                              compaction process.
     *
     * @return JsonObject The compacted JSON-LD document.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function compact($input, $context = null, $options = null)
    {
        $options = self::mergeOptions($options);

        $expanded = self::expand($input, $options);

        return self::doCompact($expanded, $context, $options);
    }

    /**
     * Compact a JSON-LD document according a supplied context
     *
     * In contrast to {@link compact()}, this method assumes that the input
     * has already been expanded.
     *
     * @param array                        $input       The JSON-LD document to
     *                                                  compact.
     * @param null|string|JsonObject|array $context     The context.
     * @param JsonObject                   $options     Options to configure the
     *                                                  compaction process.
     * @param bool                         $alwaysGraph If set to true, the resulting
     *                                                  document will always explicitly
     *                                                  contain the default graph at
     *                                                  the top-level.
     *
     * @return JsonObject The compacted JSON-LD document.
     *
     * @throws JsonLdException
     */
    private static function doCompact($input, $context, $options, $alwaysGraph = false)
    {
        if (is_string($context)) {
            $context = $options->documentLoader->loadDocument($context)->document;
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

        $compactedDocument = new JsonObject();
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
     * Flatten a JSON-LD document
     *
     * Both the document and the context can be supplied directly as string,
     * by passing a file path, or by passing a URL.
     *
     * Usage:
     *
     *     $flattened = JsonLD::flatten('document.jsonld');
     *     print_r($flattened);
     *
     * It is possible to configure the flattening process by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>graph</dt>
     *   <dd>The graph whose flattened representation should be returned.
     *     The default graph is identified by {@link DEFAULT_GRAPH} and the
     *     merged dataset graph by {@link MERGED_GRAPH}. If <em>null</em> is
     *     passed, all graphs will be returned.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array      $input   The JSON-LD document to flatten.
     * @param null|string|JsonObject|array $context The context to compact the
     *                                              flattened document. If
     *                                              <em>null</em> is passed, the
     *                                              result will not be compacted.
     * @param null|array|JsonObject        $options Options to configure the
     *                                              flattening process.
     *
     * @return JsonObject The flattened JSON-LD document.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function flatten($input, $context = null, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);

        $processor = new Processor($options);
        $flattened = $processor->flatten($input);

        if (null === $context) {
            return $flattened;
        }

        return self::doCompact($flattened, $context, $options, true);
    }

    /**
     * Convert a JSON-LD document to RDF quads
     *
     * The document can be supplied directly as string, by passing a file
     * path, or by passing a URL.
     *
     * Usage:
     *
     *     $quads = JsonLD::toRdf('document.jsonld');
     *     print_r($quads);
     *
     * It is possible to configure the extraction process by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array $input   The JSON-LD document to expand.
     * @param null|array|JsonObject   $options Options to configure the expansion
     *                                         process.
     *
     * @return Quad[] The extracted quads.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function toRdf($input, $options = null)
    {
        $options = self::mergeOptions($options);

        $expanded = self::expand($input, $options);

        $processor = new Processor($options);

        return $processor->toRdf($expanded);
    }

    /**
     * Convert an array of RDF quads to a JSON-LD document
     *
     * Usage:
     *
     *     $document = JsonLD::fromRdf($quads);
     *     print(JsonLD::toString($document, true));
     *
     * It is possible to configure the conversion process by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>useNativeTypes</dt>
     *   <dd>If set to true, native types are used for <em>xsd:integer</em>,
     *     <em>xsd:double</em>, and <em>xsd:boolean</em>; otherwise,
     *     typed strings will be used instead.</dd>
     *
     *   <dt>useRdfType</dt>
     *   <dd>If set to true, <em>rdf:type</em> will be used instead of <em>@type</em>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param Quad[]                $quads   Array of quads.
     * @param null|array|JsonObject $options Options to configure the expansion
     *                                       process.
     *
     * @return array The JSON-LD document in expanded form.
     *
     * @throws InvalidQuadException If an invalid quad was detected.
     * @throws JsonLdException      If converting the quads to a JSON-LD document failed.
     *
     * @api
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
     * Both the document and the frame can be supplied directly as string,
     * by passing a file path, or by passing a URL.
     *
     * Usage:
     *
     *     $result = JsonLD::frame('document.jsonld', 'frame.jsonldf');
     *     print_r($compacted);
     *
     * It is possible to configure the framing process by setting the options
     * parameter accordingly. Available options are:
     *
     * <dl>
     *   <dt>base</dt>
     *   <dd>The base IRI of the input document.</dd>
     *
     *   <dt>expandContext</dt>
     *   <dd>An optional context to use additionally to the context embedded
     *     in input when expanding the input.</dd>
     *
     *   <dt>optimize</dt>
     *   <dd>If set to true, the processor is free to optimize the result to
     *     produce an even compacter representation than the algorithm
     *     described by the official JSON-LD specification.</dd>
     *
     *   <dt>compactArrays</dt>
     *   <dd>If set to true, arrays holding just one element are compacted
     *     to scalars, otherwise the arrays are kept as arrays.</dd>
     *
     *   <dt>documentLoader</dt>
     *   <dd>The document loader.</dd>
     * </dl>
     *
     * The options parameter might be passed as associative array or as
     * object.
     *
     * @param string|JsonObject|array $input   The JSON-LD document to compact.
     * @param string|JsonObject       $frame   The frame.
     * @param null|array|JsonObject   $options Options to configure the framing
     *                                         process.
     *
     * @return JsonObject The framed JSON-LD document.
     *
     * @throws JsonLdException
     *
     * @api
     */
    public static function frame($input, $frame, $options = null)
    {
        $options = self::mergeOptions($options);

        $input = self::expand($input, $options);
        $frame = (is_string($frame))
            ? $options->documentLoader->loadDocument($frame)->document
            : $frame;

        if (false === is_object($frame)) {
            throw new JsonLdException(
                JsonLdException::UNSPECIFIED,
                'Invalid frame detected. It must be an object.',
                $frame
            );
        }

        $processor = new Processor($options);

        // Store the frame's context as $frame gets modified
        $frameContext = new JsonObject();
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
     * Convert the PHP structure returned by the various processing methods
     * to a string
     *
     * Usage:
     *
     *     $compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
     *     $prettyString = JsonLD::toString($compacted, true);
     *     print($prettyString);
     *
     * @param mixed $value  The value to convert.
     * @param bool  $pretty Use whitespace in returned string to format it
     *                      (this just works in PHP >=5.4)?
     *
     * @return string
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
     * Merge the passed options with the options' default values.
     *
     * @param null|array|JsonObject $options The options.
     *
     * @return JsonObject The merged options.
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
            'documentFactory' => null,
            'documentLoader' => new FileGetContentsLoader()
        );

        if (is_array($options) || is_object($options)) {
            $options = (object) $options;
            if (isset($options->{'base'})) {
                if (is_string($options->{'base'})) {
                    $result->base = new IRI($options->{'base'});
                } elseif (($options->{'base'} instanceof IRI) && $options->{'base'}->isAbsolute()) {
                    $result->base = clone $options->{'base'};
                } else {
                    throw new \InvalidArgumentException('The "base" option must be set to null or an absolute IRI.');
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
            if (property_exists($options, 'documentLoader') &&
                ($options->documentLoader instanceof DocumentLoaderInterface)) {
                $result->documentLoader = $options->documentLoader;
            } elseif (null !== self::$documentLoader) {
                $result->documentLoader = self::$documentLoader;
            }
            if (property_exists($options, 'expandContext')) {
                if (is_string($options->expandContext)) {
                    $result->expandContext = $result->documentLoader->loadDocument($options->expandContext)->document;
                } elseif (is_object($options->expandContext)) {
                    $result->expandContext = $options->expandContext;
                }
                if (is_object($result->expandContext) && property_exists($result->expandContext, '@context')) {
                    $result->expandContext = $result->expandContext->{'@context'};
                }
            }
        }

        return $result;
    }

    /**
     * Set the default document loader.
     *
     * It can be overridden in individual operations by setting the
     * `documentLoader` option.
     *
     * @param DocumentLoaderInterface $documentLoader
     */
    public static function setDefaultDocumentLoader(DocumentLoaderInterface $documentLoader)
    {
        self::$documentLoader = $documentLoader;
    }
}
