JsonLD
==============

At some point this should become a full-fledged JSON-LD processor. In the
meantime you can [play with it online](http://www.markus-lanthaler.com/jsonld/playground/).

**Already implemented:**

  * [expansion](http://json-ld.org/spec/latest/json-ld-api/#expansion)
  * [compaction](http://json-ld.org/spec/latest/json-ld-api/#compaction)
  * [framing](http://json-ld.org/spec/latest/json-ld-api/#framing) (supports
    [value matching](https://github.com/json-ld/json-ld.org/issues/110),
    [deep-filtering](https://github.com/json-ld/json-ld.org/issues/110),
    [aggressive re-embedding](https://github.com/json-ld/json-ld.org/issues/119), and
    [named graphs](https://github.com/json-ld/json-ld.org/issues/118))

Tests: [![Build Status](https://secure.travis-ci.org/lanthaler/JsonLD.png?branch=master)](http://travis-ci.org/lanthaler/JsonLD)
(see [official JSON-LD test suite](https://github.com/json-ld/json-ld.org/tree/master/test-suite))


**Still missing:**

 * [toRDF](http://json-ld.org/spec/latest/json-ld-api/#convert-to-rdf-algorithm) /
   [fromRDF](http://json-ld.org/spec/latest/json-ld-api/#convert-from-rdf-algorithm)


Installation
------------

The easiest way to use JsonLD is to integrate it as a dependency in your project's
[composer.json](http://getcomposer.org/doc/00-intro.md) file:

    {
        "require": {
            "ml/json-ld": "*"
        }
    }

Installing is then a matter of running composer

    php composer.phar install

... and including Composer's autoloader to your project

    require('vendor/autoload.php');


Of course you can also download an [archive](https://github.com/lanthaler/JsonLD/downloads)
from Github.
