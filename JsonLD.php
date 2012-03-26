<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD;

use ML\JsonLD\Exception\ParseException;

/**
 * JsonLD offers convenience methods to load, process, and dump JSON-LD.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * @api
 */
class JsonLD
{
    /**
     * Parses JSON-LD into a PHP array.
     *
     * The parse method, when supplied with a JSON-LD stream (string or
     * file), will do its best to convert JSON-LD into a PHP array.
     *
     *  Usage:
     *  <code>
     *   $array = JsonLD::parse('document.jsonld');
     *   print_r($array);
     *  </code>
     *
     * @param string $input Path to a JSON-LD file or a string containing
     *                      JSON-LD
     *
     * @return array The JSON-LD document converted to a PHP array
     *
     * @throws ParseException If the JSON-LD is not valid
     *
     * @api
     */
    static public function parse($input)
    {
        // if input is a file, process it
        $file = '';
        if ((strpos($input, "{") === false) && (strpos($input, "[") === false) && is_file($input)) {
            if (false === is_readable($input)) {
                throw new ParseException(sprintf('Unable to parse "%s" as the file is not readable.', $input));
            }
            $file = $input;
            $input = file_get_contents($file);
        }

        $jsonld = new Processor();

        try {
            return $jsonld->parse($input);
        } catch (ParseException $e) {
            if ($file) {
                $e->setParsedFile($file);
            }

            throw $e;
        }
    }

    /**
     * Expands a JSON-LD document into a PHP array.
     *
     * The parse method, when supplied with a JSON-LD stream (string or
     * file), will do its best to convert JSON-LD into a PHP array.
     *
     *  Usage:
     *  <code>
     *   $array = JsonLD::parse('document.jsonld');
     *   print_r($array);
     *  </code>
     *
     * @param string $input Path to a JSON-LD file or a string containing
     *                      JSON-LD
     *
     * @return array The JSON-LD document converted to a PHP array
     *
     * @throws ParseException If the JSON-LD is not valid
     *
     * @api
     */
    static public function expand($input)
    {
        $input = self::parse($input);

        $processor = new Processor();
        $activectx = array();

        if (false === is_array($input))
        {
            $input = array($input);
        }

        foreach ($input as &$obj)
        {
            $processor->expand($obj, $activectx);
        }

        return $input;
    }

    /**
     * Dumps a PHP value to a JSON-LD string.
     *
     * The dump method will do its best to convert the supplied value into
     * friendly JSON-LD.
     *
     * @param mixed   $value  The value being converted.
     * @param boolean $pretty Use whitespace in returned string to format it?
     *
     * @return string A JSON-LD string representing the original PHP array
     *
     * @api
     */
    static public function dump($value, $pretty = false)
    {
        $options = JSON_UNESCAPED_SLASHES;
        if ($pretty)
        {
            $options |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $options);
    }
}
