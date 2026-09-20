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
# Built from a commit, never from the working tree: git emits tracked files only, so user data
# (config/sites.json, config/sites/*.caddy, data/, sites/) can never ship. The old cp + hand
# written rm list DID leak those into the v1.0.0/v1.1.0 bundles. Pass a ref to rebuild an old
# release: scripts/make-bundles.sh v1.1.0
REF="${1:-HEAD}"
echo "==> locadev-repo.zip / locadev-repo.tar.gz (from $REF)"
# --worktree-attributes so the LF/CRLF rules in .gitattributes also apply when rebuilding an
# older tag that predates that file; autocrlf off so the maintainer's git config cannot
# rewrite every line ending in the bundle.
ARCHIVE=(git -C "$ROOT" -c core.autocrlf=false archive --worktree-attributes --prefix=locadev-main/ "$REF")
"${ARCHIVE[@]}" --format=zip > "$DIST/locadev-repo.zip"
"${ARCHIVE[@]}" --format=tgz > "$DIST/locadev-repo.tar.gz"

echo ""
ls -lh "$DIST"
echo ""
echo "Next: gh release create vX.Y.Z dist/locadev-*  (or upload via the web UI)"
