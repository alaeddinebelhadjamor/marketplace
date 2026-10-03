<?php

namespace App\Services\Commission;

use App\Models\CommissionRate;
use App\Models\Order;
use App\Models\PayoutStatement;
use App\Models\Seller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Calcul des commissions et des relevés mensuels de paiement. */
class CommissionService
{
    public function defaultRate(): float
    {
        return (float) config('marketplace.commission_rate');
    }

    public function rateFor(int $sellerId): float
    {
        return CommissionRate::find($sellerId)?->rate ?? $this->defaultRate();
    }

    /**
     * Taux effectif de chaque vendeur demandé.
     *
     * @param  iterable<int>  $sellerIds
     * @return array<int, float>
     */
    public function ratesFor(iterable $sellerIds): array
    {
        $ids = collect($sellerIds)->map(fn ($id) => (int) $id)->unique()->values();
        $custom = CommissionRate::query()->whereIn('seller_id', $ids)->pluck('rate', 'seller_id');

        return $ids->mapWithKeys(fn (int $id) => [$id => (float) ($custom[$id] ?? $this->defaultRate())])->all();
    }

    public function setRate(int $sellerId, ?float $rate): void
    {
        if ($rate === null) {
            CommissionRate::query()->whereKey($sellerId)->delete();

            return;
        }
        CommissionRate::updateOrCreate(['seller_id' => $sellerId], ['rate' => $rate]);
    }

    /**
     * Calcule (ou recalcule) le relevé d'un vendeur pour un mois AAAA-MM.
     * Un relevé déjà payé n'est jamais recalculé.
     */
    public function generateStatement(Seller $seller, string $period): PayoutStatement
    {
        $existing = PayoutStatement::query()->where('seller_id', $seller->seller_id)->where('period', $period)->first();
        if ($existing?->status === PayoutStatement::STATUS_PAID) {
            return $existing;
        }

        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $orders = $this->ordersOf($seller->seller_id, $start->toDateString(), $start->copy()->endOfMonth()->toDateString());

        $gross = round($orders->sum(fn (Order $o) => $o->lineTotal()), 2);
        $rate = $this->rateFor($seller->seller_id);
        $commission = round($gross * $rate, 2);

        return PayoutStatement::updateOrCreate(
            ['seller_id' => $seller->seller_id, 'period' => $period],
            [
                'orders_count' => $orders->pluck('order_id')->unique()->count(),
                'items_qty' => (int) $orders->sum('qty'),
                'gross' => $gross,
                'commission_rate' => $rate,
                'commission' => $commission,
                'net' => round($gross - $commission, 2),
                'status' => PayoutStatement::STATUS_PENDING,
            ]
        );
    }

    /**
     * Génère les relevés du mois pour tous les vendeurs ayant vendu.
     *
     * @return int nombre de relevés écrits
     */
    public function generateForPeriod(string $period): int
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $sellerIds = Order::query()->withEffectiveDate()
            ->effectiveBetween($start->toDateString(), $start->copy()->endOfMonth()->toDateString())
            ->distinct()->pluck('orders.vendor_id');

        $count = 0;
        Seller::query()->whereIn('seller_id', $sellerIds)->each(function (Seller $seller) use ($period, &$count) {
            $this->generateStatement($seller, $period);
            $count++;
        });

        return $count;
    }

    /** Lignes de vente d'un vendeur sur une période (dates effectives). */
    public function ordersOf(int $sellerId, string $from, string $to): Collection
    {
        return Order::query()->withEffectiveDate()
            ->where('orders.vendor_id', $sellerId)
            ->effectiveBetween($from, $to)
            ->orderBy('effective_at')
            ->get();
    }
}
