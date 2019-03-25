<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * RemoteDocument
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class RemoteDocument
{
    /**
     * @var string The URL of the loaded document.
     */
    public $documentUrl;

    /**
     * @var string The document's media type
     */
    public $mediaType;

    /**
     * @var mixed The retrieved document. This can either be the raw payload
     *            or the already parsed document.
     */
    public $document;

    /**
     * @var string|null The value of the context Link header if available;
     *                  otherwise null.
     */
    public $contextUrl;

    /**
     * Constructor
     *
     * @param null|string $documentUrl The final URL of the loaded document.
     * @param mixed       $document    The retrieved document (parsed or raw).
     * @param null|string $mediaType   The document's media type.
     * @param null|string $contextUrl  The value of the context Link header
     *                                 if available; otherwise null.
     */
    public function __construct($documentUrl = null, $document = null, $mediaType = null, $contextUrl = null)
    {
        $this->documentUrl = $documentUrl;
        $this->document = $document;
        $this->mediaType = $mediaType;
        $this->contextUrl = $contextUrl;
    }
}
