<?php

namespace App\Services\Statistics;

use App\Models\Order;
use App\Services\Commission\CommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tableau de bord avancé du vendeur : indicateurs d'une période comparés à
 * la période précédente de même durée, séries journalières, meilleures
 * ventes et répartition par jour de la semaine.
 */
class SellerDashboardService
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function build(int $sellerId, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        $current = $this->orders($sellerId, $from, $to);
        $previous = $this->orders($sellerId, $prevFrom, $prevTo);
        $rate = $this->commissions->rateFor($sellerId);

        $kpis = $this->kpis($current, $rate);
        $prevKpis = $this->kpis($previous, $rate);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'commission_rate' => $rate,
            'kpis' => collect($kpis)->map(fn ($value, $key) => [
                'value' => $value,
                'previous' => $prevKpis[$key],
                'change_pct' => $this->change($value, $prevKpis[$key]),
            ])->all(),
            'series' => [
                'current' => $this->daily($current, $from, $days),
                'previous' => $this->daily($previous, $prevFrom, $days),
            ],
            'top_products' => $current->groupBy('sku')->map(fn (Collection $g, $sku) => [
                'sku' => $sku,
                'name' => $g->first()->product_name,
                'qty' => (int) $g->sum('qty'),
                'revenue' => round($g->sum(fn (Order $o) => $o->lineTotal()), 2),
            ])->sortByDesc('qty')->take(5)->values()->all(),
            'by_weekday' => $this->byWeekday($current),
        ];
    }

    private function orders(int $sellerId, Carbon $from, Carbon $to): Collection
    {
        return Order::query()->withEffectiveDate()
            ->where('orders.vendor_id', $sellerId)
            ->effectiveBetween($from->toDateString(), $to->toDateString())
            ->get();
    }

    private function kpis(Collection $orders, float $rate): array
    {
        $revenue = round($orders->sum(fn (Order $o) => $o->lineTotal()), 2);
        $count = $orders->pluck('order_id')->unique()->count();

        return [
            'revenue' => $revenue,
            'orders' => $count,
            'items' => (int) $orders->sum('qty'),
            'average_basket' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'net_revenue' => round($revenue * (1 - $rate), 2),
        ];
    }

    /** Variation en % ; null si la période précédente est à zéro. */
    private function change(float|int $now, float|int $before): ?float
    {
        if ((float) $before === 0.0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }

    private function daily(Collection $orders, Carbon $start, int $days): array
    {
        $byDay = $orders->groupBy(fn (Order $o) => $o->effectiveDate()?->toDateString());
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $g = $byDay->get($day, collect());
            $out[] = [
                'date' => $day,
                'qty' => (int) $g->sum('qty'),
                'revenue' => round($g->sum(fn (Order $o) => $o->lineTotal()), 2),
            ];
        }

        return $out;
    }

    private function byWeekday(Collection $orders): array
    {
        $labels = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
        $grouped = $orders->groupBy(fn (Order $o) => $o->effectiveDate()?->isoWeekday());

        return collect($labels)->map(fn ($label, $n) => [
            'day' => $label,
            'revenue' => round(($grouped->get($n) ?? collect())->sum(fn (Order $o) => $o->lineTotal()), 2),
        ])->values()->all();
    }
}
