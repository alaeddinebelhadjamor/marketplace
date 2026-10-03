<?php

namespace App\Jobs;

use App\Models\ProductImport;
use App\Services\Import\ProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Traitement en arrière-plan d'un import de produits. */
class ImportProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $importId) {}

    public function handle(ProductImportService $service): void
    {
        $import = ProductImport::find($this->importId);
        if ($import && $import->status === ProductImport::QUEUED) {
            $service->run($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        ProductImport::query()->whereKey($this->importId)->update([
            'status' => ProductImport::FAILED,
            'failure_reason' => 'Traitement interrompu : '.($e?->getMessage() ?? 'erreur inconnue'),
            'finished_at' => now(),
        ]);
    }
}
