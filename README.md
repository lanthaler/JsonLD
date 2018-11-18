JsonLD [![Build Status](https://secure.travis-ci.org/lanthaler/JsonLD.png?branch=master)](http://travis-ci.org/lanthaler/JsonLD)
==============

JsonLD is a fully conforming [JSON-LD](http://www.w3.org/TR/json-ld/)
processor written in PHP. It is extensively tested and passes the
[official JSON-LD test suite](https://github.com/json-ld/tests).

There's an [online playground](http://www.markus-lanthaler.com/jsonld/playground/)
where you can evaluate the processor's basic functionality.

Additionally to the features defined by the [JSON-LD API specification](http://www.w3.org/TR/json-ld-api/),
JsonLD supports [framing](http://json-ld.org/spec/latest/json-ld-framing/)
(including [value matching](https://github.com/json-ld/json-ld.org/issues/110),
[deep-filtering](https://github.com/json-ld/json-ld.org/issues/110),
[aggressive re-embedding](https://github.com/json-ld/json-ld.org/issues/119), and
[named graphs](https://github.com/json-ld/json-ld.org/issues/118)) and an experimental
[object-oriented interface for JSON-LD documents](https://github.com/lanthaler/JsonLD/issues/15).


Installation
------------

The easiest way to install `JsonLD` is by requiring it with [Composer](https://getcomposer.org/).

```
composer require ml/json-ld
```

... and including Composer's autoloader to your project

```php
require('vendor/autoload.php');
```

Of course, you can also download JsonLD as
[ZIP archive](https://github.com/lanthaler/JsonLD/releases) from Github.

JsonLD requires PHP 5.3 or later.


Usage
------------

The library supports the official [JSON-LD API](http://www.w3.org/TR/json-ld-api/) as
well as a object-oriented interface for JSON-LD documents (not fully implemented yet,
see [issue #15](https://github.com/lanthaler/JsonLD/issues/15) for details).

All classes are extensively documented. Please have a look at the source code.

```php
// Official JSON-LD API
$expanded = JsonLD::expand('document.jsonld');
$compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
$framed = JsonLD::frame('document.jsonld', 'frame.jsonld');
$flattened = JsonLD::flatten('document.jsonld');
$quads = JsonLD::toRdf('document.jsonld');

// Output the expanded document (pretty print)
print JsonLD::toString($expanded, true);

// Serialize the quads as N-Quads
$nquads = new NQuads();
$serialized = $nquads->serialize($quads);
print $serialized;

// And parse them again to a JSON-LD document
$quads = $nquads->parse($serialized);
$document = JsonLD::fromRdf($quads);

print JsonLD::toString($document, true);

// Node-centric API
$doc = JsonLD::getDocument('document.jsonld');

// get the default graph
$graph = $doc->getGraph();

// get all nodes in the graph
$nodes = $graph->getNodes();

// retrieve a node by ID
$node = $graph->getNode('http://example.com/node1');

// get a property
$node->getProperty('http://example.com/vocab/name');

// add a new blank node to the graph
$newNode = $graph->createNode();

// link the new blank node to the existing node
$node->addPropertyValue('http://example.com/vocab/link', $newNode);

// even reverse properties are supported; this returns $newNode
$node->getReverseProperty('http://example.com/vocab/link');

// serialize the graph and convert it to a string
$serialized = JsonLD::toString($graph->toJsonLd());
```


Commercial Support
------------

Commercial support is available on request.
