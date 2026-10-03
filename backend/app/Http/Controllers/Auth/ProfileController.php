<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\SellerResource;
use App\Services\Auth\SellerAuthService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ProfileController extends Controller
{
    public function __construct(private readonly SellerAuthService $auth) {}

    /** GET /api/profile */
    public function show(Request $request): SellerResource
    {
        return new SellerResource($request->user()->load('twoFactor'));
    }

    /** PUT /api/profile/change-password */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $seller = $request->user();
        $this->auth->changePassword($seller, $request->input('currentPassword'), $request->input('newPassword'));

        Audit::log('password_changed', 'Changement du mot de passe', $seller, $seller);

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    /** GET /api/profile/activity : journal d'audit du vendeur (50 derniers événements). */
    public function activity(Request $request): JsonResponse
    {
        $seller = $request->user();

        $events = Activity::query()
            ->where('causer_type', $seller->getMorphClass())
            ->where('causer_id', $seller->seller_id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'ip' => $a->properties['ip'] ?? null,
                'details' => collect($a->properties ?? [])->except(['ip', 'user_agent'])->all(),
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json($events);
    }
}
