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

namespace Meta\Catalog\Cron;

use Exception;
use Meta\Catalog\Model\Product\Feed\Uploader;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use Meta\BusinessExtension\Helper\FBEHelper;

class UploadProductFeed
{
    /**
     * Wall-clock deadline in seconds for the full upload. After this elapses the
     * cron is interrupted via SIGALRM and the per-store catch is bypassed by
     * throwing an \Error subclass, so Magento records status=error in
     * cron_schedule rather than leaving the row stuck at "running" until the
     * container is recycled.
     */
    private const DEADLINE_SECONDS_DEFAULT = 1800;

    private const DEADLINE_ENV_VAR = 'META_FEED_CRON_DEADLINE_SECONDS';

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * @var Uploader
     */
    private $uploader;

    /**
     * @var FBEHelper
     */
    private FBEHelper $fbeHelper;

    /**
     * @param SystemConfig $systemConfig
     * @param Uploader $uploader
     * @param FBEHelper $fbeHelper
     */
    public function __construct(
        SystemConfig $systemConfig,
        Uploader     $uploader,
        FBEHelper    $fbeHelper
    ) {
        $this->systemConfig = $systemConfig;
        $this->uploader = $uploader;
        $this->fbeHelper = $fbeHelper;
    }

    /**
     * Upload for product
     *
     * @param int $storeId
     * @return $this
     * @throws \Throwable
     */
    private function uploadForStore($storeId)
    {
        if ($this->isFeedUploadEnabled($storeId)) {
            $traceId = $this->fbeHelper->genUniqueTraceID();
            $flowName = 'daily_product_feed_upload';
            $this->uploader->uploadFullCatalog($storeId, $flowName, $traceId);
        }

        return $this;
    }

    /**
     * Return configuration state of feed upload
     *
     * @param int $storeId Store ID to check.
     * @return bool
     */
    private function isFeedUploadEnabled($storeId)
    {
        return $this->systemConfig->isCatalogSyncEnabled($storeId);
    }

    /**
     * Execute
     *
     * @return void
     */
    public function execute()
    {
        $deadlineSeconds = $this->getDeadlineSeconds();
        $alarmInstalled = $this->installDeadline($deadlineSeconds);

        try {
            foreach ($this->systemConfig->getAllFBEInstalledStores() as $store) {
                try {
                    $this->uploadForStore($store->getId());
                } catch (Exception $e) {
                    $context = [
                        'store_id' => $store->getId(),
                        'event' => 'catalog_sync',
                        'event_type' => 'upload_product_feed_cron',
                    ];
                    $this->fbeHelper->logExceptionImmediatelyToMeta($e, $context);
                }
            }
        } finally {
            if ($alarmInstalled) {
                pcntl_alarm(0);
            }
        }
    }

    /**
     * Resolve the cron deadline.
     *
     * Reads the META_FEED_CRON_DEADLINE_SECONDS environment variable so operators
     * can tune the cap per environment without modifying code; falls back to the
     * built-in default when unset or invalid.
     *
     * @return int
     */
    private function getDeadlineSeconds(): int
    {
        $env = getenv(self::DEADLINE_ENV_VAR);
        if ($env !== false && ctype_digit((string)$env)) {
            $value = (int)$env;
            if ($value > 0) {
                return $value;
            }
        }
        return self::DEADLINE_SECONDS_DEFAULT;
    }

    /**
     * Install a SIGALRM-based wall-clock deadline.
     *
     * Uses pcntl_async_signals so the signal interrupts blocking I/O (notably
     * curl_exec inside GraphAPIAdapter::callApiForFileTransfer when the upstream
     * edge stalls mid-upload). The handler throws FeedUploadDeadlineExceeded —
     * an \Error subclass that bypasses the per-store `catch (Exception $e)`
     * block above and bubbles to Magento's cron wrapper so the schedule row
     * lands at status=error.
     *
     * Falls back silently when the pcntl extension is unavailable; the
     * CURLOPT_TIMEOUT inside callApiForFileTransfer still applies in that case.
     *
     * @param int $seconds
     * @return bool true if the alarm was installed, false if pcntl is unavailable
     */
    private function installDeadline(int $seconds): bool
    {
        if (!function_exists('pcntl_alarm')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')
        ) {
            return false;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($seconds): void {
            throw new FeedUploadDeadlineExceeded(sprintf(
                'facebook_automation_upload_product_feed exceeded the %d-second '
                . 'deadline and was aborted.',
                $seconds
            ));
        });
        pcntl_alarm($seconds);

        return true;
    }
}

/**
 * Deadline-exceeded marker. Extends \Error (not \Exception) on purpose: the
 * existing per-store `catch (Exception $e)` inside execute() must not swallow
 * this, so it propagates to Magento's cron wrapper which then records
 * status=error on the cron_schedule row.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class FeedUploadDeadlineExceeded extends \Error
{
}
