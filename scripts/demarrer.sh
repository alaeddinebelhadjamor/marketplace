#!/bin/bash
# Démarre les services de la marketplace v2 en arrière-plan (WSL Ubuntu-22.04).
#
#   API Laravel        http://localhost:8010
#   WebSocket Reverb   ws://localhost:8091
#   Interface Vue      http://localhost:5180
#   + file d'attente (queue:work) et planificateur (schedule:work)
#
# Usage : scripts/demarrer.sh [api|queue|scheduler|reverb|front|tout]   (défaut : tout)
# Journaux : backend/storage/logs/<service>.log — arrêt : scripts/arreter.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUN="$ROOT/.run"
LOGS="$ROOT/backend/storage/logs"
PHP=php8.4
mkdir -p "$RUN" "$LOGS"

export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" >/dev/null

running() { [ -f "$RUN/$1.pid" ] && kill -0 "$(cat "$RUN/$1.pid")" 2>/dev/null; }

start() {
  local name="$1" dir="$2"; shift 2
  if running "$name"; then
    echo "  $name : déjà démarré (pid $(cat "$RUN/$name.pid"))"
    return
  fi
  # Le processus écrit lui-même son identifiant : c'est le chef du groupe créé par
  # setsid, ce qui permet à arreter.sh d'arrêter aussi les processus enfants.
  ( cd "$dir" && setsid nohup bash -c 'echo $$ > "$0"; exec "$@"' "$RUN/$name.pid" "$@" </dev/null >>"$LOGS/$name.log" 2>&1 & )
  sleep 1
  if running "$name"; then echo "  $name : démarré (pid $(cat "$RUN/$name.pid"))"; else echo "  $name : ÉCHEC, voir $LOGS/$name.log"; fi
}

what="${1:-tout}"
echo "Marketplace v2 — démarrage ($what)"

# Base de données marketplace (conteneur Docker existant, port 3307).
if command -v docker >/dev/null && ! docker ps --format '{{.Names}}' | grep -qx marketplace-mysql; then
  docker start marketplace-mysql >/dev/null && echo "  MySQL marketplace (3307) : démarré"
fi

if [[ "$what" == tout || "$what" == api ]]; then
  # Serveur PHP intégré, 4 processus ; limites relevées pour les images en base64.
  # Le routeur de Laravel (server.php) attend le dossier public comme dossier courant.
  PHP_CLI_SERVER_WORKERS=4 start api "$ROOT/backend/public" \
    $PHP -d upload_max_filesize=16M -d post_max_size=64M -d memory_limit=512M \
    -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
fi
if [[ "$what" == tout || "$what" == queue ]]; then
  start queue "$ROOT/backend" $PHP artisan queue:work --sleep=2 --timeout=1800 --memory=512
fi
if [[ "$what" == tout || "$what" == scheduler ]]; then
  start scheduler "$ROOT/backend" $PHP artisan schedule:work
fi
if [[ "$what" == tout || "$what" == reverb ]]; then
  start reverb "$ROOT/backend" $PHP artisan reverb:start --host=127.0.0.1 --port=8091
fi
if [[ "$what" == tout || "$what" == front ]] && [ -f "$ROOT/frontend/package.json" ]; then
  start front "$ROOT/frontend" npm run dev -- --host 127.0.0.1 --port 5180 --strictPort
fi

echo "API : http://localhost:8010 — documentation : http://localhost:8010/docs/api — interface : http://localhost:5180"
