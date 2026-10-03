<?php

namespace App\Services\Sync;

use App\Enums\SellerStatus;
use App\Models\Order;
use App\Models\OrderMagentoDate;
use App\Models\Seller;
use App\Services\Magento\MagentoClient;
use App\Services\Magento\MagentoProductService;
use App\Services\Reclamations\ReclamationService;
use App\Support\Metrics\MetricsRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Synchronisation des commandes Magento « complete » du jour (chapitre 4).
 *
 *  1. commandes Magento state=complete mises à jour dans la journée (toutes pages) ;
 *  2. vendeur de chaque ligne via seller_id (ligne, sinon fiche produit) ;
 *  3. insertion idempotente sur (order_id, sku) ;
 *  4. une notification groupée par vendeur ayant de nouvelles ventes.
 *
 * Ajout v2 : la date de création de la commande Magento est conservée dans
 * order_magento_dates, et une erreur Magento fait échouer le job (nouvelle
 * tentative automatique) au lieu d'être ignorée silencieusement.
 */
class OrderSyncService
{
    private const PAGE_SIZE = 100;

    /** @var array<string, int|null> cache SKU -> seller_id pour une exécution */
    private array $skuSellers = [];

    public function __construct(
        private readonly MagentoClient $magento,
        private readonly MagentoProductService $products,
        private readonly ReclamationService $reclamations,
        private readonly MetricsRegistry $metrics,
    ) {}

    /**
     * @return array{orders: int, inserted: int, notified: int}
     */
    public function syncDay(?Carbon $day = null): array
    {
        $day ??= now('UTC');
        $this->skuSellers = [];

        $sellers = Seller::query()->where('status', SellerStatus::Validated->value)->pluck('seller_id')->map(fn ($id) => (int) $id)->flip();
        if ($sellers->isEmpty()) {
            return ['orders' => 0, 'inserted' => 0, 'notified' => 0];
        }

        $orders = $this->completedOrders($day);
        $newBySeller = [];
        $inserted = 0;

        foreach ($orders as $order) {
            $orderId = (string) ($order['increment_id'] ?? '');
            if ($orderId === '') {
                continue;
            }

            foreach ($order['items'] ?? [] as $item) {
                // Les lignes enfants d'un produit composé doubleraient la vente.
                if (! empty($item['parent_item_id']) || ! empty($item['parent_item'])) {
                    continue;
                }

                $sku = (string) ($item['sku'] ?? '');
                $sellerId = $this->sellerOfItem($item, $sku);
                if ($sku === '' || $sellerId === null || ! $sellers->has($sellerId)) {
                    continue;
                }

                $created = Order::query()->insertOrIgnore([
                    'order_id' => $orderId,
                    'sku' => $sku,
                    'product_name' => (string) ($item['name'] ?? $sku),
                    'qty' => (int) ($item['qty_ordered'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'vendor_id' => $sellerId,
                    'processed_at' => now()->format('Y-m-d H:i:s'),
                ]);

                if ($created > 0) {
                    $inserted++;
                    $newBySeller[$sellerId][] = $item;
                    $this->rememberOrderDate($orderId, $order['created_at'] ?? null);
                }
            }
        }

        foreach ($newBySeller as $sellerId => $items) {
            $count = count($items);
            $plural = $count > 1 ? 's' : '';
            $this->reclamations->notify($sellerId, "Félicitations ! Vous avez enregistré {$count} nouvelle{$plural} vente{$plural} aujourd'hui");
        }

        $this->metrics->increment('marketplace_orders_synced_total', [], $inserted);
        $this->metrics->increment('marketplace_sync_runs_total', ['task' => 'orders', 'result' => 'success']);
        $this->metrics->flush();
        Cache::forever('marketplace:last-orders-sync', now()->timestamp);

        Log::info('Synchronisation des commandes terminée', ['orders' => count($orders), 'inserted' => $inserted]);

        return ['orders' => count($orders), 'inserted' => $inserted, 'notified' => count($newBySeller)];
    }

    /** Commandes complete mises à jour dans la journée, toutes pages. */
    private function completedOrders(Carbon $day): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->magento->get('orders', [
                'searchCriteria[filter_groups][0][filters][0][field]' => 'state',
                'searchCriteria[filter_groups][0][filters][0][value]' => 'complete',
                'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'eq',
                'searchCriteria[filter_groups][1][filters][0][field]' => 'updated_at',
                'searchCriteria[filter_groups][1][filters][0][value]' => $day->format('Y-m-d').' 00:00:00',
                'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'gteq',
                'searchCriteria[filter_groups][2][filters][0][field]' => 'updated_at',
                'searchCriteria[filter_groups][2][filters][0][value]' => $day->format('Y-m-d').' 23:59:59',
                'searchCriteria[filter_groups][2][filters][0][condition_type]' => 'lteq',
                'searchCriteria[pageSize]' => self::PAGE_SIZE,
                'searchCriteria[currentPage]' => $page,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Lecture des commandes Magento impossible (HTTP '.$response->status().').');
            }

            $items = $response->json('items', []) ?? [];
            $all = array_merge($all, $items);
            $pages = (int) ceil(((int) $response->json('total_count', 0)) / self::PAGE_SIZE);
            $page++;
        } while ($items !== [] && $page <= $pages);

        return $all;
    }

    private function sellerOfItem(array $item, string $sku): ?int
    {
        $direct = MagentoProductService::sellerIdOf($item);
        if ($direct !== null) {
            return $direct;
        }
        if ($sku === '') {
            return null;
        }
        if (! array_key_exists($sku, $this->skuSellers)) {
            $product = $this->products->find($sku);
            $this->skuSellers[$sku] = $product ? MagentoProductService::sellerIdOf($product) : null;
        }

        return $this->skuSellers[$sku];
    }

    private function rememberOrderDate(string $orderId, ?string $createdAt): void
    {
        if (! $createdAt) {
            return;
        }
        OrderMagentoDate::query()->firstOrCreate(
            ['order_id' => $orderId],
            ['ordered_at' => Carbon::parse($createdAt, 'UTC')]
        );
    }
}
