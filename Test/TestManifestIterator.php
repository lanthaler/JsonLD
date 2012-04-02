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

    /** The test manifest. */
    private $manifest;

    /** The total number of tests. */
    private $numberTests = 0;


    /**
     * Constructor
     *
     * @param string $file The manifest's filename.
     */
    public function __construct($file)
    {
        try
        {
           $this->manifest = json_decode(file_get_contents($file));
           $this->numberTests = count($this->manifest->{'sequence'});
        }
        catch(Exception $e)
        {
           echo "Exception while parsing file: '$file'";
           throw $e;
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
     * @return int The key of the current element
     */
    public function key()
    {
        return $this->key;
    }

    /**
     * Returns the current element.
     *
     * @return array Returns an array containing the name of the test and the
     *                test definition object.
     */
    public function current()
    {
        $test = array('name' => $this->manifest->{'sequence'}[$this->key]->{'name'},
                      'test' => $this->manifest->{'sequence'}[$this->key]);

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
