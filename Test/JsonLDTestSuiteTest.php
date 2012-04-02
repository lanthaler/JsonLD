<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\Test\TestManifestIterator;


/**
 * The offical JSON-LD test suite.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class JsonLDTestSuiteTest extends \PHPUnit_Framework_TestCase
{
    /** The base IRI used by the tests. */
    const BASE_IRI = 'http://json-ld.org/test-suite/tests/';

    /**
     * The base directory from which the test manifests, input, and output
     * files should be read.
     */
    private $basedir;


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->basedir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR;
    }

    /**
     * Tests expansion.
     *
     * @param string $name The test name.
     * @param obj    $test The test definition.
     *
     * @dataProvider expansionProvider
     */
    public function testExpansion($name, $test)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::expand($this->basedir . $test->{'input'},
                                 self::BASE_IRI . $test->{'input'});

        $this->assertEquals($expected, $result);
    }

    /**
     * Provides expansion test cases.
     */
    public function expansionProvider()
    {
        return new TestManifestIterator($this->basedir . 'expand-manifest.jsonld');
    }

    /**
     * Tests compaction.
     *
     * @param string $name The test name.
     * @param obj    $test The test definition.
     *
     * @dataProvider compactionProvider
     */
    public function testCompaction($name, $test)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::compact($this->basedir . $test->{'input'},
                                  $this->basedir . $test->{'context'},
                                  self::BASE_IRI . $test->{'input'});

        $this->assertEquals($expected, $result);
    }


    /**
     * Provides compaction test cases.
     */
    public function compactionProvider()
    {
        return new TestManifestIterator($this->basedir . 'compact-manifest.jsonld');
    }
}
