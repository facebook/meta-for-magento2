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

namespace Meta\BusinessExtension\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Meta\BusinessExtension\Helper\FBEHelper;
use Meta\BusinessExtension\Model\MessageQueue\LegacyDbQueueMigrator;

/**
 * Moves one slice of the leftover MySQL queue, for the button on the Meta admin page.
 *
 * One request moves one slice, not the whole queue: the browser calls back until nothing
 * is pending, so no single request meets a PHP or web server timeout. Every slice commits
 * as it goes, so navigating away leaves a consistent remainder to resume from.
 */
class MigrateLegacyQueue extends AbstractAjax
{
    /**
     * Messages this request may move
     */
    private const SLICE_MESSAGES = 50000;

    /**
     * Seconds this request may spend moving them, well inside a default read timeout
     */
    private const SLICE_SECONDS = 10.0;

    /**
     * @var LegacyDbQueueMigrator
     */
    private LegacyDbQueueMigrator $migrator;

    /**
     * Class constructor
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param FBEHelper $fbeHelper
     * @param LegacyDbQueueMigrator $migrator
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        FBEHelper $fbeHelper,
        LegacyDbQueueMigrator $migrator
    ) {
        parent::__construct($context, $resultJsonFactory, $fbeHelper);
        $this->migrator = $migrator;
    }

    /**
     * @inheritDoc
     */
    public function executeForJson()
    {
        if (!$this->migrator->isMigrationNeeded()) {
            return [
                'success' => true,
                'done' => true,
                'moved' => 0,
                'expired' => 0,
                'failed' => 0,
                'remaining' => 0,
                'message' => __('This store runs its message queues on MySQL, so nothing needs moving.'),
            ];
        }

        if ($this->migrator->isRunning()) {
            return [
                'success' => false,
                'done' => true,
                'message' => __('A migration is already running. Wait for it to finish, then reload this page.'),
            ];
        }

        $result = $this->migrator->migrate(self::SLICE_MESSAGES, self::SLICE_SECONDS);
        // The exact count, not the capped one: the merchant is watching a progress line
        $remaining = $this->migrator->getExactPendingCount();

        return [
            'success' => true,
            // Nothing moved with rows still pending means this slice could not act on
            // them: stop asking rather than looping on the same rows.
            'done' => $remaining === 0 || array_sum($result) === 0,
            'stalled' => $remaining > 0 && array_sum($result) === 0,
            'moved' => $result['moved'],
            'expired' => $result['expired'],
            'failed' => $result['failed'],
            'remaining' => $remaining,
        ];
    }
}
