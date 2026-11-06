#!/usr/bin/env bash
# Importa em lote: ./tools/import_all.sh pasta_com_pdfs   (usa --prova/--gabarito conforme o nome do arquivo)
set -e
DIR="${1:?informe a pasta com os PDFs}"
args=()
shopt -s nullglob
for f in "$DIR"/*.pdf; do
  n=$(basename "$f" | tr 'A-Z' 'a-z')
  if [[ "$n" == *gab* ]]; then args+=("--gabarito=$f"); else args+=("--prova=$f"); fi
done
php "$(dirname "$0")/import.php" "${args[@]}" "${@:2}"
