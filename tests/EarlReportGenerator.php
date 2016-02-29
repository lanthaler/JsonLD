<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Test;

/**
 * EarlReportGenerator
 *
 * A test listener to create an EARL report. It can be configured uses
 * the following configuration
 *
 *     <listeners>
 *        <listener class="\ML\JsonLD\Test\EarlReportGenerator">
 *          <arguments>
 *            <array>
 *              <element key="target">
 *                <string>...</string>
 *              </element>
 *              <element key="project-name">
 *                <string>...</string>
 *              </element>
 *              <element key="project-url">
 *                <string>...</string>
 *              </element>
 *              <element key="project-homepage">
 *                <string>...</string>
 *              </element>
 *              <element key="license-url">
 *                <string>...</string>
 *              </element>
 *              <element key="project-description">
 *                <string>...</string>
 *              </element>
 *              <element key="programming-language">
 *                <string>...</string>
 *              </element>
 *              <element key="developer-name">
 *                <string>...</string>
 *              </element>
 *              <element key="developer-url">
 *                <string>...</string>
 *              </element>
 *              <element key="developer-homepage">
 *                <string>...</string>
 *              </element>
 *            </array>
 *          </arguments>
 *        </listener>
 *      </listeners>
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class EarlReportGenerator extends \PHPUnit_Util_Printer implements \PHPUnit_Framework_TestListener
{
    /**
     * @var string
     */
    protected $testTypeOfInterest = 'ML\\JsonLD\\Test\\W3CTestSuiteTest';

    /**
     * @var array Lookup table for EARL statuses
     */
    protected $earlStatuses;

    /**
     * @var array Options
     */
    protected $options;

    /**
     * @var array Collected EARL assertions
     */
    protected $assertions;

    /**
     * Constructor
     *
     * @param array $options Configuration options
     */
    public function __construct(array $options = array())
    {
        $reqOptions = array(
            'target',
            'project-name',
            'project-url',
            'project-homepage',
            'license-url',
            'project-description',
            'programming-language',
            'developer-name',
            'developer-url',
            'developer-homepage'
        );

        foreach ($reqOptions as $option) {
            if (false === isset($options[$option])) {
                throw new \InvalidArgumentException(
                    sprintf('The "%s" option is not set', $option)
                );
            }
        }

        $this->options = $options;

        $this->earlStatuses = array(
            \PHPUnit_Runner_BaseTestRunner::STATUS_PASSED     => 'earl:passed',
            \PHPUnit_Runner_BaseTestRunner::STATUS_SKIPPED    => 'earl:untested',
            \PHPUnit_Runner_BaseTestRunner::STATUS_INCOMPLETE => 'earl:cantTell',
            \PHPUnit_Runner_BaseTestRunner::STATUS_FAILURE    => 'earl:failed',
            \PHPUnit_Runner_BaseTestRunner::STATUS_ERROR      => 'earl:failed'
        );

        $this->assertions = array();

        parent::__construct($options['target']);
    }

    /**
     * A test ended.
     *
     * @param \PHPUnit_Framework_Test $test
     * @param float                   $time
     */
    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        if (false === ($test instanceof $this->testTypeOfInterest)) {
            return;
        }

        $assertion =  array(
            '@type' => 'earl:Assertion',
            'earl:assertedBy' => $this->options['developer-url'],
            'earl:mode' => 'earl:automatic',
            'earl:test' => $test->getTestId(),
            'earl:result' => array(
                '@type' => 'earl:TestResult',
                'earl:outcome' => $this->earlStatuses[$test->getStatus()],
                'dc:date' => date('c')
            )
        );

        $this->assertions[] = $assertion;
    }


    /**
     * @inheritdoc
     */
    public function flush()
    {
        if (0 === $this->assertions) {
            return;
        }

        $report = array(
            '@context' => array(
                'doap'            => 'http://usefulinc.com/ns/doap#',
                'foaf'            => 'http://xmlns.com/foaf/0.1/',
                'dc'              => 'http://purl.org/dc/terms/',
                'earl'            => 'http://www.w3.org/ns/earl#',
                'xsd'             => 'http://www.w3.org/2001/XMLSchema#',
                'doap:homepage'   => array('@type' => '@id'),
                'doap:license'    => array('@type' => '@id'),
                'dc:creator'      => array('@type' => '@id'),
                'foaf:homepage'   => array('@type' => '@id'),
                'subjectOf'       => array('@reverse' => 'earl:subject'),
                'earl:assertedBy' => array('@type' => '@id'),
                'earl:mode'       => array('@type' => '@id'),
                'earl:test'       => array('@type' => '@id'),
                'earl:outcome'    => array('@type' => '@id'),
                'dc:date'         => array('@type' => 'xsd:date')
            ),
            '@id'                       => $this->options['project-url'],
            '@type'                     => array('doap:Project', 'earl:TestSubject', 'earl:Software'),
            'doap:name'                 => $this->options['project-name'],
            'dc:title'                  => $this->options['project-name'],
            'doap:homepage'             => $this->options['project-homepage'],
            'doap:license'              => $this->options['license-url'],
            'doap:description'          => $this->options['project-description'],
            'doap:programming-language' => $this->options['programming-language'],
            'doap:developer' => array(
                '@id'           => $this->options['developer-url'],
                '@type'         => array('foaf:Person', 'earl:Assertor'),
                'foaf:name'     => $this->options['developer-name'],
                'foaf:homepage' => $this->options['developer-homepage']
            ),
            'dc:creator' => $this->options['developer-url'],
            'dc:date' => array(
                '@value' => date('Y-m-d'),
                '@type'  => 'xsd:date'
            ),
            'subjectOf' => $this->assertions
        );

        $options = 0;

        if (PHP_VERSION_ID >= 50400) {
            $options |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
            $report = json_encode($report, $options);
        } else {
            $report = json_encode($report);
            $report = str_replace('\\/', '/', $report);  // unescape slahes

            // unescape unicode
            $report = preg_replace_callback(
                '/\\\\u([a-f0-9]{4})/',
                function ($match) {
                    return iconv('UCS-4LE', 'UTF-8', pack('V', hexdec($match[1])));
                },
                $report
            );
        }

        $this->write($report);

        parent::flush();
    }

    /**
     * @inheritdoc
     */
    public function startTestSuite(\PHPUnit_Framework_TestSuite $suite)
    {
    }

    /**
     * @inheritdoc
     */
    public function endTestSuite(\PHPUnit_Framework_TestSuite $suite)
    {
    }

    /**
     * @inheritdoc
     */
    public function addError(\PHPUnit_Framework_Test $test, \Exception $e, $time)
    {
    }

    /**
     * @inheritdoc
     */
    public function addFailure(\PHPUnit_Framework_Test $test, \PHPUnit_Framework_AssertionFailedError $e, $time)
    {
    }

    /**
     * @inheritdoc
     */
    public function addIncompleteTest(\PHPUnit_Framework_Test $test, \Exception $e, $time)
    {
    }

    /**
     * @inheritdoc
     */
    public function addSkippedTest(\PHPUnit_Framework_Test $test, \Exception $e, $time)
    {
    }

    /**
     * @inheritdoc
     */
    public function startTest(\PHPUnit_Framework_Test $test)
    {
    }
}
