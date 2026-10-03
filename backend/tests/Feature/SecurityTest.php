<?php

/*
| Parité avec tests/security.test.js (v1) + correctifs de sécurité de la v2.
*/

use App\Models\Reclamation;
use App\Models\ReclamationAttachment;
use App\Models\Seller;
use Database\Factories\SellerFactory;
use Illuminate\Support\Facades\Storage;

describe('Routes d\'administration (x-admin-key)', function () {
    it('refuse /api/stats/sellers sans clé (401)', function () {
        $this->getJson('/api/stats/sellers')->assertUnauthorized();
    });

    it('refuse /api/stats/sellers avec une clé incorrecte (401)', function () {
        $this->getJson('/api/stats/sellers', ['x-admin-key' => 'mauvaise-cle'])->assertUnauthorized();
    });

    it('refuse /api/export/excel sans clé (401)', function () {
        $this->getJson('/api/export/excel')->assertUnauthorized();
    });

    it('refuse /api/admin/reclamations sans clé (401)', function () {
        $this->getJson('/api/admin/reclamations')->assertUnauthorized();
    });

    it('répond 200 avec la bonne clé, sans jamais exposer password_hash', function () {
        Seller::factory()->count(2)->create();

        $response = $this->getJson('/api/stats/sellers', adminHeaders())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2);
        expect($response->getContent())->not->toContain('password_hash')->not->toContain('$2y$');
    });

    it('répond 503 si la clé n\'est pas configurée sur le serveur', function () {
        config(['marketplace.admin_api_key' => null]);
        $this->getJson('/api/stats/sellers', adminHeaders())->assertStatus(503);
    });

    it('n\'expose plus le jeton administrateur Magento (404)', function () {
        $this->getJson('/api/magento/token')->assertNotFound();
    });
});

describe('Contrôle de propriété des réclamations', function () {
    it('empêche de lire les messages d\'une réclamation d\'un autre vendeur (404)', function () {
        $other = Reclamation::factory()->create();
        actingAsSeller();

        $this->getJson("/api/reclamations/{$other->id}/messages")->assertNotFound()->assertJsonPath('error', 'Reclamation not found');
    });

    it('empêche de répondre à la réclamation d\'un autre vendeur (404)', function () {
        $other = Reclamation::factory()->create();
        actingAsSeller();

        $this->postJson("/api/reclamations/{$other->id}/reply", ['message' => 'tentative'])->assertNotFound();
        $this->postJson('/api/reclamations/999999/reply', ['message' => 'tentative'])->assertNotFound();
    });

    it('empêche de résoudre la réclamation d\'un autre vendeur (404)', function () {
        $other = Reclamation::factory()->create();
        actingAsSeller();

        $this->putJson("/api/reclamations/{$other->id}/resolve")->assertNotFound();
        expect($other->fresh()->type)->toBe(1);
    });

    it('refuse la résolution sans jeton (403)', function () {
        $this->putJson('/api/reclamations/1/resolve')->assertForbidden();
    });

    it('permet au vendeur de clôturer sa propre réclamation ouverte (200, type 2)', function () {
        actingAsSeller();
        $id = $this->postJson('/api/reclamations', ['message' => 'Réclamation de test à clôturer'])->assertOk()->json('reclamation_id');

        $this->putJson("/api/reclamations/{$id}/resolve")->assertOk();

        $mine = collect($this->getJson('/api/reclamations/seller')->json());
        expect($mine->firstWhere('id', $id)['type'])->toBe(2);
    });
});

describe('Inscription : liste blanche des champs', function () {
    it('ignore status et seller_id forcés : le compte reste en attente (connexion 403)', function () {
        $this->postJson('/api/auth/register', [
            'firstname' => 'Test', 'lastname' => 'Whitelist', 'email' => 'whitelist@example.com',
            'password' => SellerFactory::PASSWORD, 'shop_title' => 'Boutique Whitelist', 'contact_number' => '20000000',
            'status' => 1, 'seller_id' => 1, 'password_hash' => 'x',
        ])->assertCreated();

        $this->postJson('/api/auth/login', ['identifier' => 'whitelist@example.com', 'password' => SellerFactory::PASSWORD])
            ->assertForbidden();
    });
});

describe('Jetons et en-têtes', function () {
    it('distingue en-tête mal formé (403) et jeton invalide (401)', function () {
        $this->getJson('/api/profile', ['Authorization' => 'Basic abc'])->assertForbidden()->assertJsonPath('message', 'Token invalide.');
        $this->getJson('/api/profile', ['Authorization' => 'Bearer faux-jeton'])->assertUnauthorized()->assertJsonPath('message', 'Token expiré ou invalide.');
    });

    it('refuse un vendeur refusé après coup, même avec un jeton valide (403)', function () {
        $seller = Seller::factory()->create();
        $token = $seller->createToken('api')->plainTextToken;
        $seller->forceFill(['status' => 2])->save();

        $this->withToken($token)->getJson('/api/profile')->assertForbidden();
    });

    it('ajoute les en-têtes de sécurité HTTP', function () {
        $this->getJson('/api/stats/sellers')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    });
});

describe('Pièces jointes (correctif C09)', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->owner = Seller::factory()->create();
        $reclamation = Reclamation::factory()->create(['vendeur_id' => $this->owner->seller_id]);
        $message = $reclamation->messages()->create(['sender' => 1, 'message' => 'avec fichier']);
        Storage::disk('local')->put('reclamations/1700000000000-123456789.pdf', '%PDF-1.4 test');
        ReclamationAttachment::create(['message_id' => $message->id, 'file_path' => 'uploads\\reclamations\\1700000000000-123456789.pdf', 'file_type' => 'application/pdf']);
    });

    it('refuse l\'accès anonyme (403)', function () {
        $this->get('/api/attachments/view/1700000000000-123456789.pdf')->assertForbidden();
    });

    it('sert le fichier à son propriétaire', function () {
        actingAsSeller($this->owner);
        $this->get('/api/attachments/download/1700000000000-123456789.pdf')->assertOk()->assertDownload('1700000000000-123456789.pdf');
    });

    it('cache le fichier aux autres vendeurs (404)', function () {
        actingAsSeller();
        $this->get('/api/attachments/view/1700000000000-123456789.pdf')->assertNotFound();
    });

    it('sert le fichier avec la clé admin (module Magento, intégrations)', function () {
        $this->get('/api/attachments/view/1700000000000-123456789.pdf', ['x-admin-key' => ADMIN_KEY])->assertOk();
    });

    it('bloque la remontée de dossier (..%2F)', function () {
        actingAsSeller($this->owner);
        $this->get('/api/attachments/view/..%2F..%2F.env')->assertNotFound();
        $this->get('/api/attachments/view/....env')->assertNotFound();
    });
});
