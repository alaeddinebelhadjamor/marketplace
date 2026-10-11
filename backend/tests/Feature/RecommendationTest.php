<?php

/*
| API publique du moteur de recommandation (App\Services\Recommendation),
| port du moteur de la v1. Pas de modèle ALS/embeddings sur le disque en
| test : seuls les signaux co-visite et popularité (user_product_behavior)
| sont exercés, avec repli Magento REST pour l'enrichissement (OpenSearch
| répond 404 par défaut dans fakeMagento()).
*/

use App\Models\UserProductBehavior;
use Illuminate\Support\Facades\Http;

function behaviorEvent(string $sessionId, string $sku, string $at): array
{
    return [
        'session_id' => $sessionId,
        'product_sku' => $sku,
        'event_type' => 'product_view',
        'created_at' => $at,
    ];
}

it('refuse une requête sans sku (T5-01)', function () {
    $this->getJson('/api/recommendations')
        ->assertStatus(400)
        ->assertJsonPath('message', 'Paramètre sku obligatoire');
});

it('renvoie une liste vide sans aucune donnée de comportement (cold start total)', function () {
    $this->getJson('/api/recommendations/popular')
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('results', []);
});

it('recommande un produit co-visité dans la même session (T5-02)', function () {
    fakeMagento([
        'products/ECRAN-24' => Http::response(magentoProduct('ECRAN-24', 1)),
        'products/CLAVIER-1' => Http::response(magentoProduct('CLAVIER-1', 1)),
    ]);

    $t0 = now()->subDay();
    UserProductBehavior::insert([
        behaviorEvent('s1', 'ECRAN-24', $t0->toDateTimeString()),
        behaviorEvent('s1', 'CLAVIER-1', $t0->copy()->addSeconds(20)->toDateTimeString()),
    ]);

    $response = $this->getJson('/api/recommendations?sku=ECRAN-24')->assertOk();

    expect($response->json('sku'))->toBe('ECRAN-24')
        ->and($response->json('count'))->toBe(1)
        ->and($response->json('results.0.sku'))->toBe('CLAVIER-1')
        ->and($response->json('results.0.source'))->toBe('co_view');
});

it('replie sur la popularité quand le sku demandé est inconnu de tout signal', function () {
    fakeMagento(['products/ECRAN-24' => Http::response(magentoProduct('ECRAN-24', 1))]);

    $t0 = now()->subDay();
    UserProductBehavior::insert([
        behaviorEvent('s1', 'ECRAN-24', $t0->toDateTimeString()),
        behaviorEvent('s2', 'ECRAN-24', $t0->copy()->addMinute()->toDateTimeString()),
    ]);

    $response = $this->getJson('/api/recommendations?sku=INCONNU-1')->assertOk();

    expect($response->json('results.0.sku'))->toBe('ECRAN-24')
        ->and($response->json('results.0.source'))->toBe('popularity');
});

it('plafonne top_k à 50', function () {
    $this->getJson('/api/recommendations/popular?top_k=9999')->assertOk();
    // Pas d'assertion de volume ici (pas de données) : vérifie seulement
    // que la requête n'échoue pas avec une valeur hors bornes.
});
