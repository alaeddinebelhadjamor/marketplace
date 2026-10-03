<?php

/*
| Réclamations (tab. 2.11), traitement par l'administration (tab. 2.16),
| notifications (tab. 2.17) et diffusion temps réel.
*/

use App\Events\SellerInboxUpdated;
use App\Models\Reclamation;
use App\Models\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

describe('Côté vendeur', function () {
    it('ouvre une réclamation avec pièces jointes', function () {
        $seller = actingAsSeller();

        $id = $this->post('/api/reclamations', [
            'message' => 'Mon produit n\'apparaît pas',
            'attachments' => [UploadedFile::fake()->create('capture.png', 20, 'image/png'), UploadedFile::fake()->create('facture.pdf', 30, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success', true)->json('reclamation_id');

        $reclamation = Reclamation::with('messages.attachments')->find($id);
        expect($reclamation->type)->toBe(1)
            ->and($reclamation->vendeur_id)->toBe($seller->seller_id)
            ->and($reclamation->admin_viewed)->toBe(0)
            ->and($reclamation->messages->first()->sender)->toBe(1)
            ->and($reclamation->messages->first()->attachments)->toHaveCount(2);

        $path = $reclamation->messages->first()->attachments->first()->file_path;
        expect($path)->toStartWith('uploads/reclamations/');
        Storage::disk('local')->assertExists('reclamations/'.basename($path));
    });

    it('refuse un message vide (400) et un type de fichier dangereux', function () {
        actingAsSeller();
        $this->postJson('/api/reclamations', ['message' => '   '])->assertStatus(400)->assertJsonPath('error', 'Message is required');
        $this->post('/api/reclamations', ['message' => 'ok', 'attachments' => [UploadedFile::fake()->create('virus.php', 1)]], ['Accept' => 'application/json'])
            ->assertStatus(400);
    });

    it('liste ses réclamations avec l\'aperçu du dernier message', function () {
        $seller = actingAsSeller();
        $r = Reclamation::factory()->create(['vendeur_id' => $seller->seller_id]);
        $r->messages()->create(['sender' => 1, 'message' => 'premier']);
        $this->travel(1)->seconds();
        $r->messages()->create(['sender' => 0, 'message' => 'réponse du support']);
        Reclamation::factory()->create();

        $this->getJson('/api/reclamations/seller')->assertOk()->assertJsonCount(1)->assertJsonPath('0.last_message', 'réponse du support');
    });

    it('lit les messages dans l\'ordre avec leurs pièces jointes', function () {
        $seller = actingAsSeller();
        $r = Reclamation::factory()->create(['vendeur_id' => $seller->seller_id]);
        $m = $r->messages()->create(['sender' => 1, 'message' => 'a']);
        $m->attachments()->create(['file_path' => 'uploads/reclamations/1-2.pdf', 'file_type' => 'application/pdf']);

        $this->getJson("/api/reclamations/{$r->id}/messages")
            ->assertOk()
            ->assertJsonPath('0.attachments.0.filename', '1-2.pdf');
    });

    it('répond, ce qui signale un nouveau message au support', function () {
        $seller = actingAsSeller();
        $r = Reclamation::factory()->create(['vendeur_id' => $seller->seller_id, 'admin_viewed' => 1]);

        $this->postJson("/api/reclamations/{$r->id}/reply", ['message' => 'relance'])->assertOk();
        expect($r->fresh()->admin_viewed)->toBe(0);
    });

    it('ne peut plus répondre à une réclamation résolue (409)', function () {
        $seller = actingAsSeller();
        $r = Reclamation::factory()->resolved()->create(['vendeur_id' => $seller->seller_id]);

        $this->postJson("/api/reclamations/{$r->id}/reply", ['message' => 'encore'])->assertStatus(409)->assertJsonPath('error', 'Reclamation already resolved');
    });

    it('ne résout que les réclamations ouvertes (400 pour une notification)', function () {
        $seller = actingAsSeller();
        $n = Reclamation::factory()->notification()->create(['vendeur_id' => $seller->seller_id]);

        $this->putJson("/api/reclamations/{$n->id}/resolve")->assertStatus(400);
    });

    it('marque une réclamation comme vue', function () {
        $seller = actingAsSeller();
        $n = Reclamation::factory()->notification()->create(['vendeur_id' => $seller->seller_id]);

        $this->putJson("/api/reclamations/{$n->id}/seen-by-seller")->assertOk();
        expect($n->fresh()->vendeur_viewed)->toBe(1);
    });
});

describe('Côté administration (clé x-admin-key)', function () {
    it('liste toutes les réclamations avec le vendeur', function () {
        $r = Reclamation::factory()->create();

        $this->getJson('/api/admin/reclamations', adminHeaders())
            ->assertOk()
            ->assertJsonPath('0.MarketplaceSeller.shop_title', $r->seller->shop_title);
    });

    it('affiche une réclamation avec ses messages, 404 sinon', function () {
        $r = Reclamation::factory()->create();
        $r->messages()->create(['sender' => 1, 'message' => 'bonjour']);

        $this->getJson("/api/admin/reclamations/{$r->id}", adminHeaders())->assertOk()->assertJsonPath('messages.0.message', 'bonjour');
        $this->getJson('/api/admin/reclamations/999', adminHeaders())->assertNotFound();
    });

    it('répond au vendeur et le prévient en temps réel', function () {
        Event::fake([SellerInboxUpdated::class]);
        $r = Reclamation::factory()->create(['vendeur_viewed' => 1]);

        $this->postJson("/api/admin/reclamations/{$r->id}/reply", ['message' => 'Nous regardons.'], adminHeaders())->assertOk();

        expect($r->fresh()->vendeur_viewed)->toBe(0)
            ->and($r->messages()->first()->sender)->toBe(0);
        Event::assertDispatched(SellerInboxUpdated::class, fn ($e) => $e->sellerId === $r->vendeur_id && $e->kind === 'reply'
            && $e->broadcastOn()[0]->name === 'private-seller.'.$r->vendeur_id);
    });

    it('résout et marque comme vue', function () {
        $r = Reclamation::factory()->create();

        $this->putJson("/api/admin/reclamations/{$r->id}/seen", [], adminHeaders())->assertOk();
        $this->putJson("/api/admin/reclamations/{$r->id}/resolve", [], adminHeaders())->assertOk();
        expect($r->fresh())->type->toBe(2)->admin_viewed->toBe(1);
    });

    it('envoie une notification à un vendeur existant', function () {
        Event::fake([SellerInboxUpdated::class]);
        $seller = Seller::factory()->create();

        $id = $this->postJson('/api/admin/notifications', ['seller_id' => $seller->seller_id, 'message' => 'Maintenance ce soir'], adminHeaders())
            ->assertOk()->json('notification_id');

        expect(Reclamation::find($id))->type->toBe(0)->vendeur_viewed->toBe(0);
        Event::assertDispatched(SellerInboxUpdated::class);

        $this->postJson('/api/admin/notifications', ['seller_id' => 9999, 'message' => 'x'], adminHeaders())->assertStatus(400);
    });
});

describe('Canal temps réel', function () {
    it('n\'autorise un vendeur que sur son propre canal', function () {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        // Les canaux sont déclarés sur le pilote actif au démarrage : on les redéclare sur Reverb.
        require base_path('routes/channels.php');
        $seller = actingAsSeller();

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-seller.'.$seller->seller_id])->assertOk();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-seller.99999'])->assertForbidden();
    });
});
