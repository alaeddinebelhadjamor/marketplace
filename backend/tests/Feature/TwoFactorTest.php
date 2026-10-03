<?php

/*
| Double authentification (TOTP) et codes de secours.
*/

use App\Models\Seller;
use Database\Factories\SellerFactory;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

function enableTwoFactor(Seller $seller): array
{
    Sanctum::actingAs($seller);
    $setup = test()->postJson('/api/profile/two-factor', ['password' => SellerFactory::PASSWORD])->assertOk()->json();
    $code = (new Google2FA)->getCurrentOtp($setup['secret']);
    test()->postJson('/api/profile/two-factor/confirm', ['code' => $code])->assertOk();

    return $setup;
}

it('active la 2FA après confirmation d\'un premier code, avec QR code et codes de secours', function () {
    $seller = Seller::factory()->create();
    $setup = enableTwoFactor($seller);

    expect($setup['qr_svg'])->toContain('<svg')
        ->and($setup['otpauth_url'])->toStartWith('otpauth://totp/')
        ->and($setup['recovery_codes'])->toHaveCount(8)
        ->and($seller->fresh()->hasTwoFactorEnabled())->toBeTrue();

    // Le secret est chiffré en base.
    expect(DB::table('seller_two_factor')->value('secret'))->not->toBe($setup['secret']);
});

it('exige le mot de passe pour activer, et refuse un mauvais code', function () {
    actingAsSeller();
    $this->postJson('/api/profile/two-factor', ['password' => 'faux'])->assertStatus(422);
    $this->postJson('/api/profile/two-factor', ['password' => SellerFactory::PASSWORD])->assertOk();
    $this->postJson('/api/profile/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
});

it('demande le code à la connexion puis termine la connexion avec un code valide', function () {
    $seller = Seller::factory()->create();
    $setup = enableTwoFactor($seller);
    app('auth')->forgetGuards();

    $challenge = $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])
        ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissingPath('accessToken')->json('challenge_token');

    $this->postJson('/api/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => '123456'])->assertUnauthorized();

    $code = (new Google2FA)->getCurrentOtp($setup['secret']);
    $this->postJson('/api/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $code])
        ->assertOk()->assertJsonPath('id', $seller->seller_id);

    // Le jeton d'étape est à usage unique.
    $this->postJson('/api/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $code])->assertUnauthorized();
});

it('accepte un code de secours une seule fois', function () {
    $seller = Seller::factory()->create();
    $setup = enableTwoFactor($seller);
    app('auth')->forgetGuards();
    $recovery = $setup['recovery_codes'][0];

    $login = fn () => $this->postJson('/api/auth/login', ['identifier' => $seller->email, 'password' => SellerFactory::PASSWORD])->json('challenge_token');

    $this->postJson('/api/auth/two-factor-challenge', ['challenge_token' => $login(), 'recovery_code' => $recovery])->assertOk();
    $this->postJson('/api/auth/two-factor-challenge', ['challenge_token' => $login(), 'recovery_code' => $recovery])->assertUnauthorized();
});

it('désactive la 2FA avec le mot de passe', function () {
    $seller = Seller::factory()->create();
    enableTwoFactor($seller);

    $this->deleteJson('/api/profile/two-factor', ['password' => SellerFactory::PASSWORD])->assertOk();
    expect($seller->fresh()->hasTwoFactorEnabled())->toBeFalse();
});
