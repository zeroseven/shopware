<?php

namespace Shopware\Tests\Service\Product;

use Shopware\Models\Article\Detail;
use Shopware\Tests\Service\TestCase;

class TranslationTest extends TestCase
{
    protected function getContext()
    {
        $tax = $this->helper->createTax();
        $customerGroup = $this->helper->createCustomerGroup();
        $shop = $this->helper->getShop(2);

        return $this->helper->createContext(
            $customerGroup,
            $shop,
            array($tax)
        );
    }

    public function testListProductTranslation()
    {
        $number = 'Translation-Test';
        $context = $this->getContext();

        $product = $this->getProduct($number, $context);
        $article = $this->helper->createArticle($product);

        $this->helper->createArticleTranslation(
            $article->getId(),
            $context->getShop()->getId()
        );

        $listProduct = $this->helper->getListProduct(
            $number,
            $context
        );

        $this->assertEquals('Dummy Translation', $listProduct->getName());
        $this->assertEquals('Dummy Translation', $listProduct->getShortDescription());
        $this->assertEquals('Dummy Translation', $listProduct->getLongDescription());
        $this->assertEquals('Dummy Translation', $listProduct->getAdditional());
        $this->assertEquals('Dummy Translation', $listProduct->getKeywords());
        $this->assertEquals('Dummy Translation', $listProduct->getMetaTitle());
        $this->assertEquals('Dummy Translation', $listProduct->getUnit()->getPackUnit());
    }

    public function testManufacturerTranslation()
    {
        $number = 'Translation-Test';
        $context = $this->getContext();

        $product = $this->getProduct($number, $context);
        $article = $this->helper->createArticle($product);

        $this->helper->createManufacturerTranslation(
            $article->getSupplier()->getId(),
            $context->getShop()->getId()
        );

        $product = $this->helper->getListProduct($number, $context);

        $manufacturer = $product->getManufacturer();

        $this->assertEquals('Dummy Translation', $manufacturer->getDescription());
        $this->assertEquals('Dummy Translation', $manufacturer->getMetaTitle());
        $this->assertEquals('Dummy Translation', $manufacturer->getMetaKeywords());
        $this->assertEquals('Dummy Translation', $manufacturer->getMetaDescription());
    }


    public function testUnitTranslation()
    {
        $number = 'Unit-Translation';
        $context = $this->getContext();

        $product = $this->getProduct($number, $context);

        $product = array_merge(
            $product,
            $this->helper->getConfigurator(
                $context->getCurrentCustomerGroup(),
                $number,
                array('Farbe' => array('rot', 'gelb'))
            )
        );

        $variant = $product['variants'][1];
        $variant['prices'] = $this->helper->getGraduatedPrices(
            $context->getCurrentCustomerGroup()->getKey(),
            -40
        );
        $variant['unit'] = array('name'  => 'Test-Unit-Variant', 'unit' => 'ABC');
        $product['variants'][1] = $variant;

        $article = $this->helper->createArticle($product);

        $unit = null;
        /**@var $detail Detail*/
        foreach ($article->getDetails() as $detail) {
            if ($variant['number'] === $detail->getNumber()) {
                $unit = $detail->getUnit();
                break;
            }
        }

        $data = array(
            $unit->getId() => array(
                'unit' => 'Dummy Translation 2',
                'description' => 'Dummy Translation 2'
            )
        );

        $this->helper->createUnitTranslations(
            array(
                $article->getMainDetail()->getUnit()->getId(),
                $unit->getId()
            ),
            $context->getShop()->getId(),
            $data
        );

        $listProduct = $this->helper->getListProduct(
            $number,
            $context
        );

        $this->assertEquals('Dummy Translation', $listProduct->getUnit()->getUnit());
        $this->assertEquals('Dummy Translation', $listProduct->getUnit()->getName());

        foreach ($listProduct->getPrices() as $price) {
            $this->assertEquals('Dummy Translation', $price->getUnit()->getUnit());
            $this->assertEquals('Dummy Translation', $price->getUnit()->getName());
        }

        $this->assertEquals('Dummy Translation 2', $listProduct->getCheapestPrice()->getUnit()->getUnit());
        $this->assertEquals('Dummy Translation 2', $listProduct->getCheapestPrice()->getUnit()->getName());
    }

    public function testPropertyTranslation()
    {
        $number = 'Property-Translation';
        $context = $this->getContext();

        $product = $this->getProduct($number, $context);
        $properties = $this->helper->getProperties(2, 2);
        $product = array_merge($product, $properties);

        $this->helper->createPropertyTranslation($properties['all'], $context->getShop()->getId());
        $this->helper->createArticle($product);

        $listProduct = $this->helper->getListProduct($number, $context);
        $property = $this->helper->getProductProperties($listProduct, $context);

        $this->assertEquals('Dummy Translation', $property->getName());

        foreach ($property->getGroups() as $group) {
            $expected = 'Dummy Translation group - ' . $group->getId();
            $this->assertEquals($expected, $group->getName());

            foreach ($group->getOptions() as $option) {
                $expected = 'Dummy Translation option - ' . $group->getId() . ' - ' . $option->getId();
                $this->assertEquals($expected, $option->getName());
            }
        }
    }


    public function testConfiguratorTranslation()
    {
        $number = 'Configurator-Translation';
        $context = $this->getContext();

        $product = $this->getProduct($number, $context);

        $configurator = $this->helper->getConfigurator(
            $context->getCurrentCustomerGroup(),
            $number,
            array(
                'Farbe' => array('rot', 'gelb'),
                'Größe' => array('L', 'M')
            )
        );

        $product = array_merge($product, $configurator);

        $this->helper->createConfiguratorTranslation(
            $configurator['configuratorSet'],
            $context->getShop()->getId()
        );

        $this->helper->createArticle($product);

        $listProduct = $this->helper->getListProduct($number, $context);

        $configurator = $this->helper->getProductConfigurator(
            $listProduct,
            $context
        );

        foreach ($configurator->getGroups() as $group) {
            $expected = 'Dummy Translation group - ' . $group->getId();
            $this->assertEquals($expected, $group->getName());

            $expected = 'Dummy Translation description - ' . $group->getId();
            $this->assertEquals($expected, $group->getDescription());

            foreach ($group->getOptions() as $option) {
                $expected = 'Dummy Translation option - ' . $group->getId() . ' - ' . $option->getId();
                $this->assertEquals($expected, $option->getName());
            }
        }
    }
}
