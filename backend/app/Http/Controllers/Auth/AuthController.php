<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Seller;
use App\Services\Auth\SellerAuthService;
use App\Services\Auth\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    public function __construct(
        private readonly SellerAuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /** POST /api/auth/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $seller = $this->auth->register($request->sellerAttributes(), (string) $request->input('password'));

        Audit::log('register', 'Inscription d\'un vendeur', $seller, $seller);

        return response()->json(['message' => 'Inscription réussie ! En attente de validation.'], 201);
    }

    /** POST /api/auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:'.$request->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw new ApiException("Trop de tentatives de connexion. Réessayez dans {$seconds} secondes.", 429);
        }

        try {
            $seller = $this->auth->attempt((string) $request->input('identifier'), (string) $request->input('password'));
        } catch (ApiException $e) {
            if ($e->status() === 401) {
                RateLimiter::hit($key, 60);
                Audit::log('login_failed', 'Échec de connexion', null, null, ['identifier' => $request->input('identifier')]);
            }
            throw $e;
        }

        RateLimiter::clear($key);

        if ($seller->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor' => true,
                'challenge_token' => $this->twoFactor->createChallenge($seller),
                'message' => 'Code de double authentification requis.',
            ]);
        }

        return response()->json($this->completeLogin($request, $seller));
    }

    /** POST /api/auth/two-factor-challenge */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $key = 'two-factor:'.hash('sha256', $data['challenge_token']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            throw new ApiException('Trop de tentatives. Reconnectez-vous.', 429);
        }

        $sellerId = $this->twoFactor->challengeSellerId($data['challenge_token']);
        $seller = $sellerId ? Seller::with('twoFactor')->find($sellerId) : null;
        if (! $seller || ! $seller->hasTwoFactorEnabled()) {
            throw new ApiException('Session de connexion expirée. Reconnectez-vous.', 401);
        }

        $valid = ! empty($data['code'])
            ? $this->twoFactor->verifyCode($seller->twoFactor, $data['code'])
            : (! empty($data['recovery_code']) && $this->twoFactor->useRecoveryCode($seller->twoFactor, $data['recovery_code']));

        if (! $valid) {
            RateLimiter::hit($key, 300);
            Audit::log('two_factor_failed', 'Code de double authentification refusé', $seller, $seller);
            throw new ApiException('Code de vérification invalide.', 401);
        }

        $this->twoFactor->forgetChallenge($data['challenge_token']);
        RateLimiter::clear($key);

        return response()->json($this->completeLogin($request, $seller));
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $seller = $request->user();
        $token = $seller?->currentAccessToken();

        // Jeton d'API : on le révoque. Session SPA : on ferme la session.
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Audit::log('logout', 'Déconnexion', $seller);

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    /**
     * Termine la connexion. Application Vue (requête « stateful ») : session
     * dans un cookie HttpOnly, aucun jeton renvoyé au navigateur. Client d'API :
     * jeton Sanctum valable 24 h, comme le JWT de la v1.
     */
    private function completeLogin(Request $request, Seller $seller): array
    {
        $token = null;

        if ($request->hasSession() && EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            Auth::guard('web')->login($seller);
            $request->session()->regenerate();
        } else {
            $token = $seller->createToken('api', ['*'], now()->addMinutes((int) config('sanctum.expiration', 1440)))->plainTextToken;
        }

        Audit::log('login', 'Connexion', $seller, $seller);

        return [
            'id' => $seller->seller_id,
            'shop_title' => $seller->shop_title,
            'accessToken' => $token,
        ];
    }
}
