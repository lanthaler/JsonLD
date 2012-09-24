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
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @dataProvider expansionProvider
     */
    public function testExpansion($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::expand($this->basedir . $test->{'input'},
                                 null,
                                 $options);

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
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @dataProvider compactionProvider
     */
    public function testCompaction($name, $test, $options)
    {
        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::compact($this->basedir . $test->{'input'},
                                  $this->basedir . $test->{'context'},
                                  $options);

        $this->assertEquals($expected, $result);
    }


    /**
     * Provides compaction test cases.
     */
    public function compactionProvider()
    {
        return new TestManifestIterator($this->basedir . 'compact-manifest.jsonld');
    }

    /**
     * Tests framing.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @dataProvider framingProvider
     */
    public function testFraming($name, $test, $options)
    {
        if (in_array($test->{'input'}, array('frame-0005-in.jsonld', 'frame-0009-in.jsonld', 'frame-0010-in.jsonld',
                                             'frame-0012-in.jsonld', 'frame-0013-in.jsonld')))
        {
            $this->markTestSkipped('This implementation uses deep value matching and aggressive "re-embedding". See ISSUE-110 and ISSUE-119');
        }


        $expected = json_decode(file_get_contents($this->basedir . $test->{'expect'}));
        $result = JsonLD::frame($this->basedir . $test->{'input'},
                                $this->basedir . $test->{'frame'},
                                null,
                                $options);

        $this->assertEquals($expected, $result);
    }


    /**
     * Provides framing test cases.
     */
    public function framingProvider()
    {
        return new TestManifestIterator($this->basedir . 'frame-manifest.jsonld');
    }

    /**
     * Tests conversion to quads.
     *
     * @param string $name    The test name.
     * @param object $test    The test definition.
     * @param object $options The options to configure the algorithms.
     *
     * @dataProvider toQuadsProvider
     */
    public function testToQuads($name, $test, $options)
    {
        $expected = file_get_contents($this->basedir . $test->{'expect'});
        $quads = JsonLD::toQuads($this->basedir . $test->{'input'},
                                 null,
                                 $options);

        // TODO Extract this NQuads serializer to a separate class
        $result = '';
        foreach ($quads as $quad)
        {
            $result .= ('_' === $quad[0]->getScheme()) ? $quad[0] : '<' . $quad[0] . '>';
            $result .= ' ';
            $result .= ('_' === $quad[1]->getScheme()) ? $quad[1] : '<' . $quad[1] . '>';
            $result .= ' ';
            if ($quad[2] instanceof \ML\IRI\IRI)
            {
                $result .= ('_' === $quad[2]->getScheme()) ? $quad[2] : '<' . $quad[2] . '>';
            }
            else
            {
                $result .= '"' . $quad[2]->getValue() . '"';
                $result .= ($quad[2] instanceof \ML\JsonLD\TypedValue)
                    ? '^^<' . $quad[2]->getType() . '>'
                    : '@' . $quad[2]->getLanguage();
            }
            $result .= ' ';
            if ($quad[3])
            {
                $result .= ('_' === $quad[3]->getScheme()) ? $quad[3] : '<' . $quad[3] . '>';
                $result .= ' ';
            }
            $result .= ".\n";
        }

        $this->assertEquals($expected, $result);
    }


    /**
     * Provides conversion to quads test cases.
     */
    public function toQuadsProvider()
    {
        return new TestManifestIterator($this->basedir . 'toRdf-manifest.jsonld');
    }
}
