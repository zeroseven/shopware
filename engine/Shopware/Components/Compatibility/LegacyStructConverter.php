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

namespace Shopware\Components\Compatibility;

use Shopware\Bundle\MediaBundle\MediaService;
use Shopware\Bundle\SearchBundle;
use Shopware\Bundle\StoreFrontBundle;
use Shopware\Bundle\StoreFrontBundle\Service\Core\ContextService;

/**
 * @category  Shopware
 * @package   Shopware\Components\Compatibility
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class LegacyStructConverter
{
    /**
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @var ContextService
     */
    private $contextService;

    /**
     * @var \Enlight_Event_EventManager
     */
    private $eventManager;

    /**
     * @var MediaService
     */
    private $mediaService;

    /**
     * @param \Shopware_Components_Config $config
     * @param ContextService $contextService
     * @param \Enlight_Event_EventManager $eventManager
     * @param MediaService $mediaService
     */
    public function __construct(
        \Shopware_Components_Config $config,
        ContextService $contextService,
        \Enlight_Event_EventManager $eventManager,
        MediaService $mediaService
    ) {
        $this->config = $config;
        $this->contextService = $contextService;
        $this->eventManager = $eventManager;
        $this->mediaService = $mediaService;
    }

    /**
     * Converts a configurator group struct which used for default or selection configurators.
     *
     * @param StoreFrontBundle\Struct\Configurator\Group $group
     * @return array
     */
    public function convertConfiguratorGroupStruct(StoreFrontBundle\Struct\Configurator\Group $group)
    {
        return array(
            'groupID' => $group->getId(),
            'groupname' => $group->getName(),
            'groupdescription' => $group->getDescription(),
            'selected_value' => null,
            'selected' => $group->isSelected(),
            'user_selected' => $group->isSelected()
        );
    }

    /**
     * @param StoreFrontBundle\Struct\Category $category
     * @return array
     * @throws \Exception
     */
    public function convertCategoryStruct(StoreFrontBundle\Struct\Category $category)
    {
        $media = null;
        if ($category->getMedia()) {
            $media = $this->convertMediaStruct($category->getMedia());
        }

        $attribute = [];
        if ($category->hasAttribute('core')) {
            $attribute = $category->getAttribute('core')->toArray();
        }

        return [
            'id' => $category->getId(),
            'parentId' => $category->getParentId(),
            'name' => $category->getName(),
            'position' => $category->getPosition(),
            'metaKeywords' => $category->getMetaKeywords(),
            'metaDescription' => $category->getMetaDescription(),
            'cmsHeadline' => $category->getCmsHeadline(),
            'cmsText' => $category->getCmsText(),
            'active' => true,
            'template' => $category->getTemplate(),
            'productBoxLayout' => $category->getProductBoxLayout(),
            'blog' => $category->isBlog(),
            'path' => $category->getPath(),
            'external' => $category->getExternalLink(),
            'hideFilter' => !$category->displayFacets(),
            'hideTop' => !$category->displayInNavigation(),
            'noViewSelect' => $category->allowViewSelect(),
            'changed' => null,
            'added' => null,
            'attribute' => $attribute,
            'attributes' => $category->getAttributes(),
            'media' => $media,
            'link' => $this->getCategoryLink($category)
        ];
    }

    /**
     * @param StoreFrontBundle\Struct\Category $category
     * @return string
     */
    private function getCategoryLink(StoreFrontBundle\Struct\Category $category)
    {
        $viewport = $category->isBlog() ? 'blog' : 'cat';
        $params = http_build_query(
            ['sViewport' => $viewport, 'sCategory' => $category->getId()],
            '',
            '&'
        );

        return $this->config->get('baseFile') . '?' . $params;
    }

    /**
     * @param StoreFrontBundle\Struct\ListProduct[] $products
     * @return array
     */
    public function convertListProductStructList(array $products)
    {
        return array_map([$this, 'convertListProductStruct'], $products);
    }

    /**
     * Converts the passed ListProduct struct to a shopware 3-4 array structure.
     *
     * @param StoreFrontBundle\Struct\ListProduct $product
     * @return array
     */
    public function convertListProductStruct(StoreFrontBundle\Struct\ListProduct $product)
    {
        if (!$product instanceof StoreFrontBundle\Struct\ListProduct) {
            return array();
        }

        if ($this->config->get('calculateCheapestPriceWithMinPurchase')) {
            $cheapestPrice = $product->getCheapestPrice();
        } else {
            $cheapestPrice = $product->getCheapestUnitPrice();
        }

        $unit = $cheapestPrice->getUnit();

        $price = $this->sFormatPrice(
            $cheapestPrice->getCalculatedPrice()
        );

        $pseudoPrice = $this->sFormatPrice(
            $cheapestPrice->getCalculatedPseudoPrice()
        );

        $referencePrice = $this->sFormatPrice(
            $cheapestPrice->getCalculatedReferencePrice()
        );

        $promotion = $this->getListProductData($product);

        $promotion = array_merge(
            $promotion,
            array(
                'has_pseudoprice' => $cheapestPrice->getCalculatedPseudoPrice() > $cheapestPrice->getCalculatedPrice(),
                'price' => $price,
                'price_numeric' => $cheapestPrice->getCalculatedPrice(),
                'pseudoprice' => $pseudoPrice,
                'pseudoprice_numeric' => $cheapestPrice->getCalculatedPseudoPrice(),
                'pricegroup' => $cheapestPrice->getCustomerGroup()->getKey(),
            )
        );

        if ($referencePrice) {
            $promotion['referenceprice'] = $referencePrice;
        }

        if ($product->getPriceGroup()) {
            $promotion['pricegroupActive'] = true;
            $promotion['pricegroupID'] = $product->getPriceGroup()->getId();
        }

        if (count($product->getPrices()) > 1 || $product->hasDifferentPrices()) {
            $promotion['priceStartingFrom'] = $price;
        }

        if ($cheapestPrice->getCalculatedPseudoPrice()) {
            $discPseudo = $cheapestPrice->getCalculatedPseudoPrice();
            $discPrice = $cheapestPrice->getCalculatedPrice();

            if ($discPseudo != 0) {
                $discount = round(($discPrice / $discPseudo * 100) - 100, 2) * -1;
            } else {
                $discount = 0;
            }

            $promotion["pseudopricePercent"] = array(
                "int" => round($discount, 0),
                "float" => $discount
            );
        }

        if ($unit) {
            $promotion = array_merge($promotion, $this->convertUnitStruct($unit));
        }

        if ($product->getCover()) {
            $promotion['image'] = $this->convertMediaStruct($product->getCover());
        }


        if ($product->getVoteAverage()) {
            $promotion['sVoteAverage'] = $this->convertVoteAverageStruct($product->getVoteAverage());
        }

        $promotion['prices'] = [];
        foreach ($product->getPrices() as $price) {
            $priceData = $this->convertPriceStruct($price);

            $priceData = array_merge($priceData, array(
                'has_pseudoprice' => $price->getCalculatedPseudoPrice() > $price->getCalculatedPrice(),
                'price' => $this->sFormatPrice($price->getCalculatedPrice()),
                'price_numeric' => $price->getCalculatedPrice(),
                'pseudoprice' => $this->sFormatPrice($price->getCalculatedPseudoPrice()),
                'pseudoprice_numeric' => $price->getCalculatedPseudoPrice(),
                'pricegroup' => $price->getCustomerGroup()->getKey(),
                'purchaseunit' => $price->getUnit()->getPurchaseUnit(),
                'maxpurchase' => $price->getUnit()->getMaxPurchase()
            ));

            $promotion['prices'][] = $priceData;
        }

        $promotion["linkBasket"] = $this->config->get('baseFile') .
            "?sViewport=basket&sAdd=" . $promotion["ordernumber"];

        $promotion["linkDetails"] = $this->config->get('baseFile') .
            "?sViewport=detail&sArticle=" . $promotion["articleID"];

        return $promotion;
    }

    /**
     * Converts the passed ProductStream struct to an array structure.
     *
     * @param StoreFrontBundle\Struct\ProductStream $productStream
     * @return array
     */
    public function convertRelatedProductStreamStruct(StoreFrontBundle\Struct\ProductStream $productStream)
    {
        if (!$productStream instanceof StoreFrontBundle\Struct\ProductStream) {
            return array();
        }

        return [
            'id' => $productStream->getId(),
            'name' => $productStream->getName(),
            'description' => $productStream->getDescription(),
            'type' => $productStream->getType()
        ];
    }

    /**
     * @param StoreFrontBundle\Struct\Product $product
     * @return array
     */
    public function convertProductStruct(StoreFrontBundle\Struct\Product $product)
    {
        if (!$product instanceof StoreFrontBundle\Struct\Product) {
            return [];
        }

        $data = $this->getListProductData($product);

        if ($product->getUnit()) {
            $data = array_merge($data, $this->convertUnitStruct($product->getUnit()));
        }

        //set defaults for detail page combo box.
        if (!$data['maxpurchase']) {
            $data['maxpurchase'] = $this->config->get('maxPurchase');
        }
        if (!$data['purchasesteps']) {
            $data['purchasesteps'] = 1;
        }

        if ($product->getPriceGroup()) {
            $data = array_merge(
                $data,
                array(
                    'pricegroupActive' => $product->isPriceGroupActive(),
                    'pricegroupID' => $product->getPriceGroup()->getId()
                )
            );
        }

        /** @var $variantPrice StoreFrontBundle\Struct\Product\Price */
        $variantPrice = $product->getVariantPrice();

        $data['price'] = $this->sFormatPrice($variantPrice->getCalculatedPrice());
        $data['price_numeric'] = $variantPrice->getCalculatedPrice();
        $data['pseudoprice'] = $this->sFormatPrice($variantPrice->getCalculatedPseudoPrice());
        $data['pseudoprice_numeric'] = $variantPrice->getCalculatedPseudoPrice();
        $data['has_pseudoprice'] = $variantPrice->getCalculatedPseudoPrice() > $variantPrice->getCalculatedPrice();

        if ($variantPrice->getCalculatedPseudoPrice()) {
            $discPseudo = $variantPrice->getCalculatedPseudoPrice();
            $discPrice = $variantPrice->getCalculatedPrice();

            if ($discPseudo != 0) {
                $discount = round(($discPrice / $discPseudo * 100) - 100, 2) * -1;
            } else {
                $discount = 0;
            }

            $data["pseudopricePercent"] = array(
                "int" => round($discount, 0),
                "float" => $discount
            );
        }

        $data['pricegroup'] = $variantPrice->getCustomerGroup()->getKey();

        $data['referenceprice'] = $variantPrice->getCalculatedReferencePrice();

        if (count($product->getPrices()) > 1) {
            foreach ($product->getPrices() as $price) {
                $data['sBlockPrices'][] = $this->convertPriceStruct(
                    $price
                );
            }
        }

        //convert all product images and set cover image
        foreach ($product->getMedia() as $media) {
            $data['images'][] = $this->convertMediaStruct($media);
        }

        if (empty($data['images'])) {
            if ($product->getCover()) {
                $data['image'] = $this->convertMediaStruct($product->getCover());
            }
        } else {
            $data['image'] = array_shift($data['images']);
        }

        //convert product voting
        foreach ($product->getVotes() as $vote) {
            $data['sVoteComments'][] = $this->convertVoteStruct($vote);
        }

        $data['sVoteAverage'] = array('average' => 0, 'count' => 0);

        if ($product->getVoteAverage()) {
            $data['sVoteAverage'] = $this->convertVoteAverageStruct($product->getVoteAverage());
        }

        if ($product->getPropertySet()) {
            $data['filtergroupID'] = $product->getPropertySet()->getId();
            $data['sProperties'] = $this->convertPropertySetStruct($product->getPropertySet());
        }

        foreach ($product->getDownloads() as $download) {
            $temp = array(
                'id' => $download->getId(),
                'description' => $download->getDescription(),
                'filename' => $this->mediaService->getUrl($download->getFile()),
                'size' => $download->getSize(),
            );

            $attributes = [];

            if ($download->hasAttribute('core')) {
                $attributes = $download->getAttribute('core')->toArray();
            }

            $temp['attributes'] = $attributes;
            $data['sDownloads'][] = $temp;
        }

        foreach ($product->getLinks() as $link) {
            $temp = array(
                'id' => $link->getId(),
                'description' => $link->getDescription(),
                'link' => $link->getLink(),
                'target' => $link->getTarget(),
                'supplierSearch' => false,
            );

            if (!preg_match("/http/", $temp['link'])) {
                $temp["link"] = "http://" . $link->getLink();
            }

            $data["sLinks"][] = $temp;
        }

        $data["sLinks"][] = array(
            'supplierSearch' => true,
            'description' => $product->getManufacturer()->getName(),
            'target' => '_parent',
            'link' => $this->getSupplierListingLink($product->getManufacturer())
        );

        $data['sRelatedArticles'] = array();
        foreach ($product->getRelatedProducts() as $relatedProduct) {
            $data['sRelatedArticles'][] = $this->convertListProductStruct($relatedProduct);
        }

        $data['sSimilarArticles'] = array();
        foreach ($product->getSimilarProducts() as $similarProduct) {
            $data['sSimilarArticles'][] = $this->convertListProductStruct($similarProduct);
        }

        $data['relatedProductStreams'] = array();
        foreach ($product->getRelatedProductStreams() as $relatedProductStream) {
            $data['relatedProductStreams'][] = $this->convertRelatedProductStreamStruct($relatedProductStream);
        }

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Product\VoteAverage $average
     * @return array
     */
    public function convertVoteAverageStruct(StoreFrontBundle\Struct\Product\VoteAverage $average)
    {
        $data = array(
            'average' => round($average->getAverage()),
            'count' => $average->getCount(),
            'pointCount' => $average->getPointCount()
        );

        $data['attributes'] = $average->getAttributes();

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Product\Vote $vote
     * @return array
     */
    public function convertVoteStruct(StoreFrontBundle\Struct\Product\Vote $vote)
    {
        $data = array(
            'id' => $vote->getId(),
            'name' => $vote->getName(),
            'headline' => $vote->getHeadline(),
            'comment' => $vote->getComment(),
            'points' => $vote->getPoints(),
            'active' => true,
            'email' => $vote->getEmail(),
            'answer' => $vote->getAnswer(),
            'datum' => '0000-00-00 00:00:00',
            'answer_date' => '0000-00-00 00:00:00'
        );

        if ($vote->getCreatedAt() instanceof \DateTime) {
            $data['datum'] = $vote->getCreatedAt()->format('Y-m-d H:i:s');
        }

        if ($vote->getAnsweredAt() instanceof \DateTime) {
            $data['answer_date'] = $vote->getAnsweredAt()->format('Y-m-d H:i:s');
        }

        $data['attributes'] = $vote->getAttributes();

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Product\Price $price
     * @return array
     */
    public function convertPriceStruct(StoreFrontBundle\Struct\Product\Price $price)
    {
        $data = array(
            'valFrom' => $price->getFrom(),
            'valTo' => $price->getTo(),
            'from' => $price->getFrom(),
            'to' => $price->getTo(),
            'price' => $price->getCalculatedPrice(),
            'pseudoprice' => $price->getCalculatedPseudoPrice(),
            'referenceprice' => $price->getCalculatedReferencePrice()
        );

        $data['attributes'] = $price->getAttributes();

        return $data;
    }

    private function getSourceSet($thumbnail)
    {
        if ($thumbnail->getRetinaSource() !== null) {
            return sprintf('%s, %s 2x', $thumbnail->getSource(), $thumbnail->getRetinaSource());
        } else {
            return $thumbnail->getSource();
        }
    }

    /**
     * @param StoreFrontBundle\Struct\Media $media
     * @return array
     */
    public function convertMediaStruct(StoreFrontBundle\Struct\Media $media)
    {
        if (!$media instanceof StoreFrontBundle\Struct\Media) {
            return [];
        }

        $thumbnails = [];

        foreach ($media->getThumbnails() as $thumbnail) {
            $thumbnails[] = [
                'source' => $thumbnail->getSource(),
                'retinaSource' => $thumbnail->getRetinaSource(),
                'sourceSet' => $this->getSourceSet($thumbnail),
                'maxWidth' => $thumbnail->getMaxWidth(),
                'maxHeight' => $thumbnail->getMaxHeight()
            ];
        }

        $data = array(
            'id' => $media->getId(),
            'position' => 1,
            'source' => $media->getFile(),
            'description' => $media->getName(),
            'extension' => $media->getExtension(),
            'main' => $media->isPreview(),
            'parentId' => null,
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'thumbnails' => $thumbnails
        );

        $attributes = $media->getAttributes();
        if ($attributes && isset($attributes['image'])) {
            $data['attribute'] = $attributes['image']->toArray();
            unset($data['attribute']['id']);
            unset($data['attribute']['imageID']);
        } else {
            $data['attribute'] = [];
        }

        return $this->eventManager->filter('Legacy_Struct_Converter_Convert_Media', $data, [
            'media' => $media
        ]);
    }

    /**
     * @param StoreFrontBundle\Struct\Product\Unit $unit
     * @return array
     */
    public function convertUnitStruct(StoreFrontBundle\Struct\Product\Unit $unit)
    {
        $data = array(
            'minpurchase' => $unit->getMinPurchase(),
            'maxpurchase' => $unit->getMaxPurchase(),
            'purchasesteps' => $unit->getPurchaseStep(),
            'purchaseunit' => $unit->getPurchaseUnit(),
            'referenceunit' => $unit->getReferenceUnit(),
            'packunit' => $unit->getPackUnit(),
            'unitID' => $unit->getId(),
            'sUnit' => array(
                'unit' => $unit->getUnit(),
                'description' => $unit->getName()
            )
        );

        $data['unit_attributes'] = $unit->getAttributes();

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Product\Manufacturer $manufacturer
     * @return string
     */
    public function getSupplierListingLink(StoreFrontBundle\Struct\Product\Manufacturer $manufacturer)
    {
        return 'controller=listing&action=manufacturer&sSupplier=' . (int) $manufacturer->getId();
    }

    /**
     * Example:
     *
     * return [
     *     9 => [
     *         'id' => 9,
     *         'optionID' => 9,
     *         'name' => 'Farbe',
     *         'groupID' => 1,
     *         'groupName' => 'Edelbrände',
     *         'value' => 'goldig',
     *         'values' => [
     *             53 => 'goldig',
     *         ],
     *     ],
     *     2 => [
     *         'id' => 2,
     *         'optionID' => 2,
     *         'name' => 'Flaschengröße',
     *         'groupID' => 1,
     *         'groupName' => 'Edelbrände',
     *         'value' => '0,5 Liter, 0,7 Liter, 1,0 Liter',
     *         'values' => [
     *             23 => '0,5 Liter',
     *             24 => '0,7 Liter',
     *             25 => '1,0 Liter',
     *         ],
     *     ],
     * ];
     *
     * @param StoreFrontBundle\Struct\Property\Set $set
     * @return array
     */
    public function convertPropertySetStruct(StoreFrontBundle\Struct\Property\Set $set)
    {
        $result = [];
        foreach ($set->getGroups() as $group) {
            $values = array_map(
                function (StoreFrontBundle\Struct\Property\Option $option) {
                    return $option->getName();
                },
                $group->getOptions()
            );

            $mediaValues = array();
            foreach ($group->getOptions() as $option) {
                /**@var $option StoreFrontBundle\Struct\Property\Option */
                if ($option->getMedia()) {
                    $mediaValues[$option->getId()] = array_merge(array('valueId' => $option->getId()), $this->convertMediaStruct($option->getMedia()));
                }
            }

            $result[$group->getId()] = [
                'id'        => $group->getId(),
                'optionID'  => $group->getId(),
                'name'      => $group->getName(),
                'groupID'   => $set->getId(),
                'groupName' => $set->getName(),
                'value'     => implode(', ', $values),
                'values'    => $values,
                'media'     => $mediaValues,
            ];
        }

        return $result;
    }

    /**
     * @param StoreFrontBundle\Struct\Property\Group $group
     * @return array
     */
    public function convertPropertyGroupStruct(StoreFrontBundle\Struct\Property\Group $group)
    {
        $data = array(
            'id' => $group->getId(),
            'name' => $group->getName(),
            'isFilterable' => $group->isFilterable(),
            'options' => array(),
            'attributes' => array()
        );

        foreach ($group->getAttributes() as $key => $attribute) {
            $data['attributes'][$key] = $attribute->toArray();
        }

        foreach ($group->getOptions() as $option) {
            $data['options'][] = $this->convertPropertyOptionStruct($option);
        }

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Property\Option $option
     * @return array
     */
    public function convertPropertyOptionStruct(StoreFrontBundle\Struct\Property\Option $option)
    {
        $data = array(
            'id' => $option->getId(),
            'name' => $option->getName(),
            'attributes' => array()
        );

        foreach ($option->getAttributes() as $key => $attribute) {
            $data['attributes'][$key] = $attribute->toArray();
        }

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\Product\Manufacturer $manufacturer
     * @return array
     */
    public function convertManufacturerStruct(StoreFrontBundle\Struct\Product\Manufacturer $manufacturer)
    {
        $data = array(
            'id' => $manufacturer->getId(),
            'name' => $manufacturer->getName(),
            'description' => $manufacturer->getDescription(),
            'metaTitle' => $manufacturer->getMetaTitle(),
            'metaDescription' => $manufacturer->getMetaDescription(),
            'metaKeywords' => $manufacturer->getMetaKeywords(),
            'link' => $manufacturer->getLink(),
            'image' => $manufacturer->getCoverFile(),
        );

        $data['attribute'] = array();
        foreach ($manufacturer->getAttributes() as $attribute) {
            $data['attribute'] = array_merge(
                $data['attribute'],
                $attribute->toArray()
            );
        }

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\ListProduct $product
     * @param StoreFrontBundle\Struct\Configurator\Set $set
     * @return array
     */
    public function convertConfiguratorStruct(
        StoreFrontBundle\Struct\ListProduct $product,
        StoreFrontBundle\Struct\Configurator\Set $set
    ) {
        $groups = array();
        foreach ($set->getGroups() as $group) {
            $groupData = $this->convertConfiguratorGroupStruct($group);

            $options = array();
            foreach ($group->getOptions() as $option) {
                $optionData = $this->convertConfiguratorOptionStruct(
                    $group,
                    $option
                );

                if ($option->isSelected()) {
                    $groupData['selected_value'] = $option->getId();
                }

                $options[$option->getId()] = $optionData;
            }

            $groupData['values'] = $options;
            $groups[] = $groupData;
        }

        $settings = $this->getConfiguratorSettings($set, $product);

        $data = array(
            'sConfigurator' => $groups,
            'sConfiguratorSettings' => $settings,
            'isSelectionSpecified' => $set->isSelectionSpecified()
        );

        return $data;
    }

    /**
     * @param StoreFrontBundle\Struct\ListProduct $product
     * @param StoreFrontBundle\Struct\Configurator\Set $set
     * @return array
     */
    public function convertConfiguratorPrice(
        StoreFrontBundle\Struct\ListProduct $product,
        StoreFrontBundle\Struct\Configurator\Set $set
    ) {
        if ($set->isSelectionSpecified()) {
            return [];
        }

        $data = [];

        $variantPrice = $product->getVariantPrice();

        if ($this->config->get('calculateCheapestPriceWithMinPurchase')) {
            $cheapestPrice = $product->getCheapestPrice();
        } else {
            $cheapestPrice = $product->getCheapestUnitPrice();
        }

        if (count($product->getPrices()) > 1 || $product->hasDifferentPrices()) {
            $data['priceStartingFrom'] = $this->sFormatPrice(
                $cheapestPrice->getCalculatedPrice()
            );
        }

        $data['price'] = $data['priceStartingFrom'] ? : $this->sFormatPrice(
            $variantPrice->getCalculatedPrice()
        );

        $data['sBlockPrices'] = [];

        return $data;
    }

    /**
     * Creates the settings array for the passed configurator set
     *
     * @param StoreFrontBundle\Struct\Configurator\Set $set
     * @param StoreFrontBundle\Struct\ListProduct $product
     * @return array
     */
    public function getConfiguratorSettings(
        StoreFrontBundle\Struct\Configurator\Set $set,
        StoreFrontBundle\Struct\ListProduct $product
    ) {
        $settings = array(
            'instock' => $product->isCloseouts(),
            'articleID' => $product->getId(),
            'type' => $set->getType()
        );

        //switch the template for the different configurator types.
        if ($set->getType() == 1) {
            //Selection configurator
            $settings["template"] = "article_config_step.tpl";
        } elseif ($set->getType() == 2) {
            //Table configurator
            $settings["template"] = "article_config_picture.tpl";
        } else {
            //Other configurator types
            $settings["template"] = "article_config_upprice.tpl";
        }

        return $settings;
    }

    /**
     * Converts a configurator option struct which used for default or selection configurators.
     *
     * @param StoreFrontBundle\Struct\Configurator\Group $group
     * @param StoreFrontBundle\Struct\Configurator\Option $option
     * @return array
     */
    public function convertConfiguratorOptionStruct(
        StoreFrontBundle\Struct\Configurator\Group $group,
        StoreFrontBundle\Struct\Configurator\Option $option
    ) {
        $data = array(
            'optionID' => $option->getId(),
            'groupID' => $group->getId(),
            'optionname' => $option->getName(),
            'user_selected' => $option->isSelected(),
            'selected' => $option->isSelected(),
            'selectable' => $option->getActive()
        );

        if ($option->getMedia()) {
            $data['media'] = $this->convertMediaStruct($option->getMedia());
        }

        return $data;
    }

    /**
     * Formats article prices
     * @access public
     * @param float $price
     * @return float price
     */
    private function sFormatPrice($price)
    {
        $price = str_replace(",", ".", $price);
        $price = $this->sRound($price);
        $price = str_replace(".", ",", $price); // Replaces points with commas
        $commaPos = strpos($price, ",");
        if ($commaPos) {
            $part = substr($price, $commaPos + 1, strlen($price) - $commaPos);
            switch (strlen($part)) {
                case 1:
                    $price .= "0";
                    break;
                case 2:
                    break;
            }
        } else {
            if (!$price) {
                $price = "0";
            } else {
                $price .= ",00";
            }
        }

        return $price;
    }

    /**
     * @param null $moneyfloat
     * @return float
     */
    private function sRound($moneyfloat = null)
    {
        $money_str = explode(".", $moneyfloat);
        if (empty($money_str[1])) {
            $money_str[1] = 0;
        }
        $money_str[1] = substr($money_str[1], 0, 3); // convert to rounded (to the nearest thousandth) string

        $money_str = $money_str[0] . "." . $money_str[1];

        return round($money_str, 2);
    }

    /**
     * Internal function which converts only the data of a list product.
     * Associated data won't converted.
     *
     * @param StoreFrontBundle\Struct\ListProduct $product
     * @return array
     */
    private function getListProductData(StoreFrontBundle\Struct\ListProduct $product)
    {
        $createDate = null;
        if ($product->getCreatedAt()) {
            $createDate = $product->getCreatedAt()->format('Y-m-d');
        }

        $data = array(
            'articleID' => $product->getId(),
            'articleDetailsID' => $product->getVariantId(),
            'ordernumber' => $product->getNumber(),
            'highlight' => $product->highlight(),
            'description' => $product->getShortDescription(),
            'description_long' => $product->getLongDescription(),
            'esd' => $product->hasEsd(),
            'articleName' => $product->getName(),
            'taxID' => $product->getTax()->getId(),
            'tax' => $product->getTax()->getTax(),
            'instock' => $product->getStock(),
            'isAvailable' => $product->isAvailable(),
            'weight' => $product->getWeight(),
            'shippingtime' => $product->getShippingTime(),
            'pricegroupActive' => false,
            'pricegroupID' => null,
            'length' => $product->getLength(),
            'height' => $product->getHeight(),
            'width' => $product->getWidth(),
            'laststock' => $product->isCloseouts(),
            'additionaltext' => $product->getAdditional(),
            'datum' => $createDate,
            'sales' => $product->getSales(),
            'filtergroupID' => null,
            'priceStartingFrom' => null,
            'pseudopricePercent' => null,
            //flag inside mini product
            'sVariantArticle' => null,
            'sConfigurator' => $product->hasConfigurator(),
            //only used for full products
            'metaTitle' => $product->getMetaTitle(),
            'shippingfree' => $product->isShippingFree(),
            'suppliernumber' => $product->getManufacturerNumber(),
            'notification' => $product->allowsNotification(),
            'ean' => $product->getEan(),
            'keywords' => $product->getKeywords(),
            'sReleasedate' => $this->dateToString($product->getReleaseDate()),
            'template' => $product->getTemplate(),
        );

        if ($product->hasAttribute('core')) {
            $attributes = $product->getAttribute('core')->toArray();
            unset($attributes['id'], $attributes['articleID'], $attributes['articledetailsID']);

            $data = array_merge($data, $attributes);
        }

        $data['attributes'] = $product->getAttributes();

        if ($product->getManufacturer()) {
            $manufacturer = array(
                'supplierName' => $product->getManufacturer()->getName(),
                'supplierImg' => $product->getManufacturer()->getCoverFile(),
                'supplierID' => $product->getManufacturer()->getId(),
                'supplierDescription' => $product->getManufacturer()->getDescription(),
            );

            if (!empty($manufacturer['supplierImg'])) {
                $manufacturer['supplierImg'] = $this->mediaService->getUrl($manufacturer['supplierImg']);
            }

            $data = array_merge($data, $manufacturer);
            $data['supplier_attributes'] = $product->getManufacturer()->getAttributes();
        }

        if ($product->hasAttribute('marketing')) {
            /**@var $marketing StoreFrontBundle\Struct\Product\MarketingAttribute */
            $marketing = $product->getAttribute('marketing');
            $data['newArticle'] = $marketing->isNew();
            $data['sUpcoming'] = $marketing->comingSoon();
            $data['topseller'] = $marketing->isTopSeller();
        }

        $today = new \DateTime();
        if ($product->getReleaseDate() && $product->getReleaseDate() > $today) {
            $data['sReleasedate'] = $product->getReleaseDate()->format('Y-m-d');
        }

        return $data;
    }

    /**
     * @param mixed $date
     * @return string
     */
    private function dateToString($date)
    {
        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d');
        }

        return '';
    }
}
