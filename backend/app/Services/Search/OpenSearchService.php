<?php

namespace App\Services\Search;

use App\Support\Metrics\MetricsRegistry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Recherche dans OpenSearch. L'index utilisé est le plus récent qui suit le
 * motif configuré (opensearch_index_* par défaut) : une réindexation dans un
 * nouvel index ne demande aucune modification du code.
 */
class OpenSearchService
{
    public function __construct(private readonly MetricsRegistry $metrics) {}

    public function latestIndex(): string
    {
        $pattern = config('marketplace.opensearch.index_pattern');
        $response = $this->http()->get("_cat/indices/{$pattern}", ['h' => 'index', 's' => 'index:desc']);

        if (! $response->successful()) {
            throw new RuntimeException('OpenSearch : liste des index indisponible (HTTP '.$response->status().').');
        }

        $index = trim(strtok(trim($response->body()), "\n") ?: '');
        if ($index === '') {
            throw new RuntimeException("Aucun index OpenSearch ne correspond à {$pattern}.");
        }

        return $index;
    }

    /** Exécute une recherche et renvoie la réponse complète (hits.total.value compris). */
    public function search(array $body): array
    {
        $started = microtime(true);
        $status = 'error';

        try {
            $response = $this->http()->post($this->latestIndex().'/_search', $body);
            $status = (string) $response->status();

            if (! $response->successful() || ! is_array($response->json('hits.hits'))) {
                throw new RuntimeException('OpenSearch : recherche en échec (HTTP '.$response->status().').');
            }

            return $response->json();
        } finally {
            $this->metrics->observeExternalCall('opensearch', 'POST', $status, microtime(true) - $started);
        }
    }

    private function http()
    {
        return Http::baseUrl(config('marketplace.opensearch.url').'/')
            ->timeout((int) config('marketplace.opensearch.timeout'))
            ->acceptJson();
    }
}
