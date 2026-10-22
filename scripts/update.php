<?php
/**
 * Locadev updater - the engine behind `locadev update` and the dashboard button.
 *
 *   bin/frankenphp php-cli scripts/update.php          (interactive: asks y/N)
 *   bin/frankenphp php-cli scripts/update.php --yes     (required without a terminal)
 *   bin/frankenphp php-cli scripts/update.php --check   (JSON: installed / latest / update_available)
 *   bin/frankenphp php-cli scripts/update.php --tag=v1.2.0
 *   bin/frankenphp php-cli scripts/update.php --no-bin   (thin layer only)
 *
 * Two layers: the thin layer (scripts/, dashboard/, config templates, Caddyfile, CLI) and the
 * runtime binaries in bin/. Both are refreshed, because this is the single command a user runs to
 * fix an install. A locked binary (the running server, this updater's own php.exe) is renamed out
 * of the way first - Windows refuses to overwrite a running image but allows renaming it - so no
 * stop/start dance is needed; the leftovers are deleted on the next run.
 *
 * bin/php.ini is never replaced: it is the install's own config. The bundle's copy carries the
 * maintainer's absolute extension_dir, which made a second install load another install's DLLs and
 * die with "Entry Point Not Found". locadev_fix_extension_dir() repoints it at this install.
 *
 * User data is protected by an explicit skip list, NOT by a tar --exclude pattern: the
 * release archive names its entries locadev-main/sites/..., so install.sh's --exclude="sites/*"
 * never matched anything and tar was free to replace a symlinked sites/ with a real directory.
 * Here a symlink is never replaced, whatever it points at.
 */

const LOCADEV_REPO = 'bismawy/locadev';

/** Version the install is running, read from the code that ships it. '' if unreadable. */
function locadev_version(string $root): string {
    $api = $root . '/dashboard/api.php';
    if (!is_file($api)) {
        return '';
    }
    return preg_match("/define\('LOCODEV_VERSION',\s*'([^']+)'/", (string) file_get_contents($api), $m) ? $m[1] : '';
}

/**
 * Version without the release tag's leading 'v': "v1.4.0" and "1.4.0" are the same version.
 * Comparing the raw tag against the LOCODEV_VERSION constant (which has no 'v') never matched,
 * so an install on the newest release was reported as "ahead of the published release".
 */
function locadev_version_clean(string $version): string {
    return ltrim(trim($version), 'vV');
}

/**
 * True when the install is already at (or past) the release tag: nothing to download.
 * The 'v' made '1.4.3' === 'v1.4.3' false, so every run fetched and extracted a release only to
 * write back the files it already had. Also covers a checkout AHEAD of the release, which must
 * not be silently downgraded. An unreadable installed version is never "up to date": re-copying
 * is how a half-written install repairs itself.
 */
function locadev_up_to_date(string $installed, string $target): bool {
    return $installed !== '' && $target !== ''
        && !version_compare(locadev_version_clean($installed), locadev_version_clean($target), '<');
}

/**
 * The release tag asked for on the command line, '' when none.
 * Accepts --tag=vX.Y.Z AND the pair --tag vX.Y.Z: cmd.exe splits a batch argument on '=' (also ,
 * and ;), so `locadev update --tag=v1.2.0` reaches PHP as two arguments on Windows and matching
 * the '=' form alone silently ignored the pin.
 */
function locadev_arg_tag(array $argv): string {
    $tag = '';
    foreach ($argv as $i => $arg) {
        if (str_starts_with($arg, '--tag=')) {
            $tag = substr($arg, 6);
        } elseif ($arg === '--tag' && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
            $tag = $argv[$i + 1];
        }
    }
    return $tag;
}

/** Newest release tag, by following GitHub's /releases/latest redirect - no API token needed. */
function locadev_latest_tag(): string {
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'follow_location' => 0, 'timeout' => 15]]);
    $headers = @get_headers('https://github.com/' . LOCADEV_REPO . '/releases/latest', false, $ctx);
    foreach ((array) $headers as $h) {
        // NB: delimiter must not be '#' - PHP ends the pattern at a '#' inside a character
        // class, which silently killed the same check in api.php's latest_classicpress_tag().
        if (preg_match('~^Location:\s*(\S+)~i', (string) $h, $m) && preg_match('~/tag/([^/?#]+)~', $m[1], $t)) {
            return $t[1];
        }
    }
    return '';
}

/**
 * One updater at a time. Two runs copying the same tree at once can leave a PHP file
 * half-written for the server to read, and neither entry point checks the other by itself:
 * the dashboard button and `locadev update` are separate processes.
 */
function locadev_lock_path(string $root): string {
    return $root . '/data/update.lock';
}

/** true while another updater is alive. Stale locks (killed process, reboot) are ignored. */
function locadev_update_running(string $root): bool {
    if (!is_file($lock = locadev_lock_path($root))) {
        return false;
    }
    $pid = (int) trim((string) @file_get_contents($lock));
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = [];
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>&1', $out);
        return str_contains(implode(' ', $out), (string) $pid);
    }
    return function_exists('posix_kill') && @posix_kill($pid, 0);
}

/**
 * Paths (relative to the install root, '/'-separated) an update never touches.
 * `sites`, `config/sites` and `config/sites.json` are symlinks on a shared dual-boot setup.
 */
function locadev_update_skips(): array {
    // config/my.cnf is user-editable (server tuning); install.ps1 documents the same intent
    // - "config templates only on first install" - so an update must not clobber your edits.
    return ['data', 'sites', 'config/sites', 'config/sites.json', 'config/my.cnf', '.git'];
}

/**
 * Copy the extracted bundle over an install. Never deletes, never follows or replaces a
 * symlink, never touches a skipped path. Executable bits follow the source file.
 * ponytail: stale files from a removed feature survive until the next clean install.
 */
/**
 * Files that must stay executable. The release archive stores them as 0664 - git on a Windows
 * or NTFS checkout has no executable bit - so the mode cannot be trusted: a non-executable
 * start.sh makes `locadev start` fail with "Permission denied". install.sh chmods the same set.
 */
function locadev_exec_paths(): array {
    // bin/frankenphp belongs here too: the archive stores it 0664, and on Linux/macOS a
    // non-executable frankenphp makes `locadev start` report "binary not found" - which reads as
    // a missing file, not a missing bit. start.sh used to chmod it as a side effect; now that the
    // launcher delegates, an update is the only thing that can restore the bit.
    return ['bin/locadev', 'bin/frankenphp', 'start.sh', 'stop.sh'];
}

function locadev_copy_tree(string $src, string $dst, array $skip): array {
    $stats = ['written' => 0, 'skipped' => []];

    $walk = function (string $src, string $dst, string $rel) use (&$walk, &$stats, $skip): void {
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dst . '/' . $name;
            $path = $rel === '' ? $name : $rel . '/' . $name;

            if (in_array($path, $skip, true) || is_link($from) || is_link($to)) {
                $stats['skipped'][] = $path;
                continue;
            }
            if (is_dir($from)) {
                is_dir($to) || @mkdir($to, 0777, true);
                $walk($from, $to, $path);
                continue;
            }
            if (is_dir($to)) {
                continue;
            }
            if (@copy($from, $to)) {
                @chmod($to, fileperms($from) & 0777);
                $stats['written']++;
            }
        }
    };

    $walk(rtrim($src, '/'), rtrim($dst, '/'), '');

    foreach (locadev_exec_paths() as $rel) {
        is_file($dst . '/' . $rel) && @chmod($dst . '/' . $rel, 0755);
    }
    foreach (glob($dst . '/scripts/*.php') ?: [] as $php) {
        @chmod($php, 0755);
    }
    return $stats;
}

/**
 * Stream a URL to a file. Never file_get_contents(): the Windows binary bundle is 148 MB, and
 * holding the body plus its copy in memory blew the 256 MB limit mid-update, leaving an install
 * with the thin layer copied and the binaries untouched.
 */
function locadev_download(string $url, string $dest): bool {
    $ctx = stream_context_create(['http' => [
        'timeout' => 120,
        'follow_location' => 1,
        'user_agent' => 'Locadev-updater',
    ]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) {
        return false;
    }
    $out = @fopen($dest, 'wb');
    if (!$out) {
        fclose($in);
        return false;
    }
    $bytes = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $bytes !== false && $bytes > 0;
}

/**
 * Delete a directory tree with no shell involved. The Unix 'rm -rf ... 2>/dev/null' this
 * replaces was worse than useless on Windows: cmd.exe read the redirect as a path, printed
 * "The system cannot find the path specified." and never deleted anything - the sweep of stale
 * temp dirs silently did nothing and they piled up in %TEMP%.
 */
function locadev_rm_tree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        is_dir($path) && !is_link($path) ? locadev_rm_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** '' on success, a one-line reason otherwise. */
function locadev_extract(string $tgz, string $dir): string {
    @mkdir($dir, 0777, true);
    // PharData, never the `tar` binary: GNU tar reads a Windows path as the remote form
    // host:path, so extraction died with "Cannot connect to C: resolve failed" wherever Git
    // for Windows' tar came first in PATH (bsdtar, which ships in System32 and does work,
    // only wins by PATH order). No shell, no PATH, same result on every platform.
    try {
        (new PharData($tgz))->extractTo($dir, null, true);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

/** Where the last binary sync came from: the tag the bundle was downloaded for. */
function locadev_bin_marker_path(string $root): string {
    return $root . '/data/bin.json';
}

function locadev_bin_marker(string $root): array {
    $j = json_decode((string) @file_get_contents(locadev_bin_marker_path($root)), true);
    return is_array($j) ? $j : [];
}

/**
 * Copy one file over another, renaming a locked target out of the way first.
 *
 * Windows refuses to overwrite a running image - the server's frankenphp.exe, this updater's own
 * php.exe, a mapped php8ts.dll - but it does allow renaming it, so the new file lands and the old
 * one is deleted by a later run, once nothing maps it any more.
 */
function locadev_put_file(string $from, string $to, array &$stale): bool {
    if (@copy($from, $to)) {
        return true;
    }
    $old = $to . '.old';
    @unlink($old);
    if (!@rename($to, $old)) {
        return false;
    }
    $stale[] = $old;
    return @copy($from, $to);
}

/** Copy a binary bundle over bin/. Never replaces bin/php.ini (the install's own config). */
function locadev_copy_bin(string $src, string $dst, array &$stale, string $rel = ''): int {
    $written = 0;
    foreach (scandir($src) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $from = $src . '/' . $name;
        $to = $dst . '/' . $name;
        if ($rel === '' && $name === 'php.ini') {
            continue;
        }
        if (is_dir($from)) {
            is_dir($to) || @mkdir($to, 0777, true);
            $written += locadev_copy_bin($from, $to, $stale, $rel === '' ? $name : $rel . '/' . $name);
            continue;
        }
        if (!is_dir($to) && locadev_put_file($from, $to, $stale)) {
            $written++;
        }
    }
    return $written;
}

/** FrankenPHP asset name upstream publishes for this platform, '' when there is none. */
function locadev_frankenphp_asset(): string {
    $arch = strtolower((string) php_uname('m'));
    $arm = str_contains($arch, 'arm64') || str_contains($arch, 'aarch64');
    return match (PHP_OS_FAMILY) {
        'Linux' => $arm ? 'frankenphp-linux-aarch64' : 'frankenphp-linux-x86_64',
        // 1.13.2 dropped Intel macOS static builds; upstream only publishes Apple Silicon now.
        'Darwin' => $arm ? 'frankenphp-mac-arm64' : '',
        default => '',
    };
}

/**
 * Refresh the runtime in bin/ from the release bundle. Returns files written plus a note when a
 * step could not run (the caller prints it; a failed binary sync must never look like success).
 */
function locadev_sync_binaries(string $root, string $tag, string $tmp, array &$stale): array {
    $stats = ['written' => 0, 'note' => ''];
    $bin = $root . '/bin';

    if (PHP_OS_FAMILY === 'Windows') {
        if (!class_exists('ZipArchive')) {
            $stats['note'] = 'ext/zip is not loaded - binaries were not updated.';
            return $stats;
        }
        $zip = $tmp . '/bin.zip';
        $url = 'https://github.com/' . LOCADEV_REPO . '/releases/download/' . $tag . '/locadev-bin-win-x64.zip';
        if (!locadev_download($url, $zip)) {
            $stats['note'] = 'Binary bundle download failed: ' . $url;
            return $stats;
        }
        $z = new ZipArchive();
        if ($z->open($zip) !== true) {
            $stats['note'] = 'Binary bundle could not be opened: ' . $zip;
            return $stats;
        }
        $dir = $tmp . '/binnew';
        @mkdir($dir, 0777, true);
        $z->extractTo($dir);
        $z->close();
        $stats['written'] = locadev_copy_bin($dir, $bin, $stale);
        return $stats;
    }

    // Linux/macOS ship no bundle: install.sh downloads the upstream FrankenPHP binary the same way.
    $asset = locadev_frankenphp_asset();
    if ($asset === '') {
        $stats['note'] = 'No upstream FrankenPHP build for this platform - binaries were not updated.';
        return $stats;
    }
    $url = 'https://github.com/dunglas/frankenphp/releases/latest/download/' . $asset;
    $new = $bin . '/frankenphp.new';
    if (!locadev_download($url, $new)) {
        $stats['note'] = 'FrankenPHP download failed: ' . $url;
        return $stats;
    }
    @chmod($new, 0755);
    // rename(2) succeeds even while the old binary is mapped, so no restart is needed here either.
    if (!@rename($new, $bin . '/frankenphp')) {
        $stats['note'] = 'Could not replace bin/frankenphp.';
        return $stats;
    }
    $stats['written'] = 1;
    // Replacing the file drops the capability that lets it bind :443; only root can set it again.
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        exec('setcap cap_net_bind_service=+ep ' . escapeshellarg($bin . '/frankenphp') . ' 2>&1');
    } else {
        $stats['note'] = 'If :443 stops working, run: sudo setcap cap_net_bind_service=+ep "' . $bin . '/frankenphp"';
    }
    return $stats;
}

/**
 * The shipped php.ini names the maintainer's own extension_dir, so an install that did not replace
 * it loads another install's DLLs: PHP refuses with "Entry Point Not Found" and every extension
 * (curl, openssl, gd) fails to load. Repoint it whenever it names a different directory.
 */
function locadev_fix_extension_dir(string $root): bool {
    $ini = $root . '/bin/php.ini';
    $ext = str_replace('\\', '/', $root . '/bin/ext');
    if (!is_file($ini) || !is_dir($ext)) {
        return false;
    }
    $text = (string) file_get_contents($ini);
    if (!preg_match('/^extension_dir\s*=\s*"?([^"\r\n]*)"?\s*$/m', $text, $m)) {
        return false;
    }
    if (rtrim(str_replace('\\', '/', trim($m[1])), '/') === $ext) {
        return false;
    }
    return @file_put_contents($ini, preg_replace('/^extension_dir\s*=.*$/m', 'extension_dir = "' . $ext . '"', $text, 1)) !== false;
}

/** Whether locadev_fix_extension_dir() has work to do, without writing anything. */
function locadev_ini_needs_fix(string $root): bool {
    $ini = $root . '/bin/php.ini';
    $ext = str_replace('\\', '/', $root . '/bin/ext');
    if (!is_file($ini) || !is_dir($ext)) {
        return false;
    }
    $text = (string) file_get_contents($ini);
    return preg_match('/^extension_dir\s*=\s*"?([^"\r\n]*)"?\s*$/m', $text, $m) === 1
        && rtrim(str_replace('\\', '/', trim($m[1])), '/') !== $ext;
}

function locadev_check_json(string $root): array {
    $installed = locadev_version($root);
    $latest = locadev_latest_tag();
    return [
        'installed' => $installed,
        'latest' => $latest,
        'update_available' => $latest !== '' && $installed !== ''
            && version_compare(locadev_version_clean($latest), locadev_version_clean($installed), '>'),
    ];
}

function locadev_update_main(array $argv): int {
    $root = dirname(__DIR__);
    $yes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);
    $check = in_array('--check', $argv, true);
    $tag = locadev_arg_tag($argv);

    if ($check) {
        echo json_encode(locadev_check_json($root)), "\n";
        return 0;
    }

    if (locadev_update_running($root)) {
        echo "[update] Another update is already running (data/update.lock). Nothing was done.\n";
        return 1;
    }
    @mkdir($root . '/data', 0777, true);
    file_put_contents(locadev_lock_path($root), (string) getmypid());
    register_shutdown_function(static fn() => @unlink(locadev_lock_path($root)));

    $installed = locadev_version($root);
    $latest = locadev_latest_tag();
    if ($latest === '' && $tag === '') {
        echo "[update] Cannot reach GitHub to read the newest release. Nothing changed.\n";
        return 1;
    }
    $target = $tag !== '' ? $tag : $latest;

    // `locadev update` is the one command a user runs to repair an install, so it refreshes the
    // runtime in bin/ as well as the thin layer. Binaries are skipped once they already came from
    // this tag (recorded in data/bin.json): they are 148 MB on Windows.
    $with_bin = !in_array('--no-bin', $argv, true);
    $bin_due = $with_bin && (locadev_bin_marker($root)['tag'] ?? '') !== $target;
    $ini_fix = $with_bin && locadev_ini_needs_fix($root);

    // An explicit --tag still forces the copy: that is the documented escape hatch for pinning a
    // version or re-syncing an install that a killed update left half-written.
    if ($tag === '' && locadev_up_to_date($installed, $target) && !$bin_due && !$ini_fix) {
        echo "[update] Already on {$target}. Nothing to do.\n";
        return 0;
    }
    echo '[update] Installed: ' . ($installed !== '' ? $installed : 'unknown') . ' -> ' . $target . "\n";

    if (!$yes) {
        if (!stream_isatty(STDIN)) {
            // Never mutate a install on a guess: no terminal means no confirmation to read.
            echo "[update] No terminal to ask on. Re-run with --yes to confirm.\n";
            return 1;
        }
        echo "[update] Update $target (scripts, dashboard, CLI and the runtime binaries)?\n";
        echo '[update] data/, sites/ and your site registry are not touched. [y/N] ';
        $answer = strtolower(trim((string) fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            echo "[update] Cancelled.\n";
            return 1;
        }
    }

    // Sweep leftovers from runs that were killed (SIGKILL skips every cleanup we install). Only
    // before we create our own directory, and only stale ones: we hold the lock at this point, so
    // nothing else can be mid-flight.
    foreach (glob(sys_get_temp_dir() . '/locadev-update-*') ?: [] as $old) {
        if (@filemtime($old) < time() - 3600) {
            locadev_rm_tree($old);
        }
    }

    $tmp = sys_get_temp_dir() . '/locadev-update-' . getmypid();
    locadev_rm_tree($tmp);
    @mkdir($tmp, 0777, true);
    // Removed on the way out whatever happens. Every failure path below returns early, and the
    // >1h sweep used to be the only cleanup - which never ran on Windows (see locadev_rm_tree).
    register_shutdown_function(static fn() => locadev_rm_tree($tmp));

    // Leftovers from a previous run whose files were mapped at the time. Best effort: a still
    // mapped image cannot be deleted and simply survives to the next run.
    foreach (glob($root . '/bin/*.old') ?: [] as $old) {
        @unlink($old);
    }

    $stale = [];
    $failed = false;
    if (locadev_up_to_date($installed, $target) && $tag === '') {
        echo "[update] Code already on {$target} - checking the runtime binaries.\n";
    } else {
        $url = 'https://github.com/' . LOCADEV_REPO . '/releases/download/' . $target . '/locadev-repo.tar.gz';
        echo '[update] Downloading ' . $url . "\n";
        if (!locadev_download($url, $tmp . '/repo.tar.gz')) {
            echo "[update] Download failed. Check the connection (and that the release exists).\n";
            return 1;
        }

        echo "[update] Extracting...\n";
        if ($err = locadev_extract($tmp . '/repo.tar.gz', $tmp . '/x')) {
            echo '[update] Extraction failed: ' . $err . "\n";
            return 1;
        }

        // git archive wraps everything in <repo>-<ref>/ - use the single top-level directory.
        $dirs = array_values(array_filter(scandir($tmp . '/x') ?: [], fn($n) => $n !== '.' && $n !== '..' && is_dir($tmp . '/x/' . $n)));
        if (count($dirs) !== 1) {
            echo "[update] Unexpected archive layout (expected one top-level directory).\n";
            return 1;
        }

        echo "[update] Copying over {$root}...\n";
        $stats = locadev_copy_tree($tmp . '/x/' . $dirs[0], $root, locadev_update_skips());
        echo '[update] ' . $stats['written'] . ' file(s) written';
        echo $stats['skipped'] ? ', skipped: ' . implode(', ', array_unique($stats['skipped'])) : '';
        echo "\n";

        $now = locadev_version($root);
        if ($now !== locadev_version_clean($target)) {
            echo '[update] WARNING: version reads ' . ($now !== '' ? $now : 'unreadable') . ' after copying ' . $target . '. Update may be partial.' . "\n";
            return 1;
        }
    }

    if ($bin_due) {
        echo "[update] Refreshing the runtime binaries...\n";
        $bin = locadev_sync_binaries($root, $target, $tmp, $stale);
        if ($bin['written'] > 0) {
            @mkdir($root . '/data', 0777, true);
            @file_put_contents(locadev_bin_marker_path($root), json_encode(['tag' => $target, 'files' => $bin['written'], 'at' => gmdate('c')]));
            echo '[update] ' . $bin['written'] . " binary file(s) written\n";
        } else {
            $failed = true;
        }
        if ($bin['note'] !== '') {
            echo '[update] NOTE: ' . $bin['note'] . "\n";
        }
    }

    // Never fatal: an install whose php.ini is fine must still finish the update.
    if (locadev_fix_extension_dir($root)) {
        echo "[update] Repointed bin/php.ini extension_dir at this install (it named another install).\n";
    }

    foreach ($stale as $old) {
        @unlink($old);
    }

    $now = locadev_version($root);
    if ($failed) {
        echo "[update] Thin layer is on {$now}, but the binaries were NOT updated - see the note above.\n";
        return 1;
    }
    echo "[update] Done - now on {$now}. Restart to serve it: locadev restart\n";
    return 0;
}

// Only run when invoked directly: api.php and self-check.php require this file for its
// functions, and neither may trigger an update.
if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD']) && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(locadev_update_main($argv));
}
