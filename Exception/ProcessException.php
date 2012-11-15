<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Exception;

use ML\JsonLD\JsonLD;


/**
 * Exception class thrown when an error occurs during processing.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class ProcessException extends \RuntimeException
{
    /**
     * The file being processed
     *
     * @var string
     */
    private $parsedFile;

    /**
     * The code snippet that triggered this exception
     *
     * @var mixed
     */
    private $snippet;

    /**
     * The raw error message (containing place-holders)
     *
     * @var string
     */
    private $rawMessage;


    /**
     * Constructor.
     *
     * @param string    $message    The error message
     * @param mixed     $snippet    The code snippet
     * @param string    $parsedFile The file name where the error occurred
     * @param Exception $previous   The previous exception
     */
    public function __construct($message, $snippet = null, $parsedFile = null, Exception $previous = null)
    {
        $this->parsedFile = $parsedFile;
        $this->snippet = ($snippet) ? JsonLD::toString($snippet) : $snippet;
        $this->rawMessage = $message;

        $this->updateMessage();

        parent::__construct($this->message, 0, $previous);
    }

    /**
     * Gets the snippet of code near the error.
     *
     * @return string The snippet of code
     */
    public function getSnippet()
    {
        return $this->snippet;
    }

    /**
     * Sets the snippet of code near the error.
     *
     * @param mixed $snippet The code snippet
     */
    public function setSnippet($snippet)
    {
        $this->snippet = ($snippet) ? JsonLD::toString($snippet) : $snippet;

        $this->updateMessage();
    }

    /**
     * Gets the filename where the error occurred.
     *
     * This method returns null if a string is parsed.
     *
     * @return string The filename
     */
    public function getParsedFile()
    {
        return $this->parsedFile;
    }

    /**
     * Sets the filename where the error occurred.
     *
     * @param string $parsedFile The filename
     */
    public function setParsedFile($parsedFile)
    {
        $this->parsedFile = $parsedFile;

        $this->updateMessage();
    }

    /**
     * Updates the exception message by including the file name if available.
     */
    private function updateMessage()
    {
        $this->message = $this->rawMessage;

        $dot = false;
        if ('.' === substr($this->message, -1)) {
            $this->message = substr($this->message, 0, -1);
            $dot = true;
        }

        if (null !== $this->parsedFile) {
            $this->message .= sprintf(' in %s', json_encode($this->parsedFile));
        }

        if ($this->snippet) {
            $this->message .= sprintf(' (near %s)', $this->snippet);
        }

        if ($dot) {
            $this->message .= '.';
        }
    }
}
