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

namespace Meta\BusinessExtension\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Meta\BusinessExtension\Model\MessageQueue\LegacyDbQueueMigrator;

/**
 * Offers the merchant a way to move messages the upgrade could not.
 *
 * Lives in Stores > Configuration
 *
 * The row hides itself when there is nothing to move, so on a healthy store -- where the
 * upgrade cleared the backlog inside its own time budget -- the merchant never sees it.
 */
class LegacyQueueBacklog extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Meta_BusinessExtension::system/config/legacy_queue_backlog.phtml';

    /**
     * @var LegacyDbQueueMigrator
     */
    private LegacyDbQueueMigrator $migrator;

    /**
     * Class constructor
     *
     * @param Context $context
     * @param LegacyDbQueueMigrator $migrator
     * @param array $data
     */
    public function __construct(
        Context $context,
        LegacyDbQueueMigrator $migrator,
        array $data = []
    ) {
        $this->migrator = $migrator;
        parent::__construct($context, $data);
    }

    /**
     * Render the row only when there is a backlog, and without the scope controls
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        if (!$this->hasBacklog()) {
            return '';
        }

        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Render the template in place of the input this field would otherwise get
     *
     * Without this the base class returns $element->getElementHtml() and the row shows an
     * empty text box: the template is never reached.
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Whether any messages are stranded in the MySQL queue
     *
     * @return bool
     */
    public function hasBacklog(): bool
    {
        return $this->migrator->isMigrationNeeded() && $this->migrator->getPendingCount() > 0;
    }

    /**
     * Stranded messages, as a figure to show the merchant
     *
     * @return string
     */
    public function getBacklogLabel(): string
    {
        return (string)$this->migrator->getExactPendingCount();
    }

    /**
     * What earlier slices already moved, so a reload resumes rather than restarts
     *
     * @return array{moved: int, expired: int, failed: int}
     */
    public function getProgress(): array
    {
        return $this->migrator->getProgress();
    }

    /**
     * Whether a migration is part way through
     *
     * @return bool
     */
    public function hasProgress(): bool
    {
        return array_sum($this->getProgress()) > 0;
    }

    /**
     * Whether a slice is running right now, here or in another tab
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->migrator->isRunning();
    }

    /**
     * Route the button posts to, one slice per call
     *
     * @return string
     */
    public function getMigrateAjaxRoute(): string
    {
        return $this->getUrl('fbeadmin/ajax/migrateLegacyQueue');
    }

    /**
     * Element id to render the button against, so two of these never collide
     *
     * @return string
     */
    public function getHtmlId(): string
    {
        return 'meta_legacy_queue';
    }
}
