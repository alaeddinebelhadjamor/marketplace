<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Recommendation;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\Cache\Type\Block as BlockCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Mytek\Marketplace\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Recommandations « Vous aimerez aussi » de la fiche produit, lues sur l'API v2
 * (GET /api/recommendations, route publique : pas de clé x-admin-key).
 *
 * La fiche produit ne doit jamais dépendre de la disponibilité de l'API : délai court,
 * réponse mise en cache, repli sur les produits populaires si le moteur ne connaît pas
 * le SKU, et liste vide (bloc masqué) en cas d'erreur.
 */
class RecommendationProvider
{
    public const CACHE_TAG = 'MYTEK_RECOMMENDATIONS';
    private const CACHE_PREFIX = 'mytek_reco_';
    private const CACHE_LIFETIME = 900;
    /** Après une erreur : courte durée, pour ne pas relancer l'API à chaque affichage. */
    private const CACHE_LIFETIME_ERROR = 60;

    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array{sku:string, name:string, price:float, special_price:?float,
     *                          url_key:string, image:?string, source:string}>
     */
    public function getForSku(string $sku, int $limit): array
    {
        $sku = trim($sku);
        if ($sku === '' || $limit < 1 || $this->config->getApiBaseUrl() === '') {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . sha1($sku . '|' . $limit);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        try {
            // Une marge sur top_k compense le produit courant et les entrées incomplètes écartés.
            $items = self::normalize($this->fetch('api/recommendations', ['sku' => $sku, 'top_k' => $limit + 2]), $sku, $limit);
            if ($items === []) {
                $items = self::normalize($this->fetch('api/recommendations/popular', ['top_k' => $limit + 2]), $sku, $limit);
            }
            $lifetime = self::CACHE_LIFETIME;
        } catch (\RuntimeException $e) {
            $this->logger->warning('Mytek_Marketplace : recommandations indisponibles', ['sku' => $sku, 'error' => $e->getMessage()]);
            $items = [];
            $lifetime = self::CACHE_LIFETIME_ERROR;
        }

        // Étiquette du type de cache « Blocks HTML output » : vidé par cache:clean et depuis l'admin.
        $this->cache->save($this->serializer->serialize($items), $cacheKey, [self::CACHE_TAG, BlockCache::CACHE_TAG], $lifetime);
        return $items;
    }

    /**
     * Garde les entrées exploitables, sans le produit courant ni doublon, dans l'ordre du moteur.
     *
     * @param array<string, mixed> $payload réponse JSON décodée de l'API
     * @return array<int, array{sku:string, name:string, price:float, special_price:?float,
     *                          url_key:string, image:?string, source:string}>
     */
    public static function normalize(array $payload, string $currentSku, int $limit): array
    {
        $items = [];
        foreach ((array)($payload['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = (string)($row['sku'] ?? '');
            $name = trim((string)($row['name'] ?? ''));
            $urlKey = trim((string)($row['url_key'] ?? ''));
            if ($sku === '' || $name === '' || $urlKey === '' || strcasecmp($sku, $currentSku) === 0 || isset($items[$sku])) {
                continue;
            }
            $price = (float)($row['price'] ?? 0);
            $special = isset($row['special_price']) ? (float)$row['special_price'] : null;
            $items[$sku] = [
                'sku'           => $sku,
                'name'          => $name,
                'price'         => $price,
                'special_price' => ($special !== null && $special > 0 && $special < $price) ? $special : null,
                'url_key'       => $urlKey,
                'image'         => ($row['image'] ?? '') !== '' ? (string)$row['image'] : null,
                'source'        => (string)($row['source'] ?? ''),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_values($items);
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $query): array
    {
        $client = $this->clientFactory->create(['config' => [
            'base_uri'        => $this->config->getApiBaseUrl() . '/',
            'timeout'         => $this->config->getRecommendationsTimeout(),
            'connect_timeout' => $this->config->getRecommendationsTimeout(),
            'http_errors'     => false,
        ]]);

        try {
            $response = $client->request('GET', $path, ['query' => $query, 'headers' => ['Accept' => 'application/json']]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode() . ' sur ' . $path);
        }
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Réponse non JSON sur ' . $path);
        }
        return $data;
    }
}
