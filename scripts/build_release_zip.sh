#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COUNT="$(git rev-list --count HEAD)"
MAJOR="$(( (COUNT - 1) / 100 + 1 ))"
MINOR="$(printf "%02d" $(( COUNT % 100 )))"
VERSION="${MAJOR}.${MINOR}"
OUT="dist/EasyDcim-BW-${VERSION}.zip"

rm -f "$OUT"
zip -r "$OUT" modules/addons/easydcim_bw >/dev/null

echo "Created: $OUT"
