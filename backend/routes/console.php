<?php

use App\Jobs\SyncDailyOrdersJob;
use App\Jobs\SyncProductViewsJob;
use App\Services\Behavior\ProductViewsSyncService;
use App\Services\Commission\CommissionService;
use App\Services\Recommendation\RecommenderEvaluator;
use App\Services\Recommendation\RecommenderScalingSimulator;
use App\Services\Recommendation\RecommenderTrainer;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Tâches planifiées (lancées par « php artisan schedule:work »)
|--------------------------------------------------------------------------
| Les cadences gardent les variables de la v1 (CRON_ORDERS_SYNC,
| CRON_PRODUCT_VIEWS). Les traitements partent dans la file d'attente :
| nouvelles tentatives automatiques et échecs consultables.
*/

$timezone = config('marketplace.schedule.timezone');

Schedule::job(new SyncDailyOrdersJob)
    ->cron(config('marketplace.schedule.orders_sync'))
    ->timezone($timezone)
    ->name('sync-orders')
    ->withoutOverlapping();

Schedule::job(new SyncProductViewsJob)
    ->cron(config('marketplace.schedule.product_views'))
    ->timezone($timezone)
    ->name('sync-product-views')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('marketplace.behavior.enabled'));

// Relevés de commission du mois écoulé, le 1er du mois à 02:00.
Schedule::call(fn () => app(CommissionService::class)->generateForPeriod(now()->subMonthNoOverflow()->format('Y-m')))
    ->monthlyOn(1, '02:00')
    ->timezone($timezone)
    ->name('payout-statements');

// Hygiène : jetons d'API expirés et jobs en échec anciens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();

// Ré-entraînement nocturne du moteur de recommandation (ALS + embeddings de
// contenu), même cadence que la v1 (CRON_RECOMMENDER_TRAIN, 3h du matin par
// défaut). Pas de rechargement à chaud à déclencher : chaque requête PHP
// relit le fichier le plus récent (voir RecommendationService::loadVectorModel).
Schedule::command('marketplace:train-recommender')
    ->cron(config('marketplace.schedule.recommender_train'))
    ->timezone($timezone)
    ->name('train-recommender')
    ->withoutOverlapping();

Schedule::command('marketplace:build-embeddings')
    ->cron(config('marketplace.schedule.recommender_train'))
    ->timezone($timezone)
    ->name('build-embeddings')
    ->withoutOverlapping();

/*
| Commandes manuelles
*/

Artisan::command('marketplace:sync-orders {--date= : Jour à synchroniser (AAAA-MM-JJ), aujourd\'hui par défaut}', function (OrderSyncService $sync) {
    $day = $this->option('date') ? Carbon::parse($this->option('date'), 'UTC') : null;
    $result = $sync->syncDay($day);
    $this->info("Commandes lues : {$result['orders']}, lignes insérées : {$result['inserted']}, vendeurs notifiés : {$result['notified']}.");
})->purpose('Synchronise maintenant les commandes Magento complete du jour');

Artisan::command('marketplace:sync-product-views', function (ProductViewsSyncService $sync) {
    $result = $sync->sync();
    $this->info("Événements insérés : {$result['inserted']}, lignes ignorées : {$result['skipped']}, offset : {$result['offset']}.");
})->purpose('Lit maintenant le journal Apache de Magento (suivi comportemental)');

Artisan::command('marketplace:statements {period : Mois AAAA-MM}', function (CommissionService $commissions) {
    $count = $commissions->generateForPeriod($this->argument('period'));
    $this->info("{$count} relevé(s) générés pour {$this->argument('period')}.");
})->purpose('Génère les relevés de commission d\'un mois');

Artisan::command('marketplace:train-recommender', function (RecommenderTrainer $trainer) {
    $result = $trainer->train();
    $this->info("Modèle ALS entraîné : {$result['n_baskets']} paniers, {$result['n_items']} SKUs.");
})->purpose('Entraîne le modèle de recommandation (ALS) sur les commandes existantes');

Artisan::command('marketplace:build-embeddings', function () {
    $node = config('recommender.embeddings.node_binary');
    $script = config('recommender.embeddings.script_path');

    if (! is_file($script)) {
        $this->error("Script introuvable : {$script}");

        return self::FAILURE;
    }

    $process = new Process([$node, basename($script)], dirname($script));
    $process->setTimeout(900);
    $process->run(function ($type, $buffer) {
        $this->output->write($buffer);
    });

    if (! $process->isSuccessful()) {
        $this->error('Échec de la génération des embeddings.');

        return self::FAILURE;
    }

    $this->info('Embeddings de contenu régénérés.');
})->purpose('Régénère les embeddings sémantiques de contenu (script Node, inférence seule)');

Artisan::command('marketplace:evaluate-recommender', function (RecommenderEvaluator $evaluator) {
    $result = $evaluator->evaluate();
    $this->info("{$result['n_baskets']} paniers au total, {$result['n_evaluable_baskets']} exploitables (>=2 SKUs distincts).");
    $this->info("{$result['n_train_baskets']} paniers d'entraînement, {$result['n_test_baskets']} paniers de test -> {$result['n_test_pairs']} paires (seed, target) à prédire.");
    if (! $result['content_model_available']) {
        $this->warn('content_embeddings.json absent : la colonne "hybride" sera identique à "ALS seul".');
    }

    $this->table(
        ['K', 'ALS seul', 'Hybride (ALS+contenu)', 'Baseline popularité', 'Paires évaluables'],
        collect($result['rows'])->map(fn ($r) => [
            $r['k'],
            number_format($r['als_rate'] * 100, 1).' %',
            number_format($r['hybrid_rate'] * 100, 1).' %',
            number_format($r['pop_rate'] * 100, 1).' %',
            $r['evaluable'],
        ])->all()
    );

    $best = $result['best'];
    $this->info("Meilleur K (critère hybride) : {$best['k']} -- Hybride=".number_format($best['hybrid_rate'] * 100, 1).'% ALS seul='.number_format($best['als_rate'] * 100, 1).'% Baseline='.number_format($best['pop_rate'] * 100, 1).'%');
})->purpose('Évalue le moteur par séparation entraînement/test (Hit Rate@10, K sweep)');

Artisan::command('marketplace:simulate-recommender-scaling', function (RecommenderScalingSimulator $simulator) {
    $this->warn('SIMULATION : commandes synthétiques générées en mémoire, rien n\'est écrit en base.');

    $results = $simulator->simulate();

    $this->table(
        ['Volume simulé', 'Hit Rate@10 (ALS)', 'Baseline popularité', 'Paires évaluables'],
        collect($results)->map(fn ($r) => [
            $r['volume'],
            number_format($r['hit_rate'] * 100, 1).' %',
            number_format($r['pop_rate'] * 100, 1).' %',
            $r['evaluable'],
        ])->all()
    );
})->purpose('Simule un volume de commandes croissant pour estimer l\'effet du volume de données sur le Hit Rate@10 (ALS vs popularité)');
