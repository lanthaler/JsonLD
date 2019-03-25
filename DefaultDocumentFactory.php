<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * DefaultDocumentFactory creates new Documents
 *
 * @see Document
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class DefaultDocumentFactory implements DocumentFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createDocument($iri = null)
    {
        return new Document($iri);
    }
}
