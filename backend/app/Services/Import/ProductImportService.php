<?php

namespace App\Services\Import;

use App\Exceptions\ApiException;
use App\Models\ProductImport;
use App\Services\Magento\MagentoProductService;
use App\Services\Reclamations\ReclamationService;
use App\Support\Metrics\MetricsRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Import de produits en masse.
 *
 * Chaque ligne est validée avec les mêmes règles que l'ajout unitaire, puis
 * créée dans Magento désactivée et rattachée au vendeur. Une ligne en erreur
 * n'empêche pas les autres ; le vendeur reçoit un rapport des erreurs.
 */
class ProductImportService
{
    public const COLUMNS = ['sku', 'name', 'price', 'weight', 'qty', 'short_description', 'description'];

    public const MAX_ROWS = 500;

    public function __construct(
        private readonly MagentoProductService $products,
        private readonly ReclamationService $reclamations,
        private readonly MetricsRegistry $metrics,
    ) {}

    public function run(ProductImport $import): void
    {
        $import->update(['status' => ProductImport::PROCESSING]);

        try {
            $rows = $this->readRows(Storage::disk('local')->path($import->stored_path));
        } catch (Throwable $e) {
            $this->finish($import, ProductImport::FAILED, 0, 0, [], 'Fichier illisible : '.$e->getMessage());

            return;
        }

        if (count($rows) > self::MAX_ROWS) {
            $this->finish($import, ProductImport::FAILED, count($rows), 0, [], 'Le fichier dépasse '.self::MAX_ROWS.' lignes.');

            return;
        }

        $errors = [];
        $success = 0;
        $seenSkus = [];

        foreach ($rows as $line => $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $message = $this->validate($row);

            if ($message === null && isset($seenSkus[mb_strtolower($sku)])) {
                $message = 'SKU en double dans le fichier (ligne '.$seenSkus[mb_strtolower($sku)].').';
            }

            if ($message === null) {
                try {
                    $this->products->createForSeller($this->toProduct($row), $import->seller_id);
                    $success++;
                    $seenSkus[mb_strtolower($sku)] = $line;
                    $this->metrics->increment('marketplace_products_imported_total', ['result' => 'created']);

                    continue;
                } catch (ApiException $e) {
                    $message = $e->getMessage();
                } catch (Throwable $e) {
                    $message = 'Erreur Magento : '.$e->getMessage();
                }
            }

            $errors[] = ['row' => $line, 'sku' => $sku, 'message' => $message];
            $this->metrics->increment('marketplace_products_imported_total', ['result' => 'rejected']);
        }

        $this->finish($import, ProductImport::DONE, count($rows), $success, $errors);
    }

    /** Modèle de fichier à remplir (en-têtes + une ligne d'exemple). */
    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Produits');
        $ws->fromArray([self::COLUMNS, ['SKU-EXEMPLE-01', 'Souris sans fil', 49.9, 0.2, 25, 'Souris 2,4 GHz', 'Description complète du produit']]);
        $ws->getStyle('A1:G1')->getFont()->setBold(true);
        foreach (range('A', 'G') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }

        return $book;
    }

    /** Rapport des lignes rejetées. */
    public function report(ProductImport $import): Spreadsheet
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Erreurs');
        $ws->fromArray([['Ligne', 'SKU', 'Erreur']]);
        $r = 2;
        foreach ($import->errors ?? [] as $e) {
            $ws->fromArray([[$e['row'], $e['sku'], $e['message']]], null, "A{$r}");
            $r++;
        }
        $ws->getStyle('A1:C1')->getFont()->setBold(true);
        $ws->getColumnDimension('A')->setWidth(8);
        $ws->getColumnDimension('B')->setWidth(24);
        $ws->getColumnDimension('C')->setWidth(80);

        return $book;
    }

    /** @return array<int, array> lignes indexées par numéro de ligne du fichier */
    private function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($sheet) ?? []);

        $missing = array_diff(['sku', 'name', 'price'], $header);
        if ($missing !== []) {
            throw new \RuntimeException('colonnes obligatoires absentes : '.implode(', ', $missing));
        }

        $rows = [];
        foreach ($sheet as $i => $values) {
            if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // ligne vide
            }
            $rows[$i + 2] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
        }

        return $rows;
    }

    private function validate(array $row): ?string
    {
        $validator = Validator::make($row, [
            'sku' => ['required', 'regex:'.config('marketplace.products.sku_pattern')],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'price' => ['required', 'numeric', 'gt:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'qty' => ['nullable', 'integer', 'min:0'],
        ], [
            'sku.required' => 'SKU obligatoire.',
            'sku.regex' => "SKU invalide : 3 à 64 caractères, lettres, chiffres, '.', '_' ou '-' uniquement.",
            'name.required' => 'Nom obligatoire.',
            'name.min' => 'Le nom du produit doit contenir entre 2 et 255 caractères.',
            'name.max' => 'Le nom du produit doit contenir entre 2 et 255 caractères.',
            'price.required' => 'Prix obligatoire.',
            'price.numeric' => 'Le prix doit être un nombre strictement positif.',
            'price.gt' => 'Le prix doit être un nombre strictement positif.',
            'weight.numeric' => 'Le poids doit être un nombre positif.',
            'qty.integer' => 'La quantité doit être un entier positif.',
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    private function toProduct(array $row): array
    {
        $attributes = [];
        foreach (['short_description', 'description'] as $code) {
            if (! empty($row[$code])) {
                $attributes[] = ['attribute_code' => $code, 'value' => (string) $row[$code]];
            }
        }

        return array_filter([
            'sku' => trim((string) $row['sku']),
            'name' => trim((string) $row['name']),
            'price' => (float) $row['price'],
            'weight' => isset($row['weight']) && $row['weight'] !== '' ? (float) $row['weight'] : null,
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'visibility' => 4,
            'extension_attributes' => ['stock_item' => [
                'qty' => (int) ($row['qty'] ?? 0) ?: 10000,
                'is_in_stock' => true,
                'manage_stock' => true,
            ]],
            'custom_attributes' => $attributes,
        ], fn ($v) => $v !== null);
    }

    private function finish(ProductImport $import, string $status, int $total, int $success, array $errors, ?string $reason = null): void
    {
        $import->update([
            'status' => $status,
            'total_rows' => $total,
            'success_rows' => $success,
            'failed_rows' => count($errors),
            'errors' => array_slice($errors, 0, self::MAX_ROWS),
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);
        $this->metrics->flush();

        $summary = $status === ProductImport::FAILED
            ? "L'import « {$import->original_name} » a échoué : {$reason}"
            : "Import « {$import->original_name} » terminé : {$success} produit(s) soumis à validation, ".count($errors).' ligne(s) en erreur.';

        try {
            $this->reclamations->notify($import->seller_id, $summary);
        } catch (Throwable) {
            // la notification est un plus : l'import reste consultable
        }
    }
}
