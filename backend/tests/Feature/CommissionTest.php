<?php

/*
| Commissions et relevés de paiement en PDF.
*/

use App\Models\Order;
use App\Models\PayoutStatement;
use App\Models\Seller;
use App\Services\Commission\CommissionService;

it('calcule le relevé mensuel au taux par défaut (5 %)', function () {
    $seller = Seller::factory()->create();
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => '1', 'qty' => 2, 'price' => 100, 'processed_at' => '2026-09-03 10:00:00']);
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => '1', 'sku' => 'B', 'qty' => 1, 'price' => 50, 'processed_at' => '2026-09-03 10:00:00']);
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'order_id' => '2', 'qty' => 1, 'price' => 999, 'processed_at' => '2026-10-01 10:00:00']);

    $s = app(CommissionService::class)->generateStatement($seller, '2026-09');

    expect($s)->orders_count->toBe(1)->items_qty->toBe(3)->gross->toBe(250.0)->commission->toBe(12.5)->net->toBe(237.5)->status->toBe('pending');
});

it('applique un taux propre au vendeur et ne recalcule jamais un relevé payé', function () {
    $seller = Seller::factory()->create();
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-03 10:00:00']);

    $this->putJson("/api/admin/sellers/{$seller->seller_id}/commission-rate", ['rate' => 0.08], adminHeaders())->assertOk()->assertJsonPath('rate', 0.08);
    $this->postJson('/api/admin/statements/generate', ['period' => '2026-09'], adminHeaders())->assertOk()->assertJsonPath('statements', 1);

    $statement = PayoutStatement::first();
    expect($statement->commission)->toBe(8.0);

    $this->putJson("/api/admin/statements/{$statement->id}/paid", [], adminHeaders())->assertOk()->assertJsonPath('status', 'paid');
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-04 10:00:00']);
    app(CommissionService::class)->generateStatement($seller, '2026-09');
    expect($statement->fresh()->gross)->toBe(100.0);
});

it('permet au vendeur de lister ses relevés et de télécharger le PDF', function () {
    $seller = actingAsSeller();
    Order::factory()->create(['vendor_id' => $seller->seller_id, 'qty' => 1, 'price' => 100, 'processed_at' => '2026-09-03 10:00:00']);
    app(CommissionService::class)->generateStatement($seller, '2026-09');

    $this->getJson('/api/statements')->assertOk()->assertJsonPath('commission_rate', 0.05)->assertJsonPath('statements.0.period', '2026-09');

    $pdf = $this->get('/api/statements/2026-09/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $this->get('/api/statements/2026-08/pdf')->assertNotFound();
});

it('n\'expose pas les relevés d\'un autre vendeur', function () {
    $other = Seller::factory()->create();
    Order::factory()->create(['vendor_id' => $other->seller_id, 'processed_at' => '2026-09-03 10:00:00']);
    app(CommissionService::class)->generateStatement($other, '2026-09');

    actingAsSeller();
    $this->getJson('/api/statements')->assertOk()->assertJsonPath('statements', []);
    $this->get('/api/statements/2026-09/pdf')->assertNotFound();
});
