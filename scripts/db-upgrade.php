<?php
/**
 * Keeps data/mariadb usable when the MariaDB build changes - either a Locadev update that
 * ships a newer bundled MariaDB, or LOCADEV_MARIADB_BIN pointing at your own copy. MariaDB
 * will not serve a data directory written by an older major version until mariadb-upgrade
 * has repaired the system tables, so compare the running binary against a marker file and
 * upgrade once per version change.
 *
 * Called by `locadev start` after the server is listening. Never blocks startup: on failure
 * it prints the manual command and leaves the marker alone, so the next start retries.
 */
$root    = dirname(__DIR__);
$datadir = $root . '/data/mariadb';
if (!is_dir($datadir . '/mysql')) {
    return; // not initialized yet - ensure_db_files owns the first run
}

$exe      = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
$override = (string) getenv('LOCADEV_MARIADB_BIN');
$binDir   = $root . '/bin/mariadb/bin';
if ($override !== '' && is_file($override)) {
    $binDir = dirname($override);
}

$server  = is_file($binDir . '/mariadbd' . $exe) ? $binDir . '/mariadbd' . $exe : 'mariadbd';
$upgrade = is_file($binDir . '/mariadb-upgrade' . $exe) ? $binDir . '/mariadb-upgrade' . $exe : 'mariadb-upgrade';

$out = [];
@exec(escapeshellarg($server) . ' --version 2>&1', $out);
$version = preg_match('/\sVer\s+([0-9]+\.[0-9]+\.[0-9]+)/', implode(' ', $out), $m) ? $m[1] : '';
if ($version === '') {
    return; // cannot tell what is running - never guess on a data directory
}

$marker = $datadir . '/.locadev-version';
$known  = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
if ($known === $version) {
    return;
}
if ($known === '') {
    @file_put_contents($marker, $version); // data dir predates the marker: adopt it silently
    return;
}

echo "[Locadev] MariaDB $known -> $version, upgrading the database...\n";
$out  = [];
$code = 1;
@exec(escapeshellarg($upgrade) . ' -h 127.0.0.1 -P 3306 -u root 2>&1', $out, $code);
if ($code === 0) {
    @file_put_contents($marker, $version);
    echo "[Locadev] Database upgraded to $version.\n";
    return;
}

echo "[Locadev] Database upgrade FAILED - data/mariadb still belongs to $known.\n";
echo "[Locadev] Run this by hand, then start again:\n  $upgrade -h 127.0.0.1 -P 3306 -u root\n";
foreach (array_slice($out, -5) as $line) {
    echo "  $line\n";
}
