<?php

namespace App\Services\Behavior;

/**
 * Analyse d'une ligne du journal d'accès Apache de Magento (format combiné).
 *
 *  - recherche : GET /myteksearch/index/productsearch/?q=...   -> type « search »
 *  - page .html à la racine (produit ou catégorie)             -> type « html »
 *  - pages techniques, sous-dossiers, statut ≠ 200             -> ignorée (null)
 */
class AccessLogParser
{
    private const SEARCH = '#^(\S+) \S+ \S+ \[.+?\] "GET /myteksearch/index/productsearch/\?q=([^ "]+) HTTP/[\d.]+" (\d{3}) \S+ "([^"]*)" "([^"]*)"#';

    private const HTML = '#^(\S+) \S+ \S+ \[.+?\] "GET ([^ "]+\.html) HTTP/[\d.]+" (\d{3}) \S+ "([^"]*)" "([^"]*)"#';

    private const EXCLUDED = ['checkout', 'cart', 'customer', 'account', 'catalogsearch', 'wishlist', 'compare'];

    public function parse(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (preg_match(self::SEARCH, $line, $m)) {
            if ($m[3] !== '200') {
                return null;
            }

            return $this->base($m[1], $m[4], $m[5]) + [
                'type' => 'search',
                'search_query' => mb_substr(urldecode(str_replace('+', ' ', $m[2])), 0, 255),
            ];
        }

        if (preg_match(self::HTML, $line, $m)) {
            [, $ip, $url, $status, $referrer, $ua] = $m;
            if ($status !== '200') {
                return null;
            }
            foreach (self::EXCLUDED as $excluded) {
                if (str_contains($url, $excluded)) {
                    return null;
                }
            }
            $path = ltrim(strtok($url, '?') ?: $url, '/');
            if ($path === '' || str_contains($path, '/')) {
                return null;
            }
            $slug = rawurldecode(preg_replace('/\.html$/', '', $path));
            if ($slug === '' || mb_strlen($slug) > 500) {
                return null;
            }

            return $this->base($ip, $referrer, $ua) + ['type' => 'html', 'slug' => $slug];
        }

        return null;
    }

    private function base(string $ip, string $referrer, string $ua): array
    {
        return [
            'ip' => $ip,
            'source' => mb_substr($referrer !== '' && $referrer !== '-' ? $referrer : 'direct', 0, 500),
            'device_type' => preg_match('/mobile|android|iphone/i', $ua) ? 'mobile' : 'desktop',
            'anonymous_id' => mb_substr($ip, 0, 64),
            'session_id' => mb_substr($ip.'-'.substr(base64_encode($ua), 0, 10), 0, 128),
        ];
    }
}
