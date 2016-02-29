<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * Interface for factories to create DocumentInterface objects
 *
 * @see DocumentInterface
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
interface DocumentFactoryInterface
{
    /**
     * Creates a new document
     *
     * @param null|string $iri The document's IRI.
     *
     * @return DocumentInterface The document.
     */
    public function createDocument($iri = null);
}
