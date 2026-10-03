<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\PayoutStatement;
use App\Models\Seller;
use App\Services\Commission\CommissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/** Relevés de paiement du vendeur connecté. */
class StatementController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    /** GET /api/statements */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();

        return response()->json([
            'commission_rate' => $this->commissions->rateFor($seller->seller_id),
            'statements' => PayoutStatement::query()->where('seller_id', $seller->seller_id)
                ->orderByDesc('period')->get()->map(fn (PayoutStatement $s) => $this->present($s)),
        ]);
    }

    /** GET /api/statements/{period}/pdf  (période AAAA-MM) */
    public function pdf(Request $request, string $period): Response
    {
        $seller = $request->user();
        $statement = PayoutStatement::query()->where('seller_id', $seller->seller_id)->where('period', $period)->first();
        if (! $statement) {
            throw new ApiException('Relevé introuvable', 404);
        }

        return self::renderPdf($statement, $seller, $this->commissions);
    }

    public static function renderPdf(PayoutStatement $statement, Seller $seller, CommissionService $commissions): Response
    {
        $start = Carbon::createFromFormat('Y-m-d', $statement->period.'-01');
        $orders = $commissions->ordersOf($seller->seller_id, $start->toDateString(), $start->copy()->endOfMonth()->toDateString());

        $pdf = Pdf::loadView('pdf.payout-statement', [
            'statement' => $statement,
            'seller' => $seller,
            'orders' => $orders,
            'periodLabel' => ucfirst($start->locale('fr')->translatedFormat('F Y')),
        ])->setPaper('a4');

        return $pdf->download("releve_{$seller->seller_id}_{$statement->period}.pdf");
    }

    public static function presentStatement(PayoutStatement $s): array
    {
        return [
            'id' => $s->id,
            'seller_id' => $s->seller_id,
            'period' => $s->period,
            'orders_count' => $s->orders_count,
            'items_qty' => $s->items_qty,
            'gross' => $s->gross,
            'commission_rate' => $s->commission_rate,
            'commission' => $s->commission,
            'net' => $s->net,
            'status' => $s->status,
            'paid_at' => $s->paid_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    private function present(PayoutStatement $s): array
    {
        return self::presentStatement($s);
    }
}
