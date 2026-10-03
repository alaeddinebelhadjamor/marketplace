<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Services\Auth\SellerAuthService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Mot de passe oublié : envoi d'un lien par email puis réinitialisation.
 * La réponse à la demande de lien est identique que l'email existe ou non,
 * pour ne pas révéler les comptes existants.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly SellerAuthService $auth) {}

    /** POST /api/auth/forgot-password */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $status = Password::broker('sellers')->sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            throw new ApiException('Veuillez patienter avant de redemander un lien.', 429);
        }

        return response()->json([
            'message' => 'Si un compte correspond à cet email, un lien de réinitialisation vient d\'être envoyé.',
        ]);
    }

    /** POST /api/auth/reset-password */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ]);

        $status = Password::broker('sellers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Seller $seller, string $password) {
                $this->auth->setPassword($seller, $password);
                $seller->tokens()->delete();
                Audit::log('password_reset', 'Réinitialisation du mot de passe par email', $seller, $seller);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('Lien de réinitialisation invalide ou expiré.', 422);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.']);
    }
}
