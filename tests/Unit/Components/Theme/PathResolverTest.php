<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

use Shopware\Components\Theme\PathResolver;

class PathResolverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var PathResolver
     */
    private $pathResolver;

    protected function setUp()
    {
        $this->pathResolver = new PathResolver(
            '/my/root/dir',
            [],
            $this->createTemplateManagerMock()
        );
    }

    public function testFiles()
    {
        $timestamp = '200000';
        $templateId = 5;
        $shopId = 4;

        $templateMock = $this->createTemplateMock($templateId);
        $shopMock = $this->createShopMock($shopId, $templateMock);

        $filenameHash = $timestamp.'_'.md5($timestamp.$templateId.$shopId.\Shopware::REVISION);

        $expected = '/my/root/dir/web/cache/' . $filenameHash . '.css';
        $this->assertEquals($expected, $this->pathResolver->getCssFilePath($shopMock, $timestamp));

        $expected = '/my/root/dir/web/cache/' . $filenameHash . '.js';
        $this->assertEquals($expected, $this->pathResolver->getJsFilePath($shopMock, $timestamp));
    }

    /**
     * @return \Enlight_Template_Manager
     */
    private function createTemplateManagerMock()
    {
        $templateManager = $this->getMockBuilder('Enlight_Template_Manager')
                                ->disableOriginalConstructor()
                                ->getMock();

        return $templateManager;
    }

    /**
     * @param int $templateId
     * @return \Shopware\Models\Shop\Template
     */
    private function createTemplateMock($templateId)
    {
        $templateStub = $this->getMockBuilder('Shopware\Models\Shop\Template')
                             ->disableOriginalConstructor()
                             ->getMock();

        $templateStub->method('getId')
                     ->willReturn($templateId);

        return $templateStub;
    }

    /**
     * @param int $shopId
     * @param \Shopware\Models\Shop\Template $templateStub
     * @return \Shopware\Models\Shop\Shop
     */
    private function createShopMock($shopId, $templateStub)
    {
        $stub = $this->getMockBuilder('Shopware\Models\Shop\Shop')
                     ->disableOriginalConstructor()
                     ->getMock();

        $stub->method('getMain')
            ->willReturn(null);

        $stub->method('getId')
             ->willReturn($shopId);

        $stub->method('getTemplate')
             ->willReturn($templateStub);

        return $stub;
    }
}
