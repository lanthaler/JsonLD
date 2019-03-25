<?php

/*
 * (c) Markus Lanthaler <mail@markus-lanthaler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ML\JsonLD\Exception;

use ML\JsonLD\Quad;

/**
 * Exception that is thrown when an invalid quad is detected.
 *
 * @author Markus Lanthaler <mail@markus-lanthaler.com>
 */
class InvalidQuadException extends \RuntimeException
{
    /**
     * The quad that triggered this exception
     *
     * @var Quad
     */
    private $quad;

    /**
     * Constructor.
     *
     * @param string    $message  The error message
     * @param Quad      $quad     The quad
     * @param null|\Exception $previous The previous exception
     */
    public function __construct($message, $quad, \Exception $previous = null)
    {
        $this->quad = $quad;

        parent::__construct($this->message, 0, $previous);
    }

    /**
     * Gets the quad
     *
     * @return Quad The quad.
     */
    public function getQuad()
    {
        return $this->quad;
    }

    /**
     * Sets the quad
     *
     * @param Quad $quad The quad.
     */
    public function setQuad($quad)
    {
        $this->quad = $quad;
    }
}
