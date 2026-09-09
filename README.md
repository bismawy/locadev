<p align="center">
  <img src="assets/locadev.svg" alt="Locadev" width="280">
</p>

<p align="center">
  <em>Just runs. Natively.</em><br>
  A self-contained local web development environment — FrankenPHP, Caddy & MariaDB —<br>
  for <b>Windows</b>, <b>Linux</b>, and <b>macOS</b>. No Docker. No WSL. No configuration.
</p>

---

## ✨ What is Locadev?

Locadev gives you a production-grade local PHP stack in one folder:

- **FrankenPHP + Caddy** — one binary serves everything: PHP 8.x, automatic local **HTTPS** (`.localhost` domains, TLS auto-provisioned), HTTP/2, gzip/zstd.
- **MariaDB** — bundled on Windows, auto-started from your package manager on Linux/macOS.
- **Web Dashboard** — create, enable/disable, and delete sites visually; create/drop databases; hot-reload Caddy with **zero downtime**. No terminal required.
- **Presets: ClassicPress, WordPress & Laravel** — one click installs the CMS, or scaffolds a fresh Laravel app via Composer (auto-downloaded, `.env` + `APP_KEY` + migrations included).
- **Background by design** — services start hidden, verify readiness, and stay out of your taskbar/terminal.

| | |
|---|---|
| Dashboard | `https://localhost` (alias `https://locadev.localhost`) |
| Websites | `https://<site-name>.localhost` — no port needed |
| MariaDB | `127.0.0.1:3306` · user `root` · empty password |
| Security | Loopback-only bind (`127.0.0.1` / `::1`) — never exposed to your LAN |

---

## 🚀 One-Time Installation

Copy **one** line for your platform, paste it into a terminal, done. That's it.

<details open>
<summary><b>Windows</b> (PowerShell)</summary>

```powershell
irm https://raw.githubusercontent.com/bismawy/locadev/main/install.ps1 | iex
```

- Installs to `C:\Users\<you>\Locadev` (override with `$env:LOCADEV_HOME` before running).
- Registers the `locadev` command on your user PATH.
- Starts everything in the background and opens the dashboard.

</details>

<details>
<summary><b>Linux</b> (Bash — x86_64 &amp; aarch64)</summary>

```bash
curl -fsSL https://raw.githubusercontent.com/bismawy/locadev/main/install.sh | bash
```

- Installs to `~/locadev` (override with `LOCADEV_HOME=...`).
- Downloads the official static FrankenPHP build for your architecture.
- Needs `mariadb-server` from your package manager if it isn't already installed (`apt`, `pacman`, `dnf`…).
- Registers `locadev` in `~/.local/bin` — restart your terminal afterwards.

</details>

<details>
<summary><b>macOS</b> (arm64 &amp; Intel)</summary>

```bash
curl -fsSL https://raw.githubusercontent.com/bismawy/locadev/main/install.sh | bash
```

- Same installer as Linux; downloads the official macOS FrankenPHP build for your architecture.
- Needs MariaDB: `brew install mariadb` (if not installed, Locadev tells you).

</details>

<details>
<summary><b>Manual install</b> (zip / clone, no installer)</summary>

1. Download &amp; extract the repository anywhere (e.g. `D:\Locadev` or `~/locadev`).
2. Add the `bin` folder to your PATH (optional, for the global `locadev` command).
3. Start it:

```cmd
:: Windows
start.bat            (or: bin\locadev.cmd start)
```

```bash
# Linux / macOS
./start.sh           # foreground, Ctrl+C to stop
./bin/locadev start  # background (recommended)
```

</details>

> **Note:** after publishing your fork, replace `username` in the one-liners above — or just copy the raw file URLs from your own repository page.

---

## ⚡ Quick Start

```bash
locadev start     # start everything in the background (default command)
locadev open      # open the dashboard in your browser
```

Then, in the dashboard: **Create Site** → pick a preset (ClassicPress / WordPress / Laravel / blank) → open `https://yoursite.localhost` and finish the setup. The database is created for you.

<details>
<summary><b>Laravel preset details</b></summary>

- Runs `composer create-project laravel/laravel` using Locadev's bundled PHP (Windows) or `frankenphp php-cli` (Linux/macOS) — Composer itself is downloaded once into `data/composer/composer.phar`, nothing extra to install.
- Auto-creates the MariaDB database, writes `.env` (`DB_*` + `APP_URL`), ensures `APP_KEY`, runs initial migrations, and points the document root at `/public`.
- Re-running on an existing project never re-installs over it (idempotent).
- No `artisan` terminal? It's there: `bin/php.exe sites/<name>/artisan` (Windows) or `bin/frankenphp php-cli sites/<name>/artisan` (Linux/macOS).

</details>

<details>
<summary>Global CLI reference</summary>

| Command | What it does |
| :--- | :--- |
| `locadev` / `locadev start` | Start FrankenPHP &amp; MariaDB in the background |
| `locadev stop` | Gracefully stop all services |
| `locadev status` | Check server status (Online / Offline) &amp; ports |
| `locadev reload` | Hot-reload the Caddy config with zero downtime |
| `locadev restart` | Restart all services |
| `locadev open` | Open the web dashboard in your default browser |

Works from any terminal: PowerShell, CMD, Git Bash, zsh, VS Code / Zed terminal.

</details>

---

## 🗂 How It Works

<details>
<summary><b>Background operations</b></summary>

- **Start** launches each service *hidden* (PowerShell `Start-Process -WindowStyle Hidden` on Windows, background process on Unix), then **polls the port until the service is actually ready** (up to 30 s) before reporting `[OK]`.
- **Stop** is graceful: Caddy is stopped via its admin API (`POST /stop`), MariaDB via `mariadb-admin shutdown` — force-kill is only a fallback.
- **Reload** asks the running Caddy to re-read the Caddyfile with zero downtime — no dropped requests.
- The Caddyfile caps FrankenPHP at `num_threads 8` — plenty for local dev while keeping worst-case memory (`8 × 256M`) well under RAM.

</details>

<details>
<summary><b>Directory layout</b></summary>

```
Locadev/
├── Caddyfile              # global Caddy config (imports per-site blocks)
├── start.bat / start.sh   # launcher scripts
├── stop.bat  / stop.sh
├── install.ps1 / install.sh
├── bin/                   # FrankenPHP (Win + Linux), PHP, MariaDB (Windows), locadev CLI
├── config/
│   ├── my.cnf             # MariaDB settings (shared across OSes)
│   ├── sites.json         # site registry used by the dashboard
│   └── sites/*.caddy      # generated per-site Caddy blocks
├── dashboard/             # the PHP web dashboard (served at https://localhost)
├── data/
│   ├── mariadb/           # database storage (auto-initialized on first Linux/macOS run)
│   ├── cms/               # CMS .zip archives (versioned)
│   └── composer/          # composer.phar + Composer package cache
└── sites/                 # your websites — one folder per <name>.localhost
```

</details>

<details>
<summary><b>Cross-platform details</b></summary>

| Platform | FrankenPHP | MariaDB |
| :--- | :--- | :--- |
| Windows | bundled `bin/frankenphp.exe` | bundled `bin/mariadb/` |
| Linux x86_64 | bundled static `bin/frankenphp` | system `mariadbd` |
| Linux aarch64 | auto-downloaded by installer | system `mariadbd` |
| macOS (arm64/Intel) | auto-downloaded by installer | Homebrew MariaDB |

- On Linux/macOS, the Windows-style paths inside `config/my.cnf` are **overridden at launch** (`--datadir`, `--socket`), so one shared config works everywhere — including a dual-boot Windows/Linux machine sharing the same data directory.
- The first run on Linux/macOS **auto-initializes** `data/mariadb` (`mariadb-install-db`) if it's empty.
- All service detection uses portable port checks (`nc` → `lsof` → `ss` → `netstat`), so it behaves the same in Git Bash, zsh, and plain sh.

</details>

<details>
<summary><b>Security model</b></summary>

- `default_bind 127.0.0.1 ::1` — Caddy, the admin API, and MariaDB's bind address are **loopback only**. Nothing is reachable from your LAN.
- The dashboard API has no auth and MariaDB root has no password — this is safe *only* because everything stays on localhost. Do **not** remove the loopback bind unless you add auth.

</details>

---

## 🧰 Troubleshooting

<details>
<summary>Service didn't start / reports OFFLINE</summary>

```bash
locadev status          # what's online?
locadev restart         # clean slate
```

- MariaDB errors: check `data/mariadb/*.err`
- FrankenPHP errors: run `./bin/frankenphp run --config Caddyfile` in a terminal to see the output directly.
- On Linux/macOS: is MariaDB installed (`command -v mariadbd`)? Is port 3306 already taken by a system service? A running system MariaDB is detected and reused.

</details>

<details>
<summary>Browser warns about the self-signed certificate</summary>

Certificates are auto-provisioned by Caddy's internal CA for every `*.localhost` domain. Your browser may show a warning on first visit — proceed, or install the local CA:

```bash
locadev restart   # ensures the CA is present
```

Advanced: `bin/frankenphp trust` (Windows/Linux) installs the Caddy local CA into the system trust store.

</details>

<details>
<summary>The <code>locadev</code> command isn't found after installing</summary>

- **Windows:** open a *new* terminal (PATH changes don't affect already-open windows).
- **Linux/macOS:** run `source ~/.zshrc` (or `~/.bashrc`), or open a new terminal — `~/.local/bin` was added on install.

</details>

<details>
<summary>Changing the install location / repo source</summary>

Both installers accept overrides before running:

```powershell
$env:LOCADEV_HOME = "D:\Locadev"; $env:LOCADEV_REPO = "https://github.com/you/locadev/archive/main.zip"
irm <your-install.ps1-url> | iex
```

```bash
LOCADEV_HOME=/srv/locadev LOCADEV_REPO=https://github.com/you/locadev/archive/main.tar.gz \
  curl -fsSL <your-install.sh-url> | bash
```

</details>

---

## 🔌 Classtive Plugin Integration

The `Classtive` plugin is linked directly from `D:\Workspace\Classtive` via an NTFS Junction to:
`sites/classtive/wp-content/plugins/classtive`

Every code change in the plugin workspace is live on the ClassicPress site instantly.

---

<p align="center"><sub>Locadev — the local dev environment that <em>just runs, natively</em>.</sub></p>
