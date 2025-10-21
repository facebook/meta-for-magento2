<?php
declare(strict_types=1);

namespace Meta\Conversion\Model\Tracker;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Meta\Conversion\Api\TrackerInterface;
use Meta\Conversion\Helper\MagentoDataHelper;
use Magento\Store\Model\StoreManagerInterface;

class AddToCart implements TrackerInterface
{
    private const EVENT_TYPE = "AddToCart";

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var MagentoDataHelper
     */
    private $magentoDataHelper;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param MagentoDataHelper $magentoDataHelper
     * @param ProductRepositoryInterface $productRepository
     * @param Escaper $escaper
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        MagentoDataHelper $magentoDataHelper,
        ProductRepositoryInterface $productRepository,
        Escaper $escaper,
        StoreManagerInterface $storeManager
    ) {
        $this->magentoDataHelper = $magentoDataHelper;
        $this->productRepository = $productRepository;
        $this->escaper = $escaper;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    public function getPayload(array $params): array
    {
        $productId = $params['productId'];
        try {
            $product = $this->productRepository->getById($productId, false, $this->storeManager->getStore()->getId());
            $contentId = $this->magentoDataHelper->getContentId($product);
            return [
                'currency' => $this->magentoDataHelper->getCurrency(),
                'value' => $this->magentoDataHelper->getValueForProduct($product),
                'content_ids' => [$contentId],
                'content_name' => $this->escaper->escapeUrl($product->getName()),
                'contents' => [
                    [
                        'product_id' => $contentId,
                        'quantity' => 1
                    ]
                ],
                'content_type' => $this->magentoDataHelper->getContentType($product)
            ];
        } catch (NoSuchEntityException $e) {
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getEventType(): string
    {
        return self::EVENT_TYPE;
    }
}
