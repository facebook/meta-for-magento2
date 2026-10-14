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

namespace Meta\BusinessExtension\Helper;

use CURLFile;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\View\FileFactory;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GraphAPIAdapter
{
    public const ORDER_FILTER_REFUNDS = 'refunds';
    public const ORDER_FILTER_CANCELLATIONS = 'cancellations';
    private const GET_ORDERS_LIMIT = 25;

    /**
     * Wall-clock cap for the multipart product-feed upload curl call. Without an
     * explicit CURLOPT_TIMEOUT the request can hang indefinitely on a stalled
     * read from the upstream edge, leaving the cron in `status=running` forever.
     */
    private const FEED_UPLOAD_TIMEOUT_DEFAULT = 1800;

    private const FEED_UPLOAD_CONNECT_TIMEOUT = 30;

    private const FEED_UPLOAD_TIMEOUT_ENV_VAR = 'META_FEED_CRON_DEADLINE_SECONDS';

    /**
     * @var mixed
     */
    private $accessToken;

    /**
     * @var mixed
     */
    private $clientAccessToken;

    /**
     * @var string
     */
    private $graphAPIVersion = 'v22.0';

    /**
     * @var Client
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $debugMode = false;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var FileFactory
     */
    private $fileFactory;

    /**
     * @var GraphAPIConfig
     */
    private $graphAPIConfig;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * GraphAPIAdapter constructor.
     *
     * @param SystemConfig         $systemConfig
     * @param LoggerInterface      $logger
     * @param CurlFactory          $curlFactory
     * @param FileFactory          $fileFactory
     * @param GraphAPIConfig       $graphAPIConfig
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        SystemConfig         $systemConfig,
        LoggerInterface      $logger,
        CurlFactory          $curlFactory,
        FileFactory          $fileFactory,
        GraphAPIConfig       $graphAPIConfig,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->systemConfig = $systemConfig;
        $this->logger = $logger;
        $this->accessToken = $systemConfig->getAccessToken();
        $this->clientAccessToken = $systemConfig->getClientAccessToken();
        $this->client = new Client(
            [
                'base_uri' => "{$graphAPIConfig->getGraphBaseURL()}{$this->getGraphApiVersion()}/",
                'timeout' => 60,
            ]
        );
        $this->debugMode = $systemConfig->isDebugMode();
        $this->curlFactory = $curlFactory;
        $this->fileFactory = $fileFactory;
        $this->graphAPIConfig = $graphAPIConfig;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Set access token
     *
     * @param  null|string $accessToken
     * @return $this
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * Set debug mode
     *
     * @param  bool $debugMode
     * @return $this
     */
    public function setDebugMode($debugMode)
    {
        $this->debugMode = $debugMode;
        return $this;
    }

    /**
     * Get uniq Id
     *
     * @return string
     */
    private function getUniqId()
    {
        return uniqid();
    }

    /**
     * Call api
     *
     * @param  string $method
     * @param  string $endpoint
     * @param  array  $request
     * @return ResponseInterface
     * @throws GuzzleException
     * @todo   implement custom logger class
     */
    private function callApi($method, $endpoint, $request)
    {
        try {
            $logRequest = $request;
            if ($this->debugMode) {
                if (isset($logRequest['access_token'])) {
                    unset($logRequest['access_token']);
                }
                $this->logger->debug(
                    json_encode(
                        [
                            'endpoint' => "/{$method} {$endpoint}",
                            'request' => $logRequest,
                        ],
                        JSON_PRETTY_PRINT
                    )
                );
            }
            // TODO: replace with admin user local
            $request['locale'] = 'en_US';

            // post request form_params should be used as it adds data in body rather than keeping it in URL
            $option = $method === 'POST' ? 'form_params' : 'query';
            $response = $this->client->request($method, $endpoint, [$option => $request]);
            if ($this->debugMode) {
                $logResponse = (string)$response->getBody();
                $logResponse = preg_replace(
                    '/access_token=([a-z0-9A-Z]+)(?=[a-zA-Z0-9]{4,})/',
                    'access_token=XXXXXXX',
                    $logResponse ?? ''
                );
                $logResponse = preg_replace(
                    '/access_token\":\"([a-z0-9A-Z]+)(?=[a-zA-Z0-9]{4,})/',
                    'access_token":"XXXXXXX',
                    $logResponse ?? ''
                );

                $this->logger->debug(
                    json_encode(
                        [
                            'response' => [
                                'status_code' => $response->getStatusCode(),
                                'reason_phrase' => $response->getReasonPhrase(),
                                'headers' => json_encode(
                                    array_map(
                                        function ($a) {
                                            return $a[0];
                                        },
                                        $response->getHeaders()
                                    )
                                ),
                                'body' => $logResponse,
                            ]
                        ],
                        JSON_PRETTY_PRINT
                    )
                );
            }
            return $response;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->logger->debug($e->getMessage());
            if (stripos($e->getMessage(), 'truncated') !== false) {
                $this->logger->debug('Full error: ' . (string)$response->getBody());
            }
            throw $e;
        }
    }

    /**
     * Call api via CURL to transfer file
     *
     * @param  string $endpoint
     * @param  array  $params
     * @param  string $filePath
     * @return mixed
     */
    private function callApiForFileTransfer($endpoint, $params, $filePath)
    {
        $endpoint = "{$this->graphAPIConfig->getGraphBaseURL()}{$this->getGraphApiVersion()}/" . $endpoint;
        $result = null;
        try {
            $curl = $this->curlFactory->create();
            $fileBaseName = $this->fileFactory->create(['filename' => $filePath, 'module' => ''])->getName();

            $file = new CURLFile($filePath, mime_content_type($filePath), $fileBaseName);
            $params = array_merge($params, ['file' => $file, 'access_token' => $this->accessToken]);
            $curl->setOptions(
                [ // This will override the $params to the post function
                    CURLOPT_POSTFIELDS => $params,
                    // Bound the multipart upload so a stalled upstream edge
                    // can't hang the cron indefinitely. Tunable per environment
                    // via META_FEED_CRON_DEADLINE_SECONDS so operators can adjust
                    // without code changes.
                    CURLOPT_TIMEOUT => $this->getFeedUploadTimeout(),
                    CURLOPT_CONNECTTIMEOUT => self::FEED_UPLOAD_CONNECT_TIMEOUT,
                ]
            );
            $curl->post($endpoint, ['access_token' => '']); // Gets overridden, but still needs 1 param
            $result = $curl->getBody();
        } catch (\Exception $e) {
            if ($this->debugMode) {
                $this->logger->debug(
                    json_encode(
                        [
                            'endpoint' => "POST {$endpoint}",
                            'file' => $filePath,
                            'error' => $e->getMessage(),
                        ],
                        JSON_PRETTY_PRINT
                    )
                );
            }
            // Previously the message was swallowed into $result and json_decode
            // returned null, so a failed upload looked like a success to the
            // cron and the schedule row reported status=success. Rethrow so
            // Magento's cron wrapper records status=error.
            throw $e;
        }

        if ($this->debugMode) {
            $this->logger->debug(
                json_encode(
                    [
                        'endpoint' => "POST {$endpoint}",
                        'file' => $filePath,
                        'response' => $result
                    ],
                    JSON_PRETTY_PRINT
                )
            );
        }

        return json_decode($result);
    }

    /**
     * Resolve the upload curl timeout.
     *
     * Reads the META_FEED_CRON_DEADLINE_SECONDS environment variable so operators
     * can tune the cap per environment without modifying code; falls back to the
     * built-in default when unset or invalid.
     *
     * @return int seconds
     */
    private function getFeedUploadTimeout(): int
    {
        $env = getenv(self::FEED_UPLOAD_TIMEOUT_ENV_VAR);
        if ($env !== false && ctype_digit((string)$env)) {
            $value = (int)$env;
            if ($value > 0) {
                return $value;
            }
        }
        return self::FEED_UPLOAD_TIMEOUT_DEFAULT;
    }

    /**
     * Get page token from user token
     *
     * @param  null|string $userToken
     * @return false|string
     * @throws GuzzleException
     */
    public function getPageTokenFromUserToken($userToken)
    {
        $request = [
            'access_token' => $userToken
        ];
        $response = $this->callApi('GET', 'me/accounts', $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['data'][0]['access_token'] ?? false;
    }

    /**
     * Get page Id from user token
     *
     * @param  null|string $userToken
     * @return false|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getPageIdFromUserToken($userToken)
    {
        $request = [
            'access_token' => $userToken
        ];
        $response = $this->callApi('GET', 'me/accounts', $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['data'][0]['id'] ?? false;
    }

    /**
     * Get page access token
     *
     * @param  null|string $accessToken
     * @param  null|string $pageId
     * @return false|string
     * @throws GuzzleException
     */
    public function getPageAccessToken($accessToken, $pageId)
    {
        $request = [
            'access_token' => $accessToken,
            'fields' => 'access_token'
        ];
        $response = $this->callApi('GET', $pageId, $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['access_token'] ?? false;
    }

    /**
     * Get page merchant settings Id
     *
     * @param  null|string $accessToken
     * @param  null|string $pageId
     * @return false|string
     * @throws GuzzleException
     */
    public function getPageMerchantSettingsId($accessToken = null, $pageId = null)
    {
        $request = [
            'access_token' => $accessToken ?: $this->accessToken,
            'fields' => 'commerce_merchant_settings',
        ];
        $response = $this->callApi('GET', $pageId ?? 'me', $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['commerce_merchant_settings']['data'][0]['id'] ?? false;
    }

    /**
     * Get a URL to use to render the CommerceExtension IFrame for an onboarded Store.
     *
     * @param  string     $externalBusinessId
     * @param  mixed|null $accessToken
     * @return string
     * @throws GuzzleException
     */
    public function getCommerceExtensionIFrameURL($externalBusinessId, $accessToken = null)
    {
        $request = [
            'access_token' => $accessToken ?: $this->accessToken,
            'fields' => 'commerce_extension',
            'fbe_external_business_id' => $externalBusinessId,
        ];
        $response = $this->callApi('GET', 'fbe_business', $request);
        $response = json_decode($response->getBody()->__toString(), true);
        $baseURLOverride = $this->scopeConfig->getValue(
            'facebook/internal/extension_base_url',
            ScopeInterface::SCOPE_STORE
        );
        $uri = $response['commerce_extension']['uri'];
        if ($baseURLOverride) {
            $uri = str_replace('https://www.commercepartnerhub.com/', $baseURLOverride, $uri);
        }
        return $uri;
    }

    /**
     * Get commerce account data
     *
     * @param  mixed      $commerceAccountId
     * @param  mixed|null $accessToken
     * @return array
     * @throws GuzzleException
     * @todo   check store setup status
     */
    public function getCommerceAccountData($commerceAccountId, $accessToken = null)
    {
        $request = [
            'access_token' => $accessToken ?: $this->accessToken,
            'fields' => 'merchant_page,product_catalogs',
        ];
        $response = $this->callApi('GET', "{$commerceAccountId}", $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return [
            'page_id' => $response['merchant_page']['id'],
            'catalog_id' => $response['product_catalogs']['data'][0]['id']
        ];
    }

    /**
     * Associate merchant settings with app
     *
     * @param  mixed|null $commerceAccountId
     * @param  mixed|null $accessToken
     * @return array|mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function associateMerchantSettingsWithApp($commerceAccountId, $accessToken = null)
    {
        $request = [
            'access_token' => $accessToken ?: $this->accessToken,
        ];
        $response = $this->callApi('POST', "{$commerceAccountId}/order_management_apps", $request);
        // @todo check for success:true upstream
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * Get catalog feeds
     *
     * @param  mixed $catalogId
     * @return mixed
     * @throws GuzzleException
     */
    public function getCatalogFeeds($catalogId)
    {
        $requestFields = [
            'id',
            'file_name',
            'name'
        ];

        $response = $this->callApi(
            'GET',
            "{$catalogId}/product_feeds",
            [
                'access_token' => $this->accessToken,
                'fields' => implode(',', $requestFields),
            ]
        );
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['data'];
    }

    /**
     * Get feed
     *
     * @param  string $feedId
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function getFeed($feedId)
    {
        $response = $this->callApi(
            'GET',
            "{$feedId}",
            [
                'access_token' => $this->accessToken,
            ]
        );
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * Create empty feed
     *
     * @param  mixed  $catalogId
     * @param  string $name
     * @param  bool   $isPromotion
     * @return mixed
     * @throws GuzzleException
     */
    public function createEmptyFeed($catalogId, $name, $isPromotion = false)
    {
        $request = [
            'access_token' => $this->accessToken,
            'name' => $name,
        ];
        if ($isPromotion) {
            $request['feed_type'] = 'OFFER';
        }
        $response = $this->callApi('POST', "{$catalogId}/product_feeds", $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response['id'];
    }

    /**
     * Push product feed
     *
     * @param  string $feedId
     * @param  string $feed
     * @return mixed
     */
    public function pushProductFeed($feedId, $feed)
    {
        return $this->pushFeed($feedId, $feed);
    }

    /**
     * Push feed
     *
     * @param  string $feedId
     * @param  string $feed
     * @return mixed
     */
    public function pushFeed($feedId, $feed)
    {
        $endpoint = "{$feedId}/uploads";
        return $this->callApiForFileTransfer($endpoint, [], $feed);
    }

    /**
     * Upload file
     *
     * @param  mixed  $commercePartnerIntegrationId
     * @param  string $filePath
     * @param  string $feedType
     * @param  string $updateType
     * @return mixed
     */
    public function uploadFile($commercePartnerIntegrationId, $filePath, $feedType, $updateType)
    {
        $endpoint = "{$commercePartnerIntegrationId}/file_update";
        $params = ['feed_type' => $feedType, 'update_type' => $updateType, 'update_time' => strtotime('now')];
        return $this->callApiForFileTransfer($endpoint, $params, $filePath);
    }

    /**
     * Catalog batch request
     *
     * @param  mixed $catalogId
     * @param  array $requests
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function catalogBatchRequest($catalogId, $requests)
    {
        $response = $this->callApi(
            'POST',
            "{$catalogId}/items_batch",
            [
                'access_token' => $this->accessToken,
                'requests' => json_encode($requests),
                'item_type' => 'PRODUCT_ITEM'
            ]
        );
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * GraphAPI batch request
     *
     * @param  array $requests
     * @return mixed|ResponseInterface
     * @throws GuzzleException
     */
    public function graphAPIBatchRequest(array $requests)
    {
        $response = $this->callApi(
            'POST',
            '',
            [
            'access_token' => $this->accessToken,
            'batch' => json_encode($requests)
            ]
        );
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get the order details given an order id
     *
     * @param  string $orderId
     * @return mixed
     * @throws GuzzleException
     */
    public function getOrderDetails(string $orderId)
    {
        $requestFields = [
            'order_status',
            'estimated_payment_details'
        ];
        $request = [
            'access_token' => $this->accessToken,
            'fields' => implode(',', $requestFields),
        ];
        $response = $this->callApi('GET', "/{$orderId}", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get orders
     *
     * @param  mixed        $ordersRootId Commerce Account ID or Page ID
     * @param  false|string $cursorAfter
     * @param  string       $filterType
     * @return array
     * @throws GuzzleException
     */
    public function getOrders($ordersRootId, $cursorAfter = false, $filterType = "")
    {
        $requestFields = [
            'id',
            'buyer_details{name, email, email_remarketing_option, phone_number}',
            'channel',
            'created',
            'estimated_payment_details',
            'ship_by_date',
            'order_status',
            'selected_shipping_option{name, reference_id, price, calculated_tax, estimated_shipping_time}',
            'shipping_address{first_name, last_name, street1, street2, city, postal_code, state, country}',
            'payments',
            'promotion_details{applied_amount, coupon_code, target_granularity, sponsor, campaign_name}',
            'last_updated',
        ];
        $request = [
            'access_token' => $this->accessToken,
            'fields' => implode(',', $requestFields),
            'limit' => self::GET_ORDERS_LIMIT,
        ];
        if ($filterType === self::ORDER_FILTER_REFUNDS) {
            $request['state'] = 'CREATED,IN_PROGRESS,COMPLETED';
            $request['filters'] = 'has_refunds'; // Filter for orders with refunds
        } elseif ($filterType === self::ORDER_FILTER_CANCELLATIONS) {
            $request['state'] = 'CREATED,IN_PROGRESS,COMPLETED';
            $request['filters'] = 'has_cancellations'; // Filter for orders with refunds
        }

        // Only consider orders updated 180 days or less ago
        // This is for the return case.
        $date180DaysAgo = time() - (180 * 24 * 60 * 60);
        $request['updated_after'] = $date180DaysAgo;

        if ($cursorAfter) {
            $request['after'] = $cursorAfter;
        }
        $response = $this->callApi('GET', "{$ordersRootId}/commerce_orders", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get refunds for a specific order. Returns an array of refund_order_item
     *
     * @param  string $orderId
     * @return array
     * @throws GuzzleException
     */
    public function getRefunds($orderId)
    {
        $requestFields = [
            'id',
            'items{product_id,retailer_id,refund_subtotal,quantity}',
            'refund_reason',
            'refund_amount{subtotal,shipping,tax,total,amount,currency}',
        ];
        $request = [
            'access_token' => $this->accessToken,
            'fields' => implode(',', $requestFields),
        ];
        $response = $this->callApi('GET', "{$orderId}/refunds", $request);
        return json_decode($response->getBody()->__toString(), true)['data'];
    }

    /**
     * Get cancellations for a specific order. Returns an array of cancel_order_item
     *
     * @param  string $orderId
     * @return array
     * @throws GuzzleException
     */
    public function getCancellations($orderId)
    {
        // Construct the request
        $request = [
            'access_token' => $this->accessToken,
        ];
        // Call the API
        $response = $this->callApi('GET', "{$orderId}/cancellations", $request);
        // Decode and return the data
        return json_decode($response->getBody()->__toString(), true)['data'];
    }

    /**
     * Get order items
     *
     * @param  mixed $fbOrderId
     * @return array|mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function getOrderItems($fbOrderId)
    {
        $requestFields = [
            'retailer_id',
            'quantity',
            'price_per_unit',
            'tax_details',
            'product_id',
            'promotion_details'
        ];
        $request = [
            'access_token' => $this->accessToken,
            'fields' => implode(',', $requestFields),
        ];
        $response = $this->callApi('GET', "{$fbOrderId}/items", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Acknowledge orders
     *
     * @param  mixed $ordersRootId Commerce Account ID or Page ID
     * @param  array $orderIds
     * @return mixed
     * @throws GuzzleException
     */
    public function acknowledgeOrders($ordersRootId, array $orderIds)
    {
        $request = [];
        foreach ($orderIds as $magentoOrderId => $fbOrderId) {
            $request[] = ['id' => $fbOrderId, 'merchant_order_reference' => $magentoOrderId];
        }
        $response = $this->callApi(
            'POST',
            "{$ordersRootId}/acknowledge_orders",
            [
                'access_token' => $this->accessToken,
                'idempotency_key' => $this->getUniqId(),
                'orders' => json_encode($request),
            ]
        );
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Mark order items as shipped
     *
     * @param  mixed  $fbOrderId
     * @param  string $magentoShipmentId
     * @param  array  $items
     * @param  array  $trackingInfo
     * @param  array  $fulfillmentAddressData
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function markOrderItemsAsShipped(
        $fbOrderId,
        $magentoShipmentId,
        $items,
        $trackingInfo,
        $fulfillmentAddressData
    ) {
        $request = [
            'access_token' => $this->accessToken,
            'external_shipment_id' => $magentoShipmentId,
            'idempotency_key' => $this->getUniqId(),
            'items' => json_encode($items),
            'tracking_info' => json_encode($trackingInfo),
        ];
        if ($fulfillmentAddressData) {
            $request['should_use_default_fulfillment_location'] = false;
            $request['fulfillment']['fulfillment_address'] = $fulfillmentAddressData;
        } else {
            $request['should_use_default_fulfillment_location'] = true;
        }
        $response = $this->callApi('POST', "{$fbOrderId}/shipments", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Update tracking info for a shipment
     *
     * @param  mixed  $fbOrderId
     * @param  string $magentoShipmentId
     * @param  array  $trackingInfo
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function updateShipmentTracking($fbOrderId, $magentoShipmentId, $trackingInfo)
    {
        $request = [
            'access_token' => $this->accessToken,
            'idempotency_key' => $this->getUniqId(),
            'external_shipment_id' => $magentoShipmentId,
            'tracking_info' => json_encode($trackingInfo),
        ];

        $response = $this->callApi('POST', "{$fbOrderId}/update_shipment", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Cancel order
     *
     * @param  mixed      $fbOrderId
     * @param  array|null $items
     * @param  bool       $isOutOfStockCancellation
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function cancelOrder($fbOrderId, $items = null, $isOutOfStockCancellation = false)
    {
        // Magento doesn't support admin providing reason code or description for order cancellation
        $cancelReason = [
            'reason_code' => $isOutOfStockCancellation ? 'OUT_OF_STOCK' : 'CUSTOMER_REQUESTED',
            'reason_description' => 'Canceled from Magento',
        ];
        $response = $this->callApi(
            'POST',
            "{$fbOrderId}/cancellations",
            [
                'access_token' => $this->accessToken,
                'idempotency_key' => $this->getUniqId(),
                'cancel_reason' => $cancelReason,
                'restock_items' => true,
                'items' => json_encode($items),
            ]
        );
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * Refund order
     *
     * @param  mixed       $fbOrderId
     * @param  array       $items
     * @param  float|null  $shippingRefundAmount
     * @param  float|null  $deductionAmount
     * @param  float|null  $adjustmentAmount
     * @param  string      $currency             Order's currency code. Examples: "USD", "GBP"
     * @param  null|string $reasonText
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleException
     */
    public function refundOrder(
        $fbOrderId,
        $items,
        $shippingRefundAmount,
        $deductionAmount,
        $adjustmentAmount,
        $currency,
        $reasonText = null
    ) {
        $request = [
            'access_token' => $this->accessToken,
            'idempotency_key' => $this->getUniqId(),
            'reason_code' => 'REFUND_REASON_OTHER',
            'reason_text' => $reasonText,
            'items' => json_encode($items),
            'shipping' => json_encode(
                [
                    'shipping_refund' => [
                        'amount' => $shippingRefundAmount,
                        'currency' => $currency
                    ]
                ]
            ),
        ];
        if ($reasonText) {
            $request['reason_text'] = $reasonText;
        }
        if ($deductionAmount > 0) {
            $request['deductions'] = json_encode(
                [
                [
                    'deduction_type' => 'RETURN_SHIPPING',
                    'deduction_amount' => [
                        'amount' => $deductionAmount,
                        'currency' => $currency
                    ]
                ]
                ]
            );
        }
        if ($adjustmentAmount > 0) {
            $request['adjustment_amount'] = [
                'amount' => $adjustmentAmount,
                'currency' => $currency
            ];
        }

        $response = $this->callApi('POST', "{$fbOrderId}/refunds", $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * Get product info
     *
     * @param  mixed $fbProductId
     * @return array|mixed|object
     * @throws GuzzleException
     */
    public function getProductInfo($fbProductId)
    {
        $requestFields = [
            'price',
            'sale_price'
        ];

        $request = [
            'access_token' => $this->accessToken,
            'fields' => implode(',', $requestFields),
        ];
        $response = $this->callApi('GET', "{$fbProductId}", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get product by retailer ID
     *
     * @param  mixed           $catalogId
     * @param  bool|int|string $retailerId
     * @return array|mixed|object
     * @throws GuzzleException
     */
    public function getProductByRetailerId($catalogId, $retailerId)
    {
        $request = [
            'access_token' => $this->accessToken,
            'filter' => '{"retailer_id":{"eq":"' . $retailerId . '"}}',
        ];
        $response = $this->callApi('GET', "{$catalogId}/products", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get products by Facebook product Ids
     *
     * @param  mixed $catalogId
     * @param  array $fbProductIds
     * @return array|mixed|object
     * @throws GuzzleException
     */
    public function getProductsByFacebookProductIds($catalogId, array $fbProductIds)
    {
        $request = [
            'access_token' => $this->accessToken,
            'filter' => '{"product_item_id":{"is_any":' . json_encode($fbProductIds) . '}}',
        ];
        $response = $this->callApi('GET', "{$catalogId}/products", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get product errors
     *
     * @param  mixed $fbProductId
     * @return array|mixed|object
     * @throws GuzzleException
     */
    public function getProductErrors($fbProductId)
    {
        $request = [
            'access_token' => $this->accessToken,
            'fields' => 'errors'
        ];
        $response = $this->callApi('GET', "{$fbProductId}", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get catalog diagnostics
     *
     * @param  mixed $catalogId
     * @return mixed
     * @throws GuzzleException
     */
    public function getCatalogDiagnostics($catalogId)
    {
        $request = [
            'access_token' => $this->accessToken,
            'fields' => 'diagnostics'
        ];
        $response = $this->callApi('GET', "{$catalogId}", $request);
        return json_decode($response->getBody()->__toString(), true);
    }

    /**
     * Get FBE Installs
     *
     * @param  string $accessToken
     * @param  string $externalBusinessId
     * @return mixed
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getFBEInstalls($accessToken, $externalBusinessId)
    {
        $request = [
            'fbe_external_business_id' => $externalBusinessId,
            'access_token' => $accessToken,
        ];
        $response = $this->callApi('GET', "/fbe_business/fbe_installs", $request);
        return json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Get FBE Installs
     *
     * @param  string $accessToken
     * @param  string $externalBusinessId
     * @return mixed
     * @throws GuzzleException
     * @throws JsonException
     */
    public function deleteFBEInstalls($accessToken, $externalBusinessId)
    {
        $request = [
            'fbe_external_business_id' => $externalBusinessId,
            'access_token' => $accessToken,
        ];
        $response = $this->callApi('DELETE', "/fbe_business/fbe_installs", $request);
        return json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Persist log to Meta
     *
     * @param  mixed[]     $context
     * @param  null|string $accessToken
     * @return mixed
     * @throws GuzzleException
     */
    public function persistLogToMeta($context, $accessToken = null)
    {
        $request = [
            'access_token' => $accessToken ?? $this->clientAccessToken,
            'event' => $this->getContextData($context, 'event'),
            'event_type' => $this->getContextData($context, 'event_type'),
            'commerce_merchant_settings_id' => $this->getContextData($context, 'commerce_merchant_settings_id'),
            'exception_message' => $this->getContextData($context, 'exception_message'),
            'exception_trace' => $this->getContextData($context, 'exception_trace'),
            'exception_code' => $this->getContextData($context, 'exception_code'),
            'exception_class' => $this->getContextData($context, 'exception_class'),
            'catalog_id' => $this->getContextData($context, 'catalog_id'),
            'order_id' => $this->getContextData($context, 'order_id'),
            'promotion_id' => $this->getContextData($context, 'promotion_id'),
            'external_business_id' => $this->getContextData($context, 'external_business_id'),
            'commerce_partner_integration_id' => $this->getContextData($context, 'commerce_partner_integration_id'),
            'page_id' => $this->getContextData($context, 'page_id'),
            'pixel_id' => $this->getContextData($context, 'pixel_id'),
            'flow_name' => $this->getContextData($context, 'flow_name'),
            'flow_step' => $this->getContextData($context, 'flow_step'),
            'incoming_params' => $this->getContextData($context, 'incoming_params'),
            'seller_platform_app_version' => $this->getContextData($context, 'seller_platform_app_version'),
            'extra_data' => $this->getContextData($context, 'extra_data', []),
        ];

        $response = $this->callApi('POST', "commerce_seller_logs", $request);
        $response = json_decode($response->getBody()->__toString(), true);
        return $response;
    }

    /**
     * Gets a value from the context array, or a default if the key is not set
     *
     * @param  array  $context
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    private function getContextData(array $context, string $key, $default = null)
    {
        return $context[$key] ?? $default;
    }

    /**
     * Get Graph api version
     *
     * @return string
     */
    public function getGraphApiVersion(): string
    {
        $latestGraphApiVersion = $this->systemConfig->getGraphAPIVersion();
        return $latestGraphApiVersion ?? $this->graphAPIVersion;
    }

    /**
     * Call Meta's Repair Commerce Partner Integration endpoint
     *
     * @param  string $externalBusinessId
     * @param  string $shopDomain
     * @param  string $customToken
     * @param  string $accessToken
     * @param  string $seller_platform_type
     * @param  string $extensionVersion
     * @throws GuzzleException
     */
    public function repairCommercePartnerIntegration(
        $externalBusinessId,
        $shopDomain,
        $customToken,
        $accessToken,
        $seller_platform_type,
        $extensionVersion
    ) {
        $request = [
            'access_token' => $accessToken,
            'fbe_external_business_id' => $externalBusinessId,
            'custom_token' => $customToken,
            'shop_domain' => $shopDomain,
            'commerce_partner_seller_platform_type' => $seller_platform_type,
            'extension_version' => $extensionVersion
        ];
        $response = $this->callApi('POST', "commerce_partner_integrations_repair", $request);
        return json_decode($response->getBody()->__toString(), true);
    }
}
