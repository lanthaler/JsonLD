<?php

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\RemoteDocument;
use ML\JsonLD\RemoteDocumentLoader;

class RemoteDocumentLoaderTest extends \PHPUnit_Framework_TestCase
{
    public function testInjection()
    {
        $defaultLoader = JsonLD::getRemoteDocumentLoader();
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocumentLoader', $defaultLoader);
        $this->assertInstanceOf('ML\\JsonLD\\FileGetContentsLoader', $defaultLoader);
        $this->assertTrue($defaultLoader === JsonLD::getRemoteDocumentLoader(), 'signleton');

        $mockedLoader = $this->createMockLoader();
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocumentLoader', $mockedLoader);
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocument', $mockedLoader->loadDocument('http://example.org/'));

        JsonLD::setRemoteDocumentLoader($mockedLoader);
        $this->assertTrue($mockedLoader === JsonLD::getRemoteDocumentLoader(), 'injection');
    }

    /**
     * @return RemoteDocumentLoader
     */
    private function createMockLoader()
    {
        $loader = $this->getMock('ML\\JsonLD\\RemoteDocumentLoader');
        $loader->method('loadDocument')->willReturn(new RemoteDocument());

        return $loader;
    }
}
