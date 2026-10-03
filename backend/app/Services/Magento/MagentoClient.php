<?php

namespace App\Services\Magento;

use App\Support\Metrics\MetricsRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client de l'API REST Magento (/rest/V1).
 *
 * Le jeton administrateur Magento est obtenu avec le compte de service,
 * mis en cache (1 h par défaut) et ne quitte jamais le serveur. Un 401 de
 * Magento provoque un renouvellement du jeton puis une seule nouvelle tentative.
 */
class MagentoClient
{
    private const TOKEN_CACHE_KEY = 'magento:admin-token';

    public function __construct(private readonly MetricsRegistry $metrics) {}

    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $body = []): Response
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    public function put(string $path, array $body = []): Response
    {
        return $this->send('PUT', $path, ['json' => $body]);
    }

    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path);
    }

    /**
     * @throws MagentoUnavailableException si Magento est injoignable
     */
    private function send(string $method, string $path, array $options = []): Response
    {
        $started = microtime(true);
        $status = 'error';

        try {
            $response = $this->request($this->token())->send($method, $path, $options);

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = $this->request($this->token())->send($method, $path, $options);
            }
            $status = (string) $response->status();

            return $response;
        } catch (ConnectionException $e) {
            throw new MagentoUnavailableException('Magento est injoignable : '.$e->getMessage(), previous: $e);
        } finally {
            $this->metrics->observeExternalCall('magento', $method, $status, microtime(true) - $started);
        }
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl(config('marketplace.magento.url').'/rest/V1/')
            ->timeout((int) config('marketplace.magento.timeout'))
            ->acceptJson()
            ->withToken($token);
    }

    /** Jeton administrateur Magento, mis en cache. */
    public function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, (int) config('marketplace.magento.token_ttl'), function () {
            try {
                $response = Http::baseUrl(config('marketplace.magento.url').'/rest/V1/')
                    ->timeout((int) config('marketplace.magento.timeout'))
                    ->acceptJson()
                    ->post('integration/admin/token', [
                        'username' => config('marketplace.magento.user'),
                        'password' => config('marketplace.magento.password'),
                    ]);
            } catch (ConnectionException $e) {
                throw new MagentoUnavailableException('Magento est injoignable : '.$e->getMessage(), previous: $e);
            }

            if (! $response->successful()) {
                throw new MagentoUnavailableException('Impossible d\'obtenir le jeton Magento (HTTP '.$response->status().').');
            }

            return trim((string) $response->json(), '"');
        });
    }
}
