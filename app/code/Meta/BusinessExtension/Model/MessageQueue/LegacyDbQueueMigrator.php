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

namespace Meta\BusinessExtension\Model\MessageQueue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\BulkPublisherInterface;
use Magento\Framework\MessageQueue\DefaultValueProvider;
use Magento\Framework\MessageQueue\MessageEncoder;
use Psr\Log\LoggerInterface;

/**
 * Moves Meta messages left in the MySQL queue to the connection the topics use now.
 *
 * Up to 1.6.4 the Meta topics were pinned to the "db" connection. A store with RabbitMQ
 * configured moves them to "amqp" on upgrade, leaving whatever was queued in MySQL
 * without a consumer.
 */
class LegacyDbQueueMigrator
{
    /**
     * Queues the extension used to pin to MySQL, and the topic each one carries
     */
    private const TOPIC_BY_QUEUE = [
        'send_conversion_event_to_meta' => 'send.conversion.event.to.meta',
        'persist_meta_log_immediately' => 'persist.meta.log.immediately',
        'persist_meta_telemetry_logs' => 'persist.meta.telemetry.logs',
    ];

    /**
     * Statuses of Magento\MysqlMq\Model\QueueManagement
     */
    private const STATUS_NEW = 2;

    private const STATUS_COMPLETE = 4;

    private const STATUS_RETRY_REQUIRED = 5;

    private const STATUS_ERROR = 6;

    private const STATUS_TO_BE_DELETED = 7;

    private const DB_CONNECTION = 'db';

    /**
     * Rows read, republished and updated per round trip
     */
    private const BATCH_SIZE = 500;

    /**
     * Messages older than this are dropped rather than sent: Meta rejects stale events.
     */
    private const MAX_AGE_DAYS = 7;

    /**
     * The admin notice only needs to know "many", so counting stops here
     */
    private const COUNT_CAP = 10000;

    /**
     * Name of the lock that keeps two migrations off the same rows
     */
    private const LOCK_NAME = 'meta_legacy_queue_migration';

    /**
     * Where the running totals are kept, so a page reload does not lose them
     */
    private const PROGRESS_FLAG = 'meta_legacy_queue_migration_progress';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var BulkPublisherInterface
     */
    private BulkPublisherInterface $publisher;

    /**
     * @var MessageEncoder
     */
    private MessageEncoder $messageEncoder;

    /**
     * @var DefaultValueProvider
     */
    private DefaultValueProvider $defaultValueProvider;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var LockManagerInterface
     */
    private LockManagerInterface $lockManager;

    /**
     * @var FlagManager
     */
    private FlagManager $flagManager;

    /**
     * @var int|null
     */
    private ?int $pendingCount = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $queueIds = null;

    /**
     * Class constructor
     *
     * @param ResourceConnection $resourceConnection
     * @param BulkPublisherInterface $publisher
     * @param MessageEncoder $messageEncoder
     * @param DefaultValueProvider $defaultValueProvider
     * @param LoggerInterface $logger
     * @param LockManagerInterface $lockManager
     * @param FlagManager $flagManager
     */
    public function __construct(
        ResourceConnection     $resourceConnection,
        BulkPublisherInterface $publisher,
        MessageEncoder         $messageEncoder,
        DefaultValueProvider   $defaultValueProvider,
        LoggerInterface        $logger,
        LockManagerInterface   $lockManager,
        FlagManager            $flagManager
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->publisher = $publisher;
        $this->messageEncoder = $messageEncoder;
        $this->defaultValueProvider = $defaultValueProvider;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
        $this->flagManager = $flagManager;
    }

    /**
     * Whether the Meta topics have moved off the MySQL queue on this store
     *
     * @return bool
     */
    public function isMigrationNeeded(): bool
    {
        return $this->defaultValueProvider->getConnection() !== self::DB_CONNECTION;
    }

    /**
     * Meta messages still awaiting a consumer in the MySQL queue, counted up to the cap
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        if ($this->pendingCount === null) {
            $connection = $this->resourceConnection->getConnection();
            // Counting stops at the cap; getExactPendingCount() when the figure matters.
            $capped = $connection->select()
                ->from(['pending' => $this->pendingSelect(['id'])], 'COUNT(*)');
            $this->pendingCount = (int)$connection->fetchOne($capped);
        }

        return $this->pendingCount;
    }

    /**
     * Whether the pending count stopped at the cap, so the real backlog is larger
     *
     * @return bool
     */
    public function isPendingCountCapped(): bool
    {
        return $this->getPendingCount() >= self::COUNT_CAP;
    }

    /**
     * Exactly how many Meta messages are still awaiting a consumer
     *
     * Costs an index scan over every pending row, so use getPendingCount() on a page that
     * merely happens to render.
     *
     * @return int
     */
    public function getExactPendingCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->pendingSelect(['id'])->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);

        return (int)$connection->fetchOne(
            $connection->select()->from(['pending' => $select], 'COUNT(*)')
        );
    }

    /**
     * Republish pending MySQL-queue messages on the connection the topics use now
     *
     * Messages in flight are left alone, ones past the age cutoff are discarded, and ones
     * that cannot be decoded are marked failed. Stops at whichever budget runs out first.
     *
     * @param int $maxMessages
     * @param float $maxSeconds
     * @return array{moved: int, expired: int, failed: int}
     */
    public function migrate(int $maxMessages, float $maxSeconds): array
    {
        $result = ['moved' => 0, 'expired' => 0, 'failed' => 0];
        if (!$this->isMigrationNeeded()) {
            return $result;
        }

        // One at a time: the batch select takes no row locks, so two runs would read the
        // same rows and publish them twice. A busy lock is not an error.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return $result;
        }

        try {
            $this->drain($result, $maxMessages, $maxSeconds);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
            $this->pendingCount = null;
            $this->recordProgress($result);
        }

        return $result;
    }

    /**
     * Totals moved so far, across every slice and every caller
     *
     * Includes whatever the upgrade itself moved before it ran out of time.
     *
     * @return array{moved: int, expired: int, failed: int}
     */
    public function getProgress(): array
    {
        $stored = $this->flagManager->getFlagData(self::PROGRESS_FLAG);
        if (!is_array($stored)) {
            return ['moved' => 0, 'expired' => 0, 'failed' => 0];
        }

        return [
            'moved' => (int)($stored['moved'] ?? 0),
            'expired' => (int)($stored['expired'] ?? 0),
            'failed' => (int)($stored['failed'] ?? 0),
        ];
    }

    /**
     * Add a slice's totals to the stored ones, and forget them once the queue is clear
     *
     * @param array $result
     * @return void
     */
    private function recordProgress(array $result): void
    {
        if (array_sum($result) === 0) {
            return;
        }

        if ($this->getPendingCount() === 0) {
            // Nothing left, so a backlog appearing later starts counting from zero.
            $this->flagManager->deleteFlag(self::PROGRESS_FLAG);
            return;
        }

        $progress = $this->getProgress();
        $this->flagManager->saveFlag(self::PROGRESS_FLAG, [
            'moved' => $progress['moved'] + $result['moved'],
            'expired' => $progress['expired'] + $result['expired'],
            'failed' => $progress['failed'] + $result['failed'],
        ]);
    }

    /**
     * Whether a migration is running right now, here or in another process
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->lockManager->isLocked(self::LOCK_NAME);
    }

    /**
     * Move pending messages until one of the budgets runs out
     *
     * @param array $result
     * @param int $maxMessages
     * @param float $maxSeconds
     * @return void
     */
    private function drain(array &$result, int $maxMessages, float $maxSeconds): void
    {
        $connection = $this->resourceConnection->getConnection();
        $statusTable = $this->resourceConnection->getTableName('queue_message_status');
        $deadline = microtime(true) + $maxSeconds;
        $cutoff = $this->cutoffDate();

        while (array_sum($result) < $maxMessages && microtime(true) < $deadline) {
            $limit = min(self::BATCH_SIZE, $maxMessages - array_sum($result));
            $select = $this->pendingSelect(['relation_id' => 'id', 'updated_at', 'queue_id'])
                ->join(
                    ['message' => $this->resourceConnection->getTableName('queue_message')],
                    'message.id = status.message_id',
                    ['topic_name', 'body']
                )
                ->limit($limit);

            $rows = $connection->fetchAll($select);
            if (!$rows) {
                break;
            }

            $expiredIds = [];
            $failedIds = [];
            $payloadsByTopic = [];
            $movedIds = [];
            $queueNames = array_flip($this->queueIds());
            foreach ($rows as $row) {
                $queueName = $queueNames[(int)$row['queue_id']] ?? '';
                $topic = self::TOPIC_BY_QUEUE[$queueName] ?? (string)$row['topic_name'];
                if ($row['updated_at'] < $cutoff) {
                    $expiredIds[] = (int)$row['relation_id'];
                    continue;
                }
                try {
                    // Decode first: publishing encodes again for the target connection.
                    $payloadsByTopic[$topic][] = $this->messageEncoder->decode($topic, (string)$row['body']);
                    $movedIds[] = (int)$row['relation_id'];
                } catch (\Throwable $e) {
                    // Mark it failed: an untouched row stays in the selection and every
                    // later pass would read it again and get no further.
                    $failedIds[] = (int)$row['relation_id'];
                    $this->logger->error(
                        sprintf(
                            'Meta: could not read queued message %s of topic %s: %s',
                            $row['relation_id'],
                            $topic,
                            $e->getMessage()
                        )
                    );
                }
            }

            if ($expiredIds) {
                $connection->update(
                    $statusTable,
                    ['status' => self::STATUS_TO_BE_DELETED],
                    ['id IN (?)' => $expiredIds]
                );
                $result['expired'] += count($expiredIds);
            }

            if ($failedIds) {
                // STATUS_ERROR rather than STATUS_TO_BE_DELETED: both leave the selection,
                // but "error" keeps the row for the failed-message lifetime.
                $connection->update(
                    $statusTable,
                    ['status' => self::STATUS_ERROR],
                    ['id IN (?)' => $failedIds]
                );
                $result['failed'] += count($failedIds);
            }

            if ($payloadsByTopic) {
                try {
                    foreach ($payloadsByTopic as $topic => $payloads) {
                        $this->publisher->publish($topic, $payloads);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        sprintf(
                            'Meta: could not move queued messages to the "%s" connection: %s',
                            $this->defaultValueProvider->getConnection(),
                            $e->getMessage()
                        )
                    );
                    break;
                }
                $connection->update($statusTable, ['status' => self::STATUS_COMPLETE], ['id IN (?)' => $movedIds]);
                $result['moved'] += count($movedIds);
            }

            // Backstop: every row is accounted for above, but a batch that changed
            // nothing would otherwise be re-read until the budget ran out.
            if (!$movedIds && !$expiredIds && !$failedIds) {
                break;
            }

            if (count($rows) < $limit) {
                break;
            }
        }
    }

    /**
     * Ids of the queues the extension used to pin to MySQL, keyed by queue name
     *
     * Kept out of the batch select: joining the queue table makes the optimizer walk
     * (queue_id, message_id) and filter status afterwards, so every batch steps over the
     * rows already migrated. Filtering on queue_id uses (status, updated_at) instead.
     *
     * @return array<string, int>
     */
    private function queueIds(): array
    {
        if ($this->queueIds === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('queue'), ['name', 'id'])
                ->where('name IN (?)', array_keys(self::TOPIC_BY_QUEUE));
            $this->queueIds = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->queueIds;
    }

    /**
     * Select over the Meta messages that still await a consumer, capped for cheapness
     *
     * @param array $columns
     * @return \Magento\Framework\DB\Select
     */
    private function pendingSelect(array $columns): \Magento\Framework\DB\Select
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['status' => $this->resourceConnection->getTableName('queue_message_status')], $columns);

        $queueIds = $this->queueIds();
        if (!$queueIds) {
            // No Meta queue has ever been used on this store, so there is nothing to find.
            return $select->where('1 = 0');
        }

        // No ORDER BY on purpose: a filesort on every batch would cost more than it buys.
        return $select
            ->where('status.queue_id IN (?)', array_values($queueIds))
            ->where('status.status IN (?)', [self::STATUS_NEW, self::STATUS_RETRY_REQUIRED])
            ->limit(self::COUNT_CAP);
    }

    /**
     * Oldest moment a queued message may carry and still be worth sending
     *
     * @return string
     */
    private function cutoffDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(sprintf('-%d days', self::MAX_AGE_DAYS)) ?: time());
    }
}
