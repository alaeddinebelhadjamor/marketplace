<?php

/*
| Historique des ventes (tab. 2.9), statistiques (tab. 2.10), tableau de bord
| avancé, export Excel administrateur (tab. 2.18) et statistiques globales.
*/

use App\Models\CommissionRate;
use App\Models\Order;
use App\Models\OrderMagentoDate;
use App\Models\Seller;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function xlsxFrom($response): Spreadsheet
{
    $file = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($file, $response->getContent());

    return IOFactory::load($file);
}

describe('Historique des ventes', function () {
    it('ne renvoie que les ventes du vendeur, les plus récentes d\'abord', function () {
        $seller = actingAsSeller();
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'A', 'processed_at' => '2026-09-01 10:00:00']);
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'B', 'processed_at' => '2026-09-05 10:00:00']);
        Order::factory()->create(['order_id' => 'AUTRE']);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.order_id', 'B')
            ->assertJsonStructure([['order_id', 'sku', 'product_name', 'qty', 'price', 'vendor_id', 'processed_at', 'ordered_at', 'effective_at']]);
    });

    it('filtre par période et par SKU, et utilise la date Magento si elle est connue', function () {
        $seller = actingAsSeller();
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'A', 'sku' => 'CLAVIER-1', 'processed_at' => '2026-09-10 10:00:00']);
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'B', 'sku' => 'SOURIS-1', 'processed_at' => '2026-09-10 10:00:00']);
        // Synchronisée le 10 mais commandée le 2 : hors de la plage 05-15.
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'C', 'sku' => 'CLAVIER-2', 'processed_at' => '2026-09-10 10:00:00']);
        OrderMagentoDate::create(['order_id' => 'C', 'ordered_at' => '2026-09-02 08:00:00']);

        $this->getJson('/api/orders?from=2026-09-05&to=2026-09-15&sku=clavier')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.order_id', 'A');
    });

    it('refuse une plage de dates inversée (400, point C07)', function () {
        actingAsSeller();
        $this->getJson('/api/orders?from=2026-09-15&to=2026-09-01')
            ->assertStatus(400)
            ->assertJsonPath('message', 'La date de début doit précéder la date de fin : corrigez la plage de dates.');
    });

    it('exporte les ventes filtrées en Excel avec une ligne de total', function () {
        $seller = actingAsSeller();
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'qty' => 2, 'price' => 10, 'processed_at' => '2026-09-10 10:00:00']);
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'qty' => 1, 'price' => 5.5, 'processed_at' => '2026-09-11 10:00:00']);

        $response = $this->get('/api/orders/export?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="commandes_2026-09-01_au_2026-09-30.xlsx"');

        $sheet = xlsxFrom($response)->getActiveSheet();
        expect($sheet->getTitle())->toBe('Commandes')
            ->and($sheet->getCell('A7')->getValue())->toBe('TOTAL')
            ->and((float) $sheet->getCell('F7')->getValue())->toBe(25.5);
    });
});

describe('Tableau de bord avancé', function () {
    it('compare la période à la précédente', function () {
        $seller = actingAsSeller();
        // Période courante : 2 commandes, 100 + 50 DT
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'C1', 'sku' => 'A', 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-20 10:00:00']);
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'C2', 'sku' => 'B', 'qty' => 2, 'price' => 25, 'processed_at' => '2026-09-25 10:00:00']);
        // Période précédente : 1 commande, 50 DT
        Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => 'P1', 'sku' => 'A', 'qty' => 1, 'price' => 50, 'processed_at' => '2026-09-05 10:00:00']);

        $this->getJson('/api/dashboard?from=2026-09-16&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('period.days', 15)
            ->assertJsonPath('previous_period.from', '2026-09-01')
            ->assertJsonPath('kpis.revenue.value', 150)
            ->assertJsonPath('kpis.revenue.previous', 50)
            ->assertJsonPath('kpis.revenue.change_pct', 200)
            ->assertJsonPath('kpis.orders.value', 2)
            ->assertJsonPath('kpis.average_basket.value', 75)
            ->assertJsonPath('kpis.net_revenue.value', 142.5)
            ->assertJsonPath('top_products.0.sku', 'B')
            ->assertJsonCount(15, 'series.current')
            ->assertJsonCount(7, 'by_weekday');
    });

    it('renvoie des zéros sans erreur quand il n\'y a aucune vente', function () {
        actingAsSeller();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('kpis.revenue.value', 0)->assertJsonPath('kpis.revenue.change_pct', null);
    });
});

describe('Export Excel administrateur (7 feuilles)', function () {
    it('répond 404 sans commande sur la période', function () {
        $this->getJson('/api/export/excel?from=2020-01-01&to=2020-01-31', adminHeaders())
            ->assertNotFound()->assertJsonPath('message', 'Aucune commande trouvée pour cette période.');
    });

    it('produit les 7 feuilles avec des commandes comptées en distinct et le CA = prix × quantité', function () {
        $a = Seller::factory()->create();
        $b = Seller::factory()->create();
        // Une commande à deux lignes pour A : compte pour 1 commande.
        Order::factory()->create(['vendor_id' => $a->seller_id, 'order_id' => '100', 'sku' => 'X', 'qty' => 2, 'price' => 50, 'processed_at' => '2026-09-10 10:00:00']);
        Order::factory()->create(['vendor_id' => $a->seller_id, 'order_id' => '100', 'sku' => 'Y', 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-10 10:00:00']);
        Order::factory()->create(['vendor_id' => $b->seller_id, 'order_id' => '200', 'sku' => 'Z', 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-11 10:00:00']);

        $response = $this->get('/api/export/excel?from=2026-09-01&to=2026-09-30', adminHeaders())
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="marketplace_2026-09-01.xlsx"');

        $book = xlsxFrom($response);
        expect($book->getSheetNames())->toBe(['Résumé', 'Vendeurs', 'Par Date', 'Par SKU', 'Commissions', 'Vendeur×Date', 'Détail']);

        $resume = collect($book->getSheetByName('Résumé')->toArray())->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
        expect((int) $resume['Total commandes (distinct)'])->toBe(2)
            ->and((float) $resume['CA total (DT)'])->toBe(300.0)
            ->and((float) $resume['Total commissions dues (DT)'])->toBe(15.0);
    });

    it('applique le taux propre à un vendeur', function () {
        $a = Seller::factory()->create();
        Order::factory()->create(['vendor_id' => $a->seller_id, 'qty' => 1, 'price' => 100]);
        CommissionRate::create(['seller_id' => $a->seller_id, 'rate' => 0.1]);

        $book = xlsxFrom($this->get('/api/export/excel?sheets=commission', adminHeaders())->assertOk());
        expect($book->getSheetNames())->toBe(['Commissions'])
            ->and((float) $book->getActiveSheet()->getCell('G5')->getValue())->toBe(10.0);
    });
});

describe('Statistiques globales (administration)', function () {
    it('renvoie vendeurs et commandes', function () {
        Order::factory()->count(3)->create();

        $this->getJson('/api/stats/all', adminHeaders())
            ->assertOk()
            ->assertJsonPath('sellersCount', 3)
            ->assertJsonPath('ordersCount', 3);
        $this->getJson('/api/stats/orders', adminHeaders())->assertOk()->assertJsonPath('count', 3);
    });
});
