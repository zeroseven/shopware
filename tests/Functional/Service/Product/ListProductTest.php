<?php

namespace Shopware\Tests\Service\Product;

use Shopware\Bundle\StoreFrontBundle\Struct\ListProduct;
use Shopware\Bundle\StoreFrontBundle\Struct\ProductContext;
use Shopware\Tests\Service\TestCase;

class ListProductTest extends TestCase
{
    /**
     * @param $number
     * @param ProductContext $context
     * @return ListProduct
     */
    private function getListProduct($number, ProductContext $context)
    {
        return Shopware()->Container()->get('shopware_storefront.list_product_service')
            ->get($number, $context);
    }

    public function testProductRequirements()
    {
        $number = 'List-Product-Test';

        $context = $this->getContext();

        $data = $this->getProduct($number, $context);
        $data = array_merge(
            $data,
            $this->helper->getConfigurator(
                $context->getCurrentCustomerGroup(),
                $number
            )
        );
        $this->helper->createArticle($data);

        $product = $this->getListProduct($number, $context);

        $this->assertNotEmpty($product->getId());
        $this->assertNotEmpty($product->getVariantId());
        $this->assertNotEmpty($product->getName());
        $this->assertNotEmpty($product->getNumber());
        $this->assertNotEmpty($product->getManufacturer());
        $this->assertNotEmpty($product->getTax());
        $this->assertNotEmpty($product->getUnit());

        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\ListProduct', $product);
        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Unit', $product->getUnit());
        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Manufacturer', $product->getManufacturer());

        $this->assertNotEmpty($product->getPrices());
        $this->assertNotEmpty($product->getPriceRules());
        foreach ($product->getPrices() as $price) {
            $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Price', $price);
            $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Unit', $price->getUnit());
            $this->assertGreaterThanOrEqual(1, $price->getUnit()->getMinPurchase());
        }

        foreach ($product->getPriceRules() as $price) {
            $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\PriceRule', $price);
        }

        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Price', $product->getCheapestPrice());
        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\PriceRule', $product->getCheapestPriceRule());
        $this->assertInstanceOf('Shopware\Bundle\StoreFrontBundle\Struct\Product\Unit', $product->getCheapestPrice()->getUnit());
        $this->assertGreaterThanOrEqual(1, $product->getCheapestPrice()->getUnit()->getMinPurchase());

        $this->assertNotEmpty($product->getCheapestPriceRule()->getPrice());
        $this->assertNotEmpty($product->getCheapestPrice()->getCalculatedPrice());
        $this->assertNotEmpty($product->getCheapestPrice()->getCalculatedPseudoPrice());
        $this->assertNotEmpty($product->getCheapestPrice()->getFrom());

        $this->assertGreaterThanOrEqual(1, $product->getUnit()->getMinPurchase());
        $this->assertNotEmpty($product->getManufacturer()->getName());
    }
}
