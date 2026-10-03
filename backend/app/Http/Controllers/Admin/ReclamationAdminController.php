<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reclamations\MessageRequest;
use App\Http\Requests\Reclamations\NotificationRequest;
use App\Http\Resources\ReclamationResource;
use App\Models\Reclamation;
use App\Models\ReclamationMessage;
use App\Services\Reclamations\ReclamationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Routes d'administration des réclamations (clé x-admin-key). Le traitement
 * humain se fait dans le module Magento ; ces routes servent aux intégrations.
 */
class ReclamationAdminController extends Controller
{
    public function __construct(private readonly ReclamationService $service) {}

    /** GET /api/admin/reclamations */
    public function index(): AnonymousResourceCollection
    {
        $reclamations = Reclamation::query()
            ->with('seller:seller_id,firstname,lastname,shop_title')
            ->addSelect(['last_message' => ReclamationMessage::query()
                ->select('message')
                ->whereColumn('reclamation_id', 'reclamations.id')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(1)])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return ReclamationResource::collection($reclamations);
    }

    /** GET /api/admin/reclamations/{id} */
    public function show(string $id): JsonResponse|ReclamationResource
    {
        $reclamation = $this->find($id);
        if (! $reclamation) {
            return $this->notFound();
        }

        return new ReclamationResource($reclamation->load(['seller', 'messages.attachments']));
    }

    /** POST /api/admin/reclamations/{id}/reply */
    public function reply(MessageRequest $request, string $id): JsonResponse
    {
        $reclamation = $this->find($id);
        if (! $reclamation) {
            return $this->notFound();
        }

        $this->service->adminReply($reclamation, $request->input('message'), $request->attachments());

        return response()->json(['success' => true]);
    }

    /** PUT /api/admin/reclamations/{id}/resolve */
    public function resolve(string $id): JsonResponse
    {
        $reclamation = $this->find($id);
        if (! $reclamation) {
            return $this->notFound();
        }

        $this->service->resolveByAdmin($reclamation);

        return response()->json(['success' => true]);
    }

    /** PUT /api/admin/reclamations/{id}/seen */
    public function markSeen(string $id): JsonResponse
    {
        $reclamation = $this->find($id);
        if (! $reclamation) {
            return $this->notFound();
        }

        $reclamation->forceFill(['admin_viewed' => 1])->save();

        return response()->json(['success' => true]);
    }

    /** POST /api/admin/notifications */
    public function notify(NotificationRequest $request): JsonResponse
    {
        $reclamation = $this->service->notify((int) $request->input('seller_id'), $request->input('message'), $request->attachments());

        return response()->json(['success' => true, 'notification_id' => $reclamation->id]);
    }

    private function find(string $id): ?Reclamation
    {
        return ctype_digit($id) ? Reclamation::find((int) $id) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Reclamation not found', 'error' => 'Reclamation not found'], 404);
    }
}
