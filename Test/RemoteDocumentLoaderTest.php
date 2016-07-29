<?php

namespace ML\JsonLD\Test;

use ML\JsonLD\JsonLD;
use ML\JsonLD\RemoteDocument;
use ML\JsonLD\RemoteDocumentLoader;

class RemoteDocumentLoaderTest extends \PHPUnit_Framework_TestCase
{
    public function testInjection()
    {
        // get default loader
        $defaultLoader = JsonLD::getRemoteDocumentLoader();
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocumentLoader', $defaultLoader);
        $this->assertInstanceOf('ML\\JsonLD\\FileGetContentsLoader', $defaultLoader);
        $this->assertTrue($defaultLoader === JsonLD::getRemoteDocumentLoader(), 'signleton');

        // create mocked loader
        $mockedLoader = $this->createMockLoader();
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocumentLoader', $mockedLoader);
        $this->assertInstanceOf('ML\\JsonLD\\RemoteDocument', $mockedLoader->loadDocument('http://example.org/'));

        // inject loader
        JsonLD::setRemoteDocumentLoader($mockedLoader);
        $this->assertTrue($mockedLoader === JsonLD::getRemoteDocumentLoader(), 'injection');

        // restore loader
        JsonLD::setRemoteDocumentLoader($defaultLoader);
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
