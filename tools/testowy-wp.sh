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
# Stawia TRZY WordPressy w jednej bazie — drugi do testów przywracania na innej
# stronie (tests/backup-przywracanie.test.js): inny adres, inny prefiks tabel.
#   pierwszy  http://stara.test, prefiks wp_
#   drugi     http://nowa.test,  prefiks nowy_   (EVK_WP2_PATH)
#   trzeci    http://usun.test,  prefiks usun_   (EVK_WP3_PATH)
# Wspólna baza jest celowa: przywracanie na drugiej instalacji NIE może ruszyć
# tabel pierwszej — i test to sprawdza.
# Trzeci jest JEDNORAZOWY — do testu odinstalowania
# (tests/zapis-wp-odinstalowanie.test.js). Odinstalowanie z „Usuń dane"
# kasuje wszystko po wtyczce, więc na pierwszym zniszczyłoby stan innym
# testom; sonda sama go zasiewa i po sobie aktywuje wtyczkę z powrotem.
# Czwarty (1.250.0) ma obok Evoke ONE także Evoke FIELDS — do testów fields-*
# (tłumaczenia wartości pól):
#   czwarty   http://pola.test,  prefiks pola_   (EVK_WP4_PATH)
# Osobny, bo aktywny Fields na pierwszym zmieniałby panel i dane widziane
# przez pozostałe zestawy. Fields to osobne repozytorium: EVK_FIELDS_REPO,
# domyślnie katalog evoke-fields obok tego repozytorium. Bez niego testy
# fields-* zapalają się na czerwono z instrukcją.
# Piąty (1.274.0) ma prawdziwy motyw Bricks — do testów renderu (bricks-render):
#   piąty     http://bricks.test, prefiks bricks_ (EVK_WP5_PATH)
# Motyw NIE jest w tym repozytorium (publiczne): zip z EVK_BRICKS_ZIP, domyślnie
# najnowszy bricks*.zip z katalogu bricks-motyw obok (prywatne repozytorium
# bidero/bricks-motyw). Bez licencji Bricks nie otwiera buildera — render
# strony działa. Bez zipa piąty stoi bez Bricksa, a bricks-render jest czerwony.
#
# Gdzie stawia — zmienne, wszystkie z wartościami domyślnymi:
#   EVK_WP_PATH   katalog WordPressa   (~/.cache/evk-testowy-wp)
#   EVK_WP2_PATH  katalog drugiego     (~/.cache/evk-testowy-wp2)
#   EVK_WP3_PATH  katalog trzeciego    (~/.cache/evk-testowy-wp3)
#   EVK_WP4_PATH  katalog czwartego    (~/.cache/evk-testowy-wp4)
#   EVK_FIELDS_REPO  repozytorium Evoke FIELDS (../evoke-fields)
#   EVK_WP5_PATH  katalog piątego      (~/.cache/evk-testowy-wp5)
#   EVK_BRICKS_ZIP   zip motywu Bricks  (../bricks-motyw/bricks.X.Y.Z.zip)
#   EVK_BRICKS_KLUCZ klucz licencji Bricksa — tylko jako zmienna środowiska
#                    (sekret środowiska sesji), NIGDY w repozytorium ani w pliku
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
EVK_WP2_PATH="${EVK_WP2_PATH:-$HOME/.cache/evk-testowy-wp2}"
EVK_WP3_PATH="${EVK_WP3_PATH:-$HOME/.cache/evk-testowy-wp3}"
EVK_WP4_PATH="${EVK_WP4_PATH:-$HOME/.cache/evk-testowy-wp4}"
EVK_WP5_PATH="${EVK_WP5_PATH:-$HOME/.cache/evk-testowy-wp5}"
EVK_WP_DB="${EVK_WP_DB:-evk_test}"
EVK_WP_USER="${EVK_WP_USER:-evk}"
EVK_WP_PASS="${EVK_WP_PASS:-evk}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
EVK_FIELDS_REPO="${EVK_FIELDS_REPO:-$(dirname "$REPO")/evoke-fields}"
# Tylko oryginalne paczki „bricks.X.Y.Z.zip” — bez przeróbek (np. z dopiskiem w nazwie).
EVK_BRICKS_ZIP="${EVK_BRICKS_ZIP:-$(ls -1 "$(dirname "$REPO")"/bricks-motyw/ 2>/dev/null | grep -E '^bricks\.[0-9.]+\.zip$' | sort -V | tail -1 \
    | sed "s|^|$(dirname "$REPO")/bricks-motyw/|" || true)}"
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
    rm -rf "$EVK_WP_PATH" "$EVK_WP2_PATH" "$EVK_WP3_PATH" "$EVK_WP4_PATH" "$EVK_WP5_PATH"
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

# ── Drugi WordPress: kopia plików pierwszego, własna konfiguracja ─────────
wp2() { php "$CLI" --allow-root --path="$EVK_WP2_PATH" "$@"; }
if [ ! -f "$EVK_WP2_PATH/wp-includes/version.php" ]; then
    krok "drugi WordPress (nowa.test, prefiks nowy_)"
    mkdir -p "$EVK_WP2_PATH"
    ( cd "$EVK_WP_PATH" && tar --exclude=./wp-config.php --exclude='./wp-content/plugins/evoke-one' \
        --exclude='./wp-content/evk-backups-*' --exclude='./wp-content/uploads' -cf - . ) | ( cd "$EVK_WP2_PATH" && tar -xf - )
fi
if [ ! -f "$EVK_WP2_PATH/wp-config.php" ]; then
    wp2 config create --dbname="$EVK_WP_DB" --dbuser="$EVK_WP_USER" --dbpass="$EVK_WP_PASS" \
        --dbhost=localhost --dbprefix=nowy_ --skip-check >/dev/null
fi
if ! wp2 core is-installed 2>/dev/null; then
    wp2 core install --url=http://nowa.test --title="Evoke nowa" --admin_user=nowy \
        --admin_password=nowy --admin_email=admin@nowa.test --skip-email >/dev/null
fi
ln -sfn "$REPO" "$EVK_WP2_PATH/wp-content/plugins/evoke-one"
wp2 plugin activate evoke-one >/dev/null 2>&1 || true

# ── Trzeci WordPress: jednorazowy, do testu odinstalowania ────────────────
wp3() { php "$CLI" --allow-root --path="$EVK_WP3_PATH" "$@"; }
if [ ! -f "$EVK_WP3_PATH/wp-includes/version.php" ]; then
    krok "trzeci WordPress (usun.test, prefiks usun_)"
    mkdir -p "$EVK_WP3_PATH"
    ( cd "$EVK_WP_PATH" && tar --exclude=./wp-config.php --exclude='./wp-content/plugins/evoke-one' \
        --exclude='./wp-content/evk-backups-*' --exclude='./wp-content/uploads' -cf - . ) | ( cd "$EVK_WP3_PATH" && tar -xf - )
fi
if [ ! -f "$EVK_WP3_PATH/wp-config.php" ]; then
    wp3 config create --dbname="$EVK_WP_DB" --dbuser="$EVK_WP_USER" --dbpass="$EVK_WP_PASS" \
        --dbhost=localhost --dbprefix=usun_ --skip-check >/dev/null
fi
if ! wp3 core is-installed 2>/dev/null; then
    wp3 core install --url=http://usun.test --title="Evoke usun" --admin_user=usun \
        --admin_password=usun --admin_email=admin@usun.test --skip-email >/dev/null
fi
ln -sfn "$REPO" "$EVK_WP3_PATH/wp-content/plugins/evoke-one"
wp3 plugin activate evoke-one >/dev/null 2>&1 || true

# ── Czwarty WordPress: Evoke ONE + Evoke FIELDS (testy fields-*) ─────────
wp4() { php "$CLI" --allow-root --path="$EVK_WP4_PATH" "$@"; }
if [ ! -f "$EVK_WP4_PATH/wp-includes/version.php" ]; then
    krok "czwarty WordPress (pola.test, prefiks pola_)"
    mkdir -p "$EVK_WP4_PATH"
    ( cd "$EVK_WP_PATH" && tar --exclude=./wp-config.php --exclude='./wp-content/plugins/evoke-one' \
        --exclude='./wp-content/evk-backups-*' --exclude='./wp-content/uploads' -cf - . ) | ( cd "$EVK_WP4_PATH" && tar -xf - )
fi
if [ ! -f "$EVK_WP4_PATH/wp-config.php" ]; then
    wp4 config create --dbname="$EVK_WP_DB" --dbuser="$EVK_WP_USER" --dbpass="$EVK_WP_PASS" \
        --dbhost=localhost --dbprefix=pola_ --skip-check >/dev/null
fi
if ! wp4 core is-installed 2>/dev/null; then
    wp4 core install --url=http://pola.test --title="Evoke pola" --admin_user=admin \
        --admin_password=admin --admin_email=admin@pola.test --skip-email >/dev/null
fi
ln -sfn "$REPO" "$EVK_WP4_PATH/wp-content/plugins/evoke-one"
wp4 plugin activate evoke-one >/dev/null 2>&1 || true
if [ -f "$EVK_FIELDS_REPO/evk-repeater.php" ]; then
    ln -sfn "$EVK_FIELDS_REPO" "$EVK_WP4_PATH/wp-content/plugins/evoke-fields"
    wp4 plugin activate evoke-fields >/dev/null 2>&1 || true
    POLA="$EVK_WP4_PATH ($(wp4 plugin get evoke-fields --field=version 2>/dev/null || echo '?'), dowiązanie do $EVK_FIELDS_REPO)"
else
    POLA="$EVK_WP4_PATH — BRAK Evoke FIELDS w $EVK_FIELDS_REPO (ustaw EVK_FIELDS_REPO), testy fields-* będą czerwone"
fi

# ── Piąty WordPress: Evoke ONE + motyw Bricks (testy bricks-*) ──────────
wp5() { php "$CLI" --allow-root --path="$EVK_WP5_PATH" "$@"; }
if [ ! -f "$EVK_WP5_PATH/wp-includes/version.php" ]; then
    krok "piąty WordPress (bricks.test, prefiks bricks_)"
    mkdir -p "$EVK_WP5_PATH"
    ( cd "$EVK_WP_PATH" && tar --exclude=./wp-config.php --exclude='./wp-content/plugins/evoke-one' \
        --exclude='./wp-content/evk-backups-*' --exclude='./wp-content/uploads' -cf - . ) | ( cd "$EVK_WP5_PATH" && tar -xf - )
fi
if [ ! -f "$EVK_WP5_PATH/wp-config.php" ]; then
    wp5 config create --dbname="$EVK_WP_DB" --dbuser="$EVK_WP_USER" --dbpass="$EVK_WP_PASS" \
        --dbhost=localhost --dbprefix=bricks_ --skip-check >/dev/null
fi
if ! wp5 core is-installed 2>/dev/null; then
    wp5 core install --url=http://bricks.test --title="Evoke bricks" --admin_user=admin \
        --admin_password=admin --admin_email=admin@bricks.test --skip-email >/dev/null
fi
ln -sfn "$REPO" "$EVK_WP5_PATH/wp-content/plugins/evoke-one"
wp5 plugin activate evoke-one >/dev/null 2>&1 || true
if [ -n "$EVK_BRICKS_ZIP" ] && [ -f "$EVK_BRICKS_ZIP" ]; then
    MOTYW="$EVK_WP5_PATH/wp-content/themes/bricks"
    SUMA="$(md5sum < "$EVK_BRICKS_ZIP" | cut -d' ' -f1)"
    if [ "$(cat "$MOTYW/.evk-zip" 2>/dev/null || true)" != "$SUMA" ]; then
        krok "motyw Bricks z $(basename "$EVK_BRICKS_ZIP")"
        rm -rf "$MOTYW"
        unzip -q "$EVK_BRICKS_ZIP" -d "$EVK_WP5_PATH/wp-content/themes/"
        echo "$SUMA" > "$MOTYW/.evk-zip"
    fi
    wp5 theme activate bricks >/dev/null 2>&1 || true
    # Licencja (builder): BRICKS_LICENSE_KEY (Bricks 2.4+) czytana ze zmiennej
    # środowiska przy KAŻDYM uruchomieniu — klucz nie ląduje w wp-config.php
    # ani w bazie. Bez zmiennej stała jest pusta, a Bricks działa jak bez licencji.
    wp5 config set BRICKS_LICENSE_KEY "getenv('EVK_BRICKS_KLUCZ') ?: ''" --raw --type=constant >/dev/null
    LICENCJA="bez licencji (builder zamknięty; ustaw EVK_BRICKS_KLUCZ)"
    if [ -n "${EVK_BRICKS_KLUCZ:-}" ]; then
        LICENCJA="licencja: $(wp5 eval '
            \Bricks\License::$license_key = \Bricks\License::get_license_key();
            if (get_transient("bricks_license_status") !== "active") \Bricks\License::activate_license();
            echo \Bricks\License::license_is_valid() ? "aktywna" : "NIEAKTYWNA (" . get_transient("bricks_license_status") . ")";' 2>/dev/null || echo 'błąd aktywacji')"
    fi
    BRICKS="$EVK_WP5_PATH (Bricks $(wp5 theme get bricks --field=version 2>/dev/null || echo '?'), $LICENCJA)"
else
    BRICKS="$EVK_WP5_PATH — BRAK motywu Bricks (EVK_BRICKS_ZIP albo ../bricks-motyw/bricks*.zip), test bricks-render będzie czerwony"
fi

krok "gotowe"
echo "   WordPress: $EVK_WP_PATH ($(wp core version))"
echo "   drugi:     $EVK_WP2_PATH ($(wp2 option get home))"
echo "   trzeci:    $EVK_WP3_PATH ($(wp3 option get home))"
echo "   czwarty:   $POLA"
echo "   piąty:     $BRICKS"
echo "   wtyczka:   $(wp plugin get evoke-one --field=version) (dowiązanie do $REPO)"
echo "   testy:     node tests/run.js backup-baza"
