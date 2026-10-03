<?php
/**
 * Amorce des tests unitaires : réutilise celle de Magento (chargeur de classes, rendu des
 * phrases __()) et fait pointer l'espace de noms du module sur le dépôt, pour tester le code
 * avant de le déployer.
 */
declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: getenv('HOME') . '/magento';
$bootstrap = $magentoRoot . '/dev/tests/unit/framework/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Amorce Magento introuvable : $bootstrap\n");
    exit(1);
}
require_once $bootstrap;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mytek\\Marketplace\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/app/code/Mytek/Marketplace/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
