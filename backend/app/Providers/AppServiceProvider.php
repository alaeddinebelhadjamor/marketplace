<?php

namespace App\Providers;

use App\Models\ProductImport;
use App\Models\Reclamation;
use App\Models\Seller;
use App\Policies\MagentoProductPolicy;
use App\Policies\ReclamationPolicy;
use App\Support\Metrics\MetricsRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un seul registre par requête ou par job : les incréments sont cumulés puis écrits en une fois.
        $this->app->scoped(MetricsRegistry::class);
    }

    public function boot(): void
    {
        // Les tables existantes de la v1 sont partagées : interdiction de
        // migrate:fresh, migrate:reset, db:wipe… hors tests.
        DB::prohibitDestructiveCommands(! $this->app->environment('testing'));

        // Schéma des tables existantes, uniquement pour la base de test en mémoire.
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/legacy'));
        }

        Model::shouldBeStrict(! $this->app->isProduction());
        // Les colonnes calculées (effective_at, last_message) ne sont pas des attributs déclarés.
        Model::preventAccessingMissingAttributes(false);

        // Réponses JSON sans enveloppe « data », comme la v1.
        JsonResource::withoutWrapping();

        Relation::enforceMorphMap(['seller' => Seller::class, 'reclamation' => Reclamation::class, 'product_import' => ProductImport::class]);

        Gate::policy(Reclamation::class, ReclamationPolicy::class);
        Gate::define('update-product', [MagentoProductPolicy::class, 'update']);
        Gate::define('delete-product', [MagentoProductPolicy::class, 'delete']);
        Gate::define('view-product', [MagentoProductPolicy::class, 'view']);

        $this->configureRateLimiting();

        // Lien de réinitialisation du mot de passe : page de l'application Vue.
        ResetPassword::createUrlUsing(fn (Seller $seller, string $token) => config('marketplace.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($seller->email));

        // Les jobs (worker longue durée) écrivent leurs métriques à la fin de chaque job.
        Event::listen(JobProcessed::class, fn () => app(MetricsRegistry::class)->flush());
        Event::listen(JobFailed::class, fn () => app(MetricsRegistry::class)->flush());
    }

    private function configureRateLimiting(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
            ->response($tooMany('Trop de requêtes. Réessayez dans un instant.')));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)
            ->by($request->ip())
            ->response($tooMany('Trop de tentatives. Réessayez dans une minute.')));

        RateLimiter::for('imports', fn (Request $request) => Limit::perHour(10)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
            ->response($tooMany('Limite de 10 imports par heure atteinte.')));
    }
}
