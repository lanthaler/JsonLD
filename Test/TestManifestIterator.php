<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

/**
 * TestManifestIterator reads a test manifest and returns the contained test
 * definitions.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class TestManifestIterator implements \Iterator
{
    /** The current position. */
    private $key = 0;

    /** The test directory. */
    private $directory;

    /** The test manifest. */
    private $manifest;

    /** The URL of the test manifest. */
    private $url;

    /** The total number of tests. */
    private $numberTests = 0;

    /**
     * Constructor
     *
     * @param string $file The manifest's filename.
     * @param string $url  The manifest's URL.
     */
    public function __construct($file, $url)
    {
        if (file_exists($file)) {
            $this->manifest = json_decode(file_get_contents($file));
            $this->numberTests = count($this->manifest->{'sequence'});
            $this->url = $url;
            $this->directory = dirname($file) . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * Rewinds the TestManifestIterator to the first element.
     */
    public function rewind()
    {
        $this->key = 0;
    }

    /**
     * Checks if current position is valid.
     *
     * @return bool True if the current position is valid; otherwise, false.
     */
    public function valid()
    {
        return ($this->key < $this->numberTests);
    }

    /**
     * Returns the key of the current element.
     *
     * @return string The key of the current element
     */
    public function key()
    {
        return $this->url . $this->manifest->{'sequence'}[$this->key]->{'@id'};
    }

    /**
     * Returns the current element.
     *
     * @return array Returns an array containing the name of the test and the
     *                test definition object.
     */
    public function current()
    {
        $test = $this->manifest->{'sequence'}[$this->key];
        $options = isset($test->{'option'})
            ? clone $test->{'option'}  // cloning because we are modifying it
            : new \stdClass();

        if (false === property_exists($options, 'base')) {
            if (property_exists($this->manifest, 'baseIri')) {
                $options->base = $this->manifest->{'baseIri'} . $test->{'input'};
            } else {
                $options->base = $test->{'input'};
            }
        }

        if (isset($options->{'expandContext'}) && (false === strpos($options->{'expandContext'}, ':'))) {
            $options->{'expandContext'} = $this->directory . $options->{'expandContext'};
        }

        $test = array(
            'name'    => $test->{'name'},
            'test'    => $test,
            'options' => $options
        );

        return $test;
    }

    /**
     * Moves forward to next element.
     */
    public function next()
    {
        $this->key++;
    }
}
