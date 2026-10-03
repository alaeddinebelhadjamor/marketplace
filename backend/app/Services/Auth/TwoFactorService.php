<?php

namespace App\Services\Auth;

use App\Models\Seller;
use App\Models\SellerTwoFactor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification par code TOTP (Google Authenticator, Microsoft
 * Authenticator…) avec codes de secours à usage unique.
 */
class TwoFactorService
{
    private const RECOVERY_CODES = 8;

    private const CHALLENGE_TTL = 300; // secondes

    public function __construct(private readonly Google2FA $google2fa) {}

    /** Démarre l'activation : nouveau secret non confirmé et codes de secours. */
    public function enable(Seller $seller): SellerTwoFactor
    {
        return SellerTwoFactor::updateOrCreate(
            ['seller_id' => $seller->seller_id],
            [
                'secret' => $this->google2fa->generateSecretKey(32),
                'recovery_codes' => $this->newRecoveryCodes(),
                'confirmed_at' => null,
            ]
        );
    }

    /** Confirme l'activation avec un premier code valide. */
    public function confirm(Seller $seller, string $code): bool
    {
        $tf = $seller->twoFactor()->first();
        if (! $tf || ! $this->verifyCode($tf, $code)) {
            return false;
        }
        $tf->forceFill(['confirmed_at' => now()])->save();

        return true;
    }

    public function disable(Seller $seller): void
    {
        $seller->twoFactor()->delete();
    }

    public function verifyCode(SellerTwoFactor $tf, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return $code !== '' && $this->google2fa->verifyKey($tf->secret, $code, 1);
    }

    /** Consomme un code de secours (usage unique). */
    public function useRecoveryCode(SellerTwoFactor $tf, string $code): bool
    {
        $codes = $tf->recovery_codes ?? [];
        $index = array_search(trim($code), $codes, true);
        if ($index === false) {
            return false;
        }
        unset($codes[$index]);
        $tf->forceFill(['recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function regenerateRecoveryCodes(SellerTwoFactor $tf): array
    {
        $codes = $this->newRecoveryCodes();
        $tf->forceFill(['recovery_codes' => $codes])->save();

        return $codes;
    }

    public function otpauthUrl(Seller $seller, SellerTwoFactor $tf): string
    {
        $issuer = rawurlencode((string) config('app.name'));
        $label = $issuer.':'.rawurlencode($seller->email);

        return "otpauth://totp/{$label}?secret={$tf->secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function qrCodeSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }

    /** Crée un jeton d'étape « code 2FA attendu » après un mot de passe valide. */
    public function createChallenge(Seller $seller): string
    {
        $token = Str::random(64);
        Cache::put($this->challengeKey($token), $seller->seller_id, self::CHALLENGE_TTL);

        return $token;
    }

    /** Lit l'identifiant du vendeur associé au jeton d'étape, sans le consommer. */
    public function challengeSellerId(string $token): ?int
    {
        $id = Cache::get($this->challengeKey($token));

        return $id === null ? null : (int) $id;
    }

    public function forgetChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        return 'auth:2fa-challenge:'.hash('sha256', $token);
    }

    private function newRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
