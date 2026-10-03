<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reclamations\MessageRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\ReclamationResource;
use App\Models\Reclamation;
use App\Models\ReclamationMessage;
use App\Services\Reclamations\ReclamationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Réclamations et notifications du vendeur connecté. */
class ReclamationController extends Controller
{
    public function __construct(private readonly ReclamationService $service) {}

    /** POST /api/reclamations */
    public function store(MessageRequest $request): JsonResponse
    {
        $reclamation = $this->service->open($request->user()->seller_id, $request->input('message'), $request->attachments());

        return response()->json(['success' => true, 'reclamation_id' => $reclamation->id]);
    }

    /** GET /api/reclamations/seller : toutes ses réclamations et notifications. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reclamations = Reclamation::query()
            ->ownedBy($request->user()->seller_id)
            ->addSelect(['last_message' => ReclamationMessage::query()
                ->select('message')
                ->whereColumn('reclamation_id', 'reclamations.id')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(1)])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return ReclamationResource::collection($reclamations);
    }

    /** GET /api/reclamations/{id}/messages */
    public function messages(Request $request, string $id): JsonResponse|AnonymousResourceCollection
    {
        $reclamation = $this->authorized($request, $id, 'view');
        if ($reclamation instanceof JsonResponse) {
            return $reclamation;
        }

        return MessageResource::collection($reclamation->messages()->with('attachments')->get());
    }

    /** POST /api/reclamations/{id}/reply */
    public function reply(MessageRequest $request, string $id): JsonResponse
    {
        $reclamation = $this->authorized($request, $id, 'reply');
        if ($reclamation instanceof JsonResponse) {
            return $reclamation;
        }

        $this->service->sellerReply($reclamation, $request->input('message'), $request->attachments());

        return response()->json(['success' => true]);
    }

    /** PUT /api/reclamations/{id}/seen-by-seller */
    public function markSeen(Request $request, string $id): JsonResponse
    {
        $reclamation = $this->authorized($request, $id, 'view');
        if ($reclamation instanceof JsonResponse) {
            return $reclamation;
        }

        $reclamation->forceFill(['vendeur_viewed' => 1])->save();

        return response()->json(['success' => true]);
    }

    /** PUT /api/reclamations/{id}/resolve */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $reclamation = $this->authorized($request, $id, 'resolve');
        if ($reclamation instanceof JsonResponse) {
            return $reclamation;
        }

        $this->service->resolveBySeller($reclamation);
        Audit::log('reclamation_resolved', 'Clôture d\'une réclamation par le vendeur', $request->user(), $reclamation);

        return response()->json(['success' => true]);
    }

    /**
     * Charge la réclamation et vérifie la propriété (policy). Identifiant
     * invalide, inexistant ou appartenant à un autre vendeur : même 404.
     */
    private function authorized(Request $request, string $id, string $ability): Reclamation|JsonResponse
    {
        $reclamation = ctype_digit($id) ? Reclamation::find((int) $id) : null;

        if (! $reclamation || Gate::forUser($request->user())->denies($ability, $reclamation)) {
            return response()->json(['message' => 'Reclamation not found', 'error' => 'Reclamation not found'], 404);
        }

        return $reclamation;
    }
}
