<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\JsonLdException;

/**
 * Interface for (remote) document loaders
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface DocumentLoaderInterface
{
    /**
     * Load a (remote) document or context
     *
     * @param string $url The URL or path of the document to load.
     *
     * @return RemoteDocument The loaded document.
     *
     * @throws JsonLdException
     */
    public function loadDocument($url);
}
