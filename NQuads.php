<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\ParseException;
use ML\IRI\IRI;


/**
 * NQuads serializes quads to the NQuads format
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class NQuads implements QuadSerializerInterface
{
    /**
     * {@inheritdoc}
     */
    public function serialize(array $quads)
    {
        $result = '';
        foreach ($quads as $quad)
        {
            $result .= ('_' === $quad->getSubject()->getScheme())
                ? $quad->getSubject()
                : '<' . $quad->getSubject() . '>';
            $result .= ' ';

            $result .= ('_' === $quad->getProperty()->getScheme())
                ? $quad->getProperty()
                : '<' . $quad->getProperty() . '>';
            $result .= ' ';

            if ($quad->getObject() instanceof IRI)
            {
                $result .= ('_' === $quad->getObject()->getScheme())
                    ? $quad->getObject()
                    : '<' . $quad->getObject() . '>';
            }
            else
            {
                $result .= '"' . $quad->getObject()->getValue() . '"';
                $result .= ($quad->getObject() instanceof TypedValue)
                    ? '^^<' . $quad->getObject()->getType() . '>'
                    : '@' . $quad->getObject()->getLanguage();
            }
            $result .= ' ';

            if ($quad->getGraph())
            {
                $result .= ('_' === $quad->getGraph()->getScheme())
                    ? $quad->getGraph() :
                    '<' . $quad->getGraph() . '>';
                $result .= ' ';
            }
            $result .= ".\n";
        }

        return $result;
    }
}
