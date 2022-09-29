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
use PHPUnit\Framework\TestCase;

/**
 * Test the parsing of a JSON-LD document into a Document.
 */
class FileGetContentsLoaderTest extends TestCase
{

    protected $iri;

    protected $loader;

    public function setUp(): void
    {
        parent::setUp();

        $this->iri = new IRI('https://www.example.com');
        $this->loader = new FileGetContentsLoader;
    }

    public function tearDown(): void
    {
        unset($iri);
        unset($this->loader);

        parent::tearDown();
    }

    public function testParseLinkHeadersExactsValues()
    {
        $headers = array(
            '<https://www.example.com>; param1=foo; param2="bar";',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.example.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersTrimsValues()
    {
        $headers = array(
            '< https://www.example.com  >; param1= foo ; param2=" bar ";',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.example.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersWithMultipleHeaders()
    {
        $headers = array(
            '<https://www.example.com>; param1=foo; param2=bar;',
            '<https://www.example.org>; param1=fizz; param2=buzz;',
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
    }

    public function testParseLinkHeadersWithMultipleLinks()
    {
        $headers = array(
            '<https://www.example.com>; param1=foo; param2=bar;, '
                . '<https://www.example.org>; param1=fizz; param2=buzz;'
        );

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
        $this->assertEquals('https://www.example.com', $parsed[0]['uri']);
        $this->assertEquals('https://www.example.org', $parsed[1]['uri']);
    }

    public function testParseLinkHeadersConvertsRelativeLinksToAbsolute()
    {
        $headers = array('</foo/bar>;');
        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);
        $this->assertEquals('https://www.example.com/foo/bar', $parsed[0]['uri']);
    }
}
