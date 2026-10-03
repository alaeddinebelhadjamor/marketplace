<?php

namespace App\Services\Export;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Seller;
use App\Services\Commission\CommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Export Excel consolidé de l'administration (GET /api/export/excel), en
 * sept feuilles comme la v1 : résumé, vendeurs, par date, par SKU,
 * commissions, croisé vendeur × date, détail des commandes.
 *
 * Règles conservées : commandes comptées en order_id distinct, chiffre
 * d'affaires = Σ prix × quantité. Changement v2 : taux de commission propre
 * à chaque vendeur (5 % par défaut) et dates effectives (date Magento si connue).
 */
class MarketplaceExcelExport
{
    public const SHEETS = ['resume', 'vendeur', 'date', 'sku', 'commission', 'croise', 'detail'];

    public function __construct(
        private readonly CommissionService $commissions,
        private readonly SpreadsheetWriter $writer,
    ) {}

    /**
     * @param  list<int>|null  $sellerIds  null = tous les vendeurs
     * @param  list<string>  $sheets
     * @return array{0: string, 1: string} [nom de fichier, contenu binaire]
     *
     * @throws ApiException 404 si aucune commande
     */
    public function build(?string $from, ?string $to, ?array $sellerIds, array $sheets): array
    {
        $query = Order::query()->withEffectiveDate()->effectiveBetween($from, $to)->orderByDesc('effective_at');
        if ($sellerIds !== null) {
            $query->whereIn('orders.vendor_id', $sellerIds);
        }
        $orders = $query->get();

        if ($orders->isEmpty()) {
            throw new ApiException('Aucune commande trouvée pour cette période.', 404, ['success' => false]);
        }

        $sellers = Seller::query()->whereIn('seller_id', $orders->pluck('vendor_id')->unique())->get()->keyBy('seller_id');
        $rates = $this->commissions->ratesFor($orders->pluck('vendor_id'));
        $period = match (true) {
            $from && $to => "{$from} → {$to}",
            (bool) $from => "À partir du {$from}",
            (bool) $to => "Jusqu'au {$to}",
            default => 'Toutes les dates',
        };

        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Marketplace Mytek')->setTitle('Export marketplace '.$period);
        $want = fn (string $s) => in_array($s, $sheets, true);

        if ($want('resume')) {
            $this->summary($book, $orders, $rates, $period);
        }
        if ($want('vendeur')) {
            $this->bySeller($book, $orders, $sellers, $rates, $period);
        }
        if ($want('date')) {
            $this->byDate($book, $orders, $rates, $period);
        }
        if ($want('sku')) {
            $this->bySku($book, $orders, $period);
        }
        if ($want('commission')) {
            $this->commissionSheet($book, $orders, $sellers, $rates, $period);
        }
        if ($want('croise')) {
            $this->crossTable($book, $orders, $sellers, $period);
        }
        if ($want('detail')) {
            $this->detail($book, $orders, $sellers, $rates, $period);
        }

        $filename = 'marketplace_'.($from ?: now()->toDateString()).'.xlsx';

        return [$filename, $this->writer->toBinary($book)];
    }

    private function summary(Spreadsheet $book, Collection $orders, array $rates, string $period): void
    {
        $ca = $orders->sum(fn (Order $o) => $o->lineTotal());
        $commission = $orders->sum(fn (Order $o) => $o->lineTotal() * $rates[$o->vendor_id]);
        $distinct = $orders->pluck('order_id')->unique()->count();
        $daily = $orders->groupBy(fn (Order $o) => $o->effectiveDate()?->toDateString())
            ->map(fn (Collection $g) => $g->sum(fn (Order $o) => $o->lineTotal()));
        $rateLabel = count(array_unique($rates)) === 1 ? $this->pct(reset($rates)) : 'variable selon le vendeur';

        $rows = [
            ['indicateur' => 'PÉRIODE ANALYSÉE', 'valeur' => $period],
            ['indicateur' => 'Généré le', 'valeur' => now()->format('d/m/Y H:i:s')],
            ['indicateur' => '', 'valeur' => ''],
            ['indicateur' => '── VOLUMES ──', 'valeur' => ''],
            ['indicateur' => 'Total commandes (distinct)', 'valeur' => $distinct],
            ['indicateur' => 'Total quantités vendues', 'valeur' => (int) $orders->sum('qty')],
            ['indicateur' => 'Vendeurs actifs sur période', 'valeur' => $orders->pluck('vendor_id')->unique()->count()],
            ['indicateur' => 'SKUs distincts vendus', 'valeur' => $orders->pluck('sku')->unique()->count()],
            ['indicateur' => '', 'valeur' => ''],
            ['indicateur' => "── CHIFFRE D'AFFAIRES ──", 'valeur' => ''],
            ['indicateur' => 'CA total (DT)', 'valeur' => round($ca, 2)],
            ['indicateur' => 'Panier moyen (DT)', 'valeur' => round($ca / max(1, $distinct), 2)],
            ['indicateur' => 'CA moyen / jour (DT)', 'valeur' => round($daily->avg() ?? 0, 2)],
            ['indicateur' => 'CA max en 1 jour (DT)', 'valeur' => round($daily->max() ?? 0, 2)],
            ['indicateur' => 'CA min en 1 jour (DT)', 'valeur' => round($daily->min() ?? 0, 2)],
            ['indicateur' => '', 'valeur' => ''],
            ['indicateur' => '── COMMISSIONS ──', 'valeur' => ''],
            ['indicateur' => 'Taux commission appliqué', 'valeur' => $rateLabel],
            ['indicateur' => 'Total commissions dues (DT)', 'valeur' => round($commission, 2)],
            ['indicateur' => 'Net vendeurs (DT)', 'valeur' => round($ca - $commission, 2)],
        ];

        $ws = $this->writer->table($book, 'Résumé', 'Résumé Exécutif', "Marketplace — {$period}", [
            ['key' => 'indicateur', 'header' => 'Indicateur', 'width' => 35],
            ['key' => 'valeur', 'header' => 'Valeur', 'width' => 28],
        ], $rows);

        foreach ($rows as $i => $row) {
            if (str_starts_with($row['indicateur'], '──')) {
                $r = $i + 5;
                $ws->getStyle("A{$r}:B{$r}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '1D4ED8']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
                ]);
            }
        }
    }

    private function bySeller(Spreadsheet $book, Collection $orders, Collection $sellers, array $rates, string $period): void
    {
        $totalCa = $orders->sum(fn (Order $o) => $o->lineTotal());

        $rows = $orders->groupBy('vendor_id')->map(function (Collection $g, $vid) use ($sellers, $rates, $totalCa) {
            $revenue = $g->sum(fn (Order $o) => $o->lineTotal());
            $nb = $g->pluck('order_id')->unique()->count();
            $commission = $revenue * $rates[$vid];
            $s = $sellers->get($vid);

            return [
                'vendor_id' => $vid,
                'nom' => $s ? "{$s->firstname} {$s->lastname}" : '—',
                'boutique' => $s->shop_title ?? '—',
                'email' => $s->email ?? '—',
                'nb_commandes' => $nb,
                'qty_totale' => (int) $g->sum('qty'),
                'ca_total' => round($revenue, 2),
                'panier_moyen' => round($revenue / max(1, $nb), 2),
                'part_ca' => round($totalCa > 0 ? $revenue / $totalCa * 100 : 0, 2),
                'nb_skus' => $g->pluck('sku')->unique()->count(),
                'taux' => $this->pct($rates[$vid]),
                'commission' => round($commission, 2),
                'net_vendeur' => round($revenue - $commission, 2),
            ];
        })->sortByDesc('ca_total')->values()->map(fn ($r, $i) => ['rang' => $i + 1] + $r)->all();

        $this->writer->table($book, 'Vendeurs', 'Performance Vendeurs', "Période : {$period}", [
            ['key' => 'rang', 'header' => 'Rang', 'width' => 8],
            ['key' => 'vendor_id', 'header' => 'ID Vendeur', 'width' => 12],
            ['key' => 'nom', 'header' => 'Nom', 'width' => 22],
            ['key' => 'boutique', 'header' => 'Boutique', 'width' => 22],
            ['key' => 'email', 'header' => 'Email', 'width' => 28],
            ['key' => 'nb_commandes', 'header' => 'Nb Commandes', 'width' => 14],
            ['key' => 'qty_totale', 'header' => 'Qté Vendue', 'width' => 12],
            ['key' => 'ca_total', 'header' => 'CA Total (DT)', 'width' => 16, 'money' => true],
            ['key' => 'panier_moyen', 'header' => 'Panier Moy. (DT)', 'width' => 16, 'money' => true],
            ['key' => 'part_ca', 'header' => 'Part CA (%)', 'width' => 12, 'pct' => true],
            ['key' => 'nb_skus', 'header' => 'SKUs Distincts', 'width' => 14],
            ['key' => 'taux', 'header' => 'Taux', 'width' => 10],
            ['key' => 'commission', 'header' => 'Commission (DT)', 'width' => 18, 'money' => true],
            ['key' => 'net_vendeur', 'header' => 'Net Vendeur (DT)', 'width' => 16, 'money' => true],
        ], $rows, [
            'rang' => 'TOTAL',
            'nb_commandes' => array_sum(array_column($rows, 'nb_commandes')),
            'ca_total' => round(array_sum(array_column($rows, 'ca_total')), 2),
            'part_ca' => 100,
            'commission' => round(array_sum(array_column($rows, 'commission')), 2),
            'net_vendeur' => round(array_sum(array_column($rows, 'net_vendeur')), 2),
        ]);
    }

    private function byDate(Spreadsheet $book, Collection $orders, array $rates, string $period): void
    {
        $rows = $orders->groupBy(fn (Order $o) => $o->effectiveDate()?->toDateString())
            ->sortKeys()
            ->map(function (Collection $g, string $date) use ($rates) {
                $revenue = $g->sum(fn (Order $o) => $o->lineTotal());
                $nb = $g->pluck('order_id')->unique()->count();

                return [
                    'date' => Carbon::parse($date)->format('d/m/Y'),
                    'nb_commandes' => $nb,
                    'qty_totale' => (int) $g->sum('qty'),
                    'ca_total' => round($revenue, 2),
                    'panier_moyen' => round($revenue / max(1, $nb), 2),
                    'vendeurs_actifs' => $g->pluck('vendor_id')->unique()->count(),
                    'skus_vendus' => $g->pluck('sku')->unique()->count(),
                    'commission' => round($g->sum(fn (Order $o) => $o->lineTotal() * $rates[$o->vendor_id]), 2),
                ];
            })->values()->all();

        $this->writer->table($book, 'Par Date', 'Analyse par Date', "Période : {$period}", [
            ['key' => 'date', 'header' => 'Date', 'width' => 14],
            ['key' => 'nb_commandes', 'header' => 'Nb Commandes', 'width' => 14],
            ['key' => 'qty_totale', 'header' => 'Qté Vendue', 'width' => 12],
            ['key' => 'ca_total', 'header' => 'CA (DT)', 'width' => 16, 'money' => true],
            ['key' => 'panier_moyen', 'header' => 'Panier Moy. (DT)', 'width' => 16, 'money' => true],
            ['key' => 'vendeurs_actifs', 'header' => 'Vendeurs Actifs', 'width' => 16],
            ['key' => 'skus_vendus', 'header' => 'SKUs Distincts', 'width' => 14],
            ['key' => 'commission', 'header' => 'Commission (DT)', 'width' => 16, 'money' => true],
        ], $rows, [
            'date' => 'TOTAL',
            'nb_commandes' => array_sum(array_column($rows, 'nb_commandes')),
            'qty_totale' => array_sum(array_column($rows, 'qty_totale')),
            'ca_total' => round(array_sum(array_column($rows, 'ca_total')), 2),
            'commission' => round(array_sum(array_column($rows, 'commission')), 2),
        ]);
    }

    private function bySku(Spreadsheet $book, Collection $orders, string $period): void
    {
        $rows = $orders->groupBy('sku')->map(function (Collection $g, string $sku) {
            $revenue = $g->sum(fn (Order $o) => $o->lineTotal());
            $nb = $g->pluck('order_id')->unique()->count();

            return [
                'sku' => $sku,
                'produit' => $g->first()->product_name,
                'nb_commandes' => $nb,
                'qty_totale' => (int) $g->sum('qty'),
                'ca_total' => round($revenue, 2),
                // Prix moyen par unité vendue (la v1 divisait par le nombre de commandes).
                'prix_moyen' => round($revenue / max(1, (int) $g->sum('qty')), 2),
            ];
        })->sortByDesc('ca_total')->values()->map(fn ($r, $i) => ['rang' => $i + 1] + $r)->all();

        $this->writer->table($book, 'Par SKU', 'Analyse par SKU', "Période : {$period}", [
            ['key' => 'rang', 'header' => 'Rang', 'width' => 8],
            ['key' => 'sku', 'header' => 'SKU', 'width' => 18],
            ['key' => 'produit', 'header' => 'Produit', 'width' => 35],
            ['key' => 'nb_commandes', 'header' => 'Nb Commandes', 'width' => 14],
            ['key' => 'qty_totale', 'header' => 'Qté Vendue', 'width' => 12],
            ['key' => 'ca_total', 'header' => 'CA Total (DT)', 'width' => 16, 'money' => true],
            ['key' => 'prix_moyen', 'header' => 'Prix Moyen (DT)', 'width' => 16, 'money' => true],
        ], $rows, [
            'rang' => 'TOTAL',
            'nb_commandes' => array_sum(array_column($rows, 'nb_commandes')),
            'qty_totale' => array_sum(array_column($rows, 'qty_totale')),
            'ca_total' => round(array_sum(array_column($rows, 'ca_total')), 2),
        ]);
    }

    private function commissionSheet(Spreadsheet $book, Collection $orders, Collection $sellers, array $rates, string $period): void
    {
        $rows = $orders->groupBy('vendor_id')->map(function (Collection $g, $vid) use ($sellers, $rates) {
            $revenue = $g->sum(fn (Order $o) => $o->lineTotal());
            $commission = round($revenue * $rates[$vid], 2);
            $s = $sellers->get($vid);

            return [
                'vendor_id' => $vid,
                'nom' => $s ? "{$s->firstname} {$s->lastname}" : '—',
                'boutique' => $s->shop_title ?? '—',
                'nb_commandes' => $g->pluck('order_id')->unique()->count(),
                'ca_brut' => round($revenue, 2),
                'taux_commission' => $this->pct($rates[$vid]),
                'montant_commission' => $commission,
                'net_a_verser' => round($revenue - $commission, 2),
                'statut' => 'En attente',
            ];
        })->sortByDesc('ca_brut')->values()->all();

        $this->writer->table($book, 'Commissions', 'Suivi Commissions', "Période : {$period}", [
            ['key' => 'vendor_id', 'header' => 'ID Vendeur', 'width' => 12],
            ['key' => 'nom', 'header' => 'Nom Vendeur', 'width' => 22],
            ['key' => 'boutique', 'header' => 'Boutique', 'width' => 22],
            ['key' => 'nb_commandes', 'header' => 'Nb Commandes', 'width' => 14],
            ['key' => 'ca_brut', 'header' => 'CA Brut (DT)', 'width' => 16, 'money' => true],
            ['key' => 'taux_commission', 'header' => 'Taux', 'width' => 10],
            ['key' => 'montant_commission', 'header' => 'Commission (DT)', 'width' => 16, 'money' => true],
            ['key' => 'net_a_verser', 'header' => 'Net à Verser (DT)', 'width' => 18, 'money' => true],
            ['key' => 'statut', 'header' => 'Statut Paiement', 'width' => 18],
        ], $rows, [
            'vendor_id' => 'TOTAL',
            'nb_commandes' => array_sum(array_column($rows, 'nb_commandes')),
            'ca_brut' => round(array_sum(array_column($rows, 'ca_brut')), 2),
            'montant_commission' => round(array_sum(array_column($rows, 'montant_commission')), 2),
            'net_a_verser' => round(array_sum(array_column($rows, 'net_a_verser')), 2),
        ]);
    }

    private function crossTable(Spreadsheet $book, Collection $orders, Collection $sellers, string $period): void
    {
        $dates = $orders->map(fn (Order $o) => $o->effectiveDate()?->toDateString())->unique()->sort()->values();

        $rows = $orders->groupBy('vendor_id')->map(function (Collection $g, $vid) use ($dates, $sellers) {
            $s = $sellers->get($vid);
            $byDate = $g->groupBy(fn (Order $o) => $o->effectiveDate()?->toDateString())
                ->map(fn (Collection $d) => round($d->sum(fn (Order $o) => $o->lineTotal()), 2));
            $row = ['vendeur' => $s ? "{$s->firstname} {$s->lastname} ({$s->shop_title})" : "Vendeur #{$vid}"];
            foreach ($dates as $d) {
                $row[$d] = $byDate[$d] ?? 0;
            }
            $row['TOTAL'] = round($byDate->sum(), 2);

            return $row;
        })->sortByDesc('TOTAL')->values()->all();

        $cols = [['key' => 'vendeur', 'header' => 'Vendeur', 'width' => 28]];
        $totals = ['vendeur' => 'TOTAL'];
        foreach ($dates as $d) {
            $cols[] = ['key' => $d, 'header' => Carbon::parse($d)->format('d/m/Y'), 'width' => 14, 'money' => true];
            $totals[$d] = round(array_sum(array_column($rows, $d)), 2);
        }
        $cols[] = ['key' => 'TOTAL', 'header' => 'TOTAL', 'width' => 16, 'money' => true];
        $totals['TOTAL'] = round(array_sum(array_column($rows, 'TOTAL')), 2);

        $this->writer->table($book, 'Vendeur×Date', 'Croisé Vendeur × Date', "CA par vendeur par jour — {$period}", $cols, $rows, $totals);
    }

    private function detail(Spreadsheet $book, Collection $orders, Collection $sellers, array $rates, string $period): void
    {
        $rows = [];
        $groups = $orders->groupBy('order_id')->sortByDesc(fn (Collection $g) => $g->first()->effectiveDate()?->timestamp);

        foreach ($groups as $orderId => $items) {
            $first = $items->first();
            $s = $sellers->get($first->vendor_id);
            $total = $items->sum(fn (Order $o) => $o->lineTotal());
            $commission = round($total * $rates[$first->vendor_id], 2);
            $date = $first->effectiveDate();

            foreach ($items->values() as $i => $item) {
                $rows[] = [
                    'order_id' => $i === 0 ? (string) $orderId : '',
                    'date' => $i === 0 ? $date?->format('d/m/Y') : '',
                    'heure' => $i === 0 ? $date?->format('H:i:s') : '',
                    'vendor_id' => $i === 0 ? $first->vendor_id : '',
                    'vendeur' => $i === 0 ? ($s ? "{$s->firstname} {$s->lastname}" : '—') : '',
                    'boutique' => $i === 0 ? ($s->shop_title ?? '—') : '',
                    'sku' => $item->sku,
                    'produit' => $item->product_name,
                    'qty' => (int) $item->qty,
                    'prix_unitaire' => round((float) $item->price, 2),
                    'commission' => $i === 0 ? $commission : '',
                    'net' => $i === 0 ? round($total - $commission, 2) : '',
                ];
            }
        }

        $sum = fn (string $k) => round(array_sum(array_filter(array_column($rows, $k), 'is_numeric')), 2);

        $this->writer->table($book, 'Détail', 'Détail des Commandes', "Période : {$period} — ".$groups->count().' commandes', [
            ['key' => 'order_id', 'header' => 'N° Commande', 'width' => 16],
            ['key' => 'date', 'header' => 'Date', 'width' => 12],
            ['key' => 'heure', 'header' => 'Heure', 'width' => 10],
            ['key' => 'vendor_id', 'header' => 'ID Vendeur', 'width' => 12],
            ['key' => 'vendeur', 'header' => 'Vendeur', 'width' => 22],
            ['key' => 'boutique', 'header' => 'Boutique', 'width' => 22],
            ['key' => 'sku', 'header' => 'SKU', 'width' => 18],
            ['key' => 'produit', 'header' => 'Produit', 'width' => 35],
            ['key' => 'qty', 'header' => 'Qté', 'width' => 8],
            ['key' => 'prix_unitaire', 'header' => 'Prix (DT)', 'width' => 14, 'money' => true],
            ['key' => 'commission', 'header' => 'Commission (DT)', 'width' => 16, 'money' => true],
            ['key' => 'net', 'header' => 'Net Vendeur (DT)', 'width' => 16, 'money' => true],
        ], $rows, [
            'order_id' => 'TOTAL',
            'qty' => (int) $sum('qty'),
            'commission' => $sum('commission'),
            'net' => $sum('net'),
        ]);
    }

    private function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.').'%';
    }
}
