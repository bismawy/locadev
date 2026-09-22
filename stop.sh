#!/usr/bin/env bash
# Shortcut: all stop logic lives in bin/locadev (including killing tunnels, which
# do not die with FrankenPHP).
cd "$(dirname "$0")" || exit 1
echo "Stopping FrankenPHP and MariaDB..."
bash bin/locadev stop
