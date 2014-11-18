<?php

namespace Shopware\Tests\Service\Product;

use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\Bundle\StoreFrontBundle\Struct\Context;
use Shopware\Models\Category\Category;
use Shopware\Tests\Service\TestCase;

class ProductMediaTest extends TestCase
{
    protected function getProduct(
        $number,
        Context $context,
        Category $category = null,
        $imageCount
    ) {
        $data = parent::getProduct($number, $context, $category);

        $data['images'][] = $this->helper->getImageData(
            'sasse-korn.jpg',
            array('main' => 1)
        );

        for ($i=0; $i < $imageCount - 2; $i++) {
            $data['images'][] = $this->helper->getImageData();
        }

        return $data;
    }

    private function getVariantImageProduct($number, Struct\Context $context, $imageCount = 2)
    {
        $data = $this->getProduct(
            $number,
            $context,
            null,
            $imageCount
        );

        $data = array_merge(
            $data,
            $this->helper->getConfigurator(
                $context->getCurrentCustomerGroup(),
                $number,
                array('Farbe' => array('rot', 'gelb'))
            )
        );

        $data['variants'][0]['images'] = array($this->helper->getImageData('sasse-korn.jpg'));
        $data['variants'][1]['images'] = array($this->helper->getImageData('sasse-korn.jpg'));

        return $data;
    }


    public function testProductMediaList()
    {
        $context = $this->getContext();
        $numbers = array('testProductMediaList-1', 'testProductMediaList-2');
        foreach ($numbers as $number) {
            $this->helper->createArticle(
                $this->getProduct($number, $context, null, 4)
            );
        }

        $listProducts = Shopware()->Container()->get('list_product_service_core')
            ->getList($numbers, $context);

        $mediaList = Shopware()->Container()->get('product_media_gateway_dbal')
            ->getList($listProducts, $context);

        $this->assertCount(2, $mediaList);

        foreach ($numbers as $number) {
            $this->assertArrayHasKey($number, $mediaList);

            $productMediaList = $mediaList[$number];

            $this->assertCount(3, $productMediaList);

            /**@var $media Struct\Media*/
            foreach ($productMediaList as $media) {
                if ($media->isPreview()) {
                    $this->assertMediaFile('sasse-korn', $media);
                } else {
                    $this->assertMediaFile('test-spachtelmasse', $media);
                }
            }
        }
    }

    public function testVariantMediaList()
    {
        $numbers = array('testVariantMediaList1-', 'testVariantMediaList2-');
        $context = $this->getContext();
        $articles = array();

        foreach ($numbers as $number) {
            $data = $this->getVariantImageProduct($number, $context);
            $article = $this->helper->createArticle($data);
            $articles[] = $article;
        }

        $variantNumbers = array('testVariantMediaList1-1', 'testVariantMediaList1-2', 'testVariantMediaList2-1');

        $products = Shopware()->Container()->get('list_product_service_core')
            ->getList($variantNumbers, $context);

        $mediaList = Shopware()->Container()->get('variant_media_gateway_dbal')
            ->getList($products, $context);

        $this->assertCount(3, $mediaList);
        foreach ($variantNumbers as $number) {
            $this->assertArrayHasKey($number, $mediaList);

            $variantMedia = $mediaList[$number];

            foreach ($variantMedia as $media) {
                $this->assertMediaFile('sasse-korn', $media);
            }
        }

        $products = Shopware()->Container()->get('list_product_service_core')
            ->getList($numbers, $context);

        $mediaList = Shopware()->Container()->get('product_media_gateway_dbal')
            ->getList($products, $context);

        $this->assertCount(2, $mediaList);

        foreach ($numbers as $number) {
            $this->assertArrayHasKey($number, $mediaList);
            $media = $mediaList[$number];

            $this->assertCount(1, $media);
            $media = array_shift($media);
            $this->assertTrue($media->isPreview());
        }
    }

    public function testProductImagesWithVariant()
    {
        $number = 'testProductImagesWithVariant';
        $context = $this->getContext();

        $data = $this->getVariantImageProduct($number, $context, 3);

        $data['variants'][0]['number'] = 'testProductImagesWithVariant-1';
        $data['variants'][0]['images'] = array();

        $this->helper->createArticle($data);

        $variantNumber = 'testProductImagesWithVariant-1';
        $product = Shopware()->Container()->get('product_service_core')
            ->get($variantNumber, $context);

        $this->assertCount(2, $product->getMedia());
    }

    private function assertMediaFile($expected, Struct\Media $media)
    {
        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Media', $media);
        $this->assertNotEmpty($media->getThumbnails());

        $matcher = $this->stringContains($expected);
        $matcher->evaluate($media->getFile());

        foreach ($media->getThumbnails() as $thumbnail) {
            $matcher->evaluate($thumbnail);
        }
    }

}