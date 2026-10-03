#!/bin/bash
# Arrête les services lancés par scripts/demarrer.sh (sans toucher à MySQL ni à Magento).
# Usage : scripts/arreter.sh [api|queue|scheduler|reverb|front|tout]
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUN="$ROOT/.run"
what="${1:-tout}"

for name in front reverb scheduler queue api; do
  [[ "$what" != tout && "$what" != "$name" ]] && continue
  pidfile="$RUN/$name.pid"
  [ -f "$pidfile" ] || continue
  pid="$(cat "$pidfile")"
  if kill -0 "$pid" 2>/dev/null; then
    # Arrête tout le groupe de processus (setsid) : npm/vite et les workers PHP compris.
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid"
    echo "  $name : arrêté"
  fi
  rm -f "$pidfile"
done
