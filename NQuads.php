<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\InvalidQuadException;
use ML\IRI\IRI;

/**
 * NQuads serializes quads to the NQuads format
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class NQuads implements QuadSerializerInterface, QuadParserInterface
{
    /**
     * {@inheritdoc}
     */
    public function serialize(array $quads)
    {
        $result = '';
        foreach ($quads as $quad) {
            $result .= ('_' === $quad->getSubject()->getScheme())
                ? $quad->getSubject()
                : '<' . $quad->getSubject() . '>';
            $result .= ' ';

            $result .= ('_' === $quad->getProperty()->getScheme())
                ? $quad->getProperty()
                : '<' . $quad->getProperty() . '>';
            $result .= ' ';

            if ($quad->getObject() instanceof IRI) {
                $result .= ('_' === $quad->getObject()->getScheme())
                    ? $quad->getObject()
                    : '<' . $quad->getObject() . '>';
            } else {
                $result .= '"' . str_replace(
                    array("\n", '"'),
                    array('\n', '\"'),
                    $quad->getObject()->getValue()) . '"';
                $result .= ($quad->getObject() instanceof TypedValue)
                    ? (RdfConstants::XSD_STRING === $quad->getObject()->getType())
                        ? ''
                        : '^^<' . $quad->getObject()->getType() . '>'
                    : '@' . $quad->getObject()->getLanguage();
            }
            $result .= ' ';

            if ($quad->getGraph()) {
                $result .= ('_' === $quad->getGraph()->getScheme())
                    ? $quad->getGraph() :
                    '<' . $quad->getGraph() . '>';
                $result .= ' ';
            }
            $result .= ".\n";
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * This method is heavily based on DigitalBazaar's implementation used
     * in their {@link https://github.com/digitalbazaar/php-json-ld php-json-ld}.
     *
     * @throws InvalidQuadException If an invalid quad that can't be parsed is
     *                              encountered.
     */
    public function parse($input)
    {
        // define partial regexes
        $iri = '(?:<([^>]*)>)';
        $bnode = '(_:(?:[A-Za-z0-9]+))';
        $plain = '"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"';
        $datatype = "\\^\\^$iri";
        $language = '(?:@([a-z]+(?:-[a-z0-9]+)*))';
        $literal = "(?:$plain(?:$datatype|$language)?)";
        $ws = '[ \\t]';
        $comment = "#.*";

        $subject = "(?:$iri|$bnode)$ws+";
        $property = "$iri$ws+";
        $object = "(?:$iri|$bnode|$literal)";
        $graph = "$ws+(?:$iri|$bnode)";

        // full regexes
        $eoln = '/(?:(\r\n)|[\n\r])/';
        $quadRegex = "/^$ws*$subject$property$object$graph?$ws*.$ws*$/";
        $ignoreRegex = "/^$ws*(?:$comment)?$/";

        // build RDF statements
        $statements = array();

        // split N-Quad input into lines
        $lines = preg_split($eoln, $input);
        $line_number = 0;

        foreach ($lines as $line) {
            $line_number++;

            // skip empty lines
            if (preg_match($ignoreRegex, $line)) {
                continue;
            }

            // parse quad
            if (!preg_match($quadRegex, $line, $match)) {
                throw new InvalidQuadException(
                    sprintf(
                        'Error while parsing N-Quads. Invalid quad in line %d: %s',
                        $line_number,
                        $line
                    ),
                    $line
                );
            }

            // get subject
            if ($match[1] !== '') {
                $subject = new IRI($match[1]);
            } else {
                $subject = new IRI($match[2]);
            }

            // get property
            $property = new IRI($match[3]);

            // get object
            if ($match[4] !== '') {
                $object = new IRI($match[4]);  // IRI
            } elseif ($match[5] !== '') {
                $object = new IRI($match[5]);  // bnode
            } else {
                $unescaped = str_replace(
                    array('\"', '\t', '\n', '\r', '\\\\'),
                    array('"', "\t", "\n", "\r", '\\'),
                    $match[6]
                );

                if (isset($match[7]) && $match[7] !== '') {
                    $object = new TypedValue($unescaped, $match[7]);
                } elseif (isset($match[8]) && $match[8] !== '') {
                    $object = new LanguageTaggedString($unescaped, $match[8]);
                } else {
                    $object = new TypedValue($unescaped, RdfConstants::XSD_STRING);
                }
            }

            // get graph
            $graph = null;
            if (isset($match[9]) && $match[9] !== '') {
                $graph = new IRI($match[9]);
            } elseif (isset($match[10]) && $match[10] !== '') {
                $graph = new IRI($match[10]);
            }

            $quad = new Quad($subject, $property, $object, $graph);

            // TODO Make sure that quads are unique??
            $statements[] = $quad;
        }

        return $statements;
    }
}
