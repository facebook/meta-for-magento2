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

namespace Meta\BusinessExtension\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Meta\BusinessExtension\Model\MessageQueue\LegacyDbQueueMigrator;
use Psr\Log\LoggerInterface;

/**
 * Carries Meta messages over when the topics stop using the MySQL queue.
 *
 * The Meta topics used to be pinned to the "db" connection. On a store with RabbitMQ
 * configured they follow the store's connection from this version on, so anything still
 * queued in MySQL at upgrade time would otherwise sit there unread.
 *
 * Deliberately NonTransactionable: a broker publish cannot be rolled back, so a
 * transaction would only hold the status updates back while the messages went out and
 * re-send everything on failure. Committing each batch also avoids the undo-log cost.
 */
class MigrateLegacyQueueMessages implements DataPatchInterface, NonTransactionableInterface
{
    /**
     * No message ceiling: the time budget is the only bound. Republishing runs at roughly
     * 15,000 a second, so a count cap only deferred work the upgrade could have finished.
     */
    private const MAX_MESSAGES = PHP_INT_MAX;

    /**
     * Seconds the upgrade may spend moving them, so a pathological backlog cannot hold
     * maintenance mode open: whatever is left stays pending for the admin button.
     */
    private const MAX_SECONDS = 120.0;

    /**
     * @var LegacyDbQueueMigrator
     */
    private LegacyDbQueueMigrator $migrator;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Class constructor
     *
     * @param LegacyDbQueueMigrator $migrator
     * @param LoggerInterface $logger
     */
    public function __construct(
        LegacyDbQueueMigrator $migrator,
        LoggerInterface       $logger
    ) {
        $this->migrator = $migrator;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        if (!$this->migrator->isMigrationNeeded()) {
            // The store still runs its queues on MySQL, so the messages stay where they are.
            return $this;
        }

        $pending = $this->migrator->getPendingCount();
        if ($pending === 0) {
            return $this;
        }

        // Say "or more" rather than report the cap as if it were the backlog.
        $startedWith = $this->migrator->isPendingCountCapped() ? $pending . ' or more' : (string)$pending;

        // Everything the time budget allows, in practice the whole backlog. The admin
        // button covers what is left, and a store that gains a broker after this runs.
        $result = $this->migrator->migrate(self::MAX_MESSAGES, self::MAX_SECONDS);
        $this->logger->info(
            sprintf(
                'Meta: moved %d, discarded %d and failed %d of %s queued messages from the MySQL queue',
                $result['moved'],
                $result['expired'],
                $result['failed'],
                $startedWith
            )
        );

        $remaining = $this->migrator->getPendingCount();
        if ($remaining > 0) {
            $this->logger->info(
                sprintf(
                    'Meta: %d queued messages are still pending. Nothing moves them on its own: use '
                    . '"Move them now" under Stores > Configuration > Facebook & Instagram > Setup.',
                    $remaining
                )
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
