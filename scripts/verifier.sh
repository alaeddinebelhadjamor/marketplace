#!/bin/bash
# Test de fumée de l'API v2 démarrée (aucun secret affiché).
# Usage : scripts/verifier.sh [identifiant] [mot de passe]
# Par défaut : compte vendeur de test utilisé par la suite Jest de la v1.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="${API_URL:-http://127.0.0.1:8010}"
ID="${1:-test.auto.1789485162300@example.com}"
PW="${2:-MotDePasse123!}"
KEY="$(grep '^ADMIN_API_KEY=' "$ROOT/backend/.env" | cut -d= -f2- | tr -d "\"'")"
ok=0; ko=0

check() { # libellé, code attendu, code obtenu
  if [ "$2" = "$3" ]; then echo "  OK   $1 ($3)"; ok=$((ok+1)); else echo "  ÉCHEC $1 : attendu $2, obtenu $3"; ko=$((ko+1)); fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Vérification de $API"
check "GET /" 200 "$(code "$API/")"
check "GET /metrics" 200 "$(code "$API/metrics")"
check "GET /up (santé Laravel)" 200 "$(code "$API/up")"
check "stats sans clé" 401 "$(code "$API/api/stats/sellers")"
check "stats avec clé" 200 "$(code -H "x-admin-key: $KEY" "$API/api/stats/sellers")"
check "jeton Magento non exposé" 404 "$(code "$API/api/magento/token")"
check "pièce jointe anonyme" 403 "$(code "$API/api/attachments/view/x.pdf")"

LOGIN="$(curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"identifier\":\"$ID\",\"password\":\"$PW\"}" "$API/api/auth/login")"
TOKEN="$(printf '%s' "$LOGIN" | php8.4 -r 'echo json_decode(stream_get_contents(STDIN), true)["accessToken"] ?? "";')"
if [ -n "$TOKEN" ]; then
  echo "  OK   connexion du vendeur de test"; ok=$((ok+1))
  A=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
  check "profil" 200 "$(code "${A[@]}" "$API/api/profile")"
  check "ventes" 200 "$(code "${A[@]}" "$API/api/orders")"
  check "tableau de bord" 200 "$(code "${A[@]}" "$API/api/dashboard")"
  check "réclamations" 200 "$(code "${A[@]}" "$API/api/reclamations/seller")"
  check "relevés" 200 "$(code "${A[@]}" "$API/api/statements")"
  check "journal d'activité" 200 "$(code "${A[@]}" "$API/api/profile/activity")"
  P="$(code "${A[@]}" "$API/api/magento/products/pending")"
  echo "  INFO produits en attente via Magento : $P (503 si Magento est arrêté)"
  curl -s -o /dev/null -X POST "${A[@]}" "$API/api/auth/logout"
else
  echo "  INFO connexion du vendeur de test impossible : $(printf '%s' "$LOGIN" | head -c 120)"
fi

echo "Résultat : $ok vérification(s) réussie(s), $ko échec(s)."
[ "$ko" -eq 0 ]
