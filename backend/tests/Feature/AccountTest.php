<?php

/*
| Profil, mot de passe (tab. 2.4), mot de passe oublié et journal d'audit.
*/

use App\Models\Seller;
use App\Services\Auth\SellerAuthService;
use Database\Factories\SellerFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
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

it('révoque les autres jetons au changement de mot de passe et garde le jeton courant', function () {
    $seller = Seller::factory()->create();
    $login = fn () => $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])->json('accessToken');
    $current = $login();
    $other = $login();

    $this->withToken($current)->putJson('/api/profile/change-password', ['currentPassword' => SellerFactory::PASSWORD, 'newPassword' => 'Nouveau123'])->assertOk();

    expect($seller->tokens()->count())->toBe(1);
    $this->app['auth']->forgetGuards();
    $this->withToken($other)->getJson('/api/profile')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($current)->getJson('/api/profile')->assertOk();
});

it('supprime les autres sessions en base du vendeur et conserve la session courante', function () {
    config(['session.driver' => 'database']);
    $seller = Seller::factory()->create();
    $otherSeller = Seller::factory()->create();
    foreach (['session-courante' => $seller, 'autre-session' => $seller, 'session-autre-vendeur' => $otherSeller] as $id => $owner) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->seller_id, 'payload' => '', 'last_activity' => time()]);
    }

    app(SellerAuthService::class)->revokeOtherSessions($seller, null, 'session-courante');

    expect(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['session-autre-vendeur', 'session-courante']);
});

it('refuse un nouveau mot de passe de moins de 8 caractères (400)', function () {
    $seller = actingAsSeller();
    $this->putJson('/api/profile/change-password', ['currentPassword' => SellerFactory::PASSWORD, 'newPassword' => 'Court12'])
        ->assertStatus(400)->assertJsonPath('message', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
    expect(password_verify(SellerFactory::PASSWORD, $seller->fresh()->password_hash))->toBeTrue();
});

it('laisse se connecter un compte existant dont le mot de passe fait moins de 8 caractères', function () {
    $seller = Seller::factory()->create(['password_hash' => password_hash('abc123', PASSWORD_BCRYPT)]);

    $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'abc123'])->assertOk();
});

it('refuse une réinitialisation avec un mot de passe de moins de 8 caractères (400)', function () {
    Notification::fake();
    $seller = Seller::factory()->create();
    $this->postJson('/api/auth/forgot-password', ['email' => $seller->email]);

    $token = null;
    Notification::assertSentTo($seller, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $seller->email, 'password' => 'Court12', 'password_confirmation' => 'Court12'])
        ->assertStatus(400);
    $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => 'Court12'])->assertUnauthorized();
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
