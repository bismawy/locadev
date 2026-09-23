#!/usr/bin/env bash
# Shortcut: every bit of start logic lives in bin/locadev - MariaDB bootstrap, the php.ini
# extension_dir repoint, waiting for the ports to open, the banner with its URLs. This file used
# to carry a second copy of all that (and only it knew to run frankenphp in the foreground), so
# the two drifted: a fix in one was not a fix in the other. stop.sh already delegates; this does
# too, and Windows' start.bat does the same thing - one implementation per platform.
cd "$(dirname "$0")" || exit 1
bash bin/locadev start
