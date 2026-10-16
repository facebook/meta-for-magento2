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

namespace Meta\BusinessExtension\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Meta\BusinessExtension\Model\MessageQueue\LegacyDbQueueMigrator;

/**
 * Tells the merchant when Meta messages are stuck in the MySQL queue.
 *
 * Shown when the store's queues have moved to RabbitMQ while messages from an older
 * version are still waiting in MySQL, where nothing reads them.
 */
class LegacyQueueBacklogNotification implements MessageInterface
{
    /**
     * Section the merchant is sent to, where the button lives
     */
    private const CONFIG_SECTION = 'facebook_business_extension';

    /**
     * @var LegacyDbQueueMigrator
     */
    private LegacyDbQueueMigrator $migrator;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * Class constructor
     *
     * @param LegacyDbQueueMigrator $migrator
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        LegacyDbQueueMigrator $migrator,
        UrlInterface $urlBuilder
    ) {
        $this->migrator = $migrator;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @inheritDoc
     */
    public function getIdentity(): string
    {
        // Includes the count, so a backlog that grows shows up again after being dismissed.
        return 'meta_legacy_queue_backlog_' . $this->migrator->getPendingCount();
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed(): bool
    {
        return $this->migrator->isMigrationNeeded() && $this->migrator->getPendingCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        $count = $this->migrator->isPendingCountCapped()
            ? $this->migrator->getPendingCount() . '+'
            : (string)$this->migrator->getPendingCount();

        return __(
            'Facebook and Instagram: %1 messages published by an earlier version of the extension are still '
            . 'waiting in the MySQL message queue, which this store no longer reads. They include Conversions '
            . 'API events. Nothing moves them on its own: open <a href="%2">Setup</a> and choose '
            . '"Move them now".',
            $count,
            $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => self::CONFIG_SECTION])
        );
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
