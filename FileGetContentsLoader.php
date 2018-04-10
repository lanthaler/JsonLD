<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\JsonLdException;
use ML\IRI\IRI;

/**
 * The FileGetContentsLoader loads remote documents by calling file_get_contents
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class FileGetContentsLoader implements DocumentLoaderInterface
{
    /**
     * {@inheritdoc}
     */
    public function loadDocument($url)
    {
        // if input looks like a file, try to retrieve it
        $input = trim($url);
        if (false === (isset($input[0]) && ("{" === $input[0]) || ("[" === $input[0]))) {
            $remoteDocument = new RemoteDocument($url);

            $streamContextOptions = array(
              'method'  => 'GET',
              'header'  => "Accept: application/ld+json, application/json; q=0.9, */*; q=0.1\r\n",
              'timeout' => Processor::REMOTE_TIMEOUT
            );

            $context = stream_context_create(array(
                'http' => $streamContextOptions,
                'https' => $streamContextOptions
            ));

            $httpHeadersOffset = 0;

            stream_context_set_params($context, array('notification' =>
                function ($code, $severity, $msg, $msgCode, $bytesTx, $bytesMax) use (
                    &$remoteDocument, &$http_response_header, &$httpHeadersOffset
                ) {
                    if ($code === STREAM_NOTIFY_MIME_TYPE_IS) {
                        $remoteDocument->mediaType = $msg;
                    } elseif ($code === STREAM_NOTIFY_REDIRECTED) {
                        $remoteDocument->documentUrl = $msg;
                        $remoteDocument->mediaType = null;

                        $httpHeadersOffset = count($http_response_header);
                    }
                }
            ));

            if (false === ($input = @file_get_contents($url, false, $context))) {
                throw new JsonLdException(
                    JsonLdException::LOADING_DOCUMENT_FAILED,
                    sprintf('Unable to load the remote document "%s".', $url),
                    $http_response_header
                );
            }

            // Extract HTTP Link headers
            $linkHeaderValues = array();
            if (is_array($http_response_header)) {
              for ($i = count($http_response_header) - 1; $i > $httpHeadersOffset; $i--) {
                if (0 === substr_compare($http_response_header[$i], 'Link:', 0, 5, TRUE)) {
                  $value = substr($http_response_header[$i], 5);
                  $linkHeaderValues[] = $value;
                }
              }
            }

            $linkHeaderValues = $this->parseContextLinkHeaders($linkHeaderValues, new IRI($url));

            if (count($linkHeaderValues) === 1) {
                $remoteDocument->contextUrl = reset($linkHeaderValues);
            } elseif (count($linkHeaderValues) > 1) {
                throw new JsonLdException(
                    JsonLdException::MULTIPLE_CONTEXT_LINK_HEADERS,
                    'Found multiple contexts in HTTP Link headers',
                    $http_response_header
                );
            }

            // If we got a media type, we verify it
            if ($remoteDocument->mediaType) {
                // Drop any media type parameters such as profiles
                if (false !== ($pos = strpos($remoteDocument->mediaType, ';'))) {
                    $remoteDocument->mediaType = substr($remoteDocument->mediaType, 0, $pos);
                }

                $remoteDocument->mediaType = trim($remoteDocument->mediaType);

                if ('application/ld+json' === $remoteDocument->mediaType) {
                    $remoteDocument->contextUrl = null;
                } elseif (('application/json' !== $remoteDocument->mediaType) &&
                    (0 !== substr_compare($remoteDocument->mediaType, '+json', -5))) {
                    throw new JsonLdException(
                        JsonLdException::LOADING_DOCUMENT_FAILED,
                        'Invalid media type',
                        $remoteDocument->mediaType
                    );
                }
            }

            $remoteDocument->document = Processor::parse($input);

            return $remoteDocument;
        }

        return new RemoteDocument($url, Processor::parse($input));
    }

    /**
     * Parse HTTP Link headers
     *
     * @param array $values  An array of HTTP Link header values
     * @param  IRI  $baseIri The document's URL (used to expand relative URLs to absolutes)
     *
     * @return array An array of parsed HTTP Link headers
     */
    private function parseContextLinkHeaders(array $values, IRI $baseIri)
    {
        // Separate multiple links contained in a single header value
        for ($i = 0, $total = count($values); $i < $total; $i++) {
            if (strpos($values[$i], ',') !== false) {
                foreach (preg_split('/,(?=([^"]*"[^"]*")*[^"]*$)/', $values[$i]) as $v) {
                    $values[] = trim($v);
                }
                unset($values[$i]);
            }
        }

        $contexts = $matches = array();
        $trimWhitespaceCallback = function ($str) {
            return trim($str, "\"'  \n\t");
        };

        // Split the header in key-value pairs
        foreach ($values as $val) {
            $part = array();
            foreach (preg_split('/;(?=([^"]*"[^"]*")*[^"]*$)/', $val) as $kvp) {
                preg_match_all('/<[^>]+>|[^=]+/', $kvp, $matches);
                $pieces = array_map($trimWhitespaceCallback, $matches[0]);
                $part[$pieces[0]] = isset($pieces[1]) ? $pieces[1] : '';
            }

            if (in_array('http://www.w3.org/ns/json-ld#context', explode(' ', $part['rel']))) {
                $contexts[] = (string) $baseIri->resolve(trim(key($part), '<> '));
            }
        }

        return array_values(array_unique($contexts));
    }
}
