<?php

/*
| Profil, mot de passe (tab. 2.4), mot de passe oublié et journal d'audit.
*/

use App\Models\Seller;
use Database\Factories\SellerFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('affiche le profil sans le hash du mot de passe', function () {
    $seller = actingAsSeller();

    $response = $this->getJson('/api/profile')->assertOk()->assertJsonPath('shop_title', $seller->shop_title)->assertJsonPath('two_factor_enabled', false);
    expect($response->getContent())->not->toContain('password_hash');
});

it('change le mot de passe si l\'ancien est correct', function () {
    $seller = actingAsSeller();

    $this->putJson('/api/profile/change-password', ['currentPassword' => SellerFactory::PASSWORD, 'newPassword' => 'Nouveau123'])
        ->assertOk()->assertJsonPath('message', 'Mot de passe modifié avec succès.');
    expect(password_verify('Nouveau123', $seller->fresh()->password_hash))->toBeTrue();
});

it('refuse un ancien mot de passe faux (401) ou des champs manquants (400)', function () {
    actingAsSeller();
    $this->putJson('/api/profile/change-password', ['currentPassword' => 'faux', 'newPassword' => 'Nouveau123'])
        ->assertUnauthorized()->assertJsonPath('message', 'Mot de passe actuel incorrect.');
    $this->putJson('/api/profile/change-password', ['newPassword' => 'Nouveau123'])
        ->assertStatus(400)->assertJsonPath('message', 'Champs requis manquants.');
});

it('envoie un lien de réinitialisation sans révéler si le compte existe', function () {
    Notification::fake();
    $seller = Seller::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $seller->email])->assertOk();
    $this->postJson('/api/auth/forgot-password', ['email' => 'inconnu@example.com'])->assertOk();

    Notification::assertSentTo($seller, ResetPassword::class, function (ResetPassword $n) use ($seller) {
        return str_starts_with($n->toMail($seller)->actionUrl, 'http://localhost:5180/reset-password?token=');
    });
});

it('réinitialise le mot de passe avec le jeton reçu, une seule fois', function () {
    Notification::fake();
    $seller = Seller::factory()->create();
    $this->postJson('/api/auth/forgot-password', ['email' => $seller->email]);

    $token = null;
    Notification::assertSentTo($seller, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $payload = ['token' => $token, 'email' => $seller->email, 'password' => 'Reinit123', 'password_confirmation' => 'Reinit123'];
    $this->postJson('/api/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);

    $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'Reinit123'])->assertOk();
});

it('trace les actions sensibles dans le journal d\'audit du vendeur', function () {
    $seller = Seller::factory()->create();
    $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'faux']);
    $token = $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])->json('accessToken');
    $this->withToken($token)->putJson('/api/profile/change-password', ['currentPassword' => SellerFactory::PASSWORD, 'newPassword' => 'Nouveau123']);

    $events = collect($this->withToken($token)->getJson('/api/profile/activity')->assertOk()->json())->pluck('event');
    expect($events->all())->toContain('login', 'password_changed');

    $this->assertDatabaseHas('activity_log', ['event' => 'login_failed']);
});
