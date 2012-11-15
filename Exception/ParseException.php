<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Exception;

/**
 * Exception class thrown when an error occurs during parsing.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class ParseException extends \RuntimeException
{
    /**
     * The file being parsed
     *
     * @var string
     */
    private $parsedFile;

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
     * @param string    $parsedFile The file name where the error occurred
     * @param Exception $previous   The previous exception
     */
    public function __construct($message, $parsedFile = null, Exception $previous = null)
    {
        $this->parsedFile = $parsedFile;
        $this->rawMessage = $message;

        $this->updateMessage();

        parent::__construct($this->message, 0, $previous);
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

        if ($this->parsedFile) {
            $this->message .= sprintf(' in %s', json_encode($this->parsedFile));
        }

        if ($dot) {
            $this->message .= '.';
        }
    }
}
