#!/bin/bash
# Recopie le depot de travail (disque WSL) vers le dossier OneDrive du projet.
# Appele automatiquement par le hook git post-commit ; peut aussi etre lance a la main.
# Ne supprime jamais de fichier de travail dans OneDrive : seul le dossier .git est mis a l'identique.
set -e
SRC="$(git rev-parse --show-toplevel)"
DEST="/mnt/c/Users/abelhadjamor/OneDrive - CELESTE/Bureau/ala/marketplace-v2"
mkdir -p "$DEST"
rsync -a --no-perms --no-owner --no-group \
  --include '.env.example' \
  --exclude '.git/' --exclude 'vendor/' --exclude 'node_modules/' \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'storage/logs/' --exclude 'storage/framework/' \
  --exclude 'dist/' --exclude 'coverage/' --exclude '*.log' \
  "$SRC/" "$DEST/"
rsync -a --no-perms --no-owner --no-group --delete "$SRC/.git/" "$DEST/.git/"
echo "[miroir] copie vers OneDrive terminee"
