#!/usr/bin/env bash
# Maintainer-only: build release assets from a working Locadev install (run on Windows).
#
#   scripts/make-bundles.sh              -> dist/locadev-bin-win-x64.zip + dist/locadev-repo.{zip,tar.gz}
#
# Upload dist/* to a GitHub Release, then the one-line installers work:
#   irm  .../install.ps1 | iex        (Windows)
#   curl .../install.sh   | bash      (Linux/macOS - FrankenPHP comes from upstream)
set -e
cd "$(dirname "$0")/.."
ROOT="$PWD"
DIST="$ROOT/dist"
BSDTAR="/c/Windows/System32/tar.exe"   # bsdtar: can write zip via extension
[ -x "$BSDTAR" ] || BSDTAR="$(command -v bsdtar || command -v tar)"

mkdir -p "$DIST"
rm -rf "$DIST"/locadev-*.zip "$DIST"/locadev-repo.*

# ===== 1. Binary bundle: everything bin/ needs at runtime, minus Linux ELF & dev files =====
# NB: use native Windows tar directly from bin/ - MSYS cp/rm remap extensionless names
#     onto their .exe twins (frankenphp -> frankenphp.exe) and corrupt the copy.
echo "==> locadev-bin-win-x64.zip"
(cd "$ROOT/bin" && "$BSDTAR" -a -cf "$DIST/locadev-bin-win-x64.zip" \
    --exclude './frankenphp' \
    --exclude './dev' --exclude './extras' \
    --exclude './README.md' --exclude './news.txt' --exclude './snapshot.txt' \
    --exclude './locadev' --exclude './locadev.cmd' \
    --exclude './php.ini-development' --exclude './php.ini-production' \
    --exclude './phpdbg.exe' --exclude './php-cgi.exe' --exclude './php-win.exe' \
    --exclude './deplister.exe' --exclude './pharcommand.phar' --exclude './phar.phar.bat' \
    --exclude './php8apache2_4.dll' --exclude './php8embed.lib' --exclude './php8phpdbg.dll' \
    --exclude './cloudflared.exe' --exclude './cloudflared' \
    --exclude './license.txt' --exclude './readme-redist-bins.txt' \
    .)

# ===== 2. Thin repo bundle: scripts, dashboard, config, CLI (what installers download first) =====
echo "==> locadev-repo.zip / locadev-repo.tar.gz"
REPO_STAGE="$(mktemp -d)"
mkdir -p "$REPO_STAGE/locadev-main"
for item in .gitignore Caddyfile README.md install.ps1 install.sh \
            config dashboard scripts start.bat stop.bat start.sh stop.sh; do
    [ -e "$ROOT/$item" ] && cp -r "$ROOT/$item" "$REPO_STAGE/locadev-main/"
done
# bin/: only the two small CLI scripts - binaries ship in locadev-bin-win-x64.zip
mkdir -p "$REPO_STAGE/locadev-main/bin"
cp "$ROOT/bin/locadev" "$ROOT/bin/locadev.cmd" "$REPO_STAGE/locadev-main/bin/"
rm -f  "$REPO_STAGE/locadev-main/config/sites.json"
# User data must never ship: a leftover demo*.caddy makes fresh installs show phantom
# sites pointing at directories that do not exist (sites.json is gone, so the loader
# falls back to globbing config/sites/*.caddy).
rm -f  "$REPO_STAGE/locadev-main/config/sites/"*.caddy
mkdir -p "$REPO_STAGE/locadev-main/sites" "$REPO_STAGE/locadev-main/config/sites"
touch "$REPO_STAGE/locadev-main/sites/.gitkeep"

(cd "$REPO_STAGE" && "$BSDTAR" -a -cf "$DIST/locadev-repo.zip" locadev-main \
    && tar -czf "$DIST/locadev-repo.tar.gz" locadev-main)
rm -rf "$REPO_STAGE"

echo ""
ls -lh "$DIST"
echo ""
echo "Next: gh release create vX.Y.Z dist/locadev-*  (or upload via the web UI)"
