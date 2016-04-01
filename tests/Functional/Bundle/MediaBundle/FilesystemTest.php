<?php

namespace Shopware\Tests\Bundle\MediaBundle;

use Shopware\Bundle\MediaBundle\MediaServiceInterface;

/**
 * Class FilesystemTest
 * @package Shopware\Tests\Bundle\MediaBundle
 */
class FilesystemTest extends \Enlight_Components_Test_TestCase
{
    /**
     * @var MediaServiceInterface
     */
    private $mediaService;

    /**
     * @var array
     */
    private $testData;

    /**
     * @var array
     */
    private $testPaths = [
        'media/unknown/_phpunit_tmp.json',
        'media/unknown/5a/ef/21/_phpunit_tmp.json'
    ];

    /**
     * @var int
     */
    private $testFileSize;

    protected function setUp()
    {
        parent::setUp();

        $this->mediaService = Shopware()->Container()->get('shopware_media.media_service');
        $this->testData = [
            'key' => 'myKey',
            'name' => 'name',
            'people' => [
                'great guy',
                'greater guy',
                'grumpy guy'
            ]
        ];
        $this->testFileSize = strlen(json_encode($this->testData));
    }

    protected function tearDown()
    {
        foreach ($this->testPaths as $file) {
            if ($this->mediaService->has($file)) {
                $this->mediaService->delete($file);
            }
        }
    }

    public function testFiles()
    {
        foreach ($this->testPaths as $file) {
            $this->_testWrite($file);
            $this->_testSize($file);
            $this->_testRead($file);
            $this->_testRename($file);
            $this->_testDelete($file);
        }
    }

    public function testUrlGeneration()
    {
        $file = current($this->testPaths);

        $baseUrl = Shopware()->Container()->get('router')->assemble(['controller' => 'index', 'module' => 'frontend']);
        $mediaUrl = $baseUrl . $this->mediaService->encode($file);

        $this->assertEquals($mediaUrl, $this->mediaService->getUrl($file));
        $this->assertNull($this->mediaService->getUrl(''));
    }

    /**
     * @param string $path
     */
    private function _testWrite($path)
    {
        $content = json_encode($this->testData);
        $this->mediaService->write($path, $content);

        $this->assertTrue($this->mediaService->has($path));
    }

    /**
     * @param string $path
     */
    private function _testRead($path)
    {
        $content = $this->mediaService->read($path);
        $this->assertJsonStringEqualsJsonString($content, json_encode($this->testData));
    }

    /**
     * @param string $path
     */
    private function _testDelete($path)
    {
        $this->assertTrue($this->mediaService->has($path));
        $this->mediaService->delete($path);
        $this->assertFalse($this->mediaService->has($path));
    }

    /**
     * @param string $file
     */
    private function _testRename($file)
    {
        $tmpFile = "media/unknown/_phpunit_tmp_rename.json";
        $this->mediaService->rename($file, $tmpFile);

        $this->assertTrue($this->mediaService->has($tmpFile));
        $this->assertFalse($this->mediaService->has($file));

        $this->_testRead($tmpFile);

        $this->mediaService->rename($tmpFile, $file);

        $this->assertTrue($this->mediaService->has($file));
        $this->assertFalse($this->mediaService->has($tmpFile));
    }

    /**
     * @param string $file
     */
    private function _testSize($file)
    {
        $this->assertEquals($this->testFileSize, $this->mediaService->getSize($file));
    }
}
