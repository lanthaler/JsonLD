<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Exception;

use ML\JsonLD\JsonLD;

/**
 * Exception class thrown when an error occurs during parsing.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class JsonLdException extends \RuntimeException
{
    /**
     * An unspecified error code (none was standardized yet)
     */
    const UNSPECIFIED = 'unknown';

    /**
     * The document could not be loaded or parsed as JSON.
     */
    const LOADING_DOCUMENT_FAILED = "loading document failed";

    /**
     * A list of lists was detected. List of lists are not supported in
     * this version of JSON-LD due to the algorithmic complexity.
     */
    const LIST_OF_LISTS = "list of lists";

    /**
     * An @index member was encountered whose value was not a string.
     */
    const INVALID_INDEX_VALUE = "invalid @index value";

    /**
     * Multiple conflicting indexes have been found for the same node.
     */
    const CONFLICTING_INDEXES = "conflicting indexes";

    /**
     * An @id member was encountered whose value was not a string.
     */
    const INVALID_ID_VALUE = "invalid @id value";

    /**
     * In invalid local context was detected.
     */
    const INVALID_LOCAL_CONTEXT = "invalid local context";

    /**
     * Multiple HTTP Link Headers [RFC5988] using th
     * http://www.w3.org/ns/json-ld#context link relation have been detected.
     */
    const MULTIPLE_CONTEXT_LINK_HEADERS = "multiple context link headers";

    /**
     * There was a problem encountered loading a remote context.
     */
    const LOADING_REMOTE_CONTEXT_FAILED = "loading remote context failed";

    /**
     * No valid context document has been found for a referenced,
     * remote context.
     */
    const INVALID_REMOTE_CONTEXT = "invalid remote context";

    /**
     * A cycle in remote context inclusions has been detected.
     */
    const RECURSIVE_CONTEXT_INCLUSION = "recursive context inclusion";

    /**
     * An invalid base IRI has been detected, i.e., it is neither an
     * absolute IRI nor null.
     */
    const INVALID_BASE_IRI = "invalid base IRI";

    /**
     * An invalid vocabulary mapping has been detected, i.e., it is
     * neither an absolute IRI nor null.
     */
    const INVALID_VOCAB_MAPPING = "invalid vocab mapping";

    /**
     * The value of the default language is not a string or null and
     * thus invalid.
     */
    const INVALID_DEFAULT_LANGUAGE = "invalid default language";

    /**
     * A keyword redefinition has been detected.
     */
    const KEYWORD_REDEFINITION = "keyword redefinition";

    /**
     * An invalid term definition has been detected.
     */
    const INVALID_TERM_DEFINITION = "invalid term definition";

    /**
     * An invalid reverse property definition has been detected.
     */
    const INVALID_REVERSE_PROPERTY = "invalid reverse property";

    /**
     * IRI mapping A local context contains a term that has an invalid
     * or missing IRI mapping.
     */
    const INVALID_IRI_MAPPING = "invalid IRI mapping";

    /**
     * IRI mapping A cycle in IRI mappings has been detected.
     */
    const CYCLIC_IRI_MAPPING = "cyclic IRI mapping";

    /**
     * An invalid keyword alias definition has been encountered.
     */
    const INVALID_KEYWORD_ALIAS = "invalid keyword alias";

    /**
     * An @type member in a term definition was encountered whose value
     * could not be expanded to an absolute IRI.
     */
    const INVALID_TYPE_MAPPING = "invalid type mapping";

    /**
     * An @language member in a term definition was encountered whose
     * value was neither a string nor null and thus invalid.
     */
    const INVALID_LANGUAGE_MAPPING = "invalid language mapping";

    /**
     * Two properties which expand to the same keyword have been detected.
     * This might occur if a keyword and an alias thereof are used at the
     * same time.
     */
    const COLLIDING_KEYWORDS = "colliding keywords";

    /**
     * An @container member was encountered whose value was not one of
     * the following strings: @list, @set, or @index.
     */
    const INVALID_CONTAINER_MAPPING = "invalid container mapping";

    /**
     * An invalid value for an @type member has been detected, i.e., the
     * value was neither a string nor an array of strings.
     */
    const INVALID_TYPE_VALUE = "invalid type value";

    /**
     * A value object with disallowed members has been detected.
     */
    const INVALID_VALUE_OBJECT = "invalid value object";

    /**
     * An invalid value for the @value member of a value object has been
     * detected, i.e., it is neither a scalar nor null.
     */
    const INVALID_VALUE_OBJECT_VALUE = "invalid value object value";

    /**
     * A language-tagged string with an invalid language value was detected.
     */
    const INVALID_LANGUAGE_TAGGED_STRING = "invalid language-tagged string";

    /**
     * A number, true, or false with an associated language tag was detected.
     */
    const INVALID_LANGUAGE_TAGGED_VALUE = "invalid language-tagged value";

    /**
     * A typed value with an invalid type was detected.
     */
    const INVALID_TYPED_VALUE = "invalid typed value";

    /**
     * A set object or list object with disallowed members has been detected.
     */
    const INVALID_SET_OR_LIST_OBJECT = "invalid set or list object";

    /**
     * An invalid value in a language map has been detected. It has to be
     * a string or an array of strings.
     */
    const INVALID_LANGUAGE_MAP_VALUE = "invalid language map value";

    /**
     * The compacted document contains a list of lists as multiple lists
     * have been compacted to the same term.
     */
    const COMPACTION_TO_LIST_OF_LISTS = "compaction to list of lists";

    /**
     * An invalid reverse property map has been detected. No keywords apart
     * from @context are allowed in reverse property maps.
     */
    const INVALID_REVERSE_PROPERTY_MAP = "invalid reverse property map";

    /**
     * An invalid value for an @reverse member has been detected, i.e., the
     * value was not a JSON object.
     */
    const INVALID_REVERSE_VALUE = "invalid @reverse value";

    /**
     * An invalid value for a reverse property has been detected. The value
     * of an inverse property must be a node object.
     */
    const INVALID_REVERSE_PROPERTY_VALUE = "invalid reverse property value";

    /**
     * The JSON-LD snippet that triggered the error
     *
     * @var null|string
     */
    private $snippet;

    /**
     * The document that triggered the error
     *
     * @var null|string
     */
    private $document;

    /**
     * The raw error message (containing place-holders)
     *
     * @var string
     */
    private $rawMessage;

    /**
     * Constructor.
     *
     * @param string          $code     The error code
     * @param null|string     $message  The error message
     * @param null|mixed      $snippet  The code snippet
     * @param null|string     $document The document that triggered the error
     * @param null|\Exception $previous The previous exception
     */
    public function __construct($code, $message = null, $snippet = null, $document = null, \Exception $previous = null)
    {
        $this->code = $code;
        $this->document = $document;
        $this->snippet = ($snippet) ? JsonLD::toString($snippet) : $snippet;
        $this->rawMessage = $message;

        $this->updateMessage();

        parent::__construct($this->message, 0, $previous);
    }

    /**
     * Gets the snippet of code near the error.
     *
     * @return null|string The snippet of code
     */
    public function getSnippet()
    {
        return $this->snippet;
    }

    /**
     * Gets the document that triggered the error
     *
     * @return null|string The document that triggered the error
     */
    public function getParsedFile()
    {
        return $this->document;
    }

    /**
     * Updates the exception message by including the file name if available.
     */
    private function updateMessage()
    {
        $this->message = $this->rawMessage;

        $dot = false;
        if ('.' === substr($this->message, -1)) {
            $this->message = substr($this->message, 0, -1);
            $dot = true;
        }

        if (null !== $this->document) {
            $this->message .= sprintf(' in %s', $this->document);
        }

        if ($this->snippet) {
            $this->message .= sprintf(' (near %s)', $this->snippet);
        }

        if ($dot) {
            $this->message .= '.';
        }
    }
}
