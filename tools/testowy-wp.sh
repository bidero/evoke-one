#!/usr/bin/env bash
#
# Testowy WordPress dla testów modułu kopii (tests/backup-baza.test.js i dalsze).
#
# PO CO. Zrzut bazy i przywracanie gadają z prawdziwym MySQL-em: SHOW CREATE
# TABLE, stronicowanie po kluczu, kodowanie kolumn, RENAME TABLE. Atrapa
# $wpdb nie odpowie na żadne z tych pytań, więc testy tej części potrzebują
# prawdziwej bazy i prawdziwego WordPressa — a ten skrypt stawia oba jednym
# poleceniem.
#
#   tools/testowy-wp.sh            postaw (albo sprawdź, że stoi)
#   tools/testowy-wp.sh --od-nowa  wyczyść bazę i katalog, postaw jeszcze raz
#
# Czego potrzebuje na maszynie: php (z mysqli), git, curl, serwer MariaDB albo
# MySQL (w kontenerze sesji zdalnej: apt-get install -y mariadb-server).
#
# Gdzie stawia — zmienne, wszystkie z wartościami domyślnymi:
#   EVK_WP_PATH   katalog WordPressa   (~/.cache/evk-testowy-wp)
#   EVK_WP_DB     baza                 (evk_test)
#   EVK_WP_USER   użytkownik bazy      (evk)
#   EVK_WP_PASS   hasło                (evk)
#
# Testy czytają EVK_WP_PATH tak samo — bez eksportu trafią w domyślny katalog.
# WordPress jest przypięty do wydania (WP_WERSJA niżej), a wtyczka podpięta
# DOWIĄZANIEM do tego repozytorium, więc testy widzą bieżący kod bez kopiowania.
#
set -euo pipefail

WP_WERSJA="7.1.2"
EVK_WP_PATH="${EVK_WP_PATH:-$HOME/.cache/evk-testowy-wp}"
EVK_WP_DB="${EVK_WP_DB:-evk_test}"
EVK_WP_USER="${EVK_WP_USER:-evk}"
EVK_WP_PASS="${EVK_WP_PASS:-evk}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
CLI="$EVK_WP_PATH/../wp-cli.phar"

krok() { printf '── %s\n' "$*"; }

# ── Serwer bazy ─────────────────────────────────────────────────────────────
# Uruchamiamy go tylko wtedy, gdy nie odpowiada — na maszynie, gdzie działa
# jako usługa systemowa, skrypt niczego w nim nie rusza poza własną bazą.
if ! mysqladmin ping >/dev/null 2>&1; then
    krok "uruchamiam serwer bazy"
    if command -v mysqld_safe >/dev/null; then
        (mysqld_safe --user=mysql >/dev/null 2>&1 &)
    else
        echo "Brak serwera bazy (mysqld_safe). Zainstaluj MariaDB albo MySQL." >&2
        exit 1
    fi
    for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
    mysqladmin ping >/dev/null 2>&1 || { echo "Serwer bazy nie wstał w 30 s." >&2; exit 1; }
fi

if [ "${1:-}" = "--od-nowa" ]; then
    krok "czyszczę poprzednie środowisko"
    mysql -e "DROP DATABASE IF EXISTS \`$EVK_WP_DB\`" || true
    rm -rf "$EVK_WP_PATH"
fi

krok "baza $EVK_WP_DB i użytkownik $EVK_WP_USER"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$EVK_WP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
          CREATE USER IF NOT EXISTS '$EVK_WP_USER'@'localhost' IDENTIFIED BY '$EVK_WP_PASS';
          GRANT ALL ON *.* TO '$EVK_WP_USER'@'localhost';
          FLUSH PRIVILEGES;"

# ── WordPress ───────────────────────────────────────────────────────────────
# Z lustra na GitHubie (git działa tam, gdzie composer i api.github.com nie —
# patrz CLAUDE.md, sekcja o PHPStanie).
if [ ! -f "$EVK_WP_PATH/wp-includes/version.php" ]; then
    krok "WordPress $WP_WERSJA"
    mkdir -p "$(dirname "$EVK_WP_PATH")"
    git -c advice.detachedHead=false clone -q --depth 1 --branch "$WP_WERSJA" \
        https://github.com/WordPress/WordPress.git "$EVK_WP_PATH"
fi

if [ ! -f "$CLI" ]; then
    krok "WP-CLI"
    curl -sSLo "$CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi
wp() { php "$CLI" --allow-root --path="$EVK_WP_PATH" "$@"; }

if [ ! -f "$EVK_WP_PATH/wp-config.php" ]; then
    wp config create --dbname="$EVK_WP_DB" --dbuser="$EVK_WP_USER" --dbpass="$EVK_WP_PASS" \
        --dbhost=localhost --skip-check >/dev/null
fi

if ! wp core is-installed 2>/dev/null; then
    krok "instalacja WordPressa"
    wp core install --url=http://stara.test --title="Evoke test" --admin_user=admin \
        --admin_password=admin --admin_email=admin@stara.test --skip-email >/dev/null
fi

# ── Wtyczka: dowiązanie do repozytorium ─────────────────────────────────────
ln -sfn "$REPO" "$EVK_WP_PATH/wp-content/plugins/evoke-one"
wp plugin activate evoke-one >/dev/null 2>&1 || true

krok "gotowe"
echo "   WordPress: $EVK_WP_PATH ($(wp core version))"
echo "   wtyczka:   $(wp plugin get evoke-one --field=version) (dowiązanie do $REPO)"
echo "   testy:     node tests/run.js backup-baza"
