<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Search;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Mytek\Marketplace\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Accès HTTP à OpenSearch pour l'index de la marketplace. Mapping dynamique : aucune création
 * explicite de mapping n'est nécessaire, OpenSearch déduit text+keyword pour les chaînes et
 * long/double pour les nombres à la première indexation (voir ProductDocumentMapper).
 */
class OpenSearchIndexClient
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return string[] noms des index existants qui commencent par le préfixe configuré, triés */
    public function listIndices(): array
    {
        $response = $this->request('GET', '_cat/indices/' . $this->config->getOpenSearchIndexPrefix() . '_*', [
            'query' => ['h' => 'index', 's' => 'index'],
        ]);
        $body = trim((string)$response->getBody());
        return $body === '' ? [] : array_filter(array_map('trim', explode("\n", $body)));
    }

    /**
     * Indexe en masse (bulk API) ; $documents est [sku => document].
     *
     * @param array<string, array<string, mixed>> $documents
     */
    public function bulkIndex(string $indexName, array $documents): void
    {
        if (!$documents) {
            return;
        }
        $lines = '';
        foreach ($documents as $sku => $doc) {
            $lines .= json_encode(['index' => ['_index' => $indexName, '_id' => $sku]]) . "\n";
            $lines .= json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        }
        $this->request('POST', '_bulk', [
            'headers' => ['Content-Type' => 'application/x-ndjson'],
            'body'    => $lines,
        ]);
    }

    /** @param array<string, mixed> $document */
    public function indexDocument(string $indexName, string $sku, array $document): void
    {
        $this->request('PUT', $indexName . '/_doc/' . rawurlencode($sku), ['json' => $document]);
    }

    public function deleteDocument(string $indexName, string $sku): void
    {
        // 404 (document déjà absent) n'est pas une erreur ici.
        $this->request('DELETE', $indexName . '/_doc/' . rawurlencode($sku), [], [404]);
    }

    public function refresh(string $indexName): void
    {
        $this->request('POST', $indexName . '/_refresh');
    }

    public function deleteIndex(string $indexName): void
    {
        $this->request('DELETE', $indexName, [], [404]);
    }

    private function request(string $method, string $path, array $options = [], array $ignoreStatuses = []): \Psr\Http\Message\ResponseInterface
    {
        $client = $this->clientFactory->create(['config' => [
            'base_uri'    => $this->config->getOpenSearchUrl() . '/',
            'timeout'     => 30,
            'http_errors' => false,
        ]]);

        try {
            $response = $client->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Mytek_Marketplace : OpenSearch injoignable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new \RuntimeException(__('OpenSearch unreachable: %1', $e->getMessage())->render());
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && !in_array($status, $ignoreStatuses, true)) {
            $this->logger->error('Mytek_Marketplace : OpenSearch a refusé la requête', [
                'path' => $path, 'status' => $status, 'body' => (string)$response->getBody(),
            ]);
            throw new \RuntimeException(__('OpenSearch refused the request (HTTP %1).', $status)->render());
        }
        return $response;
    }
}
