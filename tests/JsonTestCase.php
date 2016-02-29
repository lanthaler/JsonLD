<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

/**
 * A JSON Test Case
 *
 * This class extends {@link \PHPUnit_Framework_TestCase} with an assertion
 * to compare JSON.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
abstract class JsonTestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * Asserts that two JSON structures are equal.
     *
     * @param  object|array $expected
     * @param  object|array $actual
     * @param  string $message
     */
    public static function assertJsonEquals($expected, $actual, $message = '')
    {
        $expected = self::normalizeJson($expected);
        $actual = self::normalizeJson($actual);

        self::assertEquals($expected, $actual, $message);
    }

    /**
     * Brings the keys of objects to a deterministic order to enable
     * comparison of JSON structures
     *
     * @param mixed $element The element to normalize.
     *
     * @return mixed The same data with all object keys ordered in a
     *               deterministic way.
     */
    private static function normalizeJson($element)
    {
        if (is_array($element)) {
            foreach ($element as &$item) {
                $item = self::normalizeJson($item);
            }
        } elseif (is_object($element)) {
            $element = (array) $element;
            ksort($element);
            $element = (object) $element;

            foreach ($element as &$item) {
                $item = self::normalizeJson($item);
            }
        }

        return $element;
    }
}
