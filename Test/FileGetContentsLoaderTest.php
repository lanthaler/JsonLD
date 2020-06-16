<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\IRI\IRI;
use ML\JsonLD\FileGetContentsLoader;

/**
 * Test the parsing of a JSON-LD document into a Document.
 */
class FileGetContentsLoaderTest extends \PHPUnit_Framework_TestCase
{

    protected $iri;

    protected $loader;

    public function setUp()
    {
        parent::setUp();

        $this->iri = new IRI('https://www.foobar.com');
        $this->loader = new FileGetContentsLoader;
    }

    public function tearDown()
    {
        unset($iri);
        unset($this->loader);

        parent::tearDown();
    }

    public function testParseLinkHeadersExactsValues()
    {
        $headers = array(
            '<https://www.foobar.com>; param1=foo; param2="bar";',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.foobar.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersTrimsValues()
    {
        $headers = array(
            '< https://www.foobar.com  >; param1= foo ; param2=" bar ";',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.foobar.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersWithMultipleHeaders()
    {
        $headers = array(
            '<https://www.foobar.com>; param1=foo; param2=bar;',
            '<https://www.fizzbuzz.net>; param1=fizz; param2=buzz;',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
    }

    public function testParseLinkHeadersWithMultipleLinks()
    {
        $headers = array(
            '<https://www.foobar.com>; param1=foo; param2=bar;, '
                . '<https://www.fizzbuzz.net>; param1=fizz; param2=buzz;'
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
        $this->assertEquals('https://www.foobar.com', $parsed[0]['uri']);
        $this->assertEquals('https://www.fizzbuzz.net', $parsed[1]['uri']);
    }

    public function testParseLinkHeadersConvertsRelativeLinksToAbsolute()
    {
        $headers = array('</foo/bar>;');
        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);
        $this->assertEquals('https://www.foobar.com/foo/bar', $parsed[0]['uri']);
    }
}
