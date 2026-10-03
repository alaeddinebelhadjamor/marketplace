<?php

/*
| Supervision : format Prometheus et métriques métier.
*/

use App\Models\Order;
use App\Models\Seller;
use Illuminate\Support\Facades\Http;

it('compte les requêtes par route modèle et par statut, avec l\'histogramme de durée', function () {
    $this->getJson('/api/stats/sellers'); // 401
    $this->getJson('/api/stats/sellers'); // 401

    $body = $this->get('/metrics')->assertOk()->getContent();

    expect($body)
        ->toContain('# TYPE http_requests_total counter')
        ->toContain('http_requests_total{method="GET",route="/api/stats/sellers",status="401"} 2')
        ->toContain('# TYPE http_request_duration_seconds histogram')
        ->toContain('http_request_duration_seconds_bucket{le="+Inf",method="GET",route="/api/stats/sellers"} 2');
});

it('expose les jauges métier', function () {
    Seller::factory()->count(2)->create();
    Seller::factory()->pending()->create();
    Order::factory()->create();

    $body = $this->get('/metrics')->getContent();

    expect($body)
        ->toContain('marketplace_sellers{status="1"} 3')
        ->toContain('marketplace_sellers{status="0"} 1')
        ->toContain('marketplace_order_lines 1')
        ->toContain('app_up 1');
});

it('mesure les appels à Magento', function () {
    $seller = actingAsSeller();
    fakeMagento(['products/A1' => Http::response(magentoProduct('A1', $seller->seller_id))]);
    $this->getJson('/api/magento/products/url-key?sku=A1')->assertOk();

    expect($this->get('/metrics')->getContent())->toContain('external_requests_total{method="GET",service="magento",status="200"}');
});
