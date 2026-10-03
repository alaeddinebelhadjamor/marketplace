<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StatementController;
use App\Models\PayoutStatement;
use App\Models\Seller;
use App\Services\Commission\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administration des commissions et relevés (clé x-admin-key). */
class CommissionAdminController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    /** PUT /api/admin/sellers/{sellerId}/commission-rate  { rate: 0.05 | null } */
    public function setRate(Request $request, int $sellerId): JsonResponse
    {
        $request->validate(['rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:0.5']]);
        if (! Seller::query()->whereKey($sellerId)->exists()) {
            throw new ApiException('Vendeur introuvable', 404);
        }

        $this->commissions->setRate($sellerId, $request->input('rate') === null ? null : (float) $request->input('rate'));

        return response()->json(['seller_id' => $sellerId, 'rate' => $this->commissions->rateFor($sellerId)]);
    }

    /** POST /api/admin/statements/generate  { period: AAAA-MM } */
    public function generate(Request $request): JsonResponse
    {
        $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $count = $this->commissions->generateForPeriod($request->input('period'));

        return response()->json(['period' => $request->input('period'), 'statements' => $count]);
    }

    /** GET /api/admin/statements?period=AAAA-MM */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $query = PayoutStatement::query()->with('seller:seller_id,shop_title')->orderByDesc('period')->orderBy('seller_id');
        if ($request->filled('period')) {
            $query->where('period', $request->input('period'));
        }

        return response()->json($query->get()->map(fn (PayoutStatement $s) => StatementController::presentStatement($s) + [
            'shop_title' => $s->seller?->shop_title,
        ]));
    }

    /** PUT /api/admin/statements/{id}/paid */
    public function markPaid(int $id): JsonResponse
    {
        $statement = PayoutStatement::find($id) ?? throw new ApiException('Relevé introuvable', 404);
        $statement->update(['status' => PayoutStatement::STATUS_PAID, 'paid_at' => now()]);

        return response()->json(StatementController::presentStatement($statement));
    }

    /** GET /api/admin/statements/{id}/pdf */
    public function pdf(int $id): Response
    {
        $statement = PayoutStatement::with('seller')->find($id) ?? throw new ApiException('Relevé introuvable', 404);

        return StatementController::renderPdf($statement, $statement->seller, $this->commissions);
    }
}
