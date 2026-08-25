#!/bin/bash
set -eu
cd "$(dirname "$0")/strona"
echo "http://127.0.0.1:${1:-8765}"
exec python3 -m http.server "${1:-8765}" --bind 127.0.0.1
