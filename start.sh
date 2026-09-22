#!/usr/bin/env bash
cd "$(dirname "$0")" || exit 1
chmod +x ./bin/frankenphp 2>/dev/null || true
export PHPRC="$PWD/bin"
export PATH="$PWD/bin:$PATH"

echo "==================================================="
echo "  Locadev - Local Development Environment"
echo "==================================================="
echo "  Dashboard URL    : https://localhost"
echo "  Website URLs     : https://<site-name>.localhost"
echo "  MariaDB Host     : 127.0.0.1:3306 (User: root)"
echo "  Press Ctrl+C to stop the server."
echo "==================================================="
echo

# bin/php.ini may point extension_dir at another install (the shipped path, or a Windows
# path on a shared dual-boot drive). Repoint it only when that path is gone.
PHP_INI="$PWD/bin/php.ini"
if [ -f "$PHP_INI" ] && [ -d "$PWD/bin/ext" ]; then
    CUR_EXT=$(sed -n 's/^extension_dir[[:space:]]*=[[:space:]]*"\?\([^"]*\)"\?[[:space:]]*$/\1/p' "$PHP_INI" | head -n1)
    if [ -n "$CUR_EXT" ] && [ ! -d "$CUR_EXT" ]; then
        sed -i.bak "s|^extension_dir = .*|extension_dir = \"$PWD/bin/ext\"|" "$PHP_INI" && rm -f "$PHP_INI.bak"
        echo "[Locadev] Repointed php.ini extension_dir at $PWD/bin/ext"
    fi
fi

# Start MariaDB if not already running on port 3306
if ! nc -z 127.0.0.1 3306 2>/dev/null && ! lsof -nP -iTCP:3306 -sTCP:LISTEN >/dev/null 2>&1 && ! ss -tlpn 2>/dev/null | grep -q ":3306 "; then
    DB_BIN=""
    command -v mariadbd >/dev/null 2>&1 && DB_BIN="$(command -v mariadbd)"
    [ -z "$DB_BIN" ] && command -v mysqld >/dev/null 2>&1 && DB_BIN="$(command -v mysqld)"
    if [ -n "$DB_BIN" ]; then
        # First-run bootstrap: create the data directory if it is empty
        if [ ! -d "$PWD/data/mariadb/mysql" ]; then
            echo "[Locadev] Initializing MariaDB data directory (first run)..."
            mariadb-install-db --datadir="$PWD/data/mariadb" --auth-root-authentication-method=normal >/dev/null 2>&1 \
                || mysql_install_db --datadir="$PWD/data/mariadb" --auth-root-authentication-method=normal >/dev/null 2>&1
        fi
        echo "[Locadev] Starting MariaDB..."
        # --datadir/--socket override the Windows paths inside config/my.cnf
        "$DB_BIN" --defaults-file="$PWD/config/my.cnf" \
            --datadir="$PWD/data/mariadb" \
            --socket="${XDG_RUNTIME_DIR:-/tmp}/locadev-mariadb.sock" &
        MARIADB_PID=$!
        sleep 2
    else
        echo "[Locadev Note] mariadbd/mysqld is not installed on this system."
        echo "Please install it via your package manager (e.g. pacman -S mariadb / apt install mariadb-server / brew install mariadb)."
    fi
fi

cleanup() {
    echo ""
    echo "[Locadev] Stopping servers..."
    if [ -n "$MARIADB_PID" ]; then
        kill "$MARIADB_PID" 2>/dev/null
    fi
    if command -v mariadb-admin >/dev/null 2>&1; then
        mariadb-admin -h 127.0.0.1 -P 3306 -u root shutdown 2>/dev/null
    fi
    exit 0
}

trap cleanup SIGINT SIGTERM

./bin/frankenphp run --config Caddyfile
cleanup
