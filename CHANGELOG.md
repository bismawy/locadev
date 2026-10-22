# Changelog

Locadev ships as a thin repo plus a per-platform binary bundle, so a release is the tag that
carries both. Entries are grouped by what a user notices, not by commit.

## v1.4.12 — 2026-10-22

Last piece of the launcher fix in 1.4.11.

### The bash CLI repairs `extension_dir` before PHP starts too

1.4.11 fixed this on Windows. `bin/locadev` (Linux, macOS, Git Bash) still started PHP first, so an
install whose `bin/php.ini` named another install failed the same way there: PHP could not load
`curl` or `openssl`, and `locadev update` reported "Cannot reach GitHub".

## v1.4.11 — 2026-10-22

Makes `locadev update` work on a machine that has more than one install.

### The launcher resolves the install root first

`bin/locadev.cmd` derived `PHPRC`, `PATH` and the version from `LOCADEV_DIR` at the top of the file,
but the updater runs from a copy in `%TEMP%` and only corrected `LOCADEV_DIR` further down. The copy
therefore ran one install's `php.exe` against another install's `php.ini`:

```
Warning: PHP Startup: Unable to load dynamic library 'curl' (tried: D:/Locadev/bin/ext\curl ...)
[update] Cannot reach GitHub to read the newest release. Nothing changed.
```

Because `curl` and `openssl` are among the extensions that fail to load, that surfaced as a network
error — and on a second install, as the `Entry Point Not Found` popup. The root is now resolved before
anything is derived from it.

### No hardcoded install paths

The maintainer's own `D:\Locadev` and `%USERPROFILE%\Locadev` were fallbacks in a file that ships to
every user, and they are what pointed the updater at the wrong install. Removed: a stray copy now
fails with `Cannot locate the install dir - no Caddyfile in "…"` instead of updating another install.

## v1.4.10 — 2026-10-22

Cleanup fix for the binary refresh.

### No more 90 MB of stale copies in `bin/`

A binary that was in use is renamed to `.old` and deleted at the end of the run, but the updater's
own mapped files (`php.exe`, `php8ts.dll`, the ICU and OpenSSL DLLs) cannot be deleted while it runs.
The sweep that removes them sat after the "Nothing to do" exits, so an install that stayed on one
release kept them: 13 files, ~90 MB, after a single sync. It now runs immediately after the update
lock, on every run including the no-op ones.

## v1.4.9 — 2026-10-22

Fixes the binary refresh added in 1.4.8, which could not finish.

### `locadev update` no longer runs out of memory

The 148 MB Windows binary bundle was read with `file_get_contents()`, so PHP held the body and its
copy at once and died on the 256 MB limit mid-update — after the thin layer had already been
copied. Downloads now stream straight to disk. Verified against the real v1.4.8 bundle with the
default `memory_limit`: 664 files written, `bin/php.ini` preserved.

## v1.4.8 — 2026-10-22

One command now repairs an install, not just its scripts.

### `locadev update` refreshes the runtime binaries too

It used to copy the thin layer only, so a stale FrankenPHP or PHP could only be fixed by re-running
the installer. It now syncs `bin/` from the release bundle as well — the bundle on Windows, the
upstream FrankenPHP binary on Linux/macOS. A file that is in use (the running server, the updater's
own `php.exe`, a mapped DLL) is renamed out of the way first, so the update no longer needs the
server stopped; the leftovers are deleted on the next run. `bin/php.ini` is never replaced, and the
new sync is recorded in `data/bin.json` so the 148 MB Windows bundle downloads once per release
rather than on every run. `locadev update --no-bin` keeps the old thin-layer-only behaviour.

### "Entry Point Not Found" no longer survives a second install

The shipped `bin/php.ini` carried the maintainer's absolute `extension_dir`. An install that kept it
loaded another install's extension DLLs and PHP refused to start — `smart_str_ferealloc@@24` in
`php_curl.dll`, `_safe_femalloc@@24` in `php_exif.dll` — before Locadev code could run, so no command
could fix it. `extension_dir` is now repointed at this install whenever it names a foreign
`*/bin/ext`, both by `locadev update` and by the launcher at start; a custom extension directory of
your own is left untouched.

## v1.4.7 — 2026-10-22

Security and runtime binary upgrades, plus dashboard runtime version parsing fix.

### FrankenPHP v1.13.2 / PHP 8.5.12 security upgrade
- Bundles upstream FrankenPHP v1.13.2 security release.
- Fixes PHP2Shell critical vulnerabilities (CVE-2026-109618 and CVE-2026-109619) with PHP 8.5.12.
- Patches temp directory isolation (GHSA-6q3j-62j9-f9hh), connection drops for queued worker requests, and cyclic array conversion crashes.
- Bundles Caddy v2.11.7.

### Dashboard runtime version parsing
- Uses a word boundary `/\bPHP\s+.../` when parsing runtime versions from the binary output to prevent `FrankenPHP` from overriding the PHP version.

## v1.4.6 — 2026-10-20

Fixes for problems that made a working install look broken. No new features, no data changes:
`data/`, `sites/` and the site registry are untouched by the update.

### Saving a site no longer hangs at 95%

The dashboard ran `frankenphp reload` synchronously from inside a request served by the very
process being reloaded. Caddy's config swap waits for in-flight requests to finish, so that request
waited for itself and the progress bar sat on "Hot-reloading FrankenPHP runtime" until the browser
gave up — while the site itself was already live.

The Caddyfile is now adapted first, which still reports a broken config as a real error, and the
reload runs detached. Affects saving, enabling/disabling and deleting a site, and the reload button.

### `locadev stop` stops only Locadev

On Linux and macOS the stop path ran `pkill -f frankenphp` and `pkill -f mariadbd`, which also
matches `frankenphp php-cli update.php` and the user's system MariaDB. Both the wait and the kill
are now scoped to this install, and they wait for the process to exit instead of for the port to
close. Windows behaviour is unchanged.

### Failed database work is no longer silent

`delete_site` and `save_site` swallowed a failed `DROP`/`CREATE DATABASE`. A site could report
success while its database was missing, or be created with no database at all. The error now
reaches the toast, the SSE progress line and the JSON response.

### Clearer failures

- The CLI fell back to the maintainer's own `D:/Locadev` when it could not find its install dir;
  every command then failed with confusing paths. It now stops with an explicit message.
- The installers read a `LOCADEV_VERSION` that changed nothing. Removed.
- A failed status query reported "online, 0 databases". It now reports offline.

### Local HTTPS is trusted on install

`https://<name>.localhost` showed "Not secure" because nothing ever trusted Caddy's local CA. Both
installers now install it — `install.ps1` in the per-user store (no admin rights, Windows asks
once), `install.sh` through `frankenphp trust`. Existing installs keep working; run
`bin/frankenphp trust` (Windows: `certutil -user -addstore Root %APPDATA%\Caddy\pki\authorities\local\root.crt`)
to get the padlock.

### Other

- `config/my.cnf` no longer hardcodes one machine's `datadir`. The server receives `--datadir`
  explicitly on Linux/macOS, and Windows regenerates its own `my.ini`.
- Indonesian code comments in the dashboard PHP, JS and CSS are now English, matching the rest of
  the codebase.

### Verification

`php -l` on every tracked PHP file, `scripts/self-check.php` (the two new GET-gate cases fail before
the fix and pass after), `bash -n` on the shell scripts, and the 3-OS CI matrix green on the merge
commit.
