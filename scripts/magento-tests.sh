#!/usr/bin/env bash
# Lance les tests unitaires PHPUnit du module Mytek_Marketplace avec le framework de tests de Magento.
#
# Les tests sont exécutés depuis le dépôt : le chargeur de classes de Magento est complété
# par l'espace de noms Mytek\Marketplace\ du dépôt (voir magento-module/tests-bootstrap.php).
#
# Variables : MAGENTO_ROOT (défaut ~/magento), PHPUNIT (défaut ~/tools/phpunit-9.6.phar).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export MAGENTO_ROOT="${MAGENTO_ROOT:-$HOME/magento}"
PHPUNIT="${PHPUNIT:-$HOME/tools/phpunit-9.6.phar}"

if [ ! -f "$PHPUNIT" ]; then
    echo "PHPUnit introuvable : $PHPUNIT" >&2
    echo "Installation : mkdir -p ~/tools && curl -sSLo ~/tools/phpunit-9.6.phar https://phar.phpunit.de/phpunit-9.6.phar" >&2
    exit 1
fi

php "$PHPUNIT" -c "$REPO_ROOT/magento-module/phpunit.xml.dist" "$@"
