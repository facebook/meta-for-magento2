<?php

declare(strict_types=1);

namespace Meta\BusinessExtension\Test\Unit\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Meta\BusinessExtension\Model\System\Message\UnsupportedMagentoVersionNotification;
use Meta\BusinessExtension\Setup\RecurringData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class RecurringDataTest extends TestCase
{
    /**
     * Test the install method prints the notice on an unsupported Magento version
     *
     * @return void
     */
    public function testInstallPrintsNoticeWhenDisplayed(): void
    {
        $output = new BufferedOutput();
        $this->createRecurringData(true, $output)->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        $this->assertEquals(PHP_EOL . 'Unsupported version notice', $output->fetch());
    }

    /**
     * Test the install method prints nothing on a supported Magento version
     *
     * @return void
     */
    public function testInstallPrintsNothingWhenNotDisplayed(): void
    {
        $output = new BufferedOutput();
        $this->createRecurringData(false, $output)->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        $this->assertEquals('', $output->fetch());
    }

    /**
     * Create the recurring data script with a stubbed notification
     *
     * @param bool $isDisplayed
     * @param BufferedOutput $output
     * @return RecurringData
     */
    private function createRecurringData(bool $isDisplayed, BufferedOutput $output): RecurringData
    {
        $notification = $this->createStub(UnsupportedMagentoVersionNotification::class);
        $notification->method('isDisplayed')->willReturn($isDisplayed);
        $notification->method('getText')->willReturn('Unsupported version notice');

        return new RecurringData($notification, $output);
    }
}
