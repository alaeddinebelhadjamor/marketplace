<?php

/*
| Produits du vendeur : cas d'utilisation des tableaux 2.5 à 2.8 du rapport.
| Magento et OpenSearch sont simulés.
*/

use App\Models\ProduitConsulte;
use App\Models\Seller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function productBody(array $override = []): array
{
    return ['product' => array_merge([
        'sku' => 'SKU-TEST-01', 'name' => 'Clavier mécanique', 'price' => 199.9, 'weight' => 1,
        'status' => 1, // doit être ignoré : un produit est toujours créé désactivé
        'custom_attributes' => [
            ['attribute_code' => 'short_description', 'value' => 'Court'],
            ['attribute_code' => 'seller_id', 'value' => '999'], // tentative d'usurpation
            ['attribute_code' => 'special_price', 'value' => '1'], // attribut non autorisé
        ],
        'media_gallery_entries' => [['content' => ['base64_encoded_data' => base64_encode('img'), 'type' => 'image/jpeg', 'name' => 'main.jpg']]],
    ], $override)];
}

describe('Ajouter un produit (tab. 2.5)', function () {
    it('crée le produit désactivé et rattaché au vendeur connecté (201)', function () {
        $seller = actingAsSeller();
        fakeMagento([
            'products/SKU-TEST-01' => Http::response(['message' => 'not found'], 404),
            'products' => Http::response(['sku' => 'SKU-TEST-01', 'id' => 10]),
        ]);

        $this->postJson('/api/magento/product/add', productBody())->assertCreated()->assertJsonPath('message', 'Produit ajouté avec succès');

        Http::assertSent(function (Request $r) use ($seller) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/rest/V1/products')) {
                return false;
            }
            $p = $r['product'];
            $attrs = collect($p['custom_attributes'])->pluck('value', 'attribute_code');

            return $p['status'] === 2
                && $attrs['seller_id'] === $seller->seller_id
                && ! $attrs->has('special_price')
                && $p['media_gallery_entries'][0]['types'] === ['image', 'small_image', 'thumbnail'];
        });
    });

    it('refuse un SKU déjà présent dans Magento (409)', function () {
        actingAsSeller();
        fakeMagento(['products/SKU-TEST-01' => Http::response(magentoProduct('SKU-TEST-01', 1))]);

        $this->postJson('/api/magento/product/add', productBody())->assertStatus(409)->assertJsonPath('message', 'Un produit avec le même SKU existe déjà');
    });

    it('refuse un nom déjà utilisé (clé d\'URL existante, 409)', function () {
        actingAsSeller();
        fakeMagento([
            'products/SKU-TEST-01' => Http::response([], 404),
            'products' => Http::response(['message' => 'URL key for specified store already exists.'], 400),
        ]);

        $this->postJson('/api/magento/product/add', productBody())->assertStatus(409)->assertJsonPath('message', 'Un produit avec le même nom existe déjà');
    });

    it('valide le format avant d\'appeler Magento (400)', function (array $override, string $message) {
        actingAsSeller();
        Http::preventStrayRequests();
        Http::fake();

        $this->postJson('/api/magento/product/add', productBody($override))->assertStatus(400)->assertJsonPath('message', $message);
        Http::assertNothingSent();
    })->with([
        'SKU absent' => [['sku' => null], 'SKU, name et price sont obligatoires.'],
        'SKU invalide' => [['sku' => 'a b'], "SKU invalide : 3 à 64 caractères, lettres, chiffres, '.', '_' ou '-' uniquement."],
        'nom trop court' => [['name' => ' x '], 'Le nom du produit doit contenir entre 2 et 255 caractères.'],
        'prix nul' => [['price' => 0], 'Le prix doit être un nombre strictement positif.'],
    ]);
});

describe('Consulter ses produits (tab. 2.6)', function () {
    it('lit les produits validés dans OpenSearch, filtrés par vendeur', function () {
        $seller = actingAsSeller();
        Http::preventStrayRequests();
        Http::fake([
            OPENSEARCH.'/_cat/indices/*' => Http::response("opensearch_index_2\nopensearch_index_1\n"),
            OPENSEARCH.'/opensearch_index_2/_search' => Http::response(['hits' => ['total' => ['value' => 1], 'hits' => [
                ['_source' => ['sku' => 'A1', 'name' => 'Produit A', 'price' => '120', 'special_price' => '99', 'status' => 1, 'image' => '/a/a1.jpg', 'url_key' => 'produit-a']],
            ]]]),
        ]);

        $this->getJson('/api/magento/products?page=1&pageSize=20&search=pro')
            ->assertOk()
            ->assertJsonPath('source', 'opensearch')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.special_price', 99)
            ->assertJsonPath('products.0.image', 'http://magento.test/media/catalog/product/a/a1.jpg');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '_search')
            && $r['query']['bool']['filter'][0]['term']['seller_id'] === $seller->seller_id
            && $r['query']['bool']['must'][0]['bool']['should'][0]['wildcard']['sku.keyword']['value'] === '*pro*');
    });

    it('se replie sur Magento si OpenSearch est indisponible, sans erreur pour le vendeur', function () {
        $seller = actingAsSeller();
        fakeMagento(['products*' => Http::response(['items' => [magentoProduct('A1', $seller->seller_id)], 'total_count' => 1])]);

        $this->getJson('/api/magento/products')->assertOk()->assertJsonPath('source', 'magento')->assertJsonPath('products.0.sku', 'A1');
    });

    it('liste les produits en attente via Magento (statut 2)', function () {
        $seller = actingAsSeller();
        fakeMagento(['products*' => Http::response(['items' => [magentoProduct('P1', $seller->seller_id, 2)], 'total_count' => 41])]);

        $this->getJson('/api/magento/products/pending?pageSize=20')
            ->assertOk()
            ->assertJsonPath('totalPages', 3)
            ->assertJsonPath('products.0.status', 2);

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filters][0][value]=2'));
    });

    it('renvoie un catalogue vide sans erreur', function () {
        actingAsSeller();
        fakeMagento(['products*' => Http::response(['items' => [], 'total_count' => 0])]);

        $this->getJson('/api/magento/products/pending')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('products', []);
    });
});

describe('Modifier le prix et la promotion (tab. 2.7)', function () {
    it('met à jour prix, promotion et dates de son produit', function () {
        $seller = actingAsSeller();
        fakeMagento(['products/A1' => function (Request $r) use ($seller) {
            return $r->method() === 'GET'
                ? Http::response(magentoProduct('A1', $seller->seller_id))
                : Http::response(['sku' => 'A1']);
        }]);

        $this->putJson('/api/magento/product/price', [
            'sku' => 'A1', 'price' => 150, 'special_price' => 120, 'special_from_date' => '2026-10-01', 'special_to_date' => '2026-10-31',
        ])->assertOk()->assertJsonPath('message', 'Prix mis à jour avec succès');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r['product']['price'] == 150
            && collect($r['product']['custom_attributes'])->pluck('value', 'attribute_code')->all() === [
                'special_price' => '120', 'special_from_date' => '2026-10-01', 'special_to_date' => '2026-10-31',
            ]);
    });

    it('refuse de modifier le produit d\'un autre vendeur (403)', function () {
        actingAsSeller();
        fakeMagento(['products/A1' => Http::response(magentoProduct('A1', 999))]);

        $this->putJson('/api/magento/product/price', ['sku' => 'A1', 'price' => 10])
            ->assertForbidden()->assertJsonPath('message', 'Non autorisé à modifier ce produit');
    });

    it('exige le SKU et un prix (400)', function () {
        actingAsSeller();
        $this->putJson('/api/magento/product/price', ['price' => 10])->assertStatus(400)->assertJsonPath('message', 'SKU obligatoire');
        $this->putJson('/api/magento/product/price', ['sku' => 'A1'])->assertStatus(400)->assertJsonPath('message', 'price ou special_price est obligatoire');
    });

    it('refuse une promotion supérieure au prix (400)', function () {
        actingAsSeller();
        $this->putJson('/api/magento/product/price', ['sku' => 'A1', 'price' => 10, 'special_price' => 12])
            ->assertStatus(400)->assertJsonPath('message', 'Le prix promotionnel doit être inférieur au prix normal.');
    });

    it('répond 404 si le produit n\'existe pas', function () {
        actingAsSeller();
        fakeMagento(['products/ZZZ' => Http::response([], 404)]);

        $this->putJson('/api/magento/product/price', ['sku' => 'ZZZ', 'price' => 10])->assertNotFound();
    });
});

describe('Supprimer un produit (tab. 2.8)', function () {
    it('supprime son produit', function () {
        $seller = actingAsSeller();
        fakeMagento(['products/A1' => fn (Request $r) => $r->method() === 'GET'
            ? Http::response(magentoProduct('A1', $seller->seller_id))
            : Http::response('true')]);

        $this->deleteJson('/api/magento/product/delete', ['sku' => 'A1'])->assertOk()->assertJsonPath('message', 'Produit supprimé avec succès');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('refuse de supprimer le produit d\'un autre vendeur (403)', function () {
        actingAsSeller();
        fakeMagento(['products/A1' => Http::response(magentoProduct('A1', 999))]);

        $this->deleteJson('/api/magento/product/delete', ['sku' => 'A1'])->assertForbidden();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('exige le SKU (400)', function () {
        actingAsSeller();
        $this->deleteJson('/api/magento/product/delete', [])->assertStatus(400);
    });
});

describe('Lien vers la fiche publiée', function () {
    it('renvoie l\'url_key et l\'adresse du site', function () {
        $seller = actingAsSeller();
        fakeMagento(['products/A1' => Http::response(magentoProduct('A1', $seller->seller_id))]);

        $this->getJson('/api/magento/products/url-key?sku=A1')
            ->assertOk()
            ->assertJson(['sku' => 'A1', 'url_key' => 'a1', 'url' => 'http://boutique.test/a1.html']);
    });

    it('signale Magento indisponible par un 503 lisible', function () {
        actingAsSeller();
        Http::fake([MAGENTO.'/*' => fn () => throw new ConnectionException('refus')]);

        $this->getJson('/api/magento/products/url-key?sku=A1')->assertStatus(503);
    });
});

describe('Routes d\'intégration (ex-publiques, point C08)', function () {
    it('exigent désormais la clé admin', function () {
        $this->getJson('/api/magento/disabled-seller-products')->assertUnauthorized();
        $this->postJson('/api/magento/produits-consultes', ['id_produit' => 1])->assertUnauthorized();
    });

    it('listent les produits désactivés non consultés, avec le vendeur', function () {
        $seller = Seller::factory()->create();
        ProduitConsulte::create(['id_produit' => 2]);
        fakeMagento(['products*' => Http::response(['items' => [
            magentoProduct('X1', $seller->seller_id, 2, ['id' => 1]),
            magentoProduct('X2', $seller->seller_id, 2, ['id' => 2]),
        ], 'total_count' => 2])]);

        $this->getJson('/api/magento/disabled-seller-products', adminHeaders())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'X1')
            ->assertJsonPath('products.0.shop_title', $seller->shop_title);
    });

    it('enregistrent un produit consulté une seule fois', function () {
        $this->postJson('/api/magento/produits-consultes', ['id_produit' => 7], adminHeaders())->assertCreated();
        $this->postJson('/api/magento/produits-consultes', ['id_produit' => 7], adminHeaders())->assertCreated();
        $this->postJson('/api/magento/produits-consultes', [], adminHeaders())->assertStatus(400);

        expect(ProduitConsulte::count())->toBe(1);
    });
});
