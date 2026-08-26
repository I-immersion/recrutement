#!/bin/bash
# Installe la derniere version LUMIIA RECRUTE telechargee, puis deploie.
# Trouve le fichier par son CONTENU : le telechargement deforme les noms.
cd "$(dirname "$0")" || exit 1
P="$(pwd)"
echo "=== LUMIIA RECRUTE - installation ==="
SRC=""
while IFS= read -r f; do
  grep -q "LUMIIA RECRUTE" "$f" 2>/dev/null || continue
  if [ -z "$SRC" ] || [ "$f" -nt "$SRC" ]; then SRC="$f"; fi
done < <(find "$HOME/Downloads" "$HOME/Desktop" -maxdepth 2 -name '*.html' 2>/dev/null)
if [ -z "$SRC" ]; then
  echo
  echo "AUCUN fichier LUMIIA RECRUTE dans Telechargements."
  echo "Telecharge d'abord le fichier depuis la conversation, puis relance."
  echo
  read -p "Entree pour fermer."
  exit 1
fi
NEW=$(grep -m1 -o 'v[0-9][0-9]*\.[0-9][0-9]*' "$SRC")
OLD=$(grep -m1 -o 'v[0-9][0-9]*\.[0-9][0-9]*' "$P/index.html" 2>/dev/null)
echo "fichier : $SRC"
echo "version : ${OLD:-aucune} -> ${NEW:-inconnue}"
echo
read -p "Installer et deployer ? (Entree = oui, Ctrl-C = annuler) "
cp "$SRC" "$P/index.html" || exit 1
[ -n "$NEW" ] && cp "$SRC" "$P/lumiia-recrute-$NEW.html"
echo "en place : $(grep -m1 'LUMIIA RECRUTE' "$P/index.html")"
echo
bash "$P/deployer-recrutement.command"
