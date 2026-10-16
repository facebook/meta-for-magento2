<?php

declare(strict_types=1);

/**
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Meta\Catalog\Model\Product\Feed;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Ui\DataProvider\Product\Form\Modifier\Grouped;
use Meta\BusinessExtension\Helper\FBEHelper;
use Meta\Catalog\Helper\Product\MappingConfig;
use Meta\Catalog\Model\Config\Source\FeedUploadMethod;
use Meta\Catalog\Helper\Product\Identifier as ProductIdentifier;
use Meta\Catalog\Model\Product\Feed\Builder\AdditionalAttributes;
use Meta\Catalog\Model\Product\Feed\Builder\InventoryInterface;
use Meta\Catalog\Model\Product\Feed\Builder\Tools as BuilderTools;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use Meta\Catalog\Model\Product\Feed\Builder\UnsupportedProducts;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Builder
{
    //required fields

    /**
     * Meta product attributes keys
     */
    private const ATTR_RETAILER_ID = 'id';
    private const ATTR_NAME = 'title';
    private const ATTR_DESCRIPTION = 'description';
    private const ATTR_AVAILABILITY = 'availability';
    private const ATTR_CONDITION = 'condition';
    private const ATTR_PRICE = 'price';
    private const ATTR_URL = 'link';
    private const ATTR_IMAGE_URL = 'image_link';
    private const ATTR_BRAND = 'brand';

    //required checkout fields
    private const ATTR_INVENTORY = 'quantity_to_sell_on_facebook';
    private const ATTR_PRODUCT_CATEGORY = 'google_product_category';
    private const ATTR_SIZE = 'size';

    //optional checkout fields
    private const ATTR_SALE_PRICE = 'sale_price';
    private const ATTR_SALE_PRICE_EFFECTIVE_DATE = 'sale_price_effective_date';
    private const ATTR_ITEM_GROUP_ID = 'item_group_id';
    private const ATTR_STATUS = 'status';
    private const ATTR_ADDITIONAL_IMAGE_URL = 'additional_image_link';
    private const ATTR_COLOR = 'color';
    private const ATTR_GENDER = 'gender';
    private const ATTR_AGE_GROUP = 'age_group';
    private const ATTR_MATERIAL = 'material';
    private const ATTR_PATTERN = 'pattern';
    private const ATTR_SHIPPING_WEIGHT = 'shipping_weight';
    private const ATTR_RICH_DESCRIPTION = 'rich_text_description';
    private const ATTR_PRODUCT_TYPE = 'product_type';
    private const ATTR_VIDEO = 'video';
    private const ATTR_UNIT_PRICE = 'unit_price';
    private const ATTR_METADATA = 'additional_metadata';

    // name of this column is sku, as Meta will identify this column with sku name
    private const ATTR_OTHER_ID = 'sku';

    private const ATTR_UNSUPPORTED_PRODUCT_DATA = 'unsupported_product_data';
    private const ATTR_FEATURES = 'features';
    private const CUSTOM_ATTR_PREFIX = 'custom_attribute_';

    private const ALLOWED_TAGS_FOR_RICH_TEXT_DESCRIPTION = ['<form>', '<fieldset>', '<div>', '<span>',
        '<header>', '<h1>', '<h2>', '<h3>', '<h4>', '<h5>', '<h6>',
        '<table>', '<tbody>', '<tfoot>', '<thead>', '<td>', '<th>', '<tr>',
        '<ul>', '<li>', '<ol>', '<dl>', '<dd>', '<dt>',
        '<b>', '<u>', '<i>', '<em>', '<strong>', '<title>', '<small>',
        '<br>', '<p>', '<div>', '<sub>', '<sup>', '<pre>', '<q>', '<s>'];

    private const MAIN_WEBSITE_STORE = 'Main Website Store';
    private const MAIN_STORE = 'Main Store';
    private const MAIN_WEBSITE = 'Main Website';
    private const NOT_LOGGED_IN_ID = 0;
    private const URL_TYPE_MEDIA = 'media';

    /**
     * @var FBEHelper
     */
    private $fbeHelper;

    /**
     * @var string
     */
    private $defaultBrand;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var BuilderTools
     */
    private $builderTools;

    /**
     * @var InventoryInterface|null
     */
    private ?InventoryInterface $inventory = null;

    /**
     * @var ProductIdentifier
     */
    private $productIdentifier;

    /**
     * @var int
     */
    private $storeId;

    /**
     * @var string
     */
    private $uploadMethod;

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * @var MappingConfig
     */
    private MappingConfig $mappingConfig;

    /**
     * @var UnsupportedProducts
     */
    private UnsupportedProducts $unsupportedProducts;

    /**
     * @var AdditionalAttributes
     */
    private $additionalAttributes;

    /**
     * @var array
     */
    private array $attrMap = [];

    /**
     * Constructor
     *
     * @param AdditionalAttributes $additionalAttributes
     * @param BuilderTools $builderTools
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param FBEHelper $fbeHelper
     * @param UnsupportedProducts $unsupportedProducts
     * @param MappingConfig $mappingConfig
     * @param ProductIdentifier $productIdentifier
     * @param SystemConfig $systemConfig
     */
    public function __construct(
        AdditionalAttributes      $additionalAttributes,
        BuilderTools              $builderTools,
        CategoryCollectionFactory $categoryCollectionFactory,
        FBEHelper                 $fbeHelper,
        UnsupportedProducts       $unsupportedProducts,
        MappingConfig             $mappingConfig,
        ProductIdentifier         $productIdentifier,
        SystemConfig              $systemConfig
    ) {
        $this->fbeHelper = $fbeHelper;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->builderTools = $builderTools;
        $this->productIdentifier = $productIdentifier;
        $this->systemConfig = $systemConfig;
        $this->unsupportedProducts = $unsupportedProducts;
        $this->mappingConfig = $mappingConfig;
        $this->additionalAttributes = $additionalAttributes;
    }

    /**
     * Set store ID
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }

    /**
     * Set upload method
     *
     * @param string $uploadMethod
     * @return Builder
     */
    public function setUploadMethod($uploadMethod)
    {
        $this->uploadMethod = $uploadMethod;
        return $this;
    }

    /**
     * Get default brand
     *
     * @return string
     */
    private function getDefaultBrand()
    {
        if (!$this->defaultBrand) {
            $this->defaultBrand = $this->trimAttribute(self::ATTR_BRAND, $this->getStoreName());
        }
        return $this->defaultBrand;
    }

    /**
     * Get product url
     *
     * @param Product $product
     * @return string
     */
    private function getProductUrl(Product $product)
    {
        $link = isset($this->attrMap[self::ATTR_URL])
            ? $product->getData($this->attrMap[self::ATTR_URL])
            : null;
        if ($link == '' || $link == null) {
            $parentUrl = $product->getParentProductUrl();
            // use parent product URL if a simple product has a parent and is not visible individually
            $url = (!$product->isVisibleInSiteVisibility() && $parentUrl) ? $parentUrl : $product->getProductUrl();
            return $this->builderTools->replaceLocalUrlWithDummyUrl($url);
        } else {
            return $this->trimAttribute(self::ATTR_URL, $link);
        }
    }

    /**
     * Get product images
     *
     * @param Product $product
     * @return array
     */
    private function getProductImages(Product $product)
    {
        $mainImage = $product->getImage();

        $additionalImages = [];
        if (!empty($product->getMediaGalleryImages())) {
            foreach ($product->getMediaGalleryImages() as $img) {
                if ($img['file'] === $mainImage) {
                    continue;
                }
                $additionalImages[] = $this->builderTools->replaceLocalUrlWithDummyUrl($img['url']);
            }
        }

        return [
            'main_image' => $this->builderTools->replaceLocalUrlWithDummyUrl(
                $this->getBaseUrlMedia() . 'catalog/product' . $mainImage
            ),
            'additional_images' => array_slice($additionalImages, 0, 10),
        ];
    }

    /**
     * Get product category path
     *
     * @param Product $product
     * @return string
     * @throws LocalizedException
     */
    private function getCategoryPath(Product $product)
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return '';
        }

        $categoryNames = [];
        $categories = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('entity_id', $categoryIds)
            ->setOrder('position', 'ASC');

        foreach ($categories as $category) {
            $categoryNames[] = $category->getName();
        }
        return implode(' > ', $categoryNames);
    }

    /**
     * Trim attribute
     *
     * @param string $attrName
     * @param string $attrValue
     * @return string
     */
    private function trimAttribute($attrName, $attrValue)
    {
        $attrValue = trim((string)$attrValue);

        // Facebook Product attributes
        // ref: https://developers.facebook.com/docs/commerce-platform/catalog/fields
        $maxLengths = [
            self::ATTR_RETAILER_ID => null,
            self::ATTR_OTHER_ID => null,
            self::ATTR_URL => null,
            self::ATTR_IMAGE_URL => null,
            self::ATTR_ADDITIONAL_IMAGE_URL => null,
            self::ATTR_CONDITION => null,
            self::ATTR_AVAILABILITY => null,
            self::ATTR_INVENTORY => null,
            self::ATTR_PRICE => null,
            self::ATTR_SIZE => null,
            self::ATTR_COLOR => null,
            self::ATTR_BRAND => 70,
            self::ATTR_NAME => 100,
            self::ATTR_DESCRIPTION => 9999,
            self::ATTR_RICH_DESCRIPTION => 9999,
            self::ATTR_PRODUCT_TYPE => 750,
        ];

        if (!array_key_exists($attrName, $maxLengths)) {
            return '';
        }

        $maxLength = $maxLengths[$attrName];
        if ($maxLength === null) {
            return $attrValue;
        }

        if (mb_strlen($attrValue) > $maxLength) {
            if ($attrName === self::ATTR_RICH_DESCRIPTION) {
                return '';
            }
            if ($attrName === self::ATTR_PRODUCT_TYPE) {
                return mb_substr($attrValue, mb_strlen($attrValue) - 750, 750);
            }
            return mb_substr($attrValue, 0, $maxLength);
        }
        return $attrValue;
    }

    /**
     * Get product description
     *
     * @param Product $product
     * @return string
     */
    private function getDescription(Product $product)
    {
        // 'Description' is required by default but can be made
        // optional through the magento admin panel.
        // Try using the short description and title if it doesn't exist.
        $description = isset($this->attrMap[self::ATTR_DESCRIPTION])
            ? $product->getData($this->attrMap[self::ATTR_DESCRIPTION])
            : $this->trimAttribute(
                self::ATTR_DESCRIPTION,
                $product->getDescription()
            );
        if ($description == '') {
            $description = $this->trimAttribute(
                self::ATTR_DESCRIPTION,
                $product->getDescription()
            );
        }
        if (!$description) {
            $description = $this->trimAttribute(
                self::ATTR_DESCRIPTION,
                $product->getShortDescription()
            );
        }

        // phpcs:ignore
        $description = html_entity_decode($description);

        // remove style tag and its content from description
        // phpcs:ignore
        $description = html_entity_decode(preg_replace(
            '/<\s*style.+?<\s*\/\s*style.*?>/',
            '',
            $description ?? ''
        ));
        // phpcs:ignore
        $description = html_entity_decode(preg_replace('/<[^<]+?>/', '', $description));
        return $this->builderTools->lowercaseIfAllCaps($description);
    }

    /**
     * Get product rich description
     *
     * @param Product $product
     * @return string
     */
    private function getRichDescription(Product $product)
    {
        $description = isset($this->attrMap[self::ATTR_RICH_DESCRIPTION])
            ? $product->getData($this->attrMap[self::ATTR_RICH_DESCRIPTION])
            : $product->getDescription();
        if ($description == '') {
            $description = $product->getDescription();
        }

        if (!$description) {
            $description = $product->getShortDescription();
        }
        if (!$description) {
            return '';
        }

        // phpcs:ignore
        $description = html_entity_decode($description);

        // remove style tag and its content from description
        // phpcs:ignore
        $description = html_entity_decode(preg_replace(
            '/<\s*style.+?<\s*\/\s*style.*?>/',
            '',
            $description ?? ''
        ));

        return $this->trimAttribute(
            self::ATTR_RICH_DESCRIPTION,
            strip_tags($description, self::ALLOWED_TAGS_FOR_RICH_TEXT_DESCRIPTION)
        );
    }

    /**
     * Get product condition
     *
     * @param Product $product
     * @return string
     */
    private function getCondition(Product $product)
    {
        $condition = isset($this->attrMap[self::ATTR_CONDITION])
            ? $product->getData($this->attrMap[self::ATTR_CONDITION]) : [];
        if ($condition) {
            return (in_array($condition, ['new', 'refurbished', 'used'])) ? $condition : 'new';
        }

        if ($product->getData('condition')) {
            $condition = $this->trimAttribute(self::ATTR_CONDITION, $product->getAttributeText(self::ATTR_CONDITION));
        }
        return (in_array($condition, ['new', 'refurbished', 'used'])) ? $condition : 'new';
    }

    /**
     * Get product brand
     *
     * @param Product $product
     * @return string|null
     */
    private function getBrand(Product $product)
    {
        $brand = isset($this->attrMap[self::ATTR_BRAND])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_BRAND])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_BRAND);
        if (!$brand || $brand == '') {
            $brand = $this->additionalAttributes->getCorrectText($product, self::ATTR_BRAND);
        }
        if (!$brand) {
            $brand = $this->additionalAttributes->getCorrectText($product, 'manufacturer');
        }
        if (!$brand) {
            $brand = $this->getDefaultBrand();
        }
        return $this->trimAttribute(self::ATTR_BRAND, $brand);
    }

    /**
     * Get product item group id
     *
     * @param Product $product
     * @return string
     */
    private function getItemGroupId(Product $product)
    {
        $itemGroupId = isset($this->attrMap[self::ATTR_ITEM_GROUP_ID])
            ? $product->getData($this->attrMap[self::ATTR_ITEM_GROUP_ID]) : null;
        if ($itemGroupId && $itemGroupId != '') {
            return $itemGroupId;
        }
        $configurableSettings = $product->getConfigurableSettings() ?: [];
        return array_key_exists('item_group_id', $configurableSettings) ? $configurableSettings['item_group_id'] : '';
    }

    /**
     * Get product color
     *
     * @param Product $product
     * @return string
     */
    private function getColor(Product $product)
    {
         $color = isset($this->attrMap[self::ATTR_COLOR])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_COLOR])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_COLOR);

        if ($color && $color != '') {
            return $this->trimAttribute(self::ATTR_COLOR, $color);
        }
        $configurableSettings = $product->getConfigurableSettings() ?: [];
        return array_key_exists('color', $configurableSettings) ? $configurableSettings['color'] : '';
    }

    /**
     * Get product size
     *
     * @param Product $product
     * @return string
     */
    private function getSize($product)
    {
        $size = isset($this->attrMap[self::ATTR_SIZE])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_SIZE])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_SIZE);

        if ($size && $size != '') {
            return $this->trimAttribute(self::ATTR_SIZE, $size);
        }
        $configurableSettings = $product->getConfigurableSettings() ?: [];
        return array_key_exists('size', $configurableSettings) ? $configurableSettings['size'] : '';
    }

    /**
     * Get product unit price
     *
     * @param Product $product
     * @return string
     */
    public function getUnitPrice($product)
    {
        $unitPrice = isset($this->attrMap[self::ATTR_UNIT_PRICE])
            ? $product->getData($this->attrMap[self::ATTR_UNIT_PRICE])
            : $this->builderTools->getUnitPrice($product);
        if (!$unitPrice || $unitPrice == '') {
            $unitPrice = $this->builderTools->getUnitPrice($product);
        }
        return $unitPrice;
    }

    /**
     * Get inventory for product
     *
     * @param Product $product
     * @return InventoryInterface
     */
    private function getInventory(Product $product): InventoryInterface
    {
        if ($this->inventory === null) {
            $this->inventory = $this->builderTools->getInventoryObject();
        }
        $this->inventory->initInventoryForProduct($product);
        return $this->inventory;
    }

    /**
     * Get status for product
     *
     * @param Product $product
     * @return string
     */
    private function getStatus(Product $product)
    {
        $parentProductVisibility = $product->getParentProductVisibility();

        // if product is not visible, check for parent product visibility
        if ($product->getVisibility() == Visibility::VISIBILITY_NOT_VISIBLE
            && ($parentProductVisibility === null || $parentProductVisibility == Visibility::VISIBILITY_NOT_VISIBLE)) {
            return 'archived';
        }

        $status = isset($this->attrMap[self::ATTR_STATUS])
            ? $product->getData($this->attrMap[self::ATTR_STATUS]) : $product->getStatus();
        if (!$status || $status == '') {
            $status = $product->getStatus();
        }
        return $status == Status::STATUS_ENABLED ? 'active' : 'archived';
    }

    /**
     * Get age group for product
     *
     * @param Product $product
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function getAgeGroup(Product $product)
    {
        $ageGroup = '';
        if (isset($this->attrMap[self::ATTR_AGE_GROUP])) {
            $ageGroup = $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_AGE_GROUP]);
        }
        if (empty($ageGroup)) {
            $ageGroup = $this->additionalAttributes->getCorrectText($product, self::ATTR_AGE_GROUP);
        }

        return $ageGroup ?: '';
    }

    /**
     * Get gender for product
     *
     * @param Product $product
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function getGender(Product $product)
    {
        $gender = isset($this->attrMap[self::ATTR_GENDER])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_GENDER])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_GENDER);
        if (!$gender || $gender == '') {
            $gender = $this->additionalAttributes->getCorrectText($product, self::ATTR_GENDER);
        }

        if (!$gender) {
            return '';
        }

        if (is_array($gender)) {
            $isFemale = in_array('Women', $gender) || in_array('Girls', $gender);
            $isMale = in_array('Men', $gender) || in_array('Boys', $gender);
            if (in_array('Unisex', $gender) || ($isMale && $isFemale)) {
                return 'Unisex';
            } elseif ($isFemale) {
                return 'Female';
            } elseif ($isMale) {
                return 'Male';
            }
        } else {
            if ($gender === 'Men' || $gender === 'Boys') {
                return 'Male';
            } elseif ($gender === 'Women' || $gender === 'Girls') {
                return 'Female';
            } elseif ($gender === 'Unisex') {
                return 'Unisex';
            }
        }

        return $gender;
    }

    /**
     * Get material for product
     *
     * @param Product $product
     * @return array
     */
    private function getMaterial(Product $product)
    {
        $material = isset($this->attrMap[self::ATTR_MATERIAL])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_MATERIAL])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_MATERIAL);
        if (!$material && $material == '') {
            $material = $this->additionalAttributes->getCorrectText($product, self::ATTR_MATERIAL);
        }
        if ($material) {
            return is_array($material) ? implode(', ', $material) : $material;
        }
        return '';
    }

    /**
     * Get pattern for product
     *
     * @param Product $product
     * @return array
     */
    private function getPattern(Product $product)
    {
        $pattern = isset($this->attrMap[self::ATTR_PATTERN])
            ? $this->additionalAttributes->getCorrectText($product, $this->attrMap[self::ATTR_PATTERN])
            : $this->additionalAttributes->getCorrectText($product, self::ATTR_PATTERN);
        if (!$pattern && $pattern == '') {
            $pattern = $this->additionalAttributes->getCorrectText($product, self::ATTR_PATTERN);
        }
        if ($pattern) {
            return is_array($pattern) ? implode(', ', $pattern) : $pattern;
        }
        return '';
    }

    /**
     * Get weight for product
     *
     * @param Product $product
     * @return string
     */
    private function getWeight(Product $product)
    {
        $weight = isset($this->attrMap[self::ATTR_SHIPPING_WEIGHT])
            ? $product->getData($this->attrMap[self::ATTR_SHIPPING_WEIGHT])
            : $product->getWeight();
        if (!$weight && $weight == '') {
            $weight = $product->getWeight();
        }

        if ($weight) {
            $weightUnit = $this->systemConfig->getWeightUnit() === 'lbs' ? 'lb' : 'kg';
            return $weight . ' ' . $weightUnit;
        }
        return '';
    }

    /**
     * Build product entry
     *
     * @param Product $product
     * @return array
     * @throws LocalizedException
     */
    public function buildProductEntry(Product $product)
    {
        $this->attrMap = $this->mappingConfig->getAttributeMapping($product->getStoreId());
        $product->setCustomerGroupId(self::NOT_LOGGED_IN_ID);

        $inventory = $this->getInventory($product);
        $retailerId = $this->trimAttribute(
            self::ATTR_RETAILER_ID,
            $this->productIdentifier->getMagentoProductRetailerId($product)
        );

        $otherId = $this->trimAttribute(
            self::ATTR_OTHER_ID,
            $this->productIdentifier->getProductIDOtherThanRetailerId($product)
        );

        $title = $product->getName();
        $productTitle = $this->trimAttribute(self::ATTR_NAME, $title);

        $images = $this->getProductImages($product);
        $entry = [
            self::ATTR_RETAILER_ID => $this->trimAttribute(self::ATTR_RETAILER_ID, $retailerId),
            self::ATTR_OTHER_ID => $otherId,
            self::ATTR_ITEM_GROUP_ID => $this->getItemGroupId($product),
            self::ATTR_NAME => $productTitle,
            self::ATTR_DESCRIPTION => $this->getDescription($product),
            self::ATTR_RICH_DESCRIPTION => $this->getRichDescription($product),
            self::ATTR_AVAILABILITY => $this->getAvailability($product, $inventory),
            self::ATTR_INVENTORY => $inventory->getInventory(),
            self::ATTR_BRAND => $this->getBrand($product),
            self::ATTR_PRODUCT_CATEGORY => $this->getGoogleProductCategory($product),
            self::ATTR_PRODUCT_TYPE => $this->getProductType($product),
            self::ATTR_CONDITION => $this->getCondition($product),
            self::ATTR_PRICE => $this->builderTools->getProductPrice($product),
            self::ATTR_SALE_PRICE => $this->getSalePrice($product),
            self::ATTR_SALE_PRICE_EFFECTIVE_DATE => $this->builderTools->getProductSalePriceEffectiveDate($product),
            self::ATTR_COLOR => $this->getColor($product),
            self::ATTR_SIZE => $this->getSize($product),
            self::ATTR_URL => $this->getProductUrl($product),
            self::ATTR_IMAGE_URL => $this->getProductImageUrl($product, $images),
            self::ATTR_ADDITIONAL_IMAGE_URL => $this->getAdditionalImages($product, $images),
            self::ATTR_STATUS => $this->getStatus($product),
            self::ATTR_GENDER => $this->getGender($product),
            self::ATTR_AGE_GROUP => $this->getAgeGroup($product),
            self::ATTR_MATERIAL => $this->getMaterial($product),
            self::ATTR_PATTERN => $this->getPattern($product),
            self::ATTR_SHIPPING_WEIGHT => $this->getWeight($product),
        ];

        if ($this->uploadMethod === FeedUploadMethod::UPLOAD_METHOD_FEED_API) {
            $entry[self::ATTR_UNIT_PRICE] = $this->getUnitPrice($product);
        }

        // Features is needed for customizable & unsupported products both
        $features = $this->unsupportedProducts->getFeatures($product);
        $entry[self::ATTR_FEATURES] = count($features) > 0 ? json_encode($features) : null;

        // Add Additional metadata fields if not disabled
        if (!$this->systemConfig->isAdditionalAttributesSyncDisabled()) {
            $entry[self::ATTR_METADATA] = json_encode($this->additionalAttributes->getAdditionalMetadata($product));

            $customAttributes = $this->additionalAttributes->getUserDefinedAttributesList();
            foreach ($customAttributes as $customAttribute) {
                $customAttributeKey = self::CUSTOM_ATTR_PREFIX . $customAttribute;
                if (in_array($customAttributeKey, array_keys($entry))) {
                    continue;
                }
                $entry[$customAttributeKey] =
                    $this->additionalAttributes->getCustomAttributeText($product, $customAttribute);
            }
        }

        // Add Unsupported product data if not disabled
        if (!$this->systemConfig->isUnsupportedProductsDisabled()) {
            $entry[self::ATTR_UNSUPPORTED_PRODUCT_DATA] = json_encode([
                'type_hint' => $product->getTypeId(),
                'customizable_options' => $this->unsupportedProducts->getCustomizableOptions($product),
                'digital_options' => $this->unsupportedProducts->getDigitalOptions($product),
                'bundle_options' => $this->unsupportedProducts->getBundleOptions($product),
            ]);
        }

        return $entry;
    }

    /**
     * Get product sale price
     *
     * @param Product $product
     * @return mixed|null
     */
    private function getSalePrice(Product $product)
    {
        $salePrice = isset($this->attrMap[self::ATTR_SALE_PRICE])
            ? $product->getData($this->attrMap[self::ATTR_SALE_PRICE]) : null;
        if (!$salePrice) {
            $this->builderTools->getProductSalePrice($product);
        }

        return $salePrice;
    }

    /**
     * Get product additional images
     *
     * @param Product $product
     * @param array $images
     * @return mixed|string
     */
    private function getAdditionalImages(Product $product, array $images)
    {
        $aImages = isset($this->attrMap[self::ATTR_ADDITIONAL_IMAGE_URL])
            ? $product->getData($this->attrMap[self::ATTR_ADDITIONAL_IMAGE_URL]) : null;
        if ($aImages && $aImages != '') {
            return $this->trimAttribute(self::ATTR_ADDITIONAL_IMAGE_URL, $aImages);
        }

        if ($this->uploadMethod === FeedUploadMethod::UPLOAD_METHOD_CATALOG_BATCH_API) {
            $additionalImages = $images['additional_images'];
        } else {
            $additionalImages = implode(',', $images['additional_images']);
        }
        return $additionalImages;
    }

    /**
     * Get product image url
     *
     * @param Product $product
     * @param array $images
     * @return string
     */
    private function getProductImageUrl(Product $product, array $images)
    {
        $imageUrl = $images['main_image'];
        $image = isset($this->attrMap[self::ATTR_IMAGE_URL])
            ? $product->getData($this->attrMap[self::ATTR_IMAGE_URL]) : null;

        if ($image && $image != '') {
            $imageUrl = $image;
        }

        return $this->trimAttribute(self::ATTR_IMAGE_URL, $imageUrl);
    }

    /**
     * Get product stock availability
     *
     * @param Product $product
     * @param InventoryInterface $inventory
     * @return string
     */
    private function getAvailability(Product $product, InventoryInterface $inventory)
    {
        $availability = isset($this->attrMap[self::ATTR_AVAILABILITY])
            ? $product->getData($this->attrMap[self::ATTR_AVAILABILITY])
            : $inventory->getAvailability();
        if (!$availability || $availability == '') {
            return $inventory->getAvailability();
        }
        return $availability;
    }

    /**
     * Get google product category
     *
     * @param Product $product
     * @return string
     */
    private function getGoogleProductCategory(Product $product)
    {
        $gProductCategory = isset($this->attrMap[self::ATTR_PRODUCT_CATEGORY])
            ? $product->getData($this->attrMap[self::ATTR_PRODUCT_CATEGORY])
            : ($product->getGoogleProductCategory() ?? '');

        if (!$gProductCategory || $gProductCategory == '') {
            return $product->getGoogleProductCategory() ?? '';
        }
        return $gProductCategory;
    }

    /**
     * Get product type
     *
     * @param Product $product
     * @return string
     * @throws LocalizedException
     */
    private function getProductType(Product $product): string
    {
        $type = isset($this->attrMap[self::ATTR_PRODUCT_TYPE])
            ? $product->getData($this->attrMap[self::ATTR_PRODUCT_TYPE])
            : $this->trimAttribute(self::ATTR_PRODUCT_TYPE, $this->getCategoryPath($product));
        if (!$type || $type == '') {
            return $this->trimAttribute(self::ATTR_PRODUCT_TYPE, $this->getCategoryPath($product));
        }
        return $type;
    }

    /**
     * Get header fields
     *
     * @return array
     */
    public function getHeaderFields()
    {
        $headerFields = [
            self::ATTR_RETAILER_ID,
            self::ATTR_OTHER_ID,
            self::ATTR_ITEM_GROUP_ID,
            self::ATTR_NAME,
            self::ATTR_DESCRIPTION,
            self::ATTR_RICH_DESCRIPTION,
            self::ATTR_AVAILABILITY,
            self::ATTR_INVENTORY,
            self::ATTR_BRAND,
            self::ATTR_PRODUCT_CATEGORY,
            self::ATTR_PRODUCT_TYPE,
            self::ATTR_CONDITION,
            self::ATTR_PRICE,
            self::ATTR_SALE_PRICE,
            self::ATTR_SALE_PRICE_EFFECTIVE_DATE,
            self::ATTR_COLOR,
            self::ATTR_SIZE,
            self::ATTR_URL,
            self::ATTR_IMAGE_URL,
            self::ATTR_ADDITIONAL_IMAGE_URL,
            self::ATTR_STATUS,
            self::ATTR_GENDER,
            self::ATTR_AGE_GROUP,
            self::ATTR_MATERIAL,
            self::ATTR_PATTERN,
            self::ATTR_SHIPPING_WEIGHT,
        ];

        if ($this->uploadMethod === FeedUploadMethod::UPLOAD_METHOD_FEED_API) {
            $headerFields[] = self::ATTR_UNIT_PRICE;
        }

        $headerFields[] = self::ATTR_FEATURES;

        if (!$this->systemConfig->isAdditionalAttributesSyncDisabled()) {
            $headerFields[] = self::ATTR_METADATA;

            $customAttributes = $this->additionalAttributes->getUserDefinedAttributesList();
            foreach ($customAttributes as $customAttribute) {
                $customAttributeKey = self::CUSTOM_ATTR_PREFIX . $customAttribute;
                if (in_array($customAttributeKey, $headerFields)) {
                    continue;
                }
                $headerFields[] = $customAttributeKey;
            }
        }

        if (!$this->systemConfig->isUnsupportedProductsDisabled()) {
            if (!in_array(self::ATTR_UNSUPPORTED_PRODUCT_DATA, $headerFields)) {
                $headerFields[] = self::ATTR_UNSUPPORTED_PRODUCT_DATA;
            }
        }

        return $headerFields;
    }

    /**
     * Get base url media
     *
     * @return string
     */
    public function getBaseUrlMedia()
    {
        return $this->fbeHelper->getStore()->getBaseUrl(self::URL_TYPE_MEDIA);
    }

    /**
     * Get store name
     *
     * @return array|false|int|string|null
     * @throws NoSuchEntityException
     */
    public function getStoreName()
    {
        $frontendName = $this->fbeHelper->getStore()->getFrontendName();
        if ($frontendName !== 'Default') {
            return $frontendName;
        }
        $defaultStoreName = $this->fbeHelper->getStore()->getGroup()->getName();
        $escapeStrings = ['\r', '\n', '&nbsp;', '\t'];
        $defaultStoreName =
            trim(str_replace($escapeStrings, ' ', $defaultStoreName));
        if (!$defaultStoreName) {
            $defaultStoreName = $this->fbeHelper->getStore()->getName();
            $defaultStoreName =
                trim(str_replace($escapeStrings, ' ', $defaultStoreName));
        }
        if ($defaultStoreName && $defaultStoreName !== self::MAIN_WEBSITE_STORE
            && $defaultStoreName !== self::MAIN_STORE
            && $defaultStoreName !== self::MAIN_WEBSITE) {
            return $defaultStoreName;
        }
        return parse_url($this->fbeHelper->getBaseUrl(), PHP_URL_HOST); // phpcs:ignore
    }
}
