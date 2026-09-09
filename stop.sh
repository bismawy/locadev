#!/usr/bin/env bash
cd "$(dirname "$0")" || exit 1
echo "Stopping FrankenPHP and MariaDB..."
pkill -f frankenphp 2>/dev/null
if command -v mariadb-admin >/dev/null 2>&1; then
    mariadb-admin -h 127.0.0.1 -P 3306 -u root shutdown 2>/dev/null
fi
pkill -f mariadbd 2>/dev/null
echo "Locadev stopped successfully."
