<?php

use App\Http\Controllers\Admin\CommissionAdminController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\ReclamationAdminController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ReclamationController;
use App\Http\Controllers\StatementController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la marketplace (préfixe /api)
|--------------------------------------------------------------------------
| Les chemins de la v1 sont conservés à l'identique (checklist R03 à R33).
| Protections : « auth.seller » (session SPA ou jeton Bearer),
| « admin.key » (en-tête x-admin-key), sinon route publique limitée en débit.
*/

// --- Authentification (public, limité en débit) -------------------------
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink']);
    Route::post('reset-password', [PasswordResetController::class, 'reset']);
});

// --- Espace vendeur --------------------------------------------------------
Route::middleware(['auth.seller', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile/change-password', [ProfileController::class, 'changePassword']);
    Route::get('profile/activity', [ProfileController::class, 'activity']);
    Route::get('profile/two-factor', [TwoFactorController::class, 'status']);
    Route::post('profile/two-factor', [TwoFactorController::class, 'enable']);
    Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm']);
    Route::delete('profile/two-factor', [TwoFactorController::class, 'disable']);
    Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);

    Route::prefix('magento')->group(function () {
        Route::post('product/add', [ProductController::class, 'store']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/pending', [ProductController::class, 'pending']);
        Route::get('products/url-key', [ProductController::class, 'urlKey']);
        Route::put('product/price', [ProductController::class, 'updatePrice']);
        Route::delete('product/delete', [ProductController::class, 'destroy']);

        Route::get('products/imports/template', [ProductImportController::class, 'template']);
        Route::get('products/imports', [ProductImportController::class, 'index']);
        Route::post('products/imports', [ProductImportController::class, 'store'])->middleware('throttle:imports');
        Route::get('products/imports/{id}', [ProductImportController::class, 'show'])->whereNumber('id');
        Route::get('products/imports/{id}/report', [ProductImportController::class, 'report'])->whereNumber('id');
    });

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/export', [OrderController::class, 'export']);
    Route::get('dashboard', [DashboardController::class, 'show']);

    Route::get('statements', [StatementController::class, 'index']);
    Route::get('statements/{period}/pdf', [StatementController::class, 'pdf'])->where('period', '\d{4}-\d{2}');

    Route::post('reclamations', [ReclamationController::class, 'store']);
    Route::get('reclamations/seller', [ReclamationController::class, 'index']);
    Route::get('reclamations/{reclamationId}/messages', [ReclamationController::class, 'messages']);
    Route::post('reclamations/{reclamationId}/reply', [ReclamationController::class, 'reply']);
    Route::put('reclamations/{id}/seen-by-seller', [ReclamationController::class, 'markSeen']);
    Route::put('reclamations/{id}/resolve', [ReclamationController::class, 'resolve']);
});

// --- Pièces jointes : vendeur propriétaire ou clé admin (contrôle interne) --
Route::middleware('throttle:api')->group(function () {
    Route::get('attachments/view/{filename}', [AttachmentController::class, 'view']);
    Route::get('attachments/download/{filename}', [AttachmentController::class, 'download']);
});

// --- Administration et intégrations (clé x-admin-key) -----------------------
Route::middleware(['admin.key', 'throttle:api'])->group(function () {
    Route::get('stats/sellers', [StatsController::class, 'sellers']);
    Route::get('stats/orders', [StatsController::class, 'orders']);
    Route::get('stats/all', [StatsController::class, 'all']);

    Route::get('export/excel', [ExportController::class, 'excel']);

    Route::get('admin/reclamations', [ReclamationAdminController::class, 'index']);
    Route::get('admin/reclamations/{id}', [ReclamationAdminController::class, 'show']);
    Route::post('admin/reclamations/{id}/reply', [ReclamationAdminController::class, 'reply']);
    Route::put('admin/reclamations/{id}/resolve', [ReclamationAdminController::class, 'resolve']);
    Route::put('admin/reclamations/{id}/seen', [ReclamationAdminController::class, 'markSeen']);
    Route::post('admin/notifications', [ReclamationAdminController::class, 'notify']);

    Route::put('admin/sellers/{sellerId}/commission-rate', [CommissionAdminController::class, 'setRate'])->whereNumber('sellerId');
    Route::post('admin/statements/generate', [CommissionAdminController::class, 'generate']);
    Route::get('admin/statements', [CommissionAdminController::class, 'index']);
    Route::put('admin/statements/{id}/paid', [CommissionAdminController::class, 'markPaid'])->whereNumber('id');
    Route::get('admin/statements/{id}/pdf', [CommissionAdminController::class, 'pdf'])->whereNumber('id');

    // Routes publiques en v1 (point C08) : désormais réservées aux intégrations.
    Route::get('magento/disabled-seller-products', [ProductController::class, 'disabledSellerProducts']);
    Route::post('magento/produits-consultes', [ProductController::class, 'markConsulted']);
});

// --- Autorisation des canaux temps réel (Reverb) -----------------------------
Broadcast::routes(['middleware' => ['auth.seller']]);
