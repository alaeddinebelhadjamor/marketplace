<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Dashboard;

use Mytek\Marketplace\Model\Catalog\SellerProductStats;
use Mytek\Marketplace\Model\OrderStats;
use Mytek\Marketplace\Model\ReclamationRepository;
use Mytek\Marketplace\Model\SellerRepository;

/**
 * Alertes du tableau de bord (fig. 3.12, écart 5 de l'audit) : vendeurs en attente depuis plus
 * de 48 h, réclamations non lues, produits de vendeurs en attente de validation, dernière
 * synchronisation des commandes.
 */
class AlertsProvider
{
    public const LEVEL_OK = 'ok';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';

    private const PENDING_SELLER_ALERT_HOURS = 48;
    private const STALE_SYNC_HOURS = 24;

    public function __construct(
        private readonly SellerRepository $sellers,
        private readonly ReclamationRepository $reclamations,
        private readonly OrderStats $orderStats,
        private readonly SellerProductStats $productStats
    ) {
    }

    /** @return array<int, array{level:string, message:string, url:?string}> */
    public function getAlerts(): array
    {
        $alerts = [];

        $pending = $this->sellers->countPendingOlderThan(self::PENDING_SELLER_ALERT_HOURS);
        if ($pending > 0) {
            $alerts[] = [
                'level'   => self::LEVEL_WARNING,
                'message' => (string)__('%1 seller registration(s) pending for more than 48h.', $pending),
                'url'     => 'pending',
            ];
        }

        $unseen = $this->reclamations->countUnseen();
        if ($unseen > 0) {
            $alerts[] = [
                'level'   => self::LEVEL_WARNING,
                'message' => (string)__('%1 unread claim(s).', $unseen),
                'url'     => 'reclamations',
            ];
        }

        $awaiting = $this->productStats->countAwaitingModeration();
        if ($awaiting > 0) {
            $alerts[] = [
                'level'   => self::LEVEL_INFO,
                'message' => (string)__('%1 seller product(s) awaiting moderation.', $awaiting),
                'url'     => null,
            ];
        }

        $alerts[] = $this->syncAlert();

        return $alerts;
    }

    private function syncAlert(): array
    {
        $lastSync = $this->orderStats->getLastSyncAt();
        if ($lastSync === null) {
            return [
                'level'   => self::LEVEL_WARNING,
                'message' => (string)__('No order synchronisation recorded yet.'),
                'url'     => null,
            ];
        }

        $hoursAgo = (time() - strtotime($lastSync)) / 3600;
        $formatted = date('d/m/Y H:i', strtotime($lastSync));
        return [
            'level'   => $hoursAgo > self::STALE_SYNC_HOURS ? self::LEVEL_WARNING : self::LEVEL_OK,
            'message' => (string)__('Last order synchronisation: %1.', $formatted),
            'url'     => null,
        ];
    }
}
