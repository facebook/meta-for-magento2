<?php

declare(strict_types=1);

namespace Meta\BusinessExtension\Test\Unit\Model\System\Message;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Notification\MessageInterface;
use Meta\BusinessExtension\Model\System\Message\UnsupportedMagentoVersionNotification;
use PHPUnit\Framework\TestCase;

class UnsupportedMagentoVersionNotificationTest extends TestCase
{
    /**
     * Test the getIdentity method
     *
     * @return void
     */
    public function testGetIdentity(): void
    {
        $this->assertEquals(
            'meta_unsupported_magento_version',
            $this->createNotification('2.4.4')->getIdentity()
        );
    }

    /**
     * Test the isDisplayed method on Magento versions below 2.4.5
     *
     * @return void
     */
    public function testIsDisplayedBelowMinimumVersion(): void
    {
        foreach (['2.4.3-p3', '2.4.4', '2.4.4-p13'] as $version) {
            $this->assertTrue($this->createNotification($version)->isDisplayed(), $version);
        }
    }

    /**
     * Test the isDisplayed method on Magento 2.4.5 and later
     *
     * @return void
     */
    public function testIsNotDisplayedOnMinimumVersionOrLater(): void
    {
        foreach (['2.4.5', '2.4.5-p1', '2.4.7-p4', '2.4.10'] as $version) {
            $this->assertFalse($this->createNotification($version)->isDisplayed(), $version);
        }
    }

    /**
     * Test the isDisplayed method when the version is not a release version
     *
     * @return void
     */
    public function testIsNotDisplayedWhenVersionIsUnknown(): void
    {
        foreach (['UNKNOWN', 'dev-2.4-develop', ''] as $version) {
            $this->assertFalse($this->createNotification($version)->isDisplayed(), $version);
        }
    }

    /**
     * Test the getText method
     *
     * @return void
     */
    public function testGetText(): void
    {
        $this->assertEquals(
            'Meta extension 1.7.1 is the last version that supports Magento 2.4.4-p13. '
            . 'To keep receiving Meta extension updates, upgrade Magento to 2.4.5 or later.',
            (string)$this->createNotification('2.4.4-p13')->getText()
        );
    }

    /**
     * Test the getSeverity method
     *
     * @return void
     */
    public function testGetSeverity(): void
    {
        $this->assertEquals(
            MessageInterface::SEVERITY_MAJOR,
            $this->createNotification('2.4.4')->getSeverity()
        );
    }

    /**
     * Create the notification for a Magento version
     *
     * @param string $version
     * @return UnsupportedMagentoVersionNotification
     */
    private function createNotification(string $version): UnsupportedMagentoVersionNotification
    {
        $productMetadata = $this->createStub(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn($version);

        return new UnsupportedMagentoVersionNotification($productMetadata);
    }
}
