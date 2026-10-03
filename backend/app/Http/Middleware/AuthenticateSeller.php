<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie le vendeur, soit par la session de l'application Vue (cookie
 * HttpOnly, Sanctum en mode SPA), soit par un jeton « Bearer » pour les
 * clients d'API.
 *
 * Codes conservés de la v1 :
 *  - aucune authentification          -> 403 « Token manquant. »
 *  - en-tête Authorization mal formé  -> 403 « Token invalide. »
 *  - jeton expiré ou inconnu          -> 401 « Token expiré ou invalide. »
 * Ajout v2 : un compte qui n'est plus validé (refusé après coup) -> 403.
 */
class AuthenticateSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $seller = Auth::guard('sanctum')->user();

        if (! $seller instanceof Seller) {
            $header = (string) $request->header('Authorization', '');

            if ($header === '') {
                return response()->json(['message' => 'Token manquant.'], 403);
            }
            if (! preg_match('/^Bearer\s+\S+$/i', $header)) {
                return response()->json(['message' => 'Token invalide.'], 403);
            }

            return response()->json(['message' => 'Token expiré ou invalide.'], 401);
        }

        if (! $seller->isValidated()) {
            return response()->json(['message' => 'Compte non validé : accès refusé.'], 403);
        }

        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $seller);

        return $next($request);
    }
}
