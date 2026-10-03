<?php

/*
| Parité avec tests/auth.test.js (v1) + règles du cas « Se connecter » (tab. 2.3).
*/

use App\Models\Seller;
use Database\Factories\SellerFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

function registerPayload(array $override = []): array
{
    return array_merge([
        'firstname' => 'Test',
        'lastname' => 'Automatise',
        'email' => 'test.auto@example.com',
        'password' => 'MotDePasse123!',
        'shop_title' => 'Boutique Test',
        'contact_number' => '20000000',
    ], $override);
}

describe('POST /api/auth/register', function () {
    it('crée un vendeur en attente avec un email inédit (201)', function () {
        $this->postJson('/api/auth/register', registerPayload())
            ->assertCreated()
            ->assertJson(['message' => 'Inscription réussie ! En attente de validation.']);

        $seller = Seller::where('email', 'test.auto@example.com')->first();
        expect($seller->status)->toBe(0)
            ->and(password_verify('MotDePasse123!', $seller->password_hash))->toBeTrue();
    });

    it('rejette un email déjà utilisé (409)', function () {
        Seller::factory()->create(['email' => 'test.auto@example.com']);

        $this->postJson('/api/auth/register', registerPayload(['shop_title' => 'Autre boutique']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Email déjà utilisé.');
    });

    it('rejette un nom de boutique déjà utilisé (409)', function () {
        Seller::factory()->create(['shop_title' => 'Boutique Test']);

        $response = $this->postJson('/api/auth/register', registerPayload(['email' => 'autre@example.com']))
            ->assertStatus(409);
        expect($response->json('message'))->toMatch('/boutique/i');
    });
});

describe('POST /api/auth/login', function () {
    it('rejette un identifiant inconnu par un 401 générique (amélioration du tab. 2.3)', function () {
        $this->postJson('/api/auth/login', ['identifier' => 'inconnu@example.com', 'password' => 'x'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Identifiant ou mot de passe incorrect.');
    });

    it('rejette un mot de passe incorrect (401)', function () {
        $seller = Seller::factory()->create();

        $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'MauvaisMotDePasse'])
            ->assertUnauthorized();
    });

    it('rejette un compte en attente, même avec le bon mot de passe (403)', function () {
        $seller = Seller::factory()->pending()->create();

        $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte en attente de validation.');
    });

    it('rejette un compte refusé (403)', function () {
        $seller = Seller::factory()->refused()->create();

        $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte refusé.');
    });

    it('connecte un vendeur validé par email et renvoie un jeton de 24 h', function () {
        $seller = Seller::factory()->create();

        $response = $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('id', $seller->seller_id)
            ->assertJsonPath('shop_title', $seller->shop_title);

        $token = $response->json('accessToken');
        // Format Sanctum « id|préfixe+secret » : le préfixe facilite la détection de fuites.
        expect($token)->toMatch('/^\d+\|mkv2_/');
        $this->withToken($token)->getJson('/api/profile')->assertOk()->assertJsonPath('email', $seller->email);
    });

    it('accepte le nom de boutique comme identifiant', function () {
        $seller = Seller::factory()->create();

        $this->postJson('/api/auth/login', ['identifier' => $seller->shop_title, 'password' => SellerFactory::PASSWORD])->assertOk();
    });

    it('accepte les mots de passe hachés par la v1 (bcryptjs, préfixe $2b$)', function () {
        $seller = Seller::factory()->withLegacyHash()->create();
        expect($seller->password_hash)->toStartWith('$2b$');

        $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])->assertOk();
    });

    it('bloque après 5 échecs (429)', function () {
        $seller = Seller::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'faux'])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
            ->assertStatus(429);
    });

    it('révoque le jeton à la déconnexion', function () {
        $seller = Seller::factory()->create();
        $token = $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])->json('accessToken');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        expect($seller->tokens()->count())->toBe(0);
    });

    it('ouvre une session par cookie pour l\'application Vue, sans renvoyer de jeton', function () {
        $seller = Seller::factory()->create();

        $this->withHeaders(['Origin' => 'http://localhost:5180', 'Referer' => 'http://localhost:5180/login'])
            ->withoutMiddleware(PreventRequestForgery::class)
            ->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('accessToken', null);

        $this->assertAuthenticatedAs($seller, 'web');
    });
});

describe('Endpoints généraux', function () {
    it('GET / répond 200', function () {
        $this->get('/')->assertOk()->assertJsonStructure(['message']);
    });

    it('GET /metrics expose le compteur de requêtes HTTP', function () {
        $this->getJson('/api/auth/me-inexistant');
        $this->get('/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->assertSee('http_requests_total', false)
            ->assertSee('marketplace_sellers', false);
    });
});
