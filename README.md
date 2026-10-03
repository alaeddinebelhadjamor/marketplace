# Marketplace Mytek v2 (Laravel + Vue.js)

Nouvelle version de la marketplace multi-vendeurs adossée au site Magento 2 de Mytek.
Backend Laravel 13 (PHP 8.4), frontend Vue.js 3 (Vite, Pinia, Vue Router, Vuetify).

> Projet en cours de construction. Le démarrage complet sera documenté à la fin de la réalisation.

## Organisation

| Dossier | Contenu |
|---|---|
| `backend/` | API Laravel (port 8000) |
| `frontend/` | Application Vue.js 3 (port 5173) |
| `docs/` | choix des versions, checklist de parité, notes de migration |
| `scripts/` | scripts utilitaires (miroir OneDrive) |

## Emplacement du code

Le dépôt de travail se trouve sur le disque natif de WSL Ubuntu-22.04, dans
`~/projets/marketplace-v2`, car l'accès aux fichiers Windows depuis WSL est environ
200 fois plus lent (mesure dans `docs/choix-versions.md`, section 5).

Le dossier OneDrive `Bureau\ala\marketplace-v2` reçoit une copie automatique à chaque
commit, par le hook git `post-commit` qui lance `scripts/miroir-onedrive.sh`. Les dossiers
`vendor/`, `node_modules/`, les fichiers `.env` et les journaux ne sont pas copiés.
Pour modifier le code, ouvrir le dossier WSL, et non la copie OneDrive.

Après un nouveau clonage, réactiver le hook :

```bash
ln -sf ../../scripts/miroir-onedrive.sh .git/hooks/post-commit
```
