<?php

namespace App\Services\Export;

use App\Models\Order;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Export Excel de l'historique des ventes d'un vendeur (écran « Liste Ventes »).
 * La v1 le générait dans le navigateur avec SheetJS ; la v2 le génère côté
 * serveur, ce qui évite une bibliothèque cliente vulnérable et garantit les
 * mêmes calculs que l'API.
 */
class SellerSalesExport
{
    public function __construct() {}

    /** @return array{0: string, 1: string} [nom de fichier, contenu binaire] */
    public function build(Collection $orders, ?string $from, ?string $to): array
    {
        $writer = new SpreadsheetWriter('C70A0A', '8B0000');

        $rows = $orders->map(fn (Order $o) => [
            'sku' => $o->sku,
            'produit' => $o->product_name,
            'order_id' => $o->order_id,
            'qty' => (int) $o->qty,
            'prix' => round((float) $o->price, 2),
            'total' => $o->lineTotal(),
            'date' => $o->effectiveDate()?->format('Y-m-d'),
        ])->values()->all();

        $book = new Spreadsheet;
        $writer->table($book, 'Commandes', 'Historique des ventes', 'Du '.($from ?: 'début').' au '.($to ?: 'aujourd\'hui'), [
            ['key' => 'sku', 'header' => 'SKU', 'width' => 28],
            ['key' => 'produit', 'header' => 'Produit', 'width' => 40],
            ['key' => 'order_id', 'header' => 'ID Commande', 'width' => 32],
            ['key' => 'qty', 'header' => 'Quantité', 'width' => 12],
            ['key' => 'prix', 'header' => 'Prix unitaire (DT)', 'width' => 22, 'money' => true],
            ['key' => 'total', 'header' => 'Total (DT)', 'width' => 18, 'money' => true],
            ['key' => 'date', 'header' => 'Date', 'width' => 14],
        ], $rows, [
            'sku' => 'TOTAL',
            'qty' => array_sum(array_column($rows, 'qty')),
            'total' => round(array_sum(array_column($rows, 'total')), 2),
        ]);

        $filename = 'commandes_'.($from ?: 'debut').'_au_'.($to ?: 'fin').'.xlsx';

        return [$filename, $writer->toBinary($book)];
    }
}
