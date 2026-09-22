<div align="center">

  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="dashboard/assets/locadev-white.svg">
    <img src="dashboard/assets/locadev.svg" alt="Locadev" width="280">
  </picture>

# Locadev

A self-contained local web development environment — FrankenPHP, Caddy & MariaDB — for Windows, Linux, and macOS. No Docker. No WSL. No configuration.

[Report an Issue](https://github.com/bismawy/locadev/issues)

![Windows](https://img.shields.io/badge/Windows-x64-0078D6?logo=windows&logoColor=white)
![Linux](https://img.shields.io/badge/Linux-x86__64%20%7C%20aarch64-FCC624?logo=linux&logoColor=black)
![macOS](https://img.shields.io/badge/macOS-arm64%20%7C%20Intel-999999?logo=apple&logoColor=white)
![FrankenPHP](https://img.shields.io/badge/PHP-FrankenPHP%20%26%20Caddy-7A86B8?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

</div>

<img src="design/banner.webp?v=2" alt="Locadev dashboard" width="100%">

## Features

- **One Folder, Everything Included:** FrankenPHP + Caddy + PHP and MariaDB bundled in a single directory on Windows; Linux/macOS fetch the matching official static binaries.
- **Automatic Local HTTPS:** Every site gets its own `https://<name>.localhost` domain with TLS auto-provisioned by Caddy's internal CA — no port numbers, no certificate warnings after the first trust.
- **Web Dashboard:** Create, enable/disable, and delete sites visually; create/drop databases; hot-reload Caddy with zero downtime. No terminal required.
- **Share a Site Online:** One click in the dashboard Actions column publishes a site through a free Cloudflare Quick Tunnel — no account, no DNS; `cloudflared` is fetched automatically on first use.
- **CMS & Framework Presets:** One click installs ClassicPress or WordPress, or scaffolds a fresh Laravel app via Composer (auto-downloaded, `.env` + `APP_KEY` + migrations included).
- **Background by Design:** Services start hidden, poll their ports until actually ready, and stay out of your taskbar. Stop is graceful (admin API + `mariadb-admin shutdown`), never a hard kill.
- **Cross-Platform CLI:** The same `locadev` command works identically in PowerShell, CMD, Git Bash, zsh, and plain sh.
- **Upgrade-Safe Data:** Re-running the installer upgrades in place — `data/`, `sites/`, and your site registry are never touched.

## Installation

<details open>
<summary><b>Windows</b> (PowerShell)</summary>

```powershell
irm https://raw.githubusercontent.com/bismawy/locadev/main/install.ps1 | iex
```

- Installs to `C:\Users\<you>\Locadev` (override with `$env:LOCADEV_HOME` before running).
- Downloads the binary bundle (FrankenPHP, PHP, MariaDB) for Windows x64.
- Registers `locadev` on your user PATH, starts everything, and opens the dashboard.

</details>

<details>
<summary><b>Linux</b> (x86_64 &amp; aarch64)</summary>

```bash
curl -fsSL https://raw.githubusercontent.com/bismawy/locadev/main/install.sh | bash
```

- Installs to `~/locadev` (override with `LOCADEV_HOME=...`).
- Downloads the official static FrankenPHP build for your architecture.
- Installs MariaDB automatically via your package manager (`pacman`, `apt`, `dnf`, `zypper`) if missing.
- Grants FrankenPHP permission to bind `:80`/`:443` without root (`setcap`). If that step is skipped, run:
  `sudo setcap cap_net_bind_service=+ep ~/locadev/bin/frankenphp`
- There is no `bin/php.ini` here (static FrankenPHP), so the dashboard's PHP Configuration and
  Extensions pages have nothing to edit — settings live in the `Caddyfile` and the PHP build
  itself. Those two pages only apply to the Windows install, which ships a bundled PHP.

</details>

<details>
<summary><b>macOS</b> (arm64 &amp; Intel)</summary>

```bash
curl -fsSL https://raw.githubusercontent.com/bismawy/locadev/main/install.sh | bash
```

- Same installer as Linux; downloads the official macOS FrankenPHP build for your architecture.
- Installs MariaDB via Homebrew if missing.

</details>

<details>
<summary><b>Manual install</b> (no installer)</summary>

1. Download &amp; extract the repository anywhere (e.g. `D:\Locadev` or `~/locadev`).
2. Add the `bin` folder to your PATH (optional, for the global `locadev` command).
3. Start it:

```cmd
:: Windows
start.bat            (or: bin\locadev.cmd start)
```

```bash
# Linux / macOS
./bin/locadev start  # background (recommended)
```

</details>

## Quick Start

```bash
locadev start     # start everything in the background (default command)
locadev open      # open the dashboard in your browser
```

Then, in the dashboard: **Create Site** → pick a preset (ClassicPress / WordPress / Laravel / blank) → open `https://yoursite.localhost` and finish the setup. The database is created for you.

| Command | What it does |
| :--- | :--- |
| `locadev` / `locadev start` | Start FrankenPHP &amp; MariaDB in the background |
| `locadev stop` | Gracefully stop all services |
| `locadev restart` | Restart all services |
| `locadev reload` | Hot-reload the Caddy config with zero downtime |
| `locadev status` | Check service status &amp; ports |
| `locadev db-export [file]` | Dump all user databases to a `.sql` file (default `data/db-sync.sql`) |
| `locadev db-import [file]` | Import a `.sql` dump, replacing the databases it contains |
| `locadev version` | Print the installed version (also `-v` / `--version`; every banner shows it too) |
| `locadev update` | Update Locadev in place (asks `y/N`; `--check` prints JSON, `--tag=vX.Y.Z` pins a version) |
| `locadev menu` | Interactive control menu |
| `locadev open` | Open the web dashboard in your browser |

## Updating

Terminal:

```bash
locadev update        # asks, then updates scripts, dashboard, CLI and config templates
locadev restart       # serve the new version
```

Dashboard: **System Info → Check for updates → Update now.** Progress streams into the panel
(polled every second) and ends with a **Restart server** button.

What an update does and does not touch:

| | |
| :--- | :--- |
| Updated | `dashboard/`, `scripts/`, `bin/locadev`, `Caddyfile`, `start.sh`/`stop.sh`, `README.md` |
| Never touched | `data/`, `sites/`, `config/sites/`, `config/sites.json`, `config/my.cnf`, `.git` |
| Never touched, on purpose | The binaries (`bin/frankenphp`, `bin/php.exe`, MariaDB) — Windows locks a running `.exe`, and on Linux replacing `bin/frankenphp` drops the `setcap` that lets it bind `:443`. Re-run the installer when you want newer binaries. |

The skip list is explicit code, not a `tar --exclude` pattern: the release archive names its entries
`locadev-main/sites/...`, so a pattern like `sites/*` never matched anything and `tar` was free to
replace a symlinked `sites/` (the dual-boot setup below) with a real directory.

If an update ever goes wrong, the previous version is one command away — release bundles are
immutable, so `locadev update --tag=v1.3.0` puts the old files back. Your data is never part of it.

## How It Works

<details>
<summary><b>Background operations</b></summary>

- **Start** launches each service *hidden* (PowerShell `Start-Process -WindowStyle Hidden` on Windows, background process on Unix), then polls the port until the service is actually ready (up to 30 s) before reporting `[ONLINE]`.
- **Stop** is graceful: Caddy is stopped via its admin API (`POST /stop`), MariaDB via `mariadb-admin shutdown`, and the process is awaited until fully flushed — force-kill is only a fallback, so no InnoDB crash recovery on the next start.
- **Reload** asks the running Caddy to re-read the Caddyfile with zero downtime — no dropped requests.

</details>

<details>
<summary><b>Directory layout</b></summary>

```text
Locadev/
├── Caddyfile              # global Caddy config (imports per-site blocks)
├── bin/                   # FrankenPHP, PHP, MariaDB, locadev CLI
├── config/
│   ├── my.cnf             # MariaDB settings template
│   ├── sites.json         # site registry used by the dashboard
│   └── sites/*.caddy      # generated per-site Caddy blocks
├── dashboard/             # the PHP web dashboard (https://localhost)
├── data/
│   ├── mariadb/           # database storage (auto-initialized on first run)
│   │   └── .locadev-version   # MariaDB version this data was last upgraded for
│   ├── cms/               # CMS .zip archives (versioned)
│   ├── composer/          # composer.phar + Composer package cache
│   ├── tunnels.json       # sites currently published online
│   └── tunnels/*.log      # cloudflared output, one file per site
└── sites/                 # your websites — one folder per <name>.localhost
```

</details>

<details>
<summary><b>Security model</b></summary>

```text
default_bind 127.0.0.1 ::1     # Caddy, admin API, and MariaDB: loopback only
```

- The dashboard API has no auth and MariaDB root has no password — this is safe *only* because everything stays on localhost. Do **not** remove the loopback bind unless you add auth.
- The **only** way anything leaves localhost is the per-site tunnel button (below). Those URLs are random, public, and unauthenticated — stop the tunnel when you are done sharing.

</details>

<details>
<summary><b>Cross-platform matrix</b></summary>

| Platform | FrankenPHP | MariaDB |
| :--- | :--- | :--- |
| Windows x64 | bundled `bin/frankenphp.exe` | bundled `bin/mariadb/` |
| Linux x86_64 / aarch64 | official static build (installer) | system `mariadbd` |
| macOS (arm64 / Intel) | official static build (installer) | Homebrew MariaDB |

</details>

<details>
<summary><b>Runtime versions (and swapping MariaDB)</b></summary>

Windows ships PHP, FrankenPHP and MariaDB inside `bin/`, so those versions only move when you install a new Locadev release. On Linux and macOS MariaDB comes from your package manager, so it follows your OS updates.

To run your **own** MariaDB instead of the bundled one (a newer release, or a second install), point the server at it:

```bash
LOCADEV_MARIADB_BIN=/opt/mariadb/bin/mariadbd locadev start     # bash
```

```bat
set LOCADEV_MARIADB_BIN=C:\mariadb\bin\mariadbd.exe
locadev start
```

Locadev keeps using its own `data/mariadb` and config either way. When the MariaDB binary changes - bundled update or override - `locadev start` notices and runs `mariadb-upgrade` once, so an older data directory is repaired instead of left serving errors. The version it last upgraded for is written to `data/mariadb/.locadev-version`; delete that file to force a re-check. If the upgrade fails, the start prints the command to run by hand and your data is untouched.

Current versions are on the dashboard: System Info reads them straight from the binaries — FrankenPHP together with the PHP and Caddy it embeds, MariaDB, and cloudflared ("not downloaded yet" until the first tunnel) — and the PHP Runtime card shows the PHP build. The **Copy version summary** button there puts a plain-text block on your clipboard for bug reports.

</details>

## Publishing a Site Online (Cloudflare Tunnel)

The **globe button** in a site's *Actions* column publishes that single site to the internet through a free Cloudflare Quick Tunnel.

- **First click:** a progress toast appears immediately (the first run downloads `cloudflared`, ~50 MB, into `bin/`, so this can take up to half a minute), then the finished toast reports the public URL.
- **While online:** the row gains a **link button** — it opens the public URL in a new tab and its tooltip shows the full URL. **Hover it to read the domain**; the URL is never printed inline.
- **Stop:** click the globe again, or disable/delete the site — Locadev kills the matching `cloudflared` process for you.
- **Ephemeral by design:** the URL is random, changes on every start, and dies with the process. Reloading Caddy does not restore tunnels, and `locadev stop` shuts them down along with the server; just click again when you need a new URL.
- **Only enabled sites** can be published, and the dashboard itself is never exposed.
- **CMS URLs are handled for you:** WordPress/ClassicPress store absolute URLs (`https://<name>.localhost`), which would leave remote visitors (e.g. phones) with missing CSS and fonts. When a tunnel starts, Locadev installs a small mu-plugin at `wp-content/mu-plugins/locadev-tunnel-url.php` that serves the site from the tunnel host for the requests arriving through it — no database change, and your local URL keeps working exactly as before. Only URLs generated by the CMS are rewritten; absolute URLs hard-coded inside post content are not.
- Treat the tunnel as a preview/sharing tool rather than a hosting replacement: the URL is random and changes on every start.

Each site's log lives in `data/tunnels/<id>.log`; running tunnels are tracked in `data/tunnels.json`.

## Dual-Boot: Same Sites on Windows & Linux

Share **code and config** between OSes; each OS keeps its own database.

1. Install Locadev on both OSes (each has its own `bin/` and `data/`).
2. On Linux, point sites + config at the Windows install (adjust the path):

```bash
cd ~/locadev
rm -rf sites config/sites config/sites.json
ln -s /path/to/Locadev/sites sites
ln -s /path/to/Locadev/config/sites config/sites
ln -s /path/to/Locadev/config/sites.json config/sites.json
```

3. Copy the database once (the Windows original stays untouched):

```bash
locadev stop
rm -rf ~/locadev/data/mariadb
cp -a /path/to/Locadev/data/mariadb ~/locadev/data/mariadb
locadev start
mariadb-upgrade -h 127.0.0.1 -u root
```

### Moving database changes between OSes

Site files are shared via the symlinks above, but each OS keeps its own database. To carry database changes across, dump on one side and import on the other (put the file anywhere both OSes can read, e.g. the shared drive):

```bash
# leaving this OS
locadev db-export /path/to/Locadev/data/db-sync.sql

# arriving on the other OS
locadev db-import /path/to/Locadev/data/db-sync.sql
```

`db-export` dumps all user databases; `db-import` replaces the databases contained in the dump. System databases are never touched.

## Troubleshooting

<details>
<summary>Extensions are missing (no curl, zip or pdo_mysql)</summary>

`bin/php.ini` ships with the maintainer's `extension_dir`; `locadev start` repoints it
at your actual install directory. If extensions are missing (curl, zip, pdo_mysql all
vanish together), that line is the place to look:

```bash
grep extension_dir bin/php.ini    # should be <your install>/bin/ext
locadev restart                   # repoints it, then relaunches
```

</details>

<details>
<summary>Tunnel button does nothing / no public URL appears</summary>

The first click needs one outbound HTTPS request to `github.com` (to fetch `cloudflared`);
after that cloudflared needs outbound access to `*.argotunnel.com`. Check the log:

```bash
cat data/tunnels/<site-id>.log
```

- `Download failed` → no internet, or the GitHub release is unreachable from this machine.
- `cloudflared did not come up` → egress on UDP 7844 (QUIC) and HTTPS is blocked or filtered —
  common on corporate/guest networks. Stop the tunnel and retry from another network.
- Nothing at all in the log → the site is disabled (enable it first) or the request never reached PHP.

`locadev stop` stops every running tunnel for you (cloudflared does not die with FrankenPHP,
which would otherwise leave public URLs answering 502). To be sure no stray process survived a
crash: `tasklist | findstr cloudflared` (Windows) or `pgrep -a cloudflared` (Linux/macOS).

</details>

<details>
<summary>Service didn't start / reports OFFLINE</summary>

```bash
locadev status          # what's online?
locadev restart         # clean slate
```

- MariaDB errors: check `data/mariadb/*.err`.
- FrankenPHP errors: run `./bin/frankenphp run --config Caddyfile` in a terminal to see the output directly.
- On Linux: is port 3306 already taken by a system service? A running system MariaDB is detected and reused.
- On Linux: `https://localhost` refuses to connect → `sudo setcap cap_net_bind_service=+ep bin/frankenphp` (non-root can't bind ports < 1024).

</details>

<details>
<summary>Browser warns about the self-signed certificate</summary>

Certificates are auto-provisioned by Caddy's internal CA for every `*.localhost` domain. Your browser may show a warning on first visit — proceed, or install the local CA:

```bash
bin/frankenphp trust   # Windows/Linux: install the Caddy local CA into the system trust store
```

</details>

<details>
<summary>The <code>locadev</code> command isn't found after installing</summary>

- **Windows:** open a *new* terminal (PATH changes don't affect already-open windows).
- **Linux/macOS:** run `source ~/.zshrc` (or `~/.bashrc`), or open a new terminal — `~/.local/bin` was added on install.

</details>

<details>
<summary>Changing the install location / repo source</summary>

Both installers accept environment overrides before running:

```powershell
$env:LOCADEV_HOME = "D:\Locadev"
$env:LOCADEV_GH    = "you/locadev"
irm https://raw.githubusercontent.com/bismawy/locadev/main/install.ps1 | iex
```

```bash
LOCADEV_HOME=/srv/locadev LOCADEV_GH=you/locadev \
  curl -fsSL https://raw.githubusercontent.com/bismawy/locadev/main/install.sh | bash
```

</details>

<details>
<summary>MariaDB refuses to start after a Locadev update (or a MariaDB swap)</summary>

MariaDB will not serve a data directory written by an older major version until its system tables are upgraded. `locadev start` normally does that for you and reports it:

```text
[Locadev] MariaDB 10.11.6 -> 11.4.5, upgrading the database...
[Locadev] Database upgraded to 11.4.5.
```

If it reports a failure instead, nothing was modified: run the command it prints, then start again.

```bash
mariadb-upgrade -h 127.0.0.1 -P 3306 -u root
```

Only `data/mariadb/.locadev-version` is updated once the upgrade succeeds, so a failed upgrade simply retries on the next start.

</details>

## License

Distributed under the **MIT** license.

## Developer

Self-check for the paths that fail silently (JSON mode, CMS zip cache, CSRF fence, tunnel state, MariaDB version marker):

```bash
bin/php.exe scripts/self-check.php                 # Windows (bundled PHP)
bin/frankenphp php-cli scripts/self-check.php      # Linux / macOS
```

Release assets are built with `scripts/make-bundles.sh`; upload `dist/*` to a GitHub release. The repo bundle is produced by `git archive`, so only committed files can ship and user data (`config/sites.json`, `config/sites/*.caddy`, `data/`) can never leak into a release; pass a tag to rebuild an older release (`scripts/make-bundles.sh v1.1.0`).

Developed and maintained by [Bisma](https://github.com/bismawy).
