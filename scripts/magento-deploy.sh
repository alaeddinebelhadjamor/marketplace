#!/usr/bin/env bash
# Déploie le module Mytek_Marketplace du dépôt vers le Magento local, puis met à jour Magento.
#
#   scripts/magento-deploy.sh            copie + setup:upgrade + cache:flush
#   scripts/magento-deploy.sh --compile  ajoute setup:di:compile (mode production)
#
# Variables : MAGENTO_ROOT (défaut ~/magento), PHP_BIN (défaut php, PHP 8.3 pour Magento).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO_ROOT/magento-module/app/code/Mytek/Marketplace/"
MAGENTO_ROOT="${MAGENTO_ROOT:-$HOME/magento}"
DEST="$MAGENTO_ROOT/app/code/Mytek/Marketplace/"
PHP_BIN="${PHP_BIN:-php}"

[ -f "$MAGENTO_ROOT/bin/magento" ] || { echo "Magento introuvable dans $MAGENTO_ROOT" >&2; exit 1; }

mkdir -p "$DEST"
# --delete : le dossier du module dans Magento est une copie exacte du dépôt.
rsync -a --delete --exclude 'Test/' "$SRC" "$DEST"
echo "Module copié vers $DEST"

cd "$MAGENTO_ROOT"
"$PHP_BIN" bin/magento setup:upgrade --keep-generated
if [ "${1:-}" = "--compile" ]; then
    "$PHP_BIN" bin/magento setup:di:compile
fi
"$PHP_BIN" bin/magento cache:flush
echo "Déploiement terminé."
