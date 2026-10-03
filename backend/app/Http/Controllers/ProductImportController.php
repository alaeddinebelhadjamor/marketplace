<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Jobs\ImportProductsJob;
use App\Models\ProductImport;
use App\Services\Export\SpreadsheetWriter;
use App\Services\Import\ProductImportService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Import de produits en masse (Excel ou CSV) avec rapport d'erreurs. */
class ProductImportController extends Controller
{
    public function __construct(private readonly ProductImportService $service) {}

    /** GET /api/magento/products/imports */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            ProductImport::query()->where('seller_id', $request->user()->seller_id)->latest()->limit(20)->get()
                ->map(fn (ProductImport $i) => $this->present($i))
        );
    }

    /** POST /api/magento/products/imports (champ « file ») */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'],
        ], [
            'file.required' => 'Fichier obligatoire.',
            'file.extensions' => 'Format accepté : .xlsx, .xls ou .csv.',
            'file.max' => 'Le fichier doit faire moins de 5 Mo.',
        ]);

        $seller = $request->user();
        $file = $request->file('file');
        $path = $file->storeAs('imports', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        $import = ProductImport::create([
            'seller_id' => $seller->seller_id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'status' => ProductImport::QUEUED,
        ]);

        ImportProductsJob::dispatch($import->id);
        Audit::log('products_import_started', 'Import de produits en masse', $seller, $import);

        return response()->json($this->present($import->fresh()), 202);
    }

    /** GET /api/magento/products/imports/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->present($this->owned($request, $id)));
    }

    /** GET /api/magento/products/imports/{id}/report */
    public function report(Request $request, int $id, SpreadsheetWriter $writer): Response
    {
        $import = $this->owned($request, $id);

        return response($writer->toBinary($this->service->report($import)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="rapport_import_'.$import->id.'.xlsx"',
        ]);
    }

    /** GET /api/magento/products/imports/template */
    public function template(SpreadsheetWriter $writer): Response
    {
        return response($writer->toBinary($this->service->template()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modele_import_produits.xlsx"',
        ]);
    }

    private function owned(Request $request, int $id): ProductImport
    {
        $import = ProductImport::query()->whereKey($id)->where('seller_id', $request->user()->seller_id)->first();
        if (! $import) {
            throw new ApiException('Import introuvable', 404);
        }

        return $import;
    }

    private function present(ProductImport $i): array
    {
        return [
            'id' => $i->id,
            'original_name' => $i->original_name,
            'status' => $i->status,
            'total_rows' => $i->total_rows,
            'success_rows' => $i->success_rows,
            'failed_rows' => $i->failed_rows,
            'errors' => array_slice($i->errors ?? [], 0, 50),
            'failure_reason' => $i->failure_reason,
            'created_at' => $i->created_at?->toIso8601String(),
            'finished_at' => $i->finished_at?->toIso8601String(),
        ];
    }
}
