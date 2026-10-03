<?php
/**
 * Construit backend/.env de la v2 à partir du .env de l'ancienne version (v1).
 * Les secrets sont recopiés tels quels et ne sont jamais affichés.
 *
 * Usage (WSL) : php8.4 scripts/importer-env-v1.php <chemin du .env v1> <chemin du .env v2>
 */
[$script, $v1Path, $v2Path] = $argv + [null, null, null];
if (!$v1Path || !$v2Path || !is_file($v1Path) || !is_file($v2Path)) {
    fwrite(STDERR, "Usage : php importer-env-v1.php <.env v1> <.env v2>\n");
    exit(1);
}

$parse = function (string $path): array {
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $m)) {
            continue;
        }
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $out[$m[1]] = $v;
    }
    return $out;
};

$v1 = $parse($v1Path);

// Chemin Windows -> chemin WSL (C:\x\y -> /mnt/c/x/y)
$toWsl = fn (string $p) => preg_match('/^([A-Za-z]):\\\\(.*)$/', $p, $m)
    ? '/mnt/' . strtolower($m[1]) . '/' . str_replace('\\', '/', $m[2])
    : $p;

$set = [
    'APP_NAME' => 'Marketplace Mytek v2',
    'APP_URL' => 'http://localhost:8010',
    'APP_LOCALE' => 'fr',
    'APP_FALLBACK_LOCALE' => 'en',
    'APP_FAKER_LOCALE' => 'fr_FR',
    'FRONTEND_URL' => 'http://localhost:5180',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:5180,127.0.0.1:5180',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $v1['DB_HOST'] ?? '127.0.0.1',
    'DB_PORT' => $v1['DB_PORT'] ?? '3307',
    'DB_DATABASE' => $v1['DB_NAME'] ?? '',
    'DB_USERNAME' => $v1['DB_USER'] ?? '',
    'DB_PASSWORD' => $v1['DB_PASS'] ?? '',
    // Collation des tables existantes (défaut MySQL 8) : évite les erreurs de jointure.
    'DB_COLLATION' => 'utf8mb4_0900_ai_ci',
    'SESSION_DRIVER' => 'database',
    'SESSION_LIFETIME' => '1440',
    'QUEUE_CONNECTION' => 'database',
    'CACHE_STORE' => 'database',
    'BCRYPT_ROUNDS' => '12',
    'HASH_VERIFY' => 'false',
    'ADMIN_API_KEY' => $v1['ADMIN_API_KEY'] ?? '',
    'MAGENTO_URL' => $v1['MAGENTO_URL'] ?? 'http://localhost:8080',
    'MAGENTO_USER' => $v1['MAGENTO_USER'] ?? '',
    'MAGENTO_PASSWORD' => $v1['MAGENTO_PASSWORD'] ?? '',
    'STOREFRONT_URL' => 'http://localhost:8080',
    'OPENSEARCH_BASE_URL' => $v1['OPENSEARCH_BASE_URL'] ?? 'http://localhost:9201',
    'MAGENTO_ACCESS_LOG' => $toWsl($v1['MAGENTO_ACCESS_LOG'] ?? ''),
    'MAGENTO_LOG_OFFSET_FILE' => 'storage/app/private/magento_log_offset.json',
    'BEHAVIOR_SYNC_ENABLED' => 'false',
    'CRON_ORDERS_SYNC' => '* * * * *',
    'CRON_PRODUCT_VIEWS' => '* * * * *',
    'COMMISSION_RATE' => '0.05',
    'REVERB_HOST' => 'localhost',
    'REVERB_PORT' => '8091',
    'REVERB_SERVER_PORT' => '8091',
    'REVERB_SCHEME' => 'http',
    'MAIL_MAILER' => 'log',
    'MAIL_FROM_ADDRESS' => 'no-reply@mytek.tn',
    'MAIL_FROM_NAME' => 'Mytek Marketplace',
];

$quote = function (string $v): string {
    if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/,@+-]+$/', $v)) {
        return $v;
    }
    if (!str_contains($v, "'")) {
        return "'" . $v . "'";
    }
    return '"' . addcslashes($v, "\"\\\$") . '"';
};

$lines = file($v2Path, FILE_IGNORE_NEW_LINES);
$done = [];
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*#?\s*([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $set) && !isset($done[$m[1]])) {
        $lines[$i] = $m[1] . '=' . $quote($set[$m[1]]);
        $done[$m[1]] = true;
    }
}
$lines[] = '';
$lines[] = '# --- Marketplace v2 ---';
foreach ($set as $k => $v) {
    if (!isset($done[$k])) {
        $lines[] = $k . '=' . $quote($v);
    }
}
file_put_contents($v2Path, implode("\n", $lines) . "\n");
chmod($v2Path, 0600);

$missing = array_keys(array_filter($set, fn ($v) => $v === ''));
echo count($set) . " variables écrites dans {$v2Path}.\n";
echo $missing ? 'Variables vides : ' . implode(', ', $missing) . "\n" : "Aucune variable vide.\n";
