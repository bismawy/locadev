<?php
/**
 * Locadev updater - the engine behind `locadev update` and the dashboard button.
 *
 *   bin/frankenphp php-cli scripts/update.php          (interactive: asks y/N)
 *   bin/frankenphp php-cli scripts/update.php --yes     (required without a terminal)
 *   bin/frankenphp php-cli scripts/update.php --check   (JSON: installed / latest / update_available)
 *   bin/frankenphp php-cli scripts/update.php --tag=v1.2.0
 *
 * Thin layer only: scripts/, dashboard/, config templates, Caddyfile, CLI. Binaries are
 * left alone on purpose - Windows locks bin/php.exe and bin/frankenphp.exe while the server
 * runs, and on Linux replacing bin/frankenphp drops the setcap that lets it bind :443.
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
    return ['bin/locadev', 'start.sh', 'stop.sh'];
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

function locadev_download(string $url, string $dest): bool {
    $ctx = stream_context_create(['http' => [
        'timeout' => 120,
        'follow_location' => 1,
        'user_agent' => 'Locadev-updater',
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false && $body !== '' && @file_put_contents($dest, $body) !== false;
}

/** '' on success, a one-line reason otherwise. */
function locadev_extract(string $tgz, string $dir): string {
    @mkdir($dir, 0777, true);
    $out = [];
    $code = 0;
    exec('tar -xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
    return $code === 0 ? '' : (implode(' ', array_slice($out, -2)) ?: 'tar exited with ' . $code);
}

function locadev_check_json(string $root): array {
    $installed = locadev_version($root);
    $latest = locadev_latest_tag();
    return [
        'installed' => $installed,
        'latest' => $latest,
        'update_available' => $latest !== '' && $installed !== '' && version_compare($latest, $installed, '>'),
    ];
}

function locadev_update_main(array $argv): int {
    $root = dirname(__DIR__);
    $yes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);
    $check = in_array('--check', $argv, true);
    $tag = '';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--tag=')) {
            $tag = substr($arg, 6);
        }
    }

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

    if ($installed === $target) {
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
        echo "[update] Update the thin layer (scripts, dashboard, CLI, config templates)?\n";
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
            exec('rm -rf ' . escapeshellarg($old) . ' 2>/dev/null');
        }
    }

    $tmp = sys_get_temp_dir() . '/locadev-update-' . getmypid();
    exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @mkdir($tmp, 0777, true);

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

    exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');

    $now = locadev_version($root);
    if ($now !== trim($target, 'v')) {
        echo '[update] WARNING: version reads ' . ($now !== '' ? $now : 'unreadable') . ' after copying ' . $target . '. Update may be partial.' . "\n";
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
