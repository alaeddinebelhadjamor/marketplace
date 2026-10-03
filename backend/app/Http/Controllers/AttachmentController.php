<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Services\Reclamations\AttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pièces jointes des réclamations.
 *
 * Correctif de sécurité v2 (point C09) : dans la v1 ces routes étaient
 * publiques et un nom de fichier contenant « ..%2F » permettait de lire
 * d'autres fichiers du serveur. Ici, le nom est strictement contrôlé et seul
 * le vendeur propriétaire (session ou jeton) ou un appel avec la clé admin y
 * accède.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /** GET /api/attachments/view/{filename} : affichage dans le navigateur. */
    public function view(Request $request, string $filename): Response
    {
        return $this->serve($request, $filename, false);
    }

    /** GET /api/attachments/download/{filename} : téléchargement. */
    public function download(Request $request, string $filename): Response
    {
        return $this->serve($request, $filename, true);
    }

    private function serve(Request $request, string $filename, bool $download): Response
    {
        $isAdmin = $this->hasAdminKey($request);
        $seller = $isAdmin ? null : Auth::guard('sanctum')->user();

        if (! $isAdmin && ! $seller instanceof Seller) {
            return response()->json(['message' => 'Token manquant.'], 403);
        }

        $record = $this->storage->findRecord($filename);
        $path = $record ? $this->storage->locate($filename) : null;

        $ownerId = $record?->message?->reclamation?->vendeur_id;
        $allowed = $isAdmin || ($seller && (int) $ownerId === (int) $seller->seller_id);

        if (! $record || ! $path || ! $allowed) {
            return $this->notFound();
        }

        $headers = [
            'Content-Type' => $this->storage->mimeOf($filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ];

        return $download
            ? response()->download($path, $filename, $headers)
            : response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }

    private function hasAdminKey(Request $request): bool
    {
        $expected = (string) config('marketplace.admin_api_key');
        $provided = (string) $request->header('x-admin-key', '');

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'File not found', 'error' => 'File not found'], 404);
    }
}
