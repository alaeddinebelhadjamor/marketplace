<?php

use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Tous les tests de fonctionnalité tournent sur une base SQLite en mémoire,
| reconstruite à chaque test (schéma des tables existantes + nouvelles tables).
| Magento et OpenSearch sont simulés avec Http::fake : aucun test ne touche
| aux services réels.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

const ADMIN_KEY = 'cle-admin-de-test';

const MAGENTO = 'http://magento.test/rest/V1';

const OPENSEARCH = 'http://opensearch.test';

/** Vendeur validé authentifié par jeton Sanctum. */
function actingAsSeller(?Seller $seller = null): Seller
{
    $seller ??= Seller::factory()->create();
    Sanctum::actingAs($seller);

    return $seller;
}

function adminHeaders(): array
{
    return ['x-admin-key' => ADMIN_KEY, 'Accept' => 'application/json'];
}

/** Produit au format de l'API Magento. */
function magentoProduct(string $sku, ?int $sellerId, int $status = 1, array $extra = []): array
{
    return array_merge([
        'id' => random_int(1, 99999),
        'sku' => $sku,
        'name' => "Produit {$sku}",
        'price' => 100,
        'status' => $status,
        'custom_attributes' => array_values(array_filter([
            $sellerId !== null ? ['attribute_code' => 'seller_id', 'value' => (string) $sellerId] : null,
            ['attribute_code' => 'url_key', 'value' => strtolower($sku)],
            ['attribute_code' => 'short_description', 'value' => '<p>Description</p>'],
        ])),
        'media_gallery_entries' => [['file' => '/a/b/'.strtolower($sku).'.jpg']],
    ], $extra);
}

/**
 * Simule Magento. $routes : motif d'URL relatif à /rest/V1 => réponse.
 * Le jeton administrateur est toujours accordé.
 */
function fakeMagento(array $routes = []): void
{
    $fakes = [MAGENTO.'/integration/admin/token' => Http::response('"jeton-magento-de-test"')];
    foreach ($routes as $pattern => $response) {
        $fakes[MAGENTO.'/'.ltrim($pattern, '/')] = $response;
    }
    $fakes[OPENSEARCH.'/*'] = Http::response('index introuvable', 404);
    Http::preventStrayRequests();
    Http::fake($fakes);
}
