<?php

/*
| Import de produits en masse avec rapport d'erreurs.
*/

use App\Models\ProductImport;
use App\Models\Reclamation;
use App\Models\Seller;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function csvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('produits.csv', $content);
}

it('crée les lignes valides, rejette les autres et produit un rapport', function () {
    $seller = actingAsSeller();
    fakeMagento([
        'products/EXISTE-1' => Http::response(magentoProduct('EXISTE-1', 1)),
        'products/*' => Http::response([], 404),
        'products' => Http::response(['sku' => 'ok']),
    ]);

    $csv = "sku,name,price,weight,qty,short_description,description\n"
        ."OK-1,Souris,49.9,0.2,10,Court,Long\n"
        ."OK-2,Clavier,89,,,,\n"
        ."mauvais sku,Produit,10,,,,\n"
        ."OK-3,X,10,,,,\n"
        ."OK-4,Écran,-5,,,,\n"
        ."EXISTE-1,Déjà là,10,,,,\n"
        ."OK-1,Doublon,10,,,,\n";

    $id = $this->post('/api/magento/products/imports', ['file' => csvUpload($csv)], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('id');

    $import = ProductImport::find($id);
    expect($import)->status->toBe('done')->total_rows->toBe(7)->success_rows->toBe(2)->failed_rows->toBe(5);
    expect(collect($import->errors)->pluck('row')->all())->toBe([4, 5, 6, 7, 8]);
    expect(collect($import->errors)->firstWhere('row', 7)['message'])->toBe('Un produit avec le même SKU existe déjà');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/products')
        && $r['product']['status'] === 2
        && collect($r['product']['custom_attributes'])->firstWhere('attribute_code', 'seller_id')['value'] === $seller->seller_id);

    $this->getJson("/api/magento/products/imports/{$id}")->assertOk()->assertJsonPath('success_rows', 2);
    $this->get("/api/magento/products/imports/{$id}/report")->assertOk()->assertDownload("rapport_import_{$id}.xlsx");

    // Le vendeur reçoit une notification de fin d'import.
    expect(Reclamation::where('vendeur_id', $seller->seller_id)->where('type', 0)->count())->toBe(1);
});

it('signale un fichier sans les colonnes obligatoires', function () {
    actingAsSeller();
    fakeMagento();

    $id = $this->post('/api/magento/products/imports', ['file' => csvUpload("ref,libelle\nA,B\n")], ['Accept' => 'application/json'])->json('id');

    expect(ProductImport::find($id))->status->toBe('failed')->failure_reason->toContain('sku');
});

it('refuse un format non accepté', function () {
    actingAsSeller();
    $this->post('/api/magento/products/imports', ['file' => UploadedFile::fake()->create('x.exe', 1)], ['Accept' => 'application/json'])->assertStatus(400);
});

it('fournit un modèle de fichier et protège les imports des autres vendeurs', function () {
    $other = Seller::factory()->create();
    $import = ProductImport::create(['seller_id' => $other->seller_id, 'original_name' => 'a.csv', 'stored_path' => 'imports/a.csv']);

    actingAsSeller();
    $this->get('/api/magento/products/imports/template')->assertOk()->assertDownload('modele_import_produits.xlsx');
    $this->getJson("/api/magento/products/imports/{$import->id}")->assertNotFound();
});
