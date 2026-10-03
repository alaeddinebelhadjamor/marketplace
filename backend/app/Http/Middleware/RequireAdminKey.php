<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège les routes d'administration de l'API (intégrations, scripts, exports)
 * par une clé partagée transmise dans l'en-tête x-admin-key.
 *
 *  - clé non configurée sur le serveur -> 503 (jamais d'accès ouvert par défaut)
 *  - clé absente ou incorrecte         -> 401
 * La comparaison se fait en temps constant (hash_equals).
 */
class RequireAdminKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('marketplace.admin_api_key');

        if ($expected === '') {
            return response()->json([
                'message' => "Accès administrateur désactivé : ADMIN_API_KEY n'est pas configurée sur le serveur.",
            ], 503);
        }

        $provided = (string) $request->header('x-admin-key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => "Clé d'administration manquante ou invalide."], 401);
        }

        $request->attributes->set('admin_key_authenticated', true);

        return $next($request);
    }
}
