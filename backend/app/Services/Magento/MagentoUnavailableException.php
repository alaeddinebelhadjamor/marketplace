<?php

namespace App\Services\Magento;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Magento ne répond pas ou refuse l'authentification du compte de service. */
class MagentoUnavailableException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Le catalogue Magento est momentanément indisponible. Réessayez plus tard.',
        ], 503);
    }
}
