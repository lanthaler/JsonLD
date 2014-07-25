<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;

/**
 * Tests JsonLD's API
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class JsonLDApiTest extends JsonTestCase
{
    /**
     * Tests the expansion API
     *
     * @group expansion
     */
    public function testExpansion()
    {
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
        $expected = json_decode(file_get_contents($path . 'sample-expanded.jsonld'));

        $input = $path . 'sample-in.jsonld';
        $this->assertJsonEquals($expected, JsonLD::expand($input), 'Passing the file path');

        $input = file_get_contents($input);
        $this->assertJsonEquals($expected, JsonLD::expand($input), 'Passing the raw input (string)');

        $input = json_decode($input);
        $this->assertJsonEquals($expected, JsonLD::expand($input), 'Passing the parsed object');
    }

    /**
     * Tests the compaction API
     *
     * @group compaction
     */
    public function testCompaction()
    {
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
        $expected = json_decode(file_get_contents($path . 'sample-compacted.jsonld'));

        $input   = $path . 'sample-in.jsonld';
        $context = $path . 'sample-context.jsonld';
        $this->assertJsonEquals($expected, JsonLD::compact($input, $context), 'Passing the file path');

        $input   = file_get_contents($input);
        $context = file_get_contents($context);
        $this->assertJsonEquals($expected, JsonLD::compact($input, $context), 'Passing the raw input (string)');

        $input   = json_decode($input);
        $context = json_decode($context);
        $this->assertJsonEquals($expected, JsonLD::compact($input, $context), 'Passing the parsed object');
    }

    /**
     * Tests the flattening API
     *
     * @group flattening
     */
    public function testFlatten()
    {
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
        $expected = json_decode(file_get_contents($path . 'sample-flattened.jsonld'));

        $input   = $path . 'sample-in.jsonld';
        $context = $path . 'sample-context.jsonld';
        $this->assertJsonEquals($expected, JsonLD::flatten($input, $context), 'Passing the file path');

        $input   = file_get_contents($input);
        $context = file_get_contents($context);
        $this->assertJsonEquals($expected, JsonLD::flatten($input, $context), 'Passing the raw input (string)');

        $input   = json_decode($input);
        $context = json_decode($context);
        $this->assertJsonEquals($expected, JsonLD::flatten($input, $context), 'Passing the parsed object');
    }

    /**
     * Tests the framing API
     *
     * This test intentionally uses the same fixtures as the flattening tests.
     *
     * @group framing
     */
    public function testFrame()
    {
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
        $expected = json_decode(file_get_contents($path . 'sample-flattened.jsonld'));

        $input   = $path . 'sample-in.jsonld';
        $context = $path . 'sample-context.jsonld';
        $this->assertJsonEquals($expected, JsonLD::frame($input, $context), 'Passing the file path');

        $input   = file_get_contents($input);
        $context = file_get_contents($context);
        $this->assertJsonEquals($expected, JsonLD::frame($input, $context), 'Passing the raw input (string)');

        $input   = json_decode($input);
        $context = json_decode($context);
        $this->assertJsonEquals($expected, JsonLD::frame($input, $context), 'Passing the parsed object');
    }

    /**
     * Tests the document API
     *
     * This test intentionally uses the same fixtures as the flattening tests.
     */
    public function testGetDocument()
    {

        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
        $expected = json_decode(file_get_contents($path . 'sample-serialized-document.jsonld'));

        $input   = $path . 'sample-in.jsonld';
        $this->assertJsonEquals($expected, JsonLD::getDocument($input)->toJsonLd(), 'Passing the file path');

        $input   = file_get_contents($input);
        $this->assertJsonEquals($expected, JsonLD::getDocument($input)->toJsonLd(), 'Passing the raw input (string)');

        $input   = json_decode($input);
        $this->assertJsonEquals($expected, JsonLD::getDocument($input)->toJsonLd(), 'Passing the parsed object');
    }
}
