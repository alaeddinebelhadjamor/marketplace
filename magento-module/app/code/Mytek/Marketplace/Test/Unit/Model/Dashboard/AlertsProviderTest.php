<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Dashboard;

use Mytek\Marketplace\Model\Catalog\SellerProductStats;
use Mytek\Marketplace\Model\Dashboard\AlertsProvider;
use Mytek\Marketplace\Model\OrderStats;
use Mytek\Marketplace\Model\ReclamationRepository;
use Mytek\Marketplace\Model\SellerRepository;
use PHPUnit\Framework\TestCase;

class AlertsProviderTest extends TestCase
{
    private function provider(
        int $pendingOld = 0,
        int $unseen = 0,
        int $awaiting = 0,
        ?string $lastSync = null
    ): AlertsProvider {
        $sellers = $this->createMock(SellerRepository::class);
        $sellers->method('countPendingOlderThan')->with(48)->willReturn($pendingOld);
        $reclamations = $this->createMock(ReclamationRepository::class);
        $reclamations->method('countUnseen')->willReturn($unseen);
        $orderStats = $this->createMock(OrderStats::class);
        $orderStats->method('getLastSyncAt')->willReturn($lastSync);
        $productStats = $this->createMock(SellerProductStats::class);
        $productStats->method('countAwaitingModeration')->willReturn($awaiting);

        return new AlertsProvider($sellers, $reclamations, $orderStats, $productStats);
    }

    public function testNoAlertWhenEverythingIsQuietExceptRecentSync(): void
    {
        $alerts = $this->provider(lastSync: date('Y-m-d H:i:s'))->getAlerts();
        // Seule l'alerte de synchronisation est toujours présente (informative).
        $this->assertCount(1, $alerts);
        $this->assertSame(AlertsProvider::LEVEL_OK, $alerts[0]['level']);
    }

    public function testPendingSellersAlertAppearsWhenOlderThan48h(): void
    {
        $alerts = $this->provider(pendingOld: 3, lastSync: date('Y-m-d H:i:s'))->getAlerts();
        $this->assertSame(AlertsProvider::LEVEL_WARNING, $alerts[0]['level']);
        $this->assertStringContainsString('3', $alerts[0]['message']);
        $this->assertSame('pending', $alerts[0]['url']);
    }

    public function testUnseenClaimsAlert(): void
    {
        $alerts = $this->provider(unseen: 2, lastSync: date('Y-m-d H:i:s'))->getAlerts();
        $claimAlert = $alerts[0];
        $this->assertSame(AlertsProvider::LEVEL_WARNING, $claimAlert['level']);
        $this->assertSame('reclamations', $claimAlert['url']);
    }

    public function testModerationAlertIsInformational(): void
    {
        $alerts = $this->provider(awaiting: 5, lastSync: date('Y-m-d H:i:s'))->getAlerts();
        $this->assertSame(AlertsProvider::LEVEL_INFO, $alerts[0]['level']);
        $this->assertNull($alerts[0]['url']);
    }

    public function testMissingSyncIsAWarning(): void
    {
        $alerts = $this->provider(lastSync: null)->getAlerts();
        $this->assertSame(AlertsProvider::LEVEL_WARNING, $alerts[0]['level']);
    }

    public function testStaleSyncOlderThan24hIsAWarning(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-30 hours'));
        $alerts = $this->provider(lastSync: $old)->getAlerts();
        $this->assertSame(AlertsProvider::LEVEL_WARNING, $alerts[0]['level']);
    }

    public function testRecentSyncIsOk(): void
    {
        $recent = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $alerts = $this->provider(lastSync: $recent)->getAlerts();
        $this->assertSame(AlertsProvider::LEVEL_OK, $alerts[0]['level']);
    }

    public function testAllAlertsCombinedKeepOrder(): void
    {
        $alerts = $this->provider(pendingOld: 1, unseen: 1, awaiting: 1, lastSync: date('Y-m-d H:i:s'))->getAlerts();
        $this->assertCount(4, $alerts);
        $this->assertSame('pending', $alerts[0]['url']);
        $this->assertSame('reclamations', $alerts[1]['url']);
        $this->assertNull($alerts[2]['url']);
        $this->assertNull($alerts[3]['url']);
    }
}
