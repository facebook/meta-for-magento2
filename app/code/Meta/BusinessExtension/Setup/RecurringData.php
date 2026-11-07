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

namespace Meta\BusinessExtension\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Meta\BusinessExtension\Model\System\Message\UnsupportedMagentoVersionNotification;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Repeats the unsupported Magento version notice in the setup:upgrade output
 */
class RecurringData implements InstallDataInterface
{
    /**
     * @var UnsupportedMagentoVersionNotification
     */
    private $unsupportedMagentoVersionNotification;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @param UnsupportedMagentoVersionNotification $unsupportedMagentoVersionNotification
     * @param OutputInterface|null $output
     */
    public function __construct(
        UnsupportedMagentoVersionNotification $unsupportedMagentoVersionNotification,
        ?OutputInterface $output = null
    ) {
        $this->unsupportedMagentoVersionNotification = $unsupportedMagentoVersionNotification;
        $this->output = $output ?? new ConsoleOutput();
    }

    /**
     * @inheritDoc
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (!$this->unsupportedMagentoVersionNotification->isDisplayed()) {
            return;
        }
        // The installer has just written "Running data recurring..." without a line break,
        // and ends the open line itself before its next message.
        $this->output->write(
            PHP_EOL . '<comment>' . $this->unsupportedMagentoVersionNotification->getText() . '</comment>'
        );
    }
}
