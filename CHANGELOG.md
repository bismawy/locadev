# Changelog

Locadev ships as a thin repo plus a per-platform binary bundle, so a release is the tag that
carries both. Entries are grouped by what a user notices, not by commit.

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
