<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\NQuads;
use ML\JsonLD\Test\TestManifestIterator;

/**
 * The official W3C JSON-LD test suite.
 *
 * @link http://www.w3.org/2013/json-ld-tests/ Official W3C JSON-LD test suite
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class W3CTestSuiteTest extends JsonTestCase
{
    /**
     * The base directory from which the test manifests, input, and output
     * files should be read.
     */
    private $basedir;

    /**
     * The URL corresponding to the base directory
     */
    private $baseurl = 'http://json-ld.org/test-suite/tests/';

    /**
     * @var string The test's ID.
     */
    private $id;

    /**
     * Constructs a test case with the given name.
     *
     * @param null|string $name
     * @param array  $data
     * @param string $dataName
     */
    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        $this->id = $dataName;

        parent::__construct($name, $data, $dataName);
        $this->basedir = dirname(__FILE__) . '/../vendor/json-ld/tests/';
    }

    /**
     * Returns the test identifier.
     *
     * @return string The test identifier
     */
    public function getTestId()
    {
        return $this->id;
    }

    /**
     * Tests expansion.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group expansion
     * @dataProvider expansionProvider
     */
    public function testExpansion($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::expand($this->basedir . $test->{'input'}, $options);

        $this->assertJsonEquals($expected, $result);
    }

    /**
     * Provides expansion test cases.
     */
    public function expansionProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'expand-manifest.jsonld',
            $this->baseurl . 'expand-manifest.jsonld'
        );
    }

    /**
     * Tests compaction.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group compaction
     * @dataProvider compactionProvider
     */
    public function testCompaction($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::compact(
            $this->basedir . $test->{'input'},
            $this->basedir . $test->{'context'},
            $options
        );

        $this->assertJsonEquals($expected, $result);
    }


    /**
     * Provides compaction test cases.
     */
    public function compactionProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'compact-manifest.jsonld',
            $this->baseurl . 'compact-manifest.jsonld'
        );
    }

    /**
     * Tests flattening.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group flattening
     * @dataProvider flattenProvider
     */
    public function testFlatten($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $context = (isset($test->{'context'}))
            ? $this->basedir . $test->{'context'}
            : null;

        $result = JsonLD::flatten($this->basedir . $test->{'input'}, $context, $options);

        $this->assertJsonEquals($expected, $result);
    }

    /**
     * Provides flattening test cases.
     */
    public function flattenProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'flatten-manifest.jsonld',
            $this->baseurl . 'flatten-manifest.jsonld'
        );
    }

    /**
     * Tests remote document loading.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group remote
     * @dataProvider remoteDocumentLoadingProvider
     */
    public function testRemoteDocumentLoading($name, $test, $options)
    {
        if (in_array('jld:NegativeEvaluationTest', $test->{'@type'})) {
            $this->setExpectedException('ML\JsonLD\Exception\JsonLdException', null, $test->{'expect'});
        } else {
            $expected = json_decode($this->replaceBaseUrl(file_get_contents($this->basedir . $test->{'expect'})));
        }

        unset($options->base);

        $result = JsonLD::expand($this->replaceBaseUrl($this->baseurl . $test->{'input'}), $options);

        if (isset($expected)) {
            $this->assertJsonEquals($expected, $result);
        }
    }

    /**
     * Provides remote document loading test cases.
     */
    public function remoteDocumentLoadingProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'remote-doc-manifest.jsonld',
            $this->baseurl . 'remote-doc-manifest.jsonld'
        );
    }

    /**
     * Replaces the base URL 'http://json-ld.org/' with 'https://json-ld.org:443/'.
     *
     * The test location of the test suite has been changed as the site has been
     * updated to use HTTPS everywhere.
     *
     * @param string $input The input string.
     *
     * @return string The input string with all occurrences of the old base URL replaced with the new HTTPS-based one.
     */
    private function replaceBaseUrl($input) {
        return str_replace('http://json-ld.org/', 'https://json-ld.org:443/', $input);
    }

    /**
     * Tests errors (uses flattening).
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group errors
     * @dataProvider errorProvider
     */
    public function testError($name, $test, $options)
    {
        $this->setExpectedException('ML\JsonLD\Exception\JsonLdException', null, $test->{'expect'});

        JsonLD::flatten(
            $this->basedir . $test->{'input'},
            (isset($test->{'context'})) ? $this->basedir . $test->{'context'} : null,
            $options
        );
    }

    /**
     * Provides error test cases.
     */
    public function errorProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'error-manifest.jsonld',
            $this->baseurl . 'error-manifest.jsonld'
        );
    }

    /**
     * Tests framing.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group framing
     * @dataProvider framingProvider
     */
    public function testFraming($name, $test, $options)
    {
        $ignoredTests = array(
            'frame-0005-in.jsonld',
            'frame-0009-in.jsonld',
            'frame-0010-in.jsonld',
            'frame-0012-in.jsonld',
            'frame-0013-in.jsonld',
            'frame-0023-in.jsonld',
            'frame-0024-in.jsonld',
            'frame-0027-in.jsonld',
            'frame-0028-in.jsonld',
            'frame-0029-in.jsonld',
            'frame-0030-in.jsonld'
        );

        if (in_array($test->{'input'}, $ignoredTests)) {
            $this->markTestSkipped(
                'This implementation uses deep value matching and aggressive re-embedding. See ISSUE-110 and ISSUE-119.'
            );
        }

        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::frame(
            $this->basedir . $test->{'input'},
            $this->basedir . $test->{'frame'},
            $options
        );

        $this->assertJsonEquals($expected, $result);
    }

    /**
     * Provides framing test cases.
     */
    public function framingProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'frame-manifest.jsonld',
            $this->baseurl . 'frame-manifest.jsonld'
        );
    }

    /**
     * Tests conversion to RDF quads.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group toRdf
     * @dataProvider toRdfProvider
     */
    public function testToRdf($name, $test, $options)
    {
        $expected = trim(file_get_contents($this->basedir . $test->{'expect'}));
        $quads = JsonLD::toRdf($this->basedir . $test->{'input'}, $options);

        $serializer = new NQuads();
        $result = $serializer->serialize($quads);

        // Sort quads (the expected quads are already sorted)
        $result = explode("\n", trim($result));
        sort($result);
        $result = implode("\n", $result);

        $this->assertEquals($expected, $result);
    }

    /**
     * Provides conversion to RDF quads test cases.
     */
    public function toRdfProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'toRdf-manifest.jsonld',
            $this->baseurl . 'toRdf-manifest.jsonld'
        );
    }

    /**
     * Tests conversion from quads.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @group fromRdf
     * @dataProvider fromRdfProvider
     */
    public function testFromRdf($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));

        $parser = new NQuads();
        $quads = $parser->parse(file_get_contents($this->basedir . $test->{'input'}));

        $result = JsonLD::fromRdf($quads, $options);

        $this->assertEquals($expected, $result);
    }

    /**
     * Provides conversion to quads test cases.
     */
    public function fromRdfProvider()
    {
        return new TestManifestIterator(
            $this->basedir . 'fromRdf-manifest.jsonld',
            $this->baseurl . 'fromRdf-manifest.jsonld'
        );
    }
}
