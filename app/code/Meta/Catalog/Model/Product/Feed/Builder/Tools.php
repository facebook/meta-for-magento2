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

namespace Meta\Catalog\Model\Product\Feed\Builder;

use Exception;
use Magento\Catalog\Model\Product;
use Magento\Framework\Currency;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Escaper;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Store\Model\StoreManagerInterface;

class Tools
{
    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * @var CatalogHelper
     */
    private $catalogHelper;

    /**
     * @var ModuleManager
     */
    private ModuleManager $moduleManager;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Tools constructor
     *
     * @param PriceCurrencyInterface $priceCurrency
     * @param ObjectManagerInterface $objectManager
     * @param Escaper $escaper
     * @param SystemConfig $systemConfig
     * @param CatalogHelper $catalogHelper
     * @param ModuleManager $moduleManager
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        PriceCurrencyInterface $priceCurrency,
        ObjectManagerInterface $objectManager,
        Escaper                $escaper,
        SystemConfig           $systemConfig,
        CatalogHelper          $catalogHelper,
        ModuleManager          $moduleManager,
        StoreManagerInterface  $storeManager
    ) {
        $this->priceCurrency = $priceCurrency;
        $this->objectManager = $objectManager;
        $this->escaper = $escaper;
        $this->systemConfig = $systemConfig;
        $this->catalogHelper = $catalogHelper;
        $this->moduleManager = $moduleManager;
        $this->storeManager = $storeManager;
    }

    /**
     * Change casing of string
     *
     * @param string $string
     * @return false|string|string[]|null
     */
    public function lowercaseIfAllCaps($string)
    {
        // if contains lowercase, don't update string
        if (!preg_match('/[a-z]/', $string)) {
            if (mb_strtoupper($string, 'utf-8') === $string) {
                return mb_strtolower($string, 'utf-8');
            }
        }
        return $string;
    }

    /**
     * Returns html decoded string
     *
     * @param string $value
     * @return string
     */
    public function htmlDecode($value)
    {
        return strip_tags($this->escaper->escapeHtml($value));
    }

    /**
     * Return formatted price with currency code. Examples: "9.99 USD", "27.02 GBP"
     *
     * @param float $price
     * @param int $storeId
     * @return string
     */
    public function formatPrice($price, $storeId = null)
    {
        $baseCurrency = $this->storeManager->getStore()->getBaseCurrency();
        $currencySymbol = $baseCurrency->getCurrencySymbol();
        $amount = $this->priceCurrency->convert($price, $storeId, $baseCurrency);
        try {
            $price = sprintf(
                '%s %s',
                $baseCurrency->formatTxt($amount, ['display' => Currency::NO_SYMBOL]),
                $baseCurrency->getCode()
            );
            // workaround for 2.4.3
            $price = ltrim($price, $currencySymbol ?? '');
            return $price;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Returns product unit price
     *
     * @param Product $product
     * @return string
     */
    public function getUnitPrice(Product $product)
    {
        $value = $product->getPerUnitPriceValue();
        $unit = $product->getPerUnitPriceUnit();

        if (!($value && $unit)) {
            return '';
        }

        return $this->formatPrice($value, $product->getStoreId());
    }

    /**
     * Facebook product feed validator will throw an error
     * if a local URL like https://localhost/product.html was provided
     * so replacing with a dummy URL to allow for local testing
     *
     * @param string $url
     * @return string
     */
    public function replaceLocalUrlWithDummyUrl($url)
    {
        return str_replace('localhost', 'magento.com', $url);
    }

    /**
     * Get product price
     *
     * @param Product $product
     * @return string
     */
    public function getProductPrice(Product $product)
    {
        $price = $this->systemConfig->isPriceInclTax()
            ? $this->catalogHelper->getTaxPrice($product, $product->getPrice(), true)
            : $product->getPrice();
        return $this->formatPrice($price, $product->getStoreId());
    }

    /**
     * Get product sale price
     *
     * @param Product $product
     * @return string
     */
    public function getProductSalePrice(Product $product)
    {
        $finalPrice = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        if ($finalPrice > 0 && $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue() > $finalPrice) {
            $price = $this->systemConfig->isPriceInclTax()
                ?$this->catalogHelper->getTaxPrice($product, $finalPrice, true)
                : $finalPrice;
            return $this->formatPrice($price, $product->getStoreId());
        }
        return '';
    }

    /**
     * Get product sale price effective date
     *
     * @param Product $product
     * @return string
     * @throws Exception
     */
    public function getProductSalePriceEffectiveDate(Product $product)
    {
        $specialFromDate = $product->getSpecialFromDate();
        $specialToDate = $product->getSpecialToDate();

        $salePriceStartDate = '';
        if ($specialFromDate) {
            $salePriceStartDate = (new \DateTime($specialFromDate))->format('c');
        }
        $salePriceEndDate = '';
        if ($specialToDate) {
            $salePriceEndDate = (new \DateTime($specialToDate))->format('c');
        }
        if ($product->getSpecialPrice() && $salePriceStartDate) {
            if ($salePriceEndDate) {
                return sprintf("%s/%s", $salePriceStartDate, $salePriceEndDate);
            }
            return $salePriceStartDate;
        }
        return '';
    }

    /**
     * Get inventory object
     *
     * @return InventoryInterface
     */
    public function getInventoryObject(): InventoryInterface
    {
        // Fallback to Magento_CatalogInventory in case Magento MSI modules are disabled
        //phpcs:disable Magento2.PHP.LiteralNamespaces
        return $this->moduleManager->isEnabled('Magento_InventorySalesApi')
            ? $this->objectManager->get('Meta\Catalog\Model\Product\Feed\Builder\MultiSourceInventory')
            : $this->objectManager->get('Meta\Catalog\Model\Product\Feed\Builder\Inventory');
        //phpcs:enable Magento2.PHP.LiteralNamespaces
    }
}
