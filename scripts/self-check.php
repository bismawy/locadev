<?php
/**
 * Locadev self-check: the paths that break *silently* — JSON mode, the CMS zip cache
 * and the CSRF fence. Run it with the bundled PHP:
 *
 *   bin/php.exe scripts/self-check.php        (Windows)
 *   bin/frankenphp php-cli scripts/self-check.php   (Linux/macOS)
 *
 * Exits non-zero on the first broken assumption. No network, no server needed.
 */
$root = dirname(__DIR__);
chdir($root);
// Buffer everything: nothing must be flushed before api.php sends its own headers.
ob_start();

// Probe mode: run api.php once against a synthetic request (used by the checks below).
// The config travels base64-encoded: JSON quotes do not survive escapeshellarg on Windows.
if (($argv[1] ?? '') === '--probe') {
    $cfg = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];
    $_GET = $cfg['get'] ?? [];
    $_POST = $cfg['post'] ?? [];
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'GET';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
    if (isset($cfg['origin'])) $_SERVER['HTTP_ORIGIN'] = $cfg['origin'];
    if (isset($cfg['host']))   $_SERVER['HTTP_HOST']   = $cfg['host'];
    require $root . '/dashboard/api.php';
    exit;
}

$fail = 0;
function check(string $label, bool $ok): void {
    global $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) $fail++;
}

/**
 * Command prefix that runs a PHP script with this install's CLI.
 * Under FrankenPHP, PHP_BINARY is the frankenphp binary itself: without the `php-cli`
 * argument it treats the script path as its config and every check below fails for a
 * reason that has nothing to do with the code being checked (8 false FAILs before this).
 */
function php_cli_prefix(): string {
    global $root;
    $php = PHP_BINARY;
    // Under FrankenPHP php-cli, PHP_BINARY is the SCRIPT path in script mode and empty in `-r`
    // mode - never a PHP binary. Running it spawned the .php file as a shell script, which is
    // what made these checks fail while the code under test was fine.
    if ($php !== '' && !str_ends_with(strtolower($php), '.php')) {
        return escapeshellarg($php) . (str_contains(basename($php), 'frankenphp') ? ' php-cli' : '');
    }
    // FrankenPHP reports an EMPTY PHP_BINARY, and a bare 'php' is not on PATH for anyone who
    // installed Locadev on a machine without a system PHP - every spawned check failed here.
    // Pick by OS, not by file existence: a shared dual-boot install has bin/php.exe on Linux too.
    if (PHP_OS_FAMILY === 'Windows' && is_file($root . '/bin/php.exe')) {
        return escapeshellarg($root . '/bin/php.exe');
    }
    return is_file($root . '/bin/frankenphp')
        ? escapeshellarg($root . '/bin/frankenphp') . ' php-cli'
        : 'php';
}

function probe(array $cfg): string {
    return (string) shell_exec(php_cli_prefix() . ' ' . escapeshellarg(__FILE__) . ' --probe '
        . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
}

echo "JSON mode\n";
// Without bin/php.ini (Linux/macOS: static FrankenPHP) the action has no values to return, so
// the assertion flips to "no PHP Warning in front of the JSON" - the failure mode that broke
// programmatic clients there while the HTML partial looked fine.
check('action=configuration returns values without a HX-Request header', (function () use ($root): bool {
    $out = probe(['get' => ['action' => 'configuration']]);
    return is_file($root . '/bin/php.ini') && !str_contains($out, 'Warning')
        ? str_contains($out, 'memory_limit')
        : !str_contains($out, 'Warning');
})());
$out = probe(['get' => ['action' => 'extensions']]);
check('action=extensions returns the extension list', str_contains($out, 'pdo_mysql'));

echo "CSRF fence\n";
$out = probe(['method' => 'POST', 'origin' => 'https://evil.example', 'post' => ['action' => 'status']]);
check('cross-origin POST is blocked', str_contains($out, 'Cross-origin request blocked'));
$out = probe(['method' => 'POST', 'origin' => 'http://localhost:8080', 'host' => 'localhost:8080', 'post' => ['action' => 'status']]);
check('same-origin POST passes', str_contains($out, 'php_version'));
$out = probe(['method' => 'POST', 'post' => ['action' => 'status']]);
check('POST without Origin (CLI/curl) passes', str_contains($out, 'php_version'));

echo "CMS zip cache\n";
ob_start();
$_GET = ['action' => 'status'];                 // status uses break (no exit): functions stay callable
require $root . '/dashboard/api.php';
ob_get_clean();
$tmp = sys_get_temp_dir() . '/locadev-selfcheck-' . bin2hex(random_bytes(4));
mkdir($tmp . '/site', 0777, true);
file_put_contents($tmp . '/classicpress-9.9.9.zip', 'PK this is not a zip');   // newest, but truncated
$z = new ZipArchive();
$z->open($tmp . '/classicpress-1.0.0.zip', ZipArchive::CREATE);
$z->addFromString('classicpress/index.php', '<?php echo "ok";');
$z->close();
$ok = deploy_cms_template('classicpress', $tmp . '/site', $tmp);
check('truncated cache is dropped', !file_exists($tmp . '/classicpress-9.9.9.zip'));
check('older valid cache is used instead (no download)', $ok === true && file_exists($tmp . '/site/index.php'));
array_map('unlink', glob($tmp . '/site/*') ?: []);
array_map('unlink', glob($tmp . '/*.zip') ?: []);
@rmdir($tmp . '/site');
@rmdir($tmp);

echo "Cloudflare Tunnel\n";
$sampleLog = "2026-09-20T19:00:00Z INF Thank you for trying Cloudflare Tunnel. Doing so, without a Cloudflare account,\n"
    . "2026-09-20T19:00:01Z INF Requesting new quick Tunnel on trycloudflare.com...\n"
    . "2026-09-20T19:00:04Z INF |  https://quiet-marble-otter-canyon.trycloudflare.com  |\n"
    . "2026-09-20T19:00:04Z INF +--------------------------------------------------------------------------------------------+\n";
check('reads the public URL out of the cloudflared log', tunnel_url_from_log($sampleLog) === 'https://quiet-marble-otter-canyon.trycloudflare.com');
check('log without a URL yields null', tunnel_url_from_log('INF nothing here trycloudflare.com') === null);
check('release asset name matches this OS (' . PHP_OS_FAMILY . ')', in_array(
    cloudflared_asset(),
    ['cloudflared-windows-amd64.exe', 'cloudflared-windows-arm64.exe', 'cloudflared-linux-amd64', 'cloudflared-linux-arm64', 'cloudflared-darwin-amd64.tgz', 'cloudflared-darwin-arm64.tgz', null],
    true
));
check('tunnel state round-trips through data/tunnels.json', (function (): bool {
    $before = read_tunnels();
    write_tunnels(['_selfcheck' => ['pid' => 1, 'url' => 'https://x.trycloudflare.com', 'host' => 'x.localhost', 'started' => 1]]);
    $ok = (read_tunnels()['_selfcheck']['url'] ?? '') === 'https://x.trycloudflare.com';
    write_tunnels($before);
    if ($before === [] && is_file(tunnels_state_file())) {
        @unlink(tunnels_state_file()); // do not leave empty state behind after testing
    }
    return $ok;
})());

check('site row offers the public link only while a tunnel is active', (function (): bool {
    $site = ['id' => 'x', 'name' => 'X', 'domain' => 'x.localhost', 'root' => 'sites/x', 'database' => '', 'enabled' => true, 'preset' => 'blank'];
    $url = 'https://quiet-marble-otter-canyon.trycloudflare.com';
    $off = render_site_row($site);
    $on = render_site_row($site, ['x' => ['pid' => 1, 'url' => $url, 'host' => 'x.localhost', 'started' => 1]]);
    return str_contains($off, 'toggle_tunnel') && str_contains($off, 'data-progress') && !str_contains($off, 'tunnel-on')
        && str_contains($on, 'tunnel-on') && str_contains($on, '#link_2') && substr_count($on, $url) === 2;
})());

check('CMS sites get the tunnel-URL mu-plugin, non-CMS sites are left alone', (function (): bool {
    $dir = sys_get_temp_dir() . '/locadev-selfcheck-' . getmypid();
    @mkdir($dir . '/wp-content', 0777, true);
    ensure_tunnel_url_plugin(['root' => $dir]);
    $file = $dir . '/wp-content/mu-plugins/locadev-tunnel-url.php';
    $code = (string) @file_get_contents($file);
    // Public host can only be read from this header; HTTP_HOST remains <name>.localhost at origin.
    $ok = str_contains($code, 'HTTP_X_FORWARDED_HOST') && str_contains($code, "add_filter('option_home'");
    $plain = $dir . '/plain';
    @mkdir($plain, 0777, true);
    ensure_tunnel_url_plugin(['root' => $plain]);
    $ok = $ok && !is_dir($plain . '/wp-content');
    @unlink($file);
    @rmdir($dir . '/wp-content/mu-plugins');
    @rmdir($dir . '/wp-content');
    @rmdir($plain);
    @rmdir($dir);
    return $ok;
})());

// Only runs when no tunnel is active: the script kills every tunnel it finds.
check('stop-tunnels.php runs cleanly when no tunnel is active', (function () use ($root): bool {
    if (read_tunnels() !== []) {
        return true;
    }
    $out = [];
    $code = 1;
    exec(php_cli_prefix() . ' ' . escapeshellarg($root . '/scripts/stop-tunnels.php') . ' 2>&1', $out, $code);
    return $code === 0 && !str_contains(implode(' ', $out), 'Fatal error');
})());

echo "MariaDB data dir\n";
// Only meaningful once a database exists: the check deletes the marker to exercise the adopt path.
check('db-upgrade.php records the MariaDB version (and restores the marker)', (function () use ($root): bool {
    $datadir = $root . '/data/mariadb';
    if (!is_dir($datadir . '/mysql')) {
        return true;
    }
    $marker = $datadir . '/.locadev-version';
    $had = is_file($marker) ? (string) file_get_contents($marker) : null;
    @unlink($marker);
    $out = [];
    $code = 1;
    exec(php_cli_prefix() . ' ' . escapeshellarg($root . '/scripts/db-upgrade.php') . ' 2>&1', $out, $code);
    $text = implode(' ', $out);
    $ok = $code === 0 && !str_contains($text, 'Fatal')
        && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', trim((string) @file_get_contents($marker)));
    if ($had === null) {
        @unlink($marker);
    } else {
        file_put_contents($marker, $had);
    }
    return (bool) $ok;
})());

echo "Runtime versions\n";
// Versions parsed from binary output using regex: if upstream format changes,
// System Info silently displays empty versions — this check catches that.
check('runtime_versions() reads FrankenPHP, PHP and Caddy versions from the binary', (function (): bool {
    if (!is_file(frankenphp_bin())) {
        return true; // install without binary: nothing to test
    }
    $v = runtime_versions();
    $semver = static fn(string $s): bool => (bool) preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $s);
    return $semver($v['frankenphp']) && $semver($v['php']) && $semver($v['caddy']);
})());

/**
 * Executable bits after an update, on its own: the check above bails out early when symlinks
 * cannot be created (that is Windows without Developer Mode), so every exec-bit assertion inside
 * it is vacuous on the very platform this rule was written for. bin/frankenphp joined the list
 * the day start.sh stopped chmod-ing it as a side effect, so it needs its own coverage.
 * The bit is asserted only on POSIX: NTFS has no exec flag and PHP reports 0666 either way, so on
 * Windows this checks the copy happened and leaves the real assertion to CI (ubuntu/macos).
 */
check('an update restores the executable bit on the binaries it ships', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    $tmp = sys_get_temp_dir() . '/locadev-selfcheck-exec-' . getmypid();
    locadev_rm_tree($tmp);
    @mkdir($tmp . '/src/bin', 0777, true);
    @mkdir($tmp . '/dst/bin', 0777, true);
    foreach (['locadev', 'frankenphp'] as $bin) {
        file_put_contents($tmp . '/src/bin/' . $bin, 'x');
        chmod($tmp . '/src/bin/' . $bin, 0644); // what the release archive stores
    }

    locadev_copy_tree($tmp . '/src', $tmp . '/dst', []);

    $ok = true;
    foreach (['locadev', 'frankenphp'] as $bin) {
        $dst = $tmp . '/dst/bin/' . $bin;
        $ok = $ok && is_file($dst)
            && (PHP_OS_FAMILY === 'Windows' || (fileperms($dst) & 0111) !== 0);
    }
    locadev_rm_tree($tmp);
    return $ok;
})());

// System Info names which MariaDB this install runs: bundled on Windows, the system package on
// Linux/macOS. The answer differs per platform, but it must never be empty - an empty row is how
// a broken lookup hides.
check('mariadb_server_binary() always names a source', (function (): bool {
    return mariadb_server_binary() !== '';
})());

echo "PHP configuration\n";
// php_ini_defaults() is what the dashboard writes on an install with no php.ini (Linux/macOS).
// The page reads that file back with config_ini_values(), so both sides must agree on the format.
check('php_ini_defaults() round-trips through config_ini_values()', (function () use ($root): bool {
    require_once $root . '/dashboard/partials.php';
    $tmp = sys_get_temp_dir() . '/locadev-selfcheck-' . bin2hex(random_bytes(4)) . '.ini';
    if (@file_put_contents($tmp, php_ini_defaults()) === false) {
        return false;
    }
    $values = config_ini_values($tmp);
    $expected = array_keys(config_directives());
    @unlink($tmp);
    return array_keys($values) === $expected
        && $values['display_errors'] === 'On'
        && preg_match('/^[0-9]+M$/', (string) $values['memory_limit']) === 1;
})());

echo "Versions\n";
// The dashboard compares the release tag ("v1.4.0") with the LOCODEV_VERSION constant ("1.4.0").
// Comparing them raw reported an install on the newest release as "ahead of the published release".
check('locadev_version_clean() folds a tag into the version it names', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';
    return locadev_version_clean('v1.4.0') === '1.4.0'
        && locadev_version_clean(' 1.4.0 ') === '1.4.0'
        && version_compare(locadev_version_clean('v1.5.0'), locadev_version_clean('1.4.0'), '>') === true
        && version_compare(locadev_version_clean('v1.4.0'), locadev_version_clean('1.4.0'), '>') === false;
})());

echo "Update (thin layer)\n";
// An update copy is the only path in Locadev that could delete user files:
// sites/ and config/sites.json are symlinks to Windows shared folder in dual-boot setups
// (install.sh bug: --exclude="sites/*" did not match archive entry name locadev-main/sites/...).
check('copy_tree never replaces a symlink, a skipped path, or leaks bundle-side user data', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    $tmp = sys_get_temp_dir() . '/locadev-selfcheck-' . getmypid();
    $shared = $tmp . '/shared';
    $dest = $tmp . '/dest';
    $src = $tmp . '/src';
    $mk = static fn(string $p, string $c): bool => @mkdir($p, 0777, true) && @file_put_contents($p . '/' . $c, '') !== false;

    // Destination: symlink sites/ + config/sites + config/sites.json to shared directory.
    $mk($shared, 'registry.json');
    file_put_contents($shared . '/registry.json', 'keep');
    $mk($dest . '/data/mariadb', 'keep.txt');
    file_put_contents($dest . '/data/mariadb/keep.txt', 'keep');
    $mk($dest . '/dashboard', 'api.php');
    file_put_contents($dest . '/dashboard/api.php', 'old');
    $mk($dest . '/config', 'my.cnf');
    $mk($dest . '/bin', 'locadev');
    chmod($dest . '/bin/locadev', 0755);
    if (!@symlink($shared, $dest . '/sites')
        || !@symlink($shared, $dest . '/config/sites')
        || !@symlink($shared . '/registry.json', $dest . '/config/sites.json')) {
        return true; // symlinks require permissions on Windows: nothing to test here
    }

    // Bundle: fresh thin files + sites/ and data/ entries that must be ignored.
    $mk($src . '/dashboard', 'api.php');
    file_put_contents($src . '/dashboard/api.php', 'new');
    $mk($src . '/config/sites', 'real.txt');
    $mk($src . '/sites', 'real.txt');
    $mk($src . '/data', 'ignore.txt');
    $mk($src . '/bin', 'locadev');
    $mk($src . '/scripts', 'x.php');
    file_put_contents($src . '/start.sh', '#!/bin/sh');
    file_put_contents($src . '/config/sites.json', 'clobber');
    // 0644 on purpose: that is what the release archive stores, and a trusted source mode
    // would hide the bug that made start.sh non-executable after an update.
    chmod($src . '/bin/locadev', 0644);
    chmod($src . '/scripts/x.php', 0644);
    chmod($src . '/start.sh', 0644);

    locadev_copy_tree($src, $dest, locadev_update_skips());

    $ok = file_get_contents($dest . '/dashboard/api.php') === 'new'      // thin files updated
        && is_link($dest . '/sites')                                     // symlink stays symlink
        && is_link($dest . '/config/sites')
        && is_link($dest . '/config/sites.json')
        && file_get_contents($shared . '/registry.json') === 'keep'      // registry untouched
        && file_get_contents($dest . '/data/mariadb/keep.txt') === 'keep' // data untouched
        && !file_exists($shared . '/real.txt')                           // bundle contents do not traverse symlink
        && !file_exists($dest . '/data/ignore.txt')
        && (fileperms($dest . '/bin/locadev') & 0111) !== 0             // executable bit enforced
        && (fileperms($dest . '/scripts/x.php') & 0111) !== 0
        && (fileperms($dest . '/start.sh') & 0111) !== 0;

    exec(PHP_OS_FAMILY === 'Windows' ? 'rd /s /q ' . escapeshellarg($tmp) : 'rm -rf ' . escapeshellarg($tmp));
    return (bool) $ok;
})());

// The one comparison that decides whether anything is downloaded at all. It was false for an
// install on the newest release ('1.4.3' vs 'v1.4.3'), so every run re-fetched the whole release
// just to write back the files it already had.
check('up_to_date folds the tag, keeps a newer checkout, and never trusts an unreadable version', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    return locadev_up_to_date('1.4.3', 'v1.4.3') === true    // newest release: nothing to do
        && locadev_up_to_date('1.4.2', 'v1.4.3') === false   // behind: download
        && locadev_up_to_date('1.4.4', 'v1.4.3') === true    // ahead: never downgrade
        && locadev_up_to_date('', 'v1.4.3') === false;       // unreadable: repair by re-copying
})());

// Deleting a tree must not go through the shell either: 'rm -rf ... 2>/dev/null' made cmd.exe
// print "The system cannot find the path specified." and delete nothing, so the stale-temp sweep
// silently did nothing on Windows and %TEMP% kept every abandoned update dir.
// cmd.exe splits a batch argument on '=', so the documented `locadev update --tag=v1.2.0`
// arrives as a pair. Matching only the '=' form meant a Windows pin was silently ignored.
check('arg_tag reads --tag=vX.Y.Z and the pair cmd.exe splits it into', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    return locadev_arg_tag(['update.php', '--tag=v1.2.0']) === 'v1.2.0'
        && locadev_arg_tag(['update.php', '--tag', 'v1.2.0']) === 'v1.2.0'  // what cmd.exe hands over
        && locadev_arg_tag(['update.php', '--check']) === ''
        && locadev_arg_tag(['update.php']) === '';
})());

check('rm_tree deletes a nested tree and leaves the sibling alone', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    $tmp = sys_get_temp_dir() . '/locadev-selfcheck-rm-' . getmypid();
    locadev_rm_tree($tmp);
    @mkdir($tmp . '/a/b/c', 0777, true);
    file_put_contents($tmp . '/a/b/c/deep.txt', 'x');
    file_put_contents($tmp . '/keep.txt', 'x');

    locadev_rm_tree($tmp . '/a');
    $ok = !is_dir($tmp . '/a') && file_get_contents($tmp . '/keep.txt') === 'x';
    locadev_rm_tree($tmp);
    return $ok && !is_dir($tmp);
})());

// Extraction must not depend on a `tar` binary: GNU tar (Git for Windows ships one, PATH order
// decides who wins) reads "C:\..." as the remote form host:path and died with "Cannot connect to
// C: resolve failed", leaving the update dead after download. Skips when dist/ is not built: the
// tarball is the bundle itself and dist/ is git-ignored.
check('extract reads a release tarball without shelling out to tar', (function () use ($root): bool {
    require_once $root . '/scripts/update.php';

    $tgz = $root . '/dist/locadev-repo.tar.gz';
    if (!is_file($tgz)) {
        return true; // bundle not built here: nothing to test
    }
    $out = sys_get_temp_dir() . '/locadev-selfcheck-x-' . getmypid();
    locadev_rm_tree($out);
    $ok = locadev_extract($tgz, $out) === '' && is_file($out . '/locadev-main/dashboard/api.php');
    locadev_rm_tree($out);
    return $ok && !is_dir($out);
})());

ob_end_flush();
echo $fail === 0 ? "\nALL CHECKS PASSED\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
