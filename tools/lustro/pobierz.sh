#!/bin/bash
# Ściąga stronę i jej zasoby z tego samego hosta, przepisując adresy na względne.
set -eu
URL="${1:?podaj adres strony}"
KAT="$(cd "$(dirname "$0")" && pwd)/strona"
HOST="$(printf '%s' "$URL" | sed -E 's|^(https?://[^/]+).*|\1|')"

rm -rf "$KAT"; mkdir -p "$KAT"
CIAS="$KAT/.ciasteczka"

# Strona bywa za hasłem, które ustawia ciasteczko i przekierowuje — stąd słoik.
curl -sSL -c "$CIAS" -b "$CIAS" --max-time 120 --compressed \
  -A "Mozilla/5.0" -o "$KAT/.strona.html" "$URL"

python3 - "$KAT" "$HOST" <<'PY'
import re, sys, pathlib
kat, host = pathlib.Path(sys.argv[1]), sys.argv[2]
s = (kat / '.strona.html').read_text(encoding='utf-8', errors='replace')
u = sorted({x.rstrip(',;') for x in re.findall(re.escape(host) + r"/[^\"' >)\\]+", s)})
u = [x for x in u if re.search(r'\.(css|js|svg|png|jpe?g|webp|avif|gif|woff2?|ttf|mp4|webm)(\?|$)', x)]
(kat / '.lista').write_text('\n'.join(u))
(kat / 'index.html').write_text(s.replace(host + '/', '/'), encoding='utf-8')
print(len(u), 'zasobów do pobrania')
PY

cd "$KAT"
xargs -P 8 -I{} sh -c '
  u="{}"; p=$(printf "%s" "$u" | sed "s|^https\?://[^/]*/||" | sed "s|?.*||")
  mkdir -p "$(dirname "$p")"; curl -sSL -b .ciasteczka --max-time 60 -o "$p" "$u"
' < .lista
echo "gotowe: $(find . -type f | wc -l) plików w $KAT"
