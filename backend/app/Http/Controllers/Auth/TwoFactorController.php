<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\SellerAuthService;
use App\Services\Auth\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestion de la double authentification par le vendeur connecté. */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SellerAuthService $auth,
    ) {}

    /** GET /api/profile/two-factor */
    public function status(Request $request): JsonResponse
    {
        $tf = $request->user()->twoFactor()->first();

        return response()->json([
            'enabled' => $tf?->confirmed_at !== null,
            'pending' => $tf !== null && $tf->confirmed_at === null,
            'recovery_codes_left' => $tf?->confirmed_at ? count($tf->recovery_codes ?? []) : 0,
        ]);
    }

    /** POST /api/profile/two-factor : démarre l'activation (mot de passe exigé). */
    public function enable(Request $request): JsonResponse
    {
        $seller = $request->user();
        $this->requirePassword($request);

        $tf = $this->twoFactor->enable($seller);
        $url = $this->twoFactor->otpauthUrl($seller, $tf);

        return response()->json([
            'secret' => $tf->secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrCodeSvg($url),
            'recovery_codes' => $tf->recovery_codes,
        ]);
    }

    /** POST /api/profile/two-factor/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $seller = $request->user();

        if (! $this->twoFactor->confirm($seller, $request->input('code'))) {
            throw new ApiException('Code de vérification invalide.', 422);
        }

        Audit::log('two_factor_enabled', 'Activation de la double authentification', $seller, $seller);

        return response()->json(['message' => 'Double authentification activée.']);
    }

    /** DELETE /api/profile/two-factor */
    public function disable(Request $request): JsonResponse
    {
        $seller = $request->user();
        $this->requirePassword($request);
        $this->twoFactor->disable($seller);

        Audit::log('two_factor_disabled', 'Désactivation de la double authentification', $seller, $seller);

        return response()->json(['message' => 'Double authentification désactivée.']);
    }

    /** POST /api/profile/two-factor/recovery-codes */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $seller = $request->user();
        $this->requirePassword($request);
        $tf = $seller->twoFactor()->first();
        if (! $tf?->confirmed_at) {
            throw new ApiException('La double authentification n\'est pas activée.', 409);
        }

        Audit::log('two_factor_recovery_regenerated', 'Nouveaux codes de secours', $seller, $seller);

        return response()->json(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($tf)]);
    }

    private function requirePassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! $this->auth->checkPassword($request->user(), (string) $request->input('password'))) {
            throw new ApiException('Mot de passe incorrect.', 422);
        }
    }
}
