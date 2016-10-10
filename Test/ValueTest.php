<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;

/**
 * Test LanguageTaggedString and TypedValue
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class ValueTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Tests LanguageTaggedString
     */
    public function testLanguageTaggedString()
    {
        $string1 = new LanguageTaggedString('', '');
        $this->assertSame('', $string1->getValue(), 'string1 value');
        $this->assertSame('', $string1->getLanguage(), 'string1 language');

        $string2 = new LanguageTaggedString('wert', 'de');
        $this->assertSame('wert', $string2->getValue(), 'string2 value');
        $this->assertSame('de', $string2->getLanguage(), 'string2 language');

        $string3 = new LanguageTaggedString('value', 'en');
        $this->assertSame('value', $string3->getValue(), 'string3 value');
        $this->assertSame('en', $string3->getLanguage(), 'string3 language');
    }

    /**
     * Tests LanguageTaggedString with an invalid value
     *
     * @expectedException \InvalidArgumentException
     */
    public function testLanguageTaggedStringInvalidValue()
    {
        $string1 = new LanguageTaggedString('value', 'language');
        $string1->setValue(1);
    }

    /**
     * Tests LanguageTaggedString with an invalid language
     *
     * @expectedException \InvalidArgumentException
     */
    public function testLanguageTaggedStringInvalidLanguage()
    {
        $string1 = new LanguageTaggedString('value', 'language');
        $string1->setLanguage(null);
    }

    /**
     * Tests TypedValue
     */
    public function testTypedValue()
    {
        $value1 = new TypedValue('', '');
        $this->assertSame('', $value1->getValue(), 'string1 value');
        $this->assertSame('', $value1->getType(), 'string1 type');

        $value2 = new TypedValue('wert', 'http://example.com/type1');
        $this->assertSame('wert', $value2->getValue(), 'string2 value');
        $this->assertSame('http://example.com/type1', $value2->getType(), 'string2 type');

        $value3 = new TypedValue('value', 'http://example.com/type2');
        $this->assertSame('value', $value3->getValue(), 'string3 value');
        $this->assertSame('http://example.com/type2', $value3->getType(), 'string3 type');
    }

    /**
     * Tests TypedValue with an invalid value
     *
     * @expectedException \InvalidArgumentException
     */
    public function testTypedValueInvalidValue()
    {
        $value1 = new LanguageTaggedString('value', 'language');
        $value1->setValue(1);
    }

    /**
     * Tests TypedValue with an invalid type
     *
     * @expectedException \InvalidArgumentException
     */
    public function testTypedValueInvalidLanguage()
    {
        $value1 = new TypedValue('value', 'http://example.com/type');
        $value1->setType(1);
    }

    /**
     * Tests TypedValue with an invalid type
     */
    public function testEquals()
    {
        $string1a = new LanguageTaggedString('value', 'en');
        $string1b = new LanguageTaggedString('value', 'en');
        $string2 = new LanguageTaggedString('value', 'de');
        $string3 = new LanguageTaggedString('wert', 'en');

        $this->assertTrue($string1a->equals($string1b), 's1a == s1b?');
        $this->assertFalse($string1a->equals($string2), 's1a == s2?');
        $this->assertFalse($string1a->equals($string3), 's1a == s3?');
        $this->assertFalse($string1b->equals($string2), 's1b == s2?');
        $this->assertFalse($string1b->equals($string3), 's1b == s3?');
        $this->assertFalse($string2->equals($string3), 's2 == s3?');


        $typed1a = new TypedValue('value', 'http://example.com/type1');
        $typed1b = new TypedValue('value', 'http://example.com/type1');
        $typed2 = new TypedValue('value', 'http://example.com/type2');
        $typed3 = new TypedValue('wert', 'http://example.com/type1');

        $this->assertTrue($typed1a->equals($typed1b), 't1a == t1b?');
        $this->assertFalse($typed1a->equals($typed2), 't1a == t2?');
        $this->assertFalse($typed1a->equals($typed3), 't1a == t3?');
        $this->assertFalse($typed1b->equals($typed2), 't1b == t2?');
        $this->assertFalse($typed1b->equals($typed3), 't1b == t3?');
        $this->assertFalse($typed2->equals($typed3), 't2 == t3?');

        $string4 = new LanguageTaggedString('', '');
        $typed4 = new TypedValue('', '');

        $this->assertFalse($string4->equals($typed4), 's4 == t4');
        $this->assertFalse($typed4->equals($string4), 's4 == t4');
    }
}
