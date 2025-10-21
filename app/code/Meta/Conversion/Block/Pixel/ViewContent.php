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

namespace Meta\Conversion\Block\Pixel;

use Exception;
use Magento\Catalog\Block\Product\View;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Meta\BusinessExtension\Helper\FBEHelper;
use Meta\Conversion\Helper\MagentoDataHelper;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Escaper;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * @api
 */
class ViewContent extends Common
{
    /**
     * @var FBEHelper
     */
    private FBEHelper $fbeHelper;

    /**
     * @var MagentoDataHelper
     */
    private MagentoDataHelper $magentoDataHelper;

    /**
     * @var CatalogHelper
     */
    private CatalogHelper $catalogHelper;

    /**
     * ViewContent constructor
     *
     * @param Context $context
     * @param FBEHelper $fbeHelper
     * @param MagentoDataHelper $magentoDataHelper
     * @param SystemConfig $systemConfig
     * @param Escaper $escaper
     * @param CheckoutSession $checkoutSession
     * @param CatalogHelper $catalogHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        FBEHelper $fbeHelper,
        MagentoDataHelper $magentoDataHelper,
        SystemConfig $systemConfig,
        Escaper $escaper,
        CheckoutSession $checkoutSession,
        CatalogHelper $catalogHelper,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $fbeHelper,
            $magentoDataHelper,
            $systemConfig,
            $escaper,
            $checkoutSession,
            $data
        );
        $this->fbeHelper = $fbeHelper;
        $this->magentoDataHelper = $magentoDataHelper;
        $this->catalogHelper = $catalogHelper;
    }

    /**
     * Returns content data including content_ids
     * @return array[]
     */
    public function getContentData(): array
    {
        $contents = [];
        $contentIds = [];
        foreach ($this->getProducts() as $product) {
            $contentId = $this->getContentId($product);
            $contents[] = ['id' => $contentId, 'quantity' => 1];
            $contentIds[] = $contentId;
        }

        return ['contents' => $contents, 'content_ids' => $contentIds];
    }

    /**
     * Return array of products
     * If current product is configurable or grouped, array would contain child products as well
     *
     * @return array
     */
    private function getProducts(): array
    {
        $products = [];
        $product = $this->getCurrentProduct();
        if ($product && $product->getId()) {
            $products[] = $product;
        }

        if ($product->getTypeId() == Grouped::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getAssociatedProducts($product) as $childProduct) {
                /** @var Product $childProduct */
                $products[] = $childProduct;
            }
        }

        if ($product->getTypeId() == Configurable::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $childProduct) {
                /** @var Product $childProduct */
                $products[] = $childProduct;
            }
        }

        return $products;
    }

    /**
     * Returns content name
     *
     * @return string|null
     */
    public function getContentName()
    {
        $product = $this->getCurrentProduct();
        if ($product && $product->getId()) {
            return $this->escapeQuotes($product->getName());
        } else {
            return null;
        }
    }

    /**
     * Returns content type
     *
     * @return string|null
     */
    public function getContentType()
    {
        /** @var Product $product */
        $product = $this->getCurrentProduct();
        if (!$product) {
            return null;
        }

        return $this->magentoDataHelper->getContentType($product);
    }

    /**
     * Returns content's category
     *
     * @return string|null
     */
    public function getContentCategory()
    {
        $product = $this->getCurrentProduct();
        if (!$product) {
            return null;
        }

        $categoryCollection = $product->getCategoryCollection()
            ->addAttributeToSelect('name');

        if (!$categoryCollection->count()) {
            return null;
        }

        $categoryNames = [];

        foreach ($categoryCollection as $category) {
            $categoryNames[] = $category->getName();
        }

        return $this->escapeQuotes(implode(',', $categoryNames));
    }

    /**
     * Return currency
     *
     * @return string|null
     */
    public function getValue()
    {
        $product = $this->getCurrentProduct();
        if ($product && $product->getId()) {
            $price = $product->getFinalPrice();
            $priceHelper = $this->fbeHelper->getObject(\Magento\Framework\Pricing\Helper\Data::class);
            return $priceHelper->currency($price, false, false);
        } else {
            return null;
        }
    }

    /**
     * Returns event name
     *
     * @return string
     */
    public function getEventToObserveName()
    {
        return 'facebook_businessextension_ssapi_view_content';
    }

    /**
     * Returns Product id
     *
     * @return mixed
     */
    public function getProductId()
    {
        $product = $this->getCurrentProduct();
        return $product ? $product->getId() : null;
    }

    /**
     * Returns current product
     *
     * @return Product|null
     */
    public function getCurrentProduct()
    {
        try {
            /** @var View $block */
            $block = $this->getLayout()->getBlock('product.info');
            return $block ? $block->getProduct() : $this->catalogHelper->getProduct();
        } catch (Exception $e) {
            $this->fbeHelper->logException($e);
            return null;
        }
    }
}
