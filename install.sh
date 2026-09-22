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

# ---- [1/5] Stop running services (upgrade-safe) ----
echo "${C_Y}[1/5] Stopping running services...${C_0}"
command -v locadev >/dev/null 2>&1 && locadev stop >/dev/null 2>&1 || true

# ---- [2/5] Repo (thin) ----
echo "${C_Y}[2/5] Downloading Locadev (scripts + dashboard)...${C_0}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
case "$REPO_URL" in
    file://*) cp "${REPO_URL#file://}" "$TMP/repo.tgz" ;;
    *) curl -fsSL "$REPO_URL" -o "$TMP/repo.tgz" ;;
esac
mkdir -p "$INSTALL_DIR"
# Excludes are matched against the ARCHIVE's member names (locadev-main/sites/...), so a
# pattern written as "sites/*" matches nothing at all: tar would then be free to replace a
# symlinked sites/ (user data shared with another OS) with a real, empty directory.
tar -xzf "$TMP/repo.tgz" -C "$INSTALL_DIR" --strip-components=1 \
    --exclude='*/data' --exclude='*/data/*' \
    --exclude='*/sites' --exclude='*/sites/*' \
    --exclude='*/config/sites' --exclude='*/config/sites/*' --exclude='*/config/sites.json'

# ---- [3/5] FrankenPHP binary ----
echo "${C_Y}[3/5] Downloading FrankenPHP for $OS $ARCH...${C_0}"
case "$FP_URL" in
    file://*) cp "${FP_URL#file://}" "$INSTALL_DIR/bin/frankenphp" ;;
    *) curl -fL --progress-bar "$FP_URL" -o "$INSTALL_DIR/bin/frankenphp" ;;
esac
chmod +x "$INSTALL_DIR/bin/frankenphp" "$INSTALL_DIR/bin/locadev"

# Linux: non-root users cannot bind ports <1024 - allow :443 without sudo
if [ "$OS" = Linux ] && command -v setcap >/dev/null 2>&1; then
    if sudo setcap cap_net_bind_service=+ep "$INSTALL_DIR/bin/frankenphp" 2>/dev/null; then
        echo "${C_G}[OK] FrankenPHP can bind :443 as non-root (setcap).${C_0}"
    else
        echo "${C_Y}[NOTE] setcap failed - https://localhost (port 443) will not work${C_0}"
        echo "  for non-root users until you run:"
        echo "  sudo setcap cap_net_bind_service=+ep $INSTALL_DIR/bin/frankenphp"
    fi
fi

# ---- [4/5] PATH ----
echo "${C_Y}[4/5] Registering 'locadev' command...${C_0}"
mkdir -p "$HOME/.local/bin"
ln -sf "$INSTALL_DIR/bin/locadev" "$HOME/.local/bin/locadev"
SHELL_RC=""
[ -f "$HOME/.zshrc" ] && SHELL_RC="$HOME/.zshrc"
[ -z "$SHELL_RC" ] && [ -f "$HOME/.bashrc" ] && SHELL_RC="$HOME/.bashrc"
[ -z "$SHELL_RC" ] && [ -f "$HOME/.profile" ] && SHELL_RC="$HOME/.profile"
if [ -n "$SHELL_RC" ] && ! grep -q '.local/bin' "$SHELL_RC" 2>/dev/null; then
    echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$SHELL_RC"
fi

# ---- [5/5] MariaDB: auto-install via detected package manager ----
if command -v mariadbd >/dev/null 2>&1 || command -v mysqld >/dev/null 2>&1; then
    echo "${C_G}[5/5] MariaDB: already installed.${C_0}"
else
    echo "${C_Y}[5/5] MariaDB not found - installing via system package manager...${C_0}"
    case "$(command -v pacman apt-get dnf zypper brew 2>/dev/null | head -n1)" in
        *pacman)  PKG_CMD="pacman -S --needed mariadb" ;;
        *apt-get) PKG_CMD="apt-get update && apt-get install -y mariadb-server" ;;
        *dnf)     PKG_CMD="dnf install -y mariadb-server" ;;
        *zypper)  PKG_CMD="zypper --non-interactive install mariadb-server" ;;
        *brew)    PKG_CMD="brew install mariadb"; NO_SUDO=1 ;;
        *)        PKG_CMD="" ;;
    esac
    if [ -z "$PKG_CMD" ]; then
        echo "${C_Y}[NOTE] Unknown package manager. Install MariaDB manually, then run 'locadev start'.${C_0}"
    elif [ -n "$NO_SUDO" ]; then
        $PKG_CMD \
            || echo "${C_R}[ERROR] $PKG_CMD failed - install manually.${C_0}"
    else
        sudo sh -c "$PKG_CMD" \
            || { echo "${C_R}[ERROR] Auto-install failed. Install manually:${C_0}"
                 echo "  sudo $PKG_CMD"; }
    fi
fi

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
