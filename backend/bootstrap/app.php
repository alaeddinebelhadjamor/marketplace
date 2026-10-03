<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\MetricsController;
use App\Http\Middleware\AuthenticateSeller;
use App\Http\Middleware\RecordHttpMetrics;
use App\Http\Middleware\RequireAdminKey;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Routes sans session ni CSRF.
            Route::get('/', fn () => response()->json([
                'message' => "Bienvenue sur l'API Marketplace Mytek (Laravel).",
                'documentation' => url('/docs/api'),
            ]));
            Route::get('/metrics', MetricsController::class);

            require __DIR__.'/../routes/channels.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Application Vue : authentification par cookie de session (Sanctum SPA).
        $middleware->statefulApi();

        $middleware->alias([
            'auth.seller' => AuthenticateSeller::class,
            'admin.key' => RequireAdminKey::class,
        ]);

        $middleware->append(SecurityHeaders::class);
        $middleware->append(RecordHttpMetrics::class);

        $middleware->trustProxies(at: '127.0.0.1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Erreurs métier attendues (409, 401…) : réponse au client, pas d'entrée dans le journal d'erreurs.
        $exceptions->dontReport([ApiException::class]);

        // Données invalides : 400 avec un message lisible, comme la v1.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => $e->validator->errors()->first(),
                    'errors' => $e->errors(),
                ], 400);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request) && ! $e->getPrevious()) {
                return response()->json(['message' => $e->getMessage() ?: 'Ressource introuvable.'], 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['message' => 'Méthode HTTP non autorisée sur cette route.'], 405);
            }
        });
    })->create();
