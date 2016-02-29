<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

/**
 * Some RDF constants.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
abstract class RdfConstants
{
    const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    const RDF_LIST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#List';
    const RDF_FIRST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#first';
    const RDF_REST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#rest';
    const RDF_NIL = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nil';
    const XSD_INTEGER = 'http://www.w3.org/2001/XMLSchema#integer';
    const XSD_DOUBLE = 'http://www.w3.org/2001/XMLSchema#double';
    const XSD_BOOLEAN = 'http://www.w3.org/2001/XMLSchema#boolean';
    const XSD_STRING = 'http://www.w3.org/2001/XMLSchema#string';
}
