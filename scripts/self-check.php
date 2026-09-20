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

function probe(array $cfg): string {
    $php = PHP_BINARY ?: 'php';
    return (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' --probe '
        . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
}

echo "JSON mode\n";
$out = probe(['get' => ['action' => 'configuration']]);
check('action=configuration returns values without a HX-Request header', str_contains($out, 'memory_limit'));
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
        @unlink(tunnels_state_file()); // jangan tinggalkan state kosong dari uji
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
    // Host publik hanya bisa dibaca dari header ini; HTTP_HOST tetap <nama>.localhost di origin.
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
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/stop-tunnels.php') . ' 2>&1', $out, $code);
    return $code === 0 && !str_contains(implode(' ', $out), 'Fatal error');
})());

ob_end_flush();
echo $fail === 0 ? "\nALL CHECKS PASSED\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
