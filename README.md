JsonLD
==============

This is a [PSR-0](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md) compliant
JSON-LD processor by one of the specifications authors. The processor is extensivelly tested and passes the
[official JSON-LD test suite](https://github.com/json-ld/json-ld.org/tree/master/test-suite):
[![Build Status](https://secure.travis-ci.org/lanthaler/JsonLD.png?branch=master)](http://travis-ci.org/lanthaler/JsonLD)

There's also an [online playground](http://www.markus-lanthaler.com/jsonld/playground/) where you can evaluate the
processor's basic functionality.

**Already implemented:**

  * [expansion](http://json-ld.org/spec/latest/json-ld-api/#expansion)
  * [compaction](http://json-ld.org/spec/latest/json-ld-api/#compaction)
  * [framing](http://json-ld.org/spec/latest/json-ld-api/#framing) (supports
    [value matching](https://github.com/json-ld/json-ld.org/issues/110),
    [deep-filtering](https://github.com/json-ld/json-ld.org/issues/110),
    [aggressive re-embedding](https://github.com/json-ld/json-ld.org/issues/119), and
    [named graphs](https://github.com/json-ld/json-ld.org/issues/118))
  * [toRDF](http://json-ld.org/spec/latest/json-ld-api/#convert-to-rdf-algorithm)
  * [node-based access](https://github.com/lanthaler/JsonLD/issues/15) (partially implemented)

**Still missing:**

 * [fromRDF](http://json-ld.org/spec/latest/json-ld-api/#convert-from-rdf-algorithm)
 * [PSR-1](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-1-basic-coding-standard.md)
   and [PSR-2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md) compliance


Installation
------------

The easiest way to use JsonLD is to integrate it as a dependency in your project's
[composer.json](http://getcomposer.org/doc/00-intro.md) file:

```json
{
    "require": {
        "ml/json-ld": "@dev"
    }
}
```

Installing is then a matter of running composer

    php composer.phar install

... and including Composer's autoloader to your project

```php
require('vendor/autoload.php');
```

Of course you can also download an [archive](https://github.com/lanthaler/JsonLD/downloads)
from Github.


Usage
------------

The library supports the official [JSON-LD API](http://www.w3.org/TR/json-ld-api/) as
well as a node-centric API (still a work in progress, see [issue #15](https://github.com/lanthaler/JsonLD/issues/15)
for details).

All classes are extensively documented. Please look at the source code.

```php
// Official JSON-LD API
$expanded = JsonLD::expand('document.jsonld');
$compacted = JsonLD::compact('document.jsonld', 'context.jsonld');
$framed = JsonLD::frame('document.jsonld', 'frame.jsonld');
$flattened = JsonLD::flatten('document.jsonld');

// Output the expanded document (pretty print)
print JsonLD::toString($expanded, true);

// Node-centric API
$doc = JsonLD::getDocument('document.jsonld');

// get all nodes in the document
$nodes = $doc->getNodes();

// retrieve a node by ID
$node = $doc->getNode('http://example.com/node1');

// get a property
$node->getProperty('http://example.com/vocab/name');

// add a new blank node to the document
$newNode = $doc->createNode();

// link the new blank node to the existing node
$node->addPropertyValue('http://example.com/vocab/link', $newNode);

// even reverse properties are supported; this returns $newNode
$node->getReverseProperty('http://example.com/vocab/link');
```


Commercial Support
------------

Commercial support is available on request.
