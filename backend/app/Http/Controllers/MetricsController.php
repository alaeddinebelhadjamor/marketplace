<?php

namespace App\Http\Controllers;

use App\Support\Metrics\MetricsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /metrics : métriques au format texte de Prometheus (version 0.0.4).
 *
 * Techniques : requêtes HTTP, durées, appels Magento et OpenSearch.
 * Métier : vendeurs par statut, lignes de vente, jobs en file et en échec,
 * synchronisations, vues produit par jour (jauge reprise de la v1).
 */
class MetricsController extends Controller
{
    private const HELP = [
        'http_requests_total' => ['counter', 'Nombre de requêtes HTTP traitées'],
        'http_request_duration_seconds' => ['histogram', 'Durée des requêtes HTTP'],
        'external_requests_total' => ['counter', 'Appels aux services externes (Magento, OpenSearch)'],
        'external_request_duration_seconds' => ['histogram', 'Durée des appels aux services externes'],
        'marketplace_orders_synced_total' => ['counter', 'Lignes de commande insérées par la synchronisation Magento'],
        'marketplace_sync_runs_total' => ['counter', 'Exécutions des tâches de synchronisation'],
        'marketplace_behavior_events_total' => ['counter', 'Événements de navigation insérés depuis le journal Apache'],
        'marketplace_products_imported_total' => ['counter', 'Produits traités par l\'import en masse'],
    ];

    public function __invoke(MetricsRegistry $registry): Response
    {
        $out = [];

        try {
            $stored = $registry->stored();
        } catch (Throwable $e) {
            Log::warning('Lecture des métriques impossible', ['error' => $e->getMessage()]);
            $stored = [];
        }

        // Regroupe les séries _bucket/_sum/_count sous le nom de l'histogramme.
        $families = [];
        foreach ($stored as $name => $samples) {
            $family = preg_replace('/_(bucket|sum|count)$/', '', $name);
            $family = isset(self::HELP[$family]) ? $family : $name;
            $families[$family][$name] = $samples;
        }

        foreach ($families as $family => $series) {
            [$type, $help] = self::HELP[$family] ?? ['counter', $family];
            $out[] = "# HELP {$family} {$help}";
            $out[] = "# TYPE {$family} {$type}";
            foreach ($series as $name => $samples) {
                foreach ($samples as $s) {
                    $out[] = $name.$this->labels($s['labels']).' '.$this->number($s['value']);
                }
            }
        }

        foreach ($this->gauges() as $name => [$help, $samples]) {
            $out[] = "# HELP {$name} {$help}";
            $out[] = "# TYPE {$name} gauge";
            foreach ($samples as [$labels, $value]) {
                $out[] = $name.$this->labels($labels).' '.$this->number($value);
            }
        }

        return response(implode("\n", $out)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /** Jauges calculées à la lecture. */
    private function gauges(): array
    {
        $g = [
            'app_info' => ['Version de l\'application', [[['app' => 'marketplace-v2', 'php' => PHP_VERSION, 'laravel' => app()->version()], 1]]],
            'app_up' => ['L\'API répond', [[[], 1]]],
        ];

        try {
            $g['marketplace_sellers'] = ['Vendeurs par statut (0 attente, 1 validé, 2 refusé)', DB::table('marketplace_seller')
                ->selectRaw('status, count(*) as n')->groupBy('status')->get()
                ->map(fn ($r) => [['status' => (string) $r->status], (int) $r->n])->all()];
            $g['marketplace_order_lines'] = ['Lignes de vente synchronisées', [[[], DB::table('orders')->count()]]];
            $g['marketplace_open_reclamations'] = ['Réclamations ouvertes', [[[], DB::table('reclamations')->where('type', 1)->count()]]];
            $g['marketplace_queue_jobs'] = ['Jobs en attente dans la file', [[[], Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0]]];
            $g['marketplace_failed_jobs'] = ['Jobs en échec définitif', [[[], Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0]]];

            $lastSync = Cache::get('marketplace:last-orders-sync');
            $g['marketplace_last_orders_sync_timestamp_seconds'] = ['Horodatage de la dernière synchronisation des commandes réussie', [[[], $lastSync ? (int) $lastSync : 0]]];

            // Jauge de la v1 : vues par produit et par jour (90 derniers jours).
            $views = DB::table('user_product_behavior')
                ->selectRaw('product_sku, date(created_at) as day, count(*) as n')
                ->where('event_type', 'product_view')
                ->whereNotNull('product_sku')
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->groupBy('product_sku', 'day')
                ->get();
            $g['magento_product_views_total'] = ['Nombre de vues par produit par jour', $views
                ->map(fn ($r) => [['product_sku' => $r->product_sku, 'date' => (string) $r->day], (int) $r->n])->all()];
        } catch (Throwable $e) {
            Log::warning('Jauges métier indisponibles', ['error' => $e->getMessage()]);
        }

        return $g;
    }

    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $v = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
            $parts[] = "{$k}=\"{$v}\"";
        }

        return '{'.implode(',', $parts).'}';
    }

    private function number(float|int $v): string
    {
        return is_int($v) || floor($v) == $v ? (string) (int) $v : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }
}
