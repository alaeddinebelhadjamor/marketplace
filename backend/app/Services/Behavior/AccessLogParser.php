<?php

namespace App\Services\Behavior;

/**
 * Analyse d'une ligne du journal d'accès Apache de Magento (format combiné).
 *
 *  - recherche : GET /myteksearch/index/productsearch/?q=...   -> type « search »
 *  - page .html à la racine (produit ou catégorie)             -> type « html »
 *  - pages techniques, sous-dossiers, statut ≠ 200             -> ignorée (null)
 *
 * L'heure de la visite (visited_at) est celle écrite par Apache entre crochets,
 * convertie dans le fuseau de l'application : l'événement est daté de la visite,
 * pas de la lecture du journal par la tâche planifiée.
 */
class AccessLogParser
{
    private const SEARCH = '#^(\S+) \S+ \S+ \[([^\]]+)\] "GET /myteksearch/index/productsearch/\?q=([^ "]+) HTTP/[\d.]+" (\d{3}) \S+ "([^"]*)" "([^"]*)"#';

    private const HTML = '#^(\S+) \S+ \S+ \[([^\]]+)\] "GET ([^ "]+\.html) HTTP/[\d.]+" (\d{3}) \S+ "([^"]*)" "([^"]*)"#';

    /** Format de date du journal combiné d'Apache, ex. 03/Oct/2026:10:15:32 +0100. */
    private const DATE_FORMAT = 'd/M/Y:H:i:s O';

    private const EXCLUDED = ['checkout', 'cart', 'customer', 'account', 'catalogsearch', 'wishlist', 'compare'];

    public function parse(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (preg_match(self::SEARCH, $line, $m)) {
            if ($m[4] !== '200') {
                return null;
            }

            return $this->base($m[1], $m[2], $m[5], $m[6]) + [
                'type' => 'search',
                'search_query' => mb_substr(urldecode(str_replace('+', ' ', $m[3])), 0, 255),
            ];
        }

        if (preg_match(self::HTML, $line, $m)) {
            [, $ip, $date, $url, $status, $referrer, $ua] = $m;
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

            return $this->base($ip, $date, $referrer, $ua) + ['type' => 'html', 'slug' => $slug];
        }

        return null;
    }

    private function base(string $ip, string $date, string $referrer, string $ua): array
    {
        return [
            'ip' => $ip,
            'visited_at' => $this->visitedAt($date),
            'source' => mb_substr($referrer !== '' && $referrer !== '-' ? $referrer : 'direct', 0, 500),
            'device_type' => preg_match('/mobile|android|iphone/i', $ua) ? 'mobile' : 'desktop',
            'anonymous_id' => mb_substr($ip, 0, 64),
            'session_id' => mb_substr($ip.'-'.substr(base64_encode($ua), 0, 10), 0, 128),
        ];
    }

    /** Date Apache convertie au format SQL, ou null si elle est illisible. */
    private function visitedAt(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $date);
        if ($parsed === false) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
