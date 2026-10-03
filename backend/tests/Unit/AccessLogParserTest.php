<?php

/*
| Classement des lignes du journal Apache (tests T4-01 à T4-04, automatisés).
*/

use App\Services\Behavior\AccessLogParser;

function logLine(string $path, int $status = 200, string $ua = 'Mozilla/5.0 (Windows NT 10.0)', string $ref = '-'): string
{
    return '41.226.10.5 - - [03/Oct/2026:10:15:32 +0100] "GET '.$path.' HTTP/1.1" '.$status.' 5120 "'.$ref.'" "'.$ua.'"';
}

it('classe une recherche interne (T4-01)', function () {
    $e = (new AccessLogParser)->parse(logLine('/myteksearch/index/productsearch/?q=pc+portable%20hp'));

    expect($e)->type->toBe('search')->search_query->toBe('pc portable hp')->source->toBe('direct');
});

it('classe une page .html à résoudre (T4-02 et T4-03)', function () {
    $e = (new AccessLogParser)->parse(logLine('/ecran-samsung-24.html', ua: 'Mozilla/5.0 (iPhone)', ref: 'https://google.com'));

    expect($e)->type->toBe('html')->slug->toBe('ecran-samsung-24')->device_type->toBe('mobile')->source->toBe('https://google.com');
});

it('exclut les pages techniques, les sous-dossiers et les erreurs (T4-04)', function (string $line) {
    expect((new AccessLogParser)->parse($line))->toBeNull();
})->with([
    'panier' => [logLine('/checkout/cart.html')],
    'compte client' => [logLine('/customer-account.html')],
    'sous-dossier' => [logLine('/informatique/pc.html')],
    'statut 404' => [logLine('/produit.html', 404)],
    'image' => [logLine('/media/logo.png')],
    'ligne vide' => [''],
]);
