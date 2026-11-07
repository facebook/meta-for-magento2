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

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Notification\MessageInterface;

class UnsupportedMagentoVersionNotification implements MessageInterface
{
    /**
     * Lowest Magento version supported by the extension releases after 1.7.1
     */
    private const MINIMUM_NEXT_VERSION = '2.4.5';

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(ProductMetadataInterface $productMetadata)
    {
        $this->productMetadata = $productMetadata;
    }

    /**
     * @inheritDoc
     */
    public function getIdentity(): string
    {
        return 'meta_unsupported_magento_version';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed(): bool
    {
        $version = (string)$this->productMetadata->getVersion();
        // Git-based installs can report "UNKNOWN" or a branch name; only warn on a real release version.
        return (bool)preg_match('/^\d+\.\d+\.\d+/', $version)
            && version_compare($version, self::MINIMUM_NEXT_VERSION, '<');
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        return __(
            'Meta extension 1.7.1 is the last version that supports Magento %1. '
            . 'To keep receiving Meta extension updates, upgrade Magento to %2 or later.',
            $this->productMetadata->getVersion(),
            self::MINIMUM_NEXT_VERSION
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
