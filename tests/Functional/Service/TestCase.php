<?php

namespace Shopware\Tests\Service;

use Shopware\Bundle\SearchBundle\Condition\CategoryCondition;
use Shopware\Bundle\SearchBundle\ConditionInterface;
use Shopware\Bundle\SearchBundle\Criteria;
use Shopware\Bundle\SearchBundle\FacetInterface;
use Shopware\Bundle\SearchBundle\ProductNumberSearchResult;
use Shopware\Bundle\SearchBundle\SearchProduct;
use Shopware\Bundle\SearchBundle\SortingInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ProductContext;
use Shopware\Components\MultiEdit\Resource\Product;
use Shopware\Models\Article\Article;
use Shopware\Models\Category\Category;

class TestCase extends \Enlight_Components_Test_TestCase
{
    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Converter
     */
    protected $converter;

    protected function setUp()
    {
        $this->helper = new Helper();
        $this->converter = new Converter();
        parent::setUp();
    }

    protected function tearDown()
    {
        $this->helper->cleanUp();
        parent::tearDown();
    }

    /**
     * @param Category $category
     * @param array $products
     * @param array $expectedNumbers
     * @param ConditionInterface[] $conditions
     * @param FacetInterface[] $facets
     * @param SortingInterface[] $sortings
     * @param null $context
     * @return ProductNumberSearchResult
     */
    protected function search(
        $products,
        $expectedNumbers,
        $category = null,
        $conditions = array(),
        $facets = array(),
        $sortings = array(),
        $context = null
    ) {
        if ($context === null) {
            $context = $this->getContext();
        }

        if ($category === null) {
            $category = $this->helper->createCategory();
        }

        $this->createProducts($products, $context, $category);

        $criteria = new Criteria();

        $this->addCategoryBaseCondition($criteria, $category, $conditions, $context);

        $this->addConditions($criteria, $conditions);

        $this->addFacets($criteria, $facets);

        $this->addSortings($criteria, $sortings);

        $criteria->offset(0)->limit(4000);

        $result = Shopware()->Container()->get('shopware_search.product_number_search')
            ->search($criteria, $context);

        $this->assertSearchResult($result, $expectedNumbers);

        return $result;
    }

    /**
     * @param Criteria $criteria
     * @param Category $category
     * @param $conditions
     * @param ProductContext $context
     */
    protected function addCategoryBaseCondition(
        Criteria $criteria,
        Category $category,
        $conditions,
        ProductContext $context
    ) {
        if ($category) {
            $criteria->addBaseCondition(
                new CategoryCondition(array($category->getId()))
            );
        }
    }

    /**
     * @param Criteria $criteria
     * @param ConditionInterface[] $conditions
     */
    protected function addConditions(Criteria $criteria, $conditions)
    {
        foreach ($conditions as $condition) {
            $criteria->addCondition($condition);
        }
    }

    /**
     * @param Criteria $criteria
     * @param FacetInterface[] $facets
     */
    protected function addFacets(Criteria $criteria, $facets)
    {
        foreach ($facets as $facet) {
            $criteria->addFacet($facet);
        }
    }

    /**
     * @param Criteria $criteria
     * @param SortingInterface[] $sortings
     */
    protected function addSortings(Criteria $criteria, $sortings)
    {
        foreach ($sortings as $sorting) {
            $criteria->addSorting($sorting);
        }
    }

    /**
     * @param $products
     * @param ProductContext $context
     * @param Category $category
     * @return Article[]
     */
    public function createProducts($products, ProductContext $context, Category $category)
    {
        $articles = array();
        foreach ($products as $number => $additionally) {
            $articles[] = $this->createProduct(
                $number,
                $context,
                $category,
                $additionally
            );
        }
        return $articles;
    }

    /**
     * @param $number
     * @param ProductContext $context
     * @param Category $category
     * @return \Shopware\Models\Article\Article
     */
    protected function createProduct(
        $number,
        ProductContext $context,
        Category $category,
        $additionally
    ) {
        $data = $this->getProduct(
            $number,
            $context,
            $category,
            $additionally
        );
        return $this->helper->createArticle($data);
    }

    /**
     * @param ProductNumberSearchResult $result
     * @param $expectedNumbers
     */
    protected function assertSearchResult(
        ProductNumberSearchResult $result,
        $expectedNumbers
    ) {
        $this->assertCount(count($expectedNumbers), $result->getProducts());
        $this->assertEquals(count($expectedNumbers), $result->getTotalCount());

        foreach ($result->getProducts() as $product) {
            $this->assertContains(
                $product->getNumber(),
                $expectedNumbers
            );
        }
    }

    protected function assertSearchResultSorting(
        ProductNumberSearchResult $result,
        $expectedNumbers
    ) {
        $productResult = array_values($result->getProducts());

        /**@var $product SearchProduct*/
        foreach ($productResult as $index => $product) {
            $expectedProduct = $expectedNumbers[$index];

            $this->assertEquals(
                $expectedProduct,
                $product->getNumber(),
                sprintf(
                    'Expected %s at search result position %s, but got product %s',
                    $expectedProduct, $index, $product->getNumber()
                )
            );
        }
    }

    /**
     * @return TestContext
     */
    protected function getContext()
    {
        $tax = $this->helper->createTax();
        $customerGroup = $this->helper->createCustomerGroup();

        $shop = $this->helper->getShop();

        return $this->helper->createContext(
            $customerGroup,
            $shop,
            array($tax)
        );
    }

    /**
     * @param $number
     * @param ProductContext $context
     * @param Category $category
     * @return array
     */
    protected function getProduct(
        $number,
        ProductContext $context,
        Category $category = null,
        $additionally = null
    ) {
        $product = $this->helper->getSimpleProduct(
            $number,
            array_shift($context->getTaxRules()),
            $context->getCurrentCustomerGroup()
        );
        $product['categories'] = [['id' => $context->getShop()->getCategory()->getId()]];

        if ($category) {
            $product['categories'] = array(
                array('id' => $category->getId())
            );
        }

        return $product;
    }
}
