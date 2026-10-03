<?php

namespace App\Http\Middleware;

use App\Support\Metrics\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte les requêtes HTTP et mesure leur durée, par méthode, route
 * (modèle de chemin, ex. api/reclamations/{id}/reply) et code de statut.
 */
class RecordHttpMetrics
{
    public function __construct(private readonly MetricsRegistry $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route()?->uri() ?? 'unmatched';
        if ($route === 'metrics') {
            return; // le scrape de Prometheus ne fausse pas les statistiques
        }

        $started = (float) $request->attributes->get('metrics_started_at', microtime(true));
        $method = $request->getMethod();

        $this->metrics->increment('http_requests_total', [
            'method' => $method,
            'route' => '/'.ltrim($route, '/'),
            'status' => (string) $response->getStatusCode(),
        ]);
        $this->metrics->observe('http_request_duration_seconds', ['method' => $method, 'route' => '/'.ltrim($route, '/')], microtime(true) - $started);
        $this->metrics->flush();
    }
}
