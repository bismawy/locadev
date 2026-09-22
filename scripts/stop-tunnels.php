<?php
/**
 * Stop every tunnel Locadev has recorded. Called by `locadev stop`, because
 * cloudflared does NOT die when FrankenPHP stops: without this its public URLs
 * would hang as 502 while still looking active in the dashboard.
 *
 *   bin/php.exe scripts/stop-tunnels.php            (Windows)
 *   bin/frankenphp php-cli scripts/stop-tunnels.php  (Linux/macOS)
 *
 * Always exits 0, even when no tunnel is running: `locadev stop` must not fail just
 * because this cleanup had nothing to do.
 */
$root = dirname(__DIR__);
chdir($root);

// api.php menjawab request sintetis di bawah dan menyediakan helper tunnel.
ob_start();
$_GET['action'] = 'status';
require $root . '/dashboard/api.php';
ob_end_clean();

$stopped = 0;
foreach (array_keys(read_tunnels()) as $id) {
    stop_site_tunnel((string) $id); // guard citra proses: PID non-cloudflared tidak dibunuh
    $stopped++;
}

echo $stopped > 0 ? "Stopped $stopped tunnel(s).\n" : "No tunnels were running.\n";
