#!/usr/bin/env bash
# Locadev One-Line Installer for Linux and macOS.
# Windows (PowerShell):    irm https://raw.githubusercontent.com/USERNAME/locadev/main/install.ps1 | iex
# Linux / macOS:           curl -fsSL https://raw.githubusercontent.com/USERNAME/locadev/main/install.sh | bash
#
# Logic: thin repo (scripts/dashboard/config) + platform-matching FrankenPHP binary.
# MariaDB comes from the system package manager on Unix. User data (data/, sites/,
# config/sites.json) is never touched - re-running upgrades in place.
set -e

GH_REPO="${LOCADEV_GH:-bismawy/locadev}"
VERSION="${LOCADEV_VERSION:-latest}"
INSTALL_DIR="${LOCADEV_HOME:-$HOME/locadev}"
NO_START="${LOCADEV_NO_START:-0}"
OS="$(uname -s)"
ARCH="$(uname -m)"

case "$OS" in
    MINGW*|MSYS*|CYGWIN*)
        echo "Detected Windows (Git Bash). Use the PowerShell installer instead:"
        echo "  irm https://raw.githubusercontent.com/${GH_REPO}/main/install.ps1 | iex"
        exit 1
        ;;
esac

REPO_URL="${LOCADEV_REPO:-https://github.com/${GH_REPO}/releases/latest/download/locadev-repo.tar.gz}"
FP_URL="${LOCADEV_FP_URL:-}"   # override for testing / mirrors

C_Y=$'\033[33m'; C_G=$'\033[32m'; C_R=$'\033[31m'; C_0=$'\033[0m'
echo "==================================================="
echo "  Locadev - Just runs. Natively.  (installer)"
echo "==================================================="
echo "Target  : $INSTALL_DIR"
echo "Platform: $OS ($ARCH)"

# ---- Pick the matching FrankenPHP upstream binary ----
if [ -z "$FP_URL" ]; then
    case "$OS:$ARCH" in
        Linux:aarch64|Linux:arm64)  FP_URL="frankenphp-linux-aarch64" ;;
        Linux:x86_64)               FP_URL="frankenphp-linux-x86_64" ;;
        Darwin:arm64)               FP_URL="frankenphp-mac-arm64" ;;
        Darwin:*)                   FP_URL="frankenphp-mac-x86_64" ;;
        *)
            echo "${C_R}[ERROR] Unsupported platform: $OS $ARCH${C_0}"
            exit 1
            ;;
    esac
    FP_URL="https://github.com/dunglas/frankenphp/releases/latest/download/${FP_URL}"
fi

# ---- [1/4] Stop running services (upgrade-safe) ----
echo "${C_Y}[1/4] Stopping running services...${C_0}"
command -v locadev >/dev/null 2>&1 && locadev stop >/dev/null 2>&1 || true

# ---- [2/4] Repo (thin) ----
echo "${C_Y}[2/4] Downloading Locadev (scripts + dashboard)...${C_0}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
case "$REPO_URL" in
    file://*) cp "${REPO_URL#file://}" "$TMP/repo.tgz" ;;
    *) curl -fsSL "$REPO_URL" -o "$TMP/repo.tgz" ;;
esac
mkdir -p "$INSTALL_DIR"
tar -xzf "$TMP/repo.tgz" -C "$INSTALL_DIR" --strip-components=1 \
    --exclude="data" --exclude="sites/*"

# ---- [3/4] FrankenPHP binary ----
echo "${C_Y}[3/4] Downloading FrankenPHP for $OS $ARCH...${C_0}"
case "$FP_URL" in
    file://*) cp "${FP_URL#file://}" "$INSTALL_DIR/bin/frankenphp" ;;
    *) curl -fL --progress-bar "$FP_URL" -o "$INSTALL_DIR/bin/frankenphp" ;;
esac
chmod +x "$INSTALL_DIR/bin/frankenphp" "$INSTALL_DIR/bin/locadev"

# ---- [4/4] PATH + MariaDB check ----
echo "${C_Y}[4/4] Registering 'locadev' command...${C_0}"
mkdir -p "$HOME/.local/bin"
ln -sf "$INSTALL_DIR/bin/locadev" "$HOME/.local/bin/locadev"
SHELL_RC=""
[ -f "$HOME/.zshrc" ] && SHELL_RC="$HOME/.zshrc"
[ -z "$SHELL_RC" ] && [ -f "$HOME/.bashrc" ] && SHELL_RC="$HOME/.bashrc"
[ -z "$SHELL_RC" ] && [ -f "$HOME/.profile" ] && SHELL_RC="$HOME/.profile"
if [ -n "$SHELL_RC" ] && ! grep -q '.local/bin' "$SHELL_RC" 2>/dev/null; then
    echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$SHELL_RC"
fi

command -v mariadbd >/dev/null 2>&1 || command -v mysqld >/dev/null 2>&1 || {
    echo ""
    echo "${C_Y}[NOTE] MariaDB not found. Locadev needs it - install with:${C_0}"
    case "$OS" in
        Darwin) echo "  brew install mariadb" ;;
        *)      echo "  sudo apt install mariadb-server   # or: pacman -S mariadb / dnf install mariadb-server" ;;
    esac
}

echo ""
if [ "$NO_START" = "1" ]; then
    echo "${C_G}[OK] Installed to $INSTALL_DIR (start skipped).${C_0}"
else
    "$INSTALL_DIR/bin/locadev" start
    echo ""
    echo "${C_G}  [OK] Locadev installed and ready!${C_0}"
    echo "  Dashboard: https://localhost"
    echo "  CLI:       locadev | locadev menu  (restart terminal first)"
fi
