#!/bin/bash
cd "$(dirname "$0")" || exit 1
echo "=== Deploiement LUMIIA RECRUTE ==="
git add -A
MSG="${1:-maj $(date '+%Y-%m-%d %H:%M')}"
git commit -m "$MSG" || echo "(rien a commiter)"
git push -u origin HEAD && echo "=== OK : pousse sur GitHub ===" || echo "=== ECHEC du push ==="
echo "Appuie sur Entree pour fermer."
read
