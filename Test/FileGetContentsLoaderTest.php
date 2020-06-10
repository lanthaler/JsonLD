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

        $this->iri = new IRI('https://www.google.com');
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
        $headers = [ 
            '<https://www.google.com>; param1=foo; param2="bar";',
        ];

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.google.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersTrimsValues()
    {
        $headers = [ 
            '< https://www.google.com  >; param1= foo ; param2=" bar ";',
        ];

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertEquals('https://www.google.com', $parsed[0]['uri']);
        $this->assertEquals('foo', $parsed[0]['param1']);
        $this->assertEquals('bar', $parsed[0]['param2']);
    }

    public function testParseLinkHeadersWithMultipleHeaders()
    {
        $headers = [ 
            '<https://www.google.com>; param1=foo; param2=bar;',
            '<https://www.yahoo.com>; param1=fizz; param2=buzz;',
        ];

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
    }

    public function testParseLinkHeadersWithMultipleLinks()
    {
        $headers = [ '<https://www.google.com>; param1=foo; param2=bar;, <https://www.yahoo.com>; param1=fizz; param2=buzz;' ];

        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);

        $this->assertCount(2, $parsed);
        $this->assertEquals('https://www.google.com', $parsed[0]['uri']);
        $this->assertEquals('https://www.yahoo.com', $parsed[1]['uri']);
    }

    public function testParseLinkHeadersConvertsRelativeLinksToAbsolute()
    {
        $headers = [ '</foo/bar>;' ];
        $parsed = $this->loader->parseLinkHeaders($headers, $this->iri);
        $this->assertEquals('https://www.google.com/foo/bar', $parsed[0]['uri']);
    }

}