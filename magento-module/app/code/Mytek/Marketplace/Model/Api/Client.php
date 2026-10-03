<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Api;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Mytek\Marketplace\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Appels serveur à serveur vers l'API v2 (Laravel), authentifiés par l'en-tête x-admin-key.
 * La clé ne quitte jamais le serveur Magento : les pages admin passent par des contrôleurs
 * qui relaient la réponse.
 */
class Client
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws ApiException
     */
    public function get(string $path): Response
    {
        return $this->send('GET', $path, []);
    }

    /**
     * @param array<string, mixed> $json
     * @throws ApiException
     */
    public function post(string $path, array $json = []): Response
    {
        return $this->send('POST', $path, ['json' => $json]);
    }

    /**
     * @param array<string, mixed> $json
     * @throws ApiException
     */
    public function put(string $path, array $json = []): Response
    {
        return $this->send('PUT', $path, ['json' => $json]);
    }

    /**
     * Envoi multipart/form-data.
     *
     * @param array<string, scalar> $fields
     * @param array<int, array{path: string, name: string}> $files fichiers envoyés sous « attachments[] »
     * @throws ApiException
     */
    public function postMultipart(string $path, array $fields, array $files = []): Response
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => (string)$name, 'contents' => (string)$value];
        }
        $handles = [];
        try {
            foreach ($files as $file) {
                $handle = fopen($file['path'], 'rb');
                if ($handle === false) {
                    throw new ApiException(__('Cannot read the uploaded file "%1".', $file['name']));
                }
                $handles[] = $handle;
                $multipart[] = ['name' => 'attachments[]', 'contents' => $handle, 'filename' => $file['name']];
            }
            return $this->send('POST', $path, ['multipart' => $multipart]);
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * @throws ApiException
     */
    private function send(string $method, string $path, array $options): Response
    {
        if (!$this->config->isApiConfigured()) {
            throw new ApiException(__(
                'The marketplace API is not configured (Stores > Configuration > Mytek > Marketplace).'
            ));
        }

        $client = $this->clientFactory->create(['config' => [
            'base_uri'    => $this->config->getApiBaseUrl() . '/',
            'timeout'     => $this->config->getApiTimeout(),
            'http_errors' => false,
        ]]);

        $options['headers'] = ($options['headers'] ?? []) + [
            'x-admin-key' => $this->config->getAdminApiKey(),
            'Accept'      => 'application/json',
        ];

        try {
            $response = $client->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Mytek_Marketplace : API v2 injoignable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException(__('The marketplace API cannot be reached: %1', $e->getMessage()));
        }

        return new Response(
            $response->getStatusCode(),
            (string)$response->getBody(),
            $response->getHeaderLine('Content-Type')
        );
    }
}
