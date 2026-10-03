<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Mytek\Marketplace\Console\Command\SyncSellerGovernorate;
use Mytek\Marketplace\Model\Governorate\GovernorateSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SyncSellerGovernorateTest extends TestCase
{
    public function testPrintsCheckedAndUpdatedCounts(): void
    {
        $service = $this->createMock(GovernorateSyncService::class);
        $service->method('sync')->willReturn(['checked' => 42, 'updated' => 5]);
        $state = $this->createMock(State::class);
        $state->method('setAreaCode')->willThrowException(
            new \Magento\Framework\Exception\LocalizedException(__('Area code is already set'))
        );

        $tester = new CommandTester(new SyncSellerGovernorate($service, $state));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('42 product(s) checked, 5 updated.', $tester->getDisplay());
    }
}
