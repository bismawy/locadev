<?php
/**
 * Locadev Dashboard API
 * FrankenPHP + MariaDB local management API
 *
 * Two output modes:
 *  - JSON (default): consumed by the CLI / programmatic clients
 *  - HTML partial (HTMX: HX-Request header or ?partial=1): fragments for the dashboard UI
 */

define('LOCODEV_VERSION', '1.4.5');

/**
 * AdminNeo is mirrored on Locadev's own releases: upstream ships no asset and no adminneo.php in
 * the repository. ADMINNEO_RELEASE is the Locadev tag the file is attached to and is used in the
 * download URL instead of /releases/latest, which GitHub's edge served as a STALE redirect to the
 * previous release for PHP's stream client (verified: curl got v1.4.1, PHP got v1.4.0). Bump the
 * three together with the file in dist/ when a newer AdminNeo is taken.
 */
const ADMINNEO_VERSION = '5.8.0';
const ADMINNEO_ASSET = 'adminneo-5.8.0.zip';
const ADMINNEO_RELEASE = 'v1.4.5';
const ADMINNEO_URL = 'https://adminneo.localhost';

/** MariaDB's own schemas: never counted or shown as user databases, never droppable. */
const SYSTEM_DBS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

$baseDir = dirname(__DIR__);
$configDir = $baseDir . DIRECTORY_SEPARATOR . 'config';
$sitesDir = $configDir . DIRECTORY_SEPARATOR . 'sites';
$sitesJson = $configDir . DIRECTORY_SEPARATOR . 'sites.json';
$caddyfile = $baseDir . DIRECTORY_SEPARATOR . 'Caddyfile';
$cmsDir = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cms';
$composerDir = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'composer';

if (!is_dir($sitesDir)) {
    @mkdir($sitesDir, 0777, true);
}
if (!is_dir($cmsDir)) {
    @mkdir($cmsDir, 0777, true);
}

// Helpers
function copy_template_directory($src, $dst) {
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst)) {
        @mkdir($dst, 0777, true);
    }

    $srcReal = realpath($src);
    $dstReal = realpath($dst);

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'robocopy ' . escapeshellarg($srcReal) . ' ' . escapeshellarg($dstReal) . ' /E /NFL /NDL /NJH /NJS /nc /ns /np /MT:8';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code < 8) {
            return true;
        }
    } else {
        $cmd = 'cp -a ' . escapeshellarg($srcReal . '/.') . ' ' . escapeshellarg($dstReal . '/');
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code === 0) {
            return true;
        }
    }

    // Fallback recursive copy
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        $target = $dstReal . DIRECTORY_SEPARATOR . $files->getSubPathName();
        if ($file->isDir()) {
            if (!is_dir($target)) {
                @mkdir($target, 0777, true);
            }
        } else {
            @copy($file->getRealPath(), $target);
        }
    }
    return true;
}

function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $real = realpath($dir);
    global $baseDir;
    // Safety check: only delete directories safely inside sites/.
    // Resolve sites/ itself first - it may be a symlink (dual-boot shared sites).
    $sitesBase = realpath($baseDir . DIRECTORY_SEPARATOR . 'sites');
    if (!$real || !$sitesBase ||
        ($real !== $sitesBase && !str_starts_with($real, $sitesBase . DIRECTORY_SEPARATOR))) {
        return;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        exec('rd /s /q ' . escapeshellarg($real));
    } else {
        exec('rm -rf ' . escapeshellarg($real));
    }
}

// Always extract site files straight from the trusted .zip archive (downloaded over
// HTTPS from the official source). No intermediate data/templates/ copy: it froze
// the first-downloaded version forever and was a silent tampering surface.
function deploy_cms_template($preset, $absRoot, $cmsDir) {
    if (!class_exists('ZipArchive')) {
        return false; // zip extension toggled off: fail soft instead of a fatal
    }

    $readable = function (string $f): bool {
        $z = @new ZipArchive();
        if ($z->open($f) !== true) {
            return false;
        }
        $z->close();
        return true;
    };

    // Cache is versioned (classicpress-2.7.2.zip); new upstream release = new filename,
    // so the next site creation automatically picks up the latest version. Sorted
    // naturally (2.10 > 2.9) and validated: an interrupted download leaves a 0-byte
    // file that used to be trusted forever and made every later deploy fail silently.
    $prefix = $preset === 'classicpress' ? 'classicpress' : 'wordpress';
    $cached = glob($cmsDir . DIRECTORY_SEPARATOR . $prefix . '-*.zip') ?: [];
    usort($cached, fn(string $a, string $b): int => -strnatcasecmp($a, $b));

    $zipFile = null;
    foreach ($cached as $candidate) {
        if ($readable($candidate)) {
            $zipFile = $candidate;
            break;
        }
        @unlink($candidate);
    }

    if (!$zipFile) {
        if ($preset === 'classicpress') {
            $tag = latest_classicpress_tag();
            $zipFile = $cmsDir . DIRECTORY_SEPARATOR . "classicpress-{$tag}.zip";
            @file_put_contents($zipFile, fopen("https://codeload.github.com/ClassicPress/ClassicPress-release/zip/refs/tags/{$tag}", 'r'));
        } else {
            // wordpress.org/latest.zip is always current: download it once, read the
            // version inside, and store it under a versioned name so future releases
            // trigger a fresh download instead of reusing a stale cache forever.
            $tmp = $cmsDir . DIRECTORY_SEPARATOR . 'wp-download-' . bin2hex(random_bytes(4)) . '.zip';
            @file_put_contents($tmp, fopen('https://wordpress.org/latest.zip', 'r'));
            if (!$readable($tmp)) {
                @unlink($tmp);
                return false;
            }
            $version = null;
            $z = new ZipArchive();
            if ($z->open($tmp) === true) {
                $vp = (string) $z->getFromName('wordpress/wp-includes/version.php');
                if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $vp, $m)) {
                    $version = $m[1];
                }
                $z->close();
            }
            $zipFile = $cmsDir . DIRECTORY_SEPARATOR . 'wordpress-' . ($version ?: 'latest') . '.zip';
            @rename($tmp, $zipFile);
        }
        if (!$readable($zipFile)) {
            @unlink($zipFile); // never leave a truncated download behind as "cache"
            return false;
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return false;
    }

    // Extract to a temp dir, then move into place. Handles archives that wrap
    // everything in a single top-level folder (wordpress.zip -> wordpress/).
    $tmp = $cmsDir . DIRECTORY_SEPARATOR . 'extract-' . $preset . '-' . bin2hex(random_bytes(4));
    $ok = $zip->extractTo($tmp);
    $first = $zip->numFiles > 0 ? $zip->getNameIndex(0) : '';
    $zip->close();
    if (!$ok) {
        delete_extract_temp($tmp);
        return false;
    }

    $src = $tmp;
    $slash = strpos($first, '/');
    if ($slash !== false && $slash > 0) {
        $src = $tmp . DIRECTORY_SEPARATOR . substr($first, 0, $slash);
    }

    $result = is_dir($src) && copy_template_directory($src, $absRoot);
    delete_extract_temp($tmp);
    return $result;
}

function latest_classicpress_tag() {
    // Follow GitHub's /releases/latest redirect to the newest tag (no API token needed).
    // follow_location=0 with an explicit context: without it get_headers can fail on hosts
    // that send odd default contexts.
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'follow_location' => 0, 'timeout' => 15]]);
    $headers = @get_headers('https://github.com/ClassicPress/ClassicPress-release/releases/latest', false, $ctx);
    if (is_array($headers)) {
        $loc = '';
        foreach ($headers as $h) {
            if (preg_match('#^Location:\s*(\S+)#i', $h, $m)) {
                $loc = $m[1];
            }
        }
        // Delimiter must not be '#' (nor appear escaped inside the class): PHP ends the
        // pattern at the '#' in [^/?\#], so this silently returned the offline fallback.
        if ($loc && preg_match('~/tag/([^/?#]+)~', $loc, $m)) {
            return $m[1];
        }
    }
    return '2.7.2'; // offline fallback: last known good
}

function delete_extract_temp($dir) {
    if (!is_dir($dir)) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        exec('rd /s /q ' . escapeshellarg(realpath($dir)));
    } else {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function generate_wp_config_file($destDir, $dbName, $dbUser = 'root', $dbPass = '', $dbHost = '127.0.0.1:3306') {
    $keys = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'
    ];
    $salts = '';
    foreach ($keys as $key) {
        $val = bin2hex(random_bytes(32));
        $salts .= "define( '{$key}', '{$val}' );\n";
    }

    $configFile = $destDir . DIRECTORY_SEPARATOR . 'wp-config.php';
    $content = "<?php\n";
    $content .= "/** Base configuration for ClassicPress / WordPress **/\n\n";
    $content .= "define( 'DB_NAME', '{$dbName}' );\n";
    $content .= "define( 'DB_USER', '{$dbUser}' );\n";
    $content .= "define( 'DB_PASSWORD', '{$dbPass}' );\n";
    $content .= "define( 'DB_HOST', '{$dbHost}' );\n";
    $content .= "define( 'DB_CHARSET', 'utf8mb4' );\n";
    $content .= "define( 'DB_COLLATE', '' );\n\n";
    $content .= $salts . "\n";
    $content .= "\$table_prefix = 'wp_';\n\n";
    $content .= "define( 'WP_DEBUG', false );\n\n";
    $content .= "if ( ! defined( 'ABSPATH' ) ) {\n";
    $content .= "    define( 'ABSPATH', __DIR__ . '/' );\n";
    $content .= "}\n\n";
    $content .= "require_once ABSPATH . 'wp-settings.php';\n";

    file_put_contents($configFile, $content);
}

function deploy_laravel_project($absRoot, $composerDir, $dbName, $appUrl = '') {
    global $baseDir;
    $artisan = $absRoot . DIRECTORY_SEPARATOR . 'artisan';

    // Idempotent: never re-run create-project over an existing Laravel app
    if (file_exists($artisan)) {
        return ['success' => true];
    }

    // Locate a PHP CLI: bundled php.exe (Windows) or the frankenphp binary's php-cli mode
    if (PHP_OS_FAMILY === 'Windows') {
        $phpCli = $baseDir . '/bin/php.exe';
        $phpArgs = '';
    } else {
        $phpCli = $baseDir . '/bin/frankenphp';
        $phpArgs = ' php-cli';
    }
    if (!file_exists($phpCli)) {
        return ['success' => false, 'error' => 'PHP CLI binary not found'];
    }

    // Download composer.phar once into the shared cache
    $composer = $composerDir . DIRECTORY_SEPARATOR . 'composer.phar';
    if (!file_exists($composer)) {
        @mkdir($composerDir, 0777, true);
        @file_put_contents($composer, fopen('https://getcomposer.org/download/latest-stable/composer.phar', 'r'));
    }
    if (!file_exists($composer)) {
        return ['success' => false, 'error' => 'Failed to download composer.phar (check internet connection)'];
    }

    $composerHome = $composerDir . DIRECTORY_SEPARATOR . 'composer-home';
    @mkdir($composerHome, 0777, true);
    putenv('COMPOSER_HOME=' . $composerHome);
    putenv('COMPOSER_CACHE_DIR=' . $composerHome . DIRECTORY_SEPARATOR . 'cache');
    putenv('COMPOSER_NO_INTERACTION=1');

    $cmd = escapeshellarg($phpCli) . $phpArgs . ' ' . escapeshellarg($composer)
        . ' create-project laravel/laravel ' . escapeshellarg($absRoot)
        . ' --no-interaction --prefer-dist --no-progress 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !file_exists($artisan)) {
        return ['success' => false, 'error' => "composer create-project failed:\n" . implode("\n", array_slice($out, -6))];
    }

    // Writable storage (Unix; harmless no-op semantics on Windows)
    foreach (['storage', 'bootstrap/cache'] as $w) {
        @chmod($absRoot . '/' . $w, 0777);
        is_dir($absRoot . '/' . $w) && PHP_OS_FAMILY !== 'Windows'
            && @exec('chmod -R 0777 ' . escapeshellarg($absRoot . '/' . $w) . ' 2>/dev/null');
    }

    // Patch .env with MariaDB credentials (APP_KEY is already generated by create-project)
    $envFile = $absRoot . DIRECTORY_SEPARATOR . '.env';
    if (file_exists($envFile)) {
        $env = file_get_contents($envFile);
        $settings = [
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ];
        foreach ($settings as $k => $v) {
            $env = preg_replace('/^' . $k . '=.*$/m', $k . '=' . $v, $env, 1, $count);
            if (!$count) {
                $env .= $k . '=' . $v . "\n";
            }
        }
        file_put_contents($envFile, $env);
    }

    // Ensure APP_KEY exists (composer's key:generate post-script can fail silently on cache installs)
    if (file_exists($envFile) && !preg_match('/^APP_KEY=.+$/m', (string) @file_get_contents($envFile))) {
        @exec('cd ' . escapeshellarg($absRoot) . ' && ' . escapeshellarg($phpCli) . $phpArgs
            . ' ' . escapeshellarg($artisan) . ' key:generate --ansi 2>&1');
    }

    // Run initial migrations (sessions table etc.) so the very first page load works
    $migrateCmd = 'cd ' . escapeshellarg($absRoot) . ' && ' . escapeshellarg($phpCli) . $phpArgs
        . ' ' . escapeshellarg($artisan) . ' migrate --force 2>&1';
    @exec($migrateCmd); // ponytail: failures tolerated — site still serves if DB is temporarily down

    return ['success' => true];
}

// Helpers
function get_pdo() {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

function load_sites_json($sitesJson, $sitesDir) {
    if (file_exists($sitesJson)) {
        $data = json_decode(file_get_contents($sitesJson), true);
        if (is_array($data)) {
            return $data;
        }
    }
    // Fallback if sites.json doesn't exist
    $sites = [];
    $files = glob($sitesDir . DIRECTORY_SEPARATOR . '*.caddy');
    foreach ($files as $file) {
        $id = basename($file, '.caddy');
        $sites[] = [
            'id' => $id,
            'name' => ucfirst($id),
            'domain' => $id . '.localhost',
            'root' => './sites/' . $id,
            'php' => true,
            'enabled' => true,
            'database' => '',
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    return $sites;
}

function save_sites_json($sitesJson, $sites) {
    file_put_contents($sitesJson, json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function generate_caddy_block($site) {
    $domainStr = implode(', ', array_filter(array_map('trim', explode(',', $site['domain']))));
    // Normalize path for the Caddyfile
    $root = str_replace('\\', '/', rtrim($site['root'], '/\\'));
    // Laravel serves from the public/ subdirectory
    if (($site['preset'] ?? '') === 'laravel') {
        $root .= '/public';
    }

    $content = "{$domainStr} {\n";
    $content .= "\ttls internal\n";
    $content .= "\troot * {$root}\n";
    $content .= "\tencode zstd gzip\n";
    if (!empty($site['php'])) {
        $content .= "\tphp_server\n";
    }
    $content .= "\tfile_server\n";
    $content .= "}\n";

    return $content;
}

/** Path of the FrankenPHP binary for this platform. */
function frankenphp_bin() {
    global $baseDir;
    return $baseDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'frankenphp'
        . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
}

/**
 * Versi runtime yang benar-benar terpasang (bukan yang diharapkan).
 *
 * FrankenPHP menyatukan PHP + Caddy dalam satu biner, jadi satu eksekusi `version`
 * memberi ketiganya sekaligus (~0,15 dtk). cloudflared biner terpisah dan hanya ada
 * setelah tombol terowongan pertama diklik. Hasil dikunci per request: penguraian
 * regex gagal senyap kalau format upstream berubah, karena itu ada cek di self-check.
 *
 * @return array{frankenphp:string,php:string,caddy:string,cloudflared:string}
 */
function runtime_versions(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = ['frankenphp' => '', 'php' => '', 'caddy' => '', 'cloudflared' => ''];

    $fp = frankenphp_bin();
    if (is_file($fp)) {
        $out = [];
        exec(escapeshellarg($fp) . ' version 2>&1', $out);
        $text = implode(' ', $out); // "FrankenPHP 1.12.7 PHP 8.5.10 Caddy v2.11.4 h1:..."
        $patterns = [
            // v1.12.7, not 1.12.7: without the optional 'v' the version stayed empty and System
            // Info fell back to the SAPI name. self-check's runtime_versions check caught this.
            'frankenphp' => '/FrankenPHP\s+v?([0-9][0-9.]*)/i',
            'php' => '/PHP\s+([0-9][0-9.]*)/',
            'caddy' => '/Caddy\s+v?([0-9][0-9.]*)/i',
        ];
        foreach ($patterns as $key => $re) {
            if (preg_match($re, $text, $m)) {
                $cache[$key] = $m[1];
            }
        }
    }

    $cf = cloudflared_bin();
    if (is_file($cf)) {
        $out = [];
        exec(escapeshellarg($cf) . ' --version 2>&1', $out);
        if (preg_match('/cloudflared\s+version\s+([0-9][0-9.]*)/i', implode(' ', $out), $m)) {
            $cache['cloudflared'] = $m[1];
        }
    }

    return $cache;
}

/* ================= Cloudflare Quick Tunnel (trycloudflare.com) ================= */
/** Satu situs = satu cloudflared; state runtime ada di data/tunnels.json (gitignored). */

function cloudflared_bin() {
    global $baseDir;
    return $baseDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cloudflared'
        . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
}

function tunnels_state_file() {
    global $baseDir;
    return $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tunnels.json';
}

/**
 * Where this install's MariaDB server binary comes from. Windows ships one inside bin/ (it moves
 * only when Locadev moves); Linux and macOS use the system package, which follows OS updates.
 * The dashboard shows it so "which MariaDB am I actually running" has one answer on every OS.
 */
function mariadb_server_binary(): string {
    global $baseDir;

    $bundled = $baseDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mariadb'
        . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mariadbd'
        . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (is_file($bundled)) {
        return 'bundled — bin/mariadb';
    }

    foreach (['mariadbd', 'mysqld'] as $name) {
        $out = [];
        $lookup = PHP_OS_FAMILY === 'Windows' ? 'where ' . $name . ' 2>nul' : 'command -v ' . $name . ' 2>/dev/null';
        @exec($lookup, $out);
        $path = trim((string) ($out[0] ?? ''));
        if ($path !== '' && is_file($path)) {
            return 'system — ' . $path;
        }
    }

    return 'unknown (not bundled, not found on PATH)';
}

/** @return array<string, array{pid:int,url:string,host:string,started:int}> */
function read_tunnels(): array {
    $data = json_decode((string) @file_get_contents(tunnels_state_file()), true);
    return is_array($data) ? $data : [];
}

function write_tunnels(array $tunnels): void {
    @file_put_contents(tunnels_state_file(), json_encode($tunnels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** Nama aset rilis cloudflared untuk OS/arsitektur ini (null = tidak didukung). */
function cloudflared_asset(): ?string {
    $os = ['Windows' => 'windows', 'Linux' => 'linux', 'Darwin' => 'darwin'][PHP_OS_FAMILY] ?? null;
    if ($os === null) {
        return null;
    }
    $machine = strtolower((string) php_uname('m'));
    $arch = (str_contains($machine, 'arm64') || str_contains($machine, 'aarch64')) ? 'arm64' : 'amd64';
    $suffix = $os === 'windows' ? '.exe' : ($os === 'darwin' ? '.tgz' : '');
    return 'cloudflared-' . $os . '-' . $arch . $suffix;
}

/* ================= AdminNeo (optional DB manager) ================= */

function adminneo_dir(): string {
    global $baseDir;
    return $baseDir . '/data/adminer';
}

/** Installed = the entry point and the program itself are both there. */
function adminneo_installed(): bool {
    return is_file(adminneo_dir() . '/adminneo.php') && is_file(adminneo_dir() . '/index.php');
}

/** AdminNeo config: application login root/locadev, DB root without a password. */
function adminneo_config_php(): string {
    return "<?php\n"
        . "// AdminNeo config — login aplikasi: user \"root\" + password \"locadev\"\n"
        . "// (password level aplikasi, BUKAN password MariaDB — DB root terhubung tanpa password).\n"
        . "// Aman karena Caddyfile bind loopback-only. Ubah hash di bawah untuk ganti password.\n"
        . "return [\n"
        . "\t\"defaultDriver\" => \"mysql\",\n"
        . "\t\"defaultPasswordHash\" => '" . password_hash('locadev', PASSWORD_DEFAULT) . "',\n"
        . "\t\"servers\" => [\n"
        . "\t\t[\n"
        . "\t\t\t\"driver\" => \"mysql\",\n"
        . "\t\t\t\"name\" => \"Locadev MariaDB\",\n"
        . "\t\t\t\"server\" => \"127.0.0.1\",\n"
        . "\t\t\t\"username\" => \"root\",\n"
        . "\t\t\t\"password\" => \"\",\n"
        . "\t\t],\n"
        . "\t],\n"
        . "\t\"defaultServer\" => 0,\n"
        . "];\n";
}

/**
 * Fetch AdminNeo on demand, the way ensure_cloudflared() does.
 *
 * AdminNeo is one PHP file, but upstream publishes NO release asset and keeps adminneo.php out of
 * the repository (bin/compile.php builds it from vendor/), so there is no upstream URL to fetch.
 * Locadev mirrors the file on its own releases instead (Apache-2.0, LICENSE.md included).
 * The file lives in data/, which no release or installer ever writes: it is user data, and the
 * config next to it holds the user's own password. Nothing here is overwritten once installed.
 */
function ensure_adminneo(): array {
    if (adminneo_installed()) {
        return ['success' => true, 'already' => true];
    }
    if (!ini_get('allow_url_fopen')) {
        return ['success' => false, 'error' => 'allow_url_fopen is disabled — cannot download AdminNeo'];
    }
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'The zip extension is not loaded — cannot unpack AdminNeo'];
    }

    $dir = adminneo_dir();
    @mkdir($dir, 0777, true);
    $tmp = $dir . '/adminneo.zip.part';
    // Explicit tag, never /releases/latest (see the note on ADMINNEO_RELEASE).
    $url = 'https://github.com/' . LOCADEV_REPO . '/releases/download/' . ADMINNEO_RELEASE . '/' . ADMINNEO_ASSET;

    set_time_limit(0);
    $ctx = stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'locadev']]);
    if (!@copy($url, $tmp) || filesize($tmp) < 100000) { // <100 KB = halaman error, bukan programnya
        @unlink($tmp);
        return ['success' => false, 'error' => 'Download failed (no network, or the Locadev release has no ' . ADMINNEO_ASSET . ')'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return ['success' => false, 'error' => 'Cannot open the downloaded archive'];
    }
    // Archive comes over the network: refuse anything that would extract outside data/adminer.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, ':')) {
            $zip->close();
            @unlink($tmp);
            return ['success' => false, 'error' => 'Refusing an archive with unexpected paths']; 
        }
    }
    $zip->extractTo($dir);
    $zip->close();
    @unlink($tmp);

    if (!is_file($dir . '/adminneo.php')) {
        return ['success' => false, 'error' => 'The archive did not contain adminneo.php'];
    }
    // index.php + config only when missing, so a re-run never touches an edited config.
    if (!is_file($dir . '/index.php')) {
        @file_put_contents($dir . '/index.php', "<?php\nrequire __DIR__ . '/adminneo.php';\n");
    }
    if (!is_file($dir . '/adminneo-config.php')) {
        @file_put_contents($dir . '/adminneo-config.php', adminneo_config_php());
    }

    return ['success' => true];
}

/** Unduh cloudflared sekali saja (~50 MB) dari GitHub releases. */
function ensure_cloudflared(): array {
    $bin = cloudflared_bin();
    if (is_file($bin) && filesize($bin) > 1000) {
        return ['success' => true];
    }

    $asset = cloudflared_asset();
    if ($asset === null) {
        return ['success' => false, 'error' => 'Platform not supported for the automatic cloudflared download'];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['success' => false, 'error' => 'allow_url_fopen is disabled — cannot download cloudflared'];
    }

    // Stream HTTP (bukan curl): build PHP di Windows tidak punya CA bundle untuk curl,
    // sedangkan stream HTTPS diverifikasi normal — sama seperti unduhan CMS di atas.
    $url = 'https://github.com/cloudflare/cloudflared/releases/latest/download/' . $asset;
    $tmp = $bin . '.part';
    @mkdir(dirname($bin), 0777, true);
    set_time_limit(0); // unduhan melebihi max_execution_time (30 dtk)
    $ctx = stream_context_create(['http' => ['timeout' => 900, 'user_agent' => 'locadev']]);
    $ok = @copy($url, $tmp, $ctx);
    if (!$ok || filesize($tmp) < 1000000) { // <1 MB = hampiran halaman error, bukan biner
        @unlink($tmp);
        return ['success' => false, 'error' => 'Download failed (no network, or the GitHub release is unreachable)'];
    }

    if (PHP_OS_FAMILY === 'Darwin') { // rilis macOS berbentuk .tgz
        $out = [];
        $code = 0;
        exec('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg(dirname($bin)) . ' 2>&1', $out, $code);
        @unlink($tmp);
        if ($code !== 0 || !is_file($bin)) {
            return ['success' => false, 'error' => 'Cannot extract ' . $asset];
        }
    } else {
        @rename($tmp, $bin);
    }
    @chmod($bin, 0755);
    return ['success' => true];
}

/** URL publik yang dicetak cloudflared di log-nya. */
function tunnel_url_from_log(string $log): ?string {
    return preg_match('~https://[a-z0-9][a-z0-9-]*\.trycloudflare\.com~i', $log, $m) ? $m[0] : null;
}

/** Kill pid — hanya bila prosesnya benar-benar cloudflared (pid bisa didaur ulang). */
function stop_tunnel_pid(int $pid): void {
    if ($pid <= 0) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = [];
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>&1', $out);
        if (!str_contains(strtolower(implode(' ', $out)), 'cloudflared')) {
            return;
        }
        exec('taskkill /F /PID ' . $pid . ' 2>&1');
        return;
    }
    $out = [];
    exec('ps -p ' . $pid . ' -o args= 2>/dev/null', $out); // ada di Linux & macOS
    if (!str_contains(strtolower(implode(' ', $out)), 'cloudflared')) {
        return;
    }
    exec('kill ' . $pid . ' 2>&1');
}

function stop_site_tunnel(string $id): void {
    $tunnels = read_tunnels();
    if (!isset($tunnels[$id])) {
        return;
    }
    stop_tunnel_pid((int) ($tunnels[$id]['pid'] ?? 0));
    unset($tunnels[$id]);
    write_tunnels($tunnels);
}

/** Jalankan cloudflared untuk satu situs; mengembalikan URL publiknya. */
/**
 * WordPress/ClassicPress menyimpan URL absolut (https://<nama>.localhost) di database, sehingga
 * pengunjung dari luar (mis. ponsel) gagal memuat CSS/aset miliknya. mu-plugin ini mengganti host
 * HANYA ketika permintaan datang lewat domain Quick Tunnel; akses lokal tidak berubah.
 */
function ensure_tunnel_url_plugin(array $site): void {
    $root = rtrim(str_replace('\\', '/', (string) ($site['root'] ?? '')), '/');
    $content = $root . '/wp-content';
    if ($root === '' || !is_dir($content)) {
        return; // bukan situs WordPress/ClassicPress
    }
    $dir = $content . '/mu-plugins';
    $file = $dir . '/locadev-tunnel-url.php';
        // HTTP_HOST tetap <nama>.localhost di sisi origin (--http-host-header), jadi host publik
    // dibaca dari X-Forwarded-Host yang HANYA dikirim cloudflared pada permintaan lewat tunnel.
    $code = <<<'PHP'
<?php
/**
 * Plugin Name: Locadev Tunnel URL
 * Description: Pakai host publik saat situs diakses lewat Cloudflare Tunnel Locadev.
 */
$locadevPublic = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
if (str_ends_with($locadevPublic, '.trycloudflare.com')) {
    $locadevUrl = 'https://' . $locadevPublic;
    $locadevLocal = 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
    add_filter('option_home', fn() => $locadevUrl);
    add_filter('option_siteurl', fn() => $locadevUrl);
    // Aset (wp-content, wp-includes, tema) dibuat dari konstanta yang sudah dihitung sebelum
    // plugin ini dimuat, jadi host-nya diganti di filter URL masing-masing.
    $locadevRewrite = fn($url) => str_replace($locadevLocal, $locadevUrl, (string) $url);
    foreach (['content_url', 'includes_url', 'plugins_url', 'theme_root_uri',
              'stylesheet_directory_uri', 'template_directory_uri', 'stylesheet_uri'] as $locadevFilter) {
        add_filter($locadevFilter, $locadevRewrite);
    }
}
PHP;
    // sites/ tidak ikut git, jadi file ini dipasang Locadev sendiri (idempoten).
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_file($file) || (string) @file_get_contents($file) !== $code) {
        @file_put_contents($file, $code);
    }
}

function start_site_tunnel(array $site, string $origin = 'https://localhost'): array {
    global $baseDir;

    $ready = ensure_cloudflared();
    if (!$ready['success']) {
        return $ready;
    }

    $id = (string) $site['id'];
    $host = preg_replace('~[^a-z0-9.-]~i', '', trim(explode(',', (string) $site['domain'])[0]));
    if ($host === '') {
        return ['success' => false, 'error' => 'Site has no usable domain'];
    }
    ensure_tunnel_url_plugin($site);

    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

    // Origin selalu https://localhost + Host header situs → vhost Caddy yang tepat,
    // tanpa bergantung pada resolusi DNS *.localhost (tidak universal di Windows).
    $logDir = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tunnels';
    @mkdir($logDir, 0777, true);
    $log = $logDir . DIRECTORY_SEPARATOR . $id . '.log';
    @unlink($log);

    // proc_open dengan descriptor berupa FILE + --logfile (bukan pipe, bukan Start-Process):
    // anak tidak mewarisi pipe stdout PHP sehingga panggilan ini tidak menggantung.
    // exec()/popen() + PowerShell Start-Process menggantung sampai cloudflared berhenti.
    $proc = proc_open(
        [
            cloudflared_bin(), 'tunnel', '--url', $origin, '--no-tls-verify',
            '--http-host-header', $host, '--logfile', $log,
        ],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) {
        return ['success' => false, 'error' => 'Cannot start cloudflared'];
    }
    $pid = (int) proc_get_status($proc)['pid'];

    // cloudflared mencetak URL beberapa detik setelah handshake.
    $url = null;
    for ($i = 0; $i < 40 && $url === null; $i++) {
        usleep(500000);
        $url = tunnel_url_from_log((string) @file_get_contents($log));
    }

    if ($url === null) {
        stop_tunnel_pid($pid);
        $lines = preg_split('~\R~', trim((string) @file_get_contents($log)));
        $tail = trim(implode(' ', array_slice($lines ?: [], -2)));
        return ['success' => false, 'error' => 'cloudflared did not come up' . ($tail !== '' ? ': ' . $tail : '')];
    }

    $tunnels = read_tunnels();
    $tunnels[$id] = ['pid' => $pid, 'url' => $url, 'host' => $host, 'started' => time()];
    write_tunnels($tunnels);
    return ['success' => true, 'url' => $url, 'pid' => $pid];
}


function reload_caddy($caddyfile) {
    $bin = frankenphp_bin();
    if (!file_exists($bin)) {
        return ['success' => false, 'error' => 'frankenphp binary not found'];
    }
    // Run synchronously: `frankenphp reload` signals the running server and exits, so
    // the exit code is a real answer instead of an unconditional "success".
    $out = [];
    $code = 0;
    exec(escapeshellarg($bin) . ' reload --config ' . escapeshellarg($caddyfile) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        return ['success' => false, 'error' => 'Caddy reload failed: ' . implode(' ', array_slice($out, -2))];
    }
    return ['success' => true];
}

/** Server status snapshot, shared by the JSON endpoint and the HTMX metrics partial. */
function get_status_data() {
    global $sitesJson, $sitesDir;
    $pdo = get_pdo();
    $dbCount = 0;
    $dbConnected = false;
    $dbVersion = '';
    if ($pdo) {
        $dbConnected = true;
        try {
            // User databases only: same set the Databases view shows (system schemas excluded).
            $all = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            $dbCount = count(array_diff($all, SYSTEM_DBS));
            $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (Exception $e) {}
    }

    // curl is an optional (toggleable) extension: probe the admin API over a socket
    // instead of dying with "Call to undefined function curl_init()".
    $caddyOnline = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('http://127.0.0.1:2019/config/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_exec($ch);
        // no curl_close(): deprecated in PHP 8.5 (a no-op since 8.0) and its notice
        // would corrupt the JSON/SSE output
        $caddyOnline = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    } else {
        $sock = @fsockopen('127.0.0.1', 2019, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            $caddyOnline = true;
        }
    }

    $sites = load_sites_json($sitesJson, $sitesDir);

    return [
        'caddy_online' => $caddyOnline,
        'db_online' => $dbConnected,
        'db_version' => $dbVersion,
        'db_count' => $dbCount,
        'php_version' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
        'total_sites' => count($sites),
        'active_sites' => count(array_filter($sites, fn($s) => !empty($s['enabled']))),
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Read request body for JSON posts
$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = $json;
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }
    if (!empty($input['action'])) {
        $action = $input['action'];
    }
}

// Output mode: HTMX partials (HTML) vs plain JSON. The CLI and the deploy stream
// always speak JSON/SSE — they never send the HX-Request header.
$isHx = (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') || isset($_GET['partial']);
header('Content-Type: ' . ($isHx ? 'text/html' : 'application/json') . '; charset=utf-8');
// Always loaded: the JSON path calls config_ini_values()/get_extensions_data() from here.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/components.php';
// Updater engine: the same file `locadev update` runs, so the two frontends cannot drift.
require_once dirname(__DIR__) . '/scripts/update.php';

// CSRF fence: there is no auth and the dashboard answers on a loopback port that any
// page the user visits can POST to. Loopback is not a defense against a form POST, so
// reject state-changing requests whose Origin is not this host. Browsers always send
// Origin on POST (and it cannot be forged by page JS); the CLI sends none and passes.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($origin = $_SERVER['HTTP_ORIGIN'] ?? '') !== '') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!in_array(parse_url($origin, PHP_URL_HOST), [$host, explode(':', $host)[0]], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cross-origin request blocked']);
        exit;
    }
}

switch ($action) {
    case 'status':
        $data = get_status_data();
        $data['base_dir'] = $baseDir;

        if ($isHx) {
            // OOB fragment: refresh metric cards + topbar status dots
            echo partial_status();
            exit;
        }
        echo json_encode($data);
        break;

    case 'sites':
        if ($isHx) {
            echo partial_sites($_GET);
            exit;
        }
        $sites = load_sites_json($sitesJson, $sitesDir);
        echo json_encode([
            'success' => true,
            'sites' => $sites
        ]);
        break;

    case 'save_site':
        $isStream = !empty($_GET['stream']) || !empty($input['stream']);
        if ($isStream) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $sendEvent = function($stepId, $percent, $title, $detail = '', $done = false, $error = '') use ($isStream) {
            if (!$isStream) return;
            echo "data: " . json_encode([
                'step' => $stepId,
                'percent' => $percent,
                'title' => $title,
                'detail' => $detail,
                'done' => $done,
                'error' => $error
            ], JSON_UNESCAPED_SLASHES) . "\n\n";
            @flush();
        };

        $id = preg_replace('#[^a-z0-9_-]#i', '', $input['id'] ?? '');
        $name = trim($input['name'] ?? '');
        $domain = trim($input['domain'] ?? '');
        $root = trim($input['root'] ?? '');
        $php = !isset($input['php']) || !empty($input['php']);
        $enabled = !isset($input['enabled']) || !empty($input['enabled']);
        $preset = trim($input['preset'] ?? 'blank');
        $database = trim($input['database'] ?? '');
        $createFolder = !empty($input['create_folder']);
        $createDb = !empty($input['create_db']);

        if (empty($name)) {
            if ($isStream) {
                $sendEvent('error', 100, 'Error', '', false, 'Website name is required');
            } else {
                echo json_encode(['success' => false, 'error' => 'Website name is required']);
            }
            exit;
        }

        if (empty($id)) {
            $id = strtolower(preg_replace('#[^a-z0-9_-]#i', '-', $name));
            $id = trim($id, '-');
        }

        if (empty($domain)) {
            $domain = $id . '.localhost';
        }

        if (empty($root)) {
            $root = './sites/' . $id;
        }

        $absRoot = $root;
        if (str_starts_with($root, './') || str_starts_with($root, '.\\')) {
            $absRoot = $baseDir . DIRECTORY_SEPARATOR . substr($root, 2);
        }

        $isEdit = !empty($input['id']);
        $sites = load_sites_json($sitesJson, $sitesDir);

        // Auto-increment if domain, id, or directory already exists for a new site
        if (!$isEdit) {
            $baseId = $id;
            $counter = 2;
            $existingDomains = array_map('strtolower', array_column($sites, 'domain'));
            $existingRoots = array_map('strtolower', array_column($sites, 'root'));
            $existingIds = array_map('strtolower', array_column($sites, 'id'));

            while (
                in_array(strtolower($id), $existingIds) ||
                in_array(strtolower($domain), $existingDomains) ||
                in_array(strtolower($root), $existingRoots) ||
                is_dir($absRoot)
            ) {
                $id = $baseId . '-' . $counter;
                $domain = $id . '.localhost';
                $root = './sites/' . $id;
                $absRoot = $baseDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . $id;
                if (!empty($database) && ($preset === 'classicpress' || $preset === 'wordpress' || $preset === 'laravel')) {
                    $database = str_replace('-', '_', $id);
                }
                $counter++;
            }
        }

        if ($preset === 'classicpress' || $preset === 'wordpress' || $preset === 'laravel') {
            $createFolder = true;
            $php = true;
            if (empty($database)) {
                $database = str_replace('-', '_', $id);
            }
            $createDb = true;
        }

        $sendEvent('init', 15, 'Validating configuration', "Domain: {$domain} • Root: {$root}");

        if (!is_dir($absRoot)) {
            $sendEvent('dir', 30, 'Preparing project directory', $absRoot);
            @mkdir($absRoot, 0777, true);
        }

        // Auto create MariaDB database if requested
        $cleanDb = preg_replace('#[^a-zA-Z0-9_]#', '', $database);
        if ($createDb && !empty($cleanDb)) {
            $sendEvent('db', 50, 'Creating MariaDB database', "Database: `{$cleanDb}`");
            $pdo = get_pdo();
            if ($pdo) {
                try {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cleanDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (Exception $e) {}
            }
        }

        // Handle CMS Presets (ClassicPress / WordPress)
        if ($preset === 'classicpress') {
            $sendEvent('extract', 70, 'Copying ClassicPress template', 'Preparing core files (fast mirror)');
            deploy_cms_template('classicpress', $absRoot, $cmsDir);

            if (!empty($cleanDb)) {
                $sendEvent('config', 85, 'Configuring wp-config.php', 'Generating salts & MariaDB credentials');
                generate_wp_config_file($absRoot, $cleanDb);
            }
        } elseif ($preset === 'wordpress') {
            $sendEvent('extract', 70, 'Copying WordPress template', 'Preparing core files (fast mirror)');
            deploy_cms_template('wordpress', $absRoot, $cmsDir);

            if (!empty($cleanDb)) {
                $sendEvent('config', 85, 'Configuring wp-config.php', 'Generating salts & MariaDB credentials');
                generate_wp_config_file($absRoot, $cleanDb);
            }
        } elseif ($preset === 'laravel') {
            $sendEvent('extract', 70, 'Installing Laravel via Composer', 'Downloading skeleton & dependencies (1-2 minutes)');
            $primaryHost = preg_split('/[\s,]+/', preg_replace('#^https?://#i', '', $domain))[0];
            $laravelResult = deploy_laravel_project($absRoot, $composerDir, $cleanDb, "https://{$primaryHost}");
            if (empty($laravelResult['success'])) {
                $sendEvent('error', 100, 'Laravel installation failed', '', false, $laravelResult['error'] ?? 'Unknown error');
                if (!$isStream) {
                    echo json_encode(['success' => false, 'error' => $laravelResult['error'] ?? 'Unknown error']);
                }
                exit;
            }
            $sendEvent('config', 85, 'Configuring .env', 'MariaDB credentials & APP_KEY ready');
        } elseif ($createFolder) {
            $sendEvent('files', 75, 'Creating starter files', 'index.html & index.php');
            if (!is_dir($absRoot)) {
                @mkdir($absRoot, 0777, true);
            }
            $htmlFile = $absRoot . DIRECTORY_SEPARATOR . 'index.html';
            $phpFile = $absRoot . DIRECTORY_SEPARATOR . 'index.php';
            $siteFavicon = $absRoot . DIRECTORY_SEPARATOR . 'favicon.ico';

            // Copy default favicon if available
            $defaultFavicon = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.ico';
            if (file_exists($defaultFavicon) && !file_exists($siteFavicon)) {
                @copy($defaultFavicon, $siteFavicon);
            }

            if (!file_exists($htmlFile)) {
                $htmlContent = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
                $htmlContent .= "  <meta charset=\"UTF-8\">\n";
                $htmlContent .= "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
                $htmlContent .= "  <title>Hello, welcome to Locadev!</title>\n";
                $htmlContent .= "  <link rel=\"icon\" href=\"/favicon.ico\" type=\"image/x-icon\">\n";
                $htmlContent .= "  <style>\n";
                $htmlContent .= "    * { box-sizing: border-box; margin: 0; padding: 0; }\n";
                $htmlContent .= "    body {\n";
                $htmlContent .= "      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;\n";
                $htmlContent .= "      background-color: #09090b;\n";
                $htmlContent .= "      color: #fafafa;\n";
                $htmlContent .= "      min-height: 100vh;\n";
                $htmlContent .= "      display: flex;\n";
                $htmlContent .= "      align-items: center;\n";
                $htmlContent .= "      justify-content: center;\n";
                $htmlContent .= "      -webkit-font-smoothing: antialiased;\n";
                $htmlContent .= "    }\n";
                $htmlContent .= "    h1 {\n";
                $htmlContent .= "      font-size: 20px;\n";
                $htmlContent .= "      font-weight: 500;\n";
                $htmlContent .= "      letter-spacing: -0.02em;\n";
                $htmlContent .= "      color: #fafafa;\n";
                $htmlContent .= "    }\n";
                $htmlContent .= "  </style>\n";
                $htmlContent .= "</head>\n<body>\n";
                $htmlContent .= "  <h1>Hello, welcome to Locadev!</h1>\n";
                $htmlContent .= "</body>\n</html>";
                @file_put_contents($htmlFile, $htmlContent);
            }

            if (!file_exists($phpFile)) {
                $phpContent = "<?php\n// " . addslashes($name) . " - Powered by FrankenPHP Locadev\n";
                $phpContent .= "if (file_exists(__DIR__ . '/index.html')) {\n";
                $phpContent .= "    readfile(__DIR__ . '/index.html');\n";
                $phpContent .= "    exit;\n";
                $phpContent .= "}\n";
                $phpContent .= "echo 'Hello, welcome to Locadev!';\n";
                @file_put_contents($phpFile, $phpContent);
            }
        }

        $sendEvent('caddy', 95, 'Registering Caddy virtual host', 'Hot-reloading FrankenPHP runtime');

        $sites = load_sites_json($sitesJson, $sitesDir);
        $found = false;
        foreach ($sites as &$s) {
            if ($s['id'] === $id) {
                $s['name'] = $name;
                $s['domain'] = $domain;
                $s['root'] = $root;
                $s['php'] = $php;
                $s['enabled'] = $enabled;
                $s['database'] = $database;
                if (!empty($preset) && $preset !== 'blank') {
                    $s['preset'] = $preset;
                }
                $found = true;
                break;
            }
        }
        unset($s);

        if (!$found) {
            $sites[] = [
                'id' => $id,
                'name' => $name,
                'domain' => $domain,
                'root' => $root,
                'php' => $php,
                'enabled' => $enabled,
                'database' => $database,
                'preset' => $preset,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Write site Caddyfile
        $caddyFileForSite = $sitesDir . DIRECTORY_SEPARATOR . $id . '.caddy';
        if ($enabled) {
            $siteObj = [
                'id' => $id,
                'name' => $name,
                'domain' => $domain,
                'root' => $root,
                'php' => $php,
                'preset' => $preset
            ];
            file_put_contents($caddyFileForSite, generate_caddy_block($siteObj));
        } else {
            if (file_exists($caddyFileForSite)) {
                @unlink($caddyFileForSite);
            }
        }

        save_sites_json($sitesJson, $sites);
        $reloadResult = reload_caddy($caddyfile);

        if ($isStream) {
            $domain = preg_replace('#^https?://#', '', $domain);
            $primaryHost = explode(',', $domain)[0];
            $sendEvent('finish', 100, 'Website is ready to use!', "https://{$primaryHost}", true);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Website saved',
                'caddy_reload' => $reloadResult
            ]);
        }
        break;

    case 'toggle_site':
        $id = preg_replace('#[^a-z0-9_-]#i', '', $input['id'] ?? '');

        $sites = load_sites_json($sitesJson, $sitesDir);
        $matched = null;
        foreach ($sites as &$s) {
            if ($s['id'] === $id) {
                // flip-semantics: toggle state tersimpan, tidak bergantung field form
                $enabled = !$s['enabled'];
                $s['enabled'] = $enabled;
                $matched = $s;
                break;
            }
        }
        unset($s);

        if (!$matched) {
            echo json_encode(['success' => false, 'error' => 'Site not found']);
            exit;
        }

        $caddyFileForSite = $sitesDir . DIRECTORY_SEPARATOR . $id . '.caddy';
        if ($enabled) {
            file_put_contents($caddyFileForSite, generate_caddy_block($matched));
        } else {
            if (file_exists($caddyFileForSite)) {
                @unlink($caddyFileForSite);
            }
            stop_site_tunnel($id); // situs nonaktif tidak boleh tetap publik
        }

        save_sites_json($sitesJson, $sites);
        $reloadResult = reload_caddy($caddyfile);

        if ($isHx) {
            hx_toast('Website ' . ($enabled ? 'activated' : 'disabled'));
            echo partial_sites($_GET + $_POST);
            exit;
        }
        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'caddy_reload' => $reloadResult
        ]);
        break;

    case 'toggle_tunnel':
        $id = preg_replace('#[^a-z0-9_-]#i', '', $input['id'] ?? '');

        $site = null;
        foreach (load_sites_json($sitesJson, $sitesDir) as $s) {
            if ($s['id'] === $id) {
                $site = $s;
                break;
            }
        }
        if (!$site) {
            echo json_encode(['success' => false, 'error' => 'Site not found']);
            exit;
        }

        if (isset(read_tunnels()[$id])) {
            stop_site_tunnel($id);
            $result = ['success' => true, 'active' => false, 'message' => 'Public tunnel stopped'];
        } elseif (empty($site['enabled'])) {
            $result = ['success' => false, 'active' => false, 'error' => 'Enable the website first'];
        } else {
            $result = start_site_tunnel($site);
            $result['active'] = $result['success'];
        }

        if ($isHx) {
            if ($result['success']) {
                hx_toast($result['active']
                    ? 'Public URL: ' . $result['url'] . ' (anyone with the link can access it)'
                    : 'Public tunnel stopped');
            } else {
                hx_toast('Tunnel failed: ' . $result['error'], true);
            }
            echo partial_sites($_GET + $_POST);
            exit;
        }
        echo json_encode($result);
        break;

    case 'delete_site':
        $id = preg_replace('#[^a-z0-9_-]#i', '', $input['id'] ?? '');
        $deleteFolder = !empty($input['delete_folder']);
        $deleteDb = !empty($input['delete_db']);

        $sites = load_sites_json($sitesJson, $sitesDir);
        $targetSite = null;
        $filtered = [];
        foreach ($sites as $s) {
            if ($s['id'] === $id) {
                $targetSite = $s;
            } else {
                $filtered[] = $s;
            }
        }

        if (!$targetSite) {
            echo json_encode(['success' => false, 'error' => 'Site not found']);
            exit;
        }

        $caddyFileForSite = $sitesDir . DIRECTORY_SEPARATOR . $id . '.caddy';
        if (file_exists($caddyFileForSite)) {
            @unlink($caddyFileForSite);
        }

        // Delete project folder if requested
        if ($deleteFolder && !empty($targetSite['root'])) {
            $root = $targetSite['root'];
            $absRoot = $root;
            if (str_starts_with($root, './') || str_starts_with($root, '.\\')) {
                $absRoot = $baseDir . DIRECTORY_SEPARATOR . substr($root, 2);
            }
            delete_directory_recursive($absRoot);
        }

        stop_site_tunnel($id);
        save_sites_json($sitesJson, $filtered);

        // Optional drop DB
        if ($deleteDb && !empty($targetSite['database'])) {
            $pdo = get_pdo();
            if ($pdo) {
                $cleanDb = preg_replace('#[^a-zA-Z0-9_]#', '', $targetSite['database']);
                if (!empty($cleanDb) && !in_array($cleanDb, SYSTEM_DBS, true)) {
                    try {
                        $pdo->exec("DROP DATABASE IF EXISTS `{$cleanDb}`");
                    } catch (Exception $e) {}
                }
            }
        }

        $reloadResult = reload_caddy($caddyfile);

        if ($isHx) {
            hx_toast($deleteFolder ? 'Website and folder deleted' : 'Website config removed');
            echo partial_sites($_GET + $_POST);
            exit;
        }
        echo json_encode([
            'success' => true,
            'message' => 'Website deleted',
            'caddy_reload' => $reloadResult
        ]);
        break;

    case 'reload_server':
        $reloadResult = reload_caddy($caddyfile);
        if ($isHx) {
            hx_toast($reloadResult['success'] ? 'FrankenPHP reloaded with zero downtime' : 'Failed to reload Caddy');
            exit; // no swap: the reload button uses hx-swap="none"
        }
        echo json_encode([
            'success' => $reloadResult['success'],
            'result' => $reloadResult
        ]);
        break;

    case 'databases':
        if ($isHx) {
            echo partial_databases($_GET);
            exit;
        }
        $pdo = get_pdo();
        if (!$pdo) {
            echo json_encode(['success' => false, 'error' => 'MariaDB is not connected (port 3306)']);
            exit;
        }
        $stmt = $pdo->query("
            SELECT 
                table_schema AS db_name,
                COUNT(table_name) AS total_tables,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables
            GROUP BY table_schema
        ");
        $dbStats = [];
        while ($row = $stmt->fetch()) {
            $dbStats[$row['db_name']] = [
                'tables' => (int)$row['total_tables'],
                'size_mb' => (float)($row['size_mb'] ?? 0)
            ];
        }

        $allDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        $systemDbs = SYSTEM_DBS;
        foreach ($allDbs as $name) {
            $isSystem = in_array($name, $systemDbs);
            $result[] = [
                'name' => $name,
                'is_system' => $isSystem,
                'tables' => $dbStats[$name]['tables'] ?? 0,
                'size_mb' => $dbStats[$name]['size_mb'] ?? 0
            ];
        }

        echo json_encode(['success' => true, 'databases' => $result]);
        break;

    case 'create_database':
        $name = preg_replace('#[^a-zA-Z0-9_]#', '', $input['name'] ?? '');
        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid database name']);
            exit;
        }
        $pdo = get_pdo();
        if (!$pdo) {
            echo json_encode(['success' => false, 'error' => 'MariaDB offline']);
            exit;
        }
        try {
            $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($isHx) {
                hx_toast("Database '{$name}' created");
                echo partial_databases($_GET + $_POST);
                exit;
            }
            echo json_encode(['success' => true, 'message' => "Database '{$name}' created"]);
        } catch (Exception $e) {
            if ($isHx) {
                hx_toast('Failed to create database: ' . $e->getMessage(), true);
                echo partial_databases($_GET + $_POST);
                exit;
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'drop_database':
        $name = preg_replace('#[^a-zA-Z0-9_]#', '', $input['name'] ?? '');
        if (in_array($name, SYSTEM_DBS, true)) {
            echo json_encode(['success' => false, 'error' => 'System databases cannot be deleted']);
            exit;
        }
        $pdo = get_pdo();
        if (!$pdo) {
            echo json_encode(['success' => false, 'error' => 'MariaDB offline']);
            exit;
        }
        try {
            $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
            if ($isHx) {
                hx_toast("Database '{$name}' dropped");
                echo partial_databases($_GET + $_POST);
                exit;
            }
            echo json_encode(['success' => true, 'message' => "Database '{$name}' deleted"]);
        } catch (Exception $e) {
            if ($isHx) {
                hx_toast('Failed to drop database: ' . $e->getMessage(), true);
                echo partial_databases($_GET + $_POST);
                exit;
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'system_info':
        if ($isHx) {
            echo partial_system_info();
            exit;
        }
        echo json_encode(['success' => true]);
        break;

    case 'update':
        if ($isHx) {
            // The check itself is the answer: same one-line text in the panel and as a toast, so
            // the button gives feedback without the page having to be read.
            $status = update_status_text();
            hx_toast($status['toast'], $status['error']);
            echo partial_update_response(false);
            exit;
        }
        echo json_encode(locadev_check_json($baseDir));
        break;

    case 'install_adminneo':
        $result = ensure_adminneo();
        if ($isHx) {
            hx_toast(
                $result['success']
                    ? (($result['already'] ?? false) ? 'AdminNeo is already installed' : 'AdminNeo installed — open it from the button')
                    : 'AdminNeo: ' . $result['error'],
                !$result['success']
            );
            echo partial_databases($_GET);
            exit;
        }
        echo json_encode($result);
        break;

    case 'create_php_ini':
        // Linux/macOS installs have no php.ini (static FrankenPHP). PHPRC points PHP at bin/, so
        // writing the recommended defaults here makes the Configuration page usable there.
        $ini = $baseDir . '/bin/php.ini';
        if (is_file($ini)) {
            hx_toast('php.ini already exists', true);
        } elseif (@file_put_contents($ini, php_ini_defaults()) === false) {
            hx_toast('Could not write bin/php.ini', true);
        } else {
            hx_toast('php.ini created — Restart Server to apply');
        }
        if ($isHx) {
            echo partial_configuration();
            exit;
        }
        echo json_encode(['success' => is_file($ini), 'path' => $ini]);
        break;

    case 'update_run':
        $log = $baseDir . '/data/update.log';
        @mkdir(dirname($log), 0777, true);

        // Two signals, because a run started from the terminal writes no log: the lock file
        // (written by update.php itself) and an unfinished log (a dashboard run in flight).
        if (locadev_update_running($baseDir) || !update_log_state()[1]) {
            if ($isHx) {
                hx_toast('An update is already running', true);
                echo partial_update_response(false);
                exit;
            }
            echo json_encode(['success' => false, 'error' => 'An update is already running']);
            break;
        }
        // Seed the log now: the panel polls it, and an empty file would look finished.
        @file_put_contents($log, "[update] Starting...\n");

        // Detached on purpose: this request is served by the very process whose files are
        // about to be replaced, so it has to answer before the copy starts.
        //
        // proc_open with an argument array and file descriptors - the same shape the tunnel
        // feature uses, and for the same reason: the child inherits no stdout pipe, so this
        // request returns immediately. `start /B` via popen hangs the caller on Windows until
        // the child exits (see start_site_tunnel), which would leave the button spinning.
        $script = $baseDir . '/scripts/update.php';
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? [$baseDir . '/bin/php.exe', $script, '--yes']
            : [$baseDir . '/bin/frankenphp', 'php-cli', $script, '--yes'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $ini = $baseDir . '/bin/php.ini';
        // Replaces the `set PHPRC=` the .cmd used to do; PATH must survive for `tar`.
        $env = is_file($ini) ? array_merge(getenv(), ['PHPRC' => $baseDir . '/bin']) : null;

        $proc = proc_open($cmd, [
            0 => ['file', $null, 'r'],
            1 => ['file', $log, 'w'],
            2 => ['redirect', 1],
        ], $pipes, $baseDir, $env, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            @file_put_contents($log, "[update] Could not start the updater process.\n");
        }

        if ($isHx) {
            hx_toast('Update started - progress below');
            echo partial_update_response(false);
            exit;
        }
        echo json_encode(['success' => true, 'started' => true, 'log' => $log]);
        break;

    case 'update_log':
        // Polled by the invisible poller: everything is an out-of-band swap.
        echo partial_update_response(true);
        break;

    case 'configuration':
        if ($isHx) {
            echo partial_configuration();
            exit;
        }
        echo json_encode(['success' => true, 'values' => config_ini_values($baseDir . '/bin/php.ini')]);
        break;

    case 'save_configuration':
        $iniPath = $baseDir . '/bin/php.ini';
        if (!file_exists($iniPath)) {
            http_response_code(422);
            hx_toast('php.ini not found', true);
            exit;
        }

        $current = config_ini_values($iniPath);
        $new = [];
        foreach (config_directives() as $name => $d) {
            if ($d['type'] === 'switch') {
                $new[$name] = isset($input[$name]) ? 'On' : 'Off';
                continue;
            }
            $val = trim((string) ($input[$name] ?? ''));
            // Whitelist: hanya nilai dari dropdown yang boleh masuk ke php.ini
            if (!in_array($val, $d['options'], true)) {
                http_response_code(422);
                hx_toast("Invalid value '{$val}' for {$name}", true);
                exit;
            }
            $new[$name] = $val;
        }

        // Validasi silang: upload_max_filesize harus <= post_max_size
        $toBytes = function (string $v): int {
            return (int) $v * 1024 ** (['K' => 1, 'M' => 2, 'G' => 3][strtoupper(substr($v, -1))] ?? 0);
        };
        if ($toBytes($new['upload_max_filesize']) > $toBytes($new['post_max_size'])) {
            http_response_code(422);
            hx_toast("Upload Max Filesize ({$new['upload_max_filesize']}) cannot exceed Post Max Size ({$new['post_max_size']})", true);
            exit;
        }

        // Replace kemunculan TERAKHIR tiap direktif (blok Locadev tuning menimpa default di atasnya)
        copy($iniPath, $iniPath . '.bak');
        $raw = file_get_contents($iniPath);
        $crlf = str_contains($raw, "\r\n");
        $eol = $crlf ? "\r\n" : "\n";
        $lines = file($iniPath, FILE_IGNORE_NEW_LINES) ?: [];
        $changed = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            foreach (config_directives() as $name => $d) {
                if ($new[$name] === ($current[$name] ?? null) || isset($done[$name])) continue;
                if (preg_match('/^' . $name . '\\s*=/', rtrim($lines[$i], "\r"))) {
                    $lines[$i] = $name . ' = ' . $new[$name];
                    $done[$name] = true;
                    $changed++;
                    break;
                }
            }
        }
        $tmp = $iniPath . '.tmp';
        file_put_contents($tmp, implode($eol, $lines) . (str_ends_with($raw, "\n") ? $eol : ''));
        rename($tmp, $iniPath);

        if ($isHx) {
            hx_toast($changed > 0
                ? "Configuration saved ({$changed} change" . ($changed > 1 ? 's' : '') . ') — restart server to apply'
                : 'No changes to save');
            http_response_code(204);
            exit;
        }
        echo json_encode(['success' => true, 'changed' => $changed, 'values' => $new]);
        break;

    case 'extensions':
        if ($isHx) {
            echo partial_extensions($_GET);
            exit;
        }
        echo json_encode(['success' => true] + get_extensions_data());
        break;

    case 'toggle_extension':
        $name = strtolower(preg_replace('#[^a-z0-9_]#i', '', $input['name'] ?? ''));
        $iniPath = $baseDir . '/bin/php.ini';

        if ($name === '' || !file_exists($iniPath)) {
            // 422: htmx noSwap → tidak di-swap ke #view; pesan lewat toast error
            http_response_code(422);
            hx_toast('php.ini not found', true);
            exit;
        }

        // Checkbox semantics: flip from the current ini state.
        $lines = file($iniPath, FILE_IGNORE_NEW_LINES) ?: [];
        $matched = false;
        $currentlyEnabled = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^;?extension\s*=\s*' . $name . '\s*$/i', trim($line))) {
                $currentlyEnabled = trim($line)[0] !== ';';
                $enable = !$currentlyEnabled;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            http_response_code(422);
            hx_toast("Extension '{$name}' not found in php.ini", true);
            exit;
        }

        $dllFile = $baseDir . '/bin/ext/php_' . $name . '.dll';
        if ($enable && !file_exists($dllFile)) {
            http_response_code(422);
            hx_toast("Module file php_{$name}.dll not found in bin/ext", true);
            exit;
        }

        // Backup, flip the exact extension= line, atomic write (preserve line endings)
        copy($iniPath, $iniPath . '.bak');
        $raw = file_get_contents($iniPath);
        $crlf = str_contains($raw, "\r\n");
        $trailing = str_ends_with($raw, "\n");
        foreach ($lines as $i => $line) {
            if (preg_match('/^;?extension\s*=\s*' . $name . '\s*$/i', trim($line))) {
                $lines[$i] = ($enable ? '' : ';') . 'extension=' . $name;
                break;
            }
        }
        $tmp = $iniPath . '.tmp';
        file_put_contents($tmp, implode($crlf ? "\r\n" : "\n", $lines) . ($trailing ? ($crlf ? "\r\n" : "\n") : ''));
        rename($tmp, $iniPath);

        if ($isHx) {
            // Tidak ada konten tabel yang berubah saat toggle — state checkbox sudah
            // berubah native di browser. 204 = tanpa swap, animasi switch tidak terputus.
            hx_toast('Extension ' . $name . ' ' . ($enable ? 'enabled' : 'disabled') . ' — restart server to apply');
            http_response_code(204);
            exit;
        }
        echo json_encode(['success' => true, 'name' => $name, 'enabled' => $enable, 'requires_restart' => true]);
        break;

    case 'restart_server':
        $frankenphpBin = frankenphp_bin();
        if (!file_exists($frankenphpBin)) {
            echo json_encode(['success' => false, 'error' => 'frankenphp binary not found']);
            exit;
        }
        // Detached relaunch: wait for this response to flush, kill FrankenPHP, start it
        // again with the same flags as the CLI. MariaDB is not touched.
        if (PHP_OS_FAMILY === 'Windows') {
            $tmpCmd = tempnam(sys_get_temp_dir(), 'locadev-restart');
            rename($tmpCmd, $tmpCmd . '.cmd');
            $tmpCmd .= '.cmd';
            file_put_contents($tmpCmd,
                "@echo off\r\n"
                . "timeout /t 2 /nobreak >nul\r\n"
                . "taskkill /F /IM frankenphp.exe >nul 2>&1\r\n"
                . "timeout /t 1 /nobreak >nul\r\n"
                . "set PHPRC={$baseDir}\\bin\r\n"
                . "cd /d \"{$baseDir}\"\r\n"
                . "start \"\" /b \"{$frankenphpBin}\" run --config Caddyfile\r\n");
            pclose(popen('start /B cmd /C "' . $tmpCmd . '"', 'r'));
        } else {
            // Graceful stop through Caddy's admin API first, then a PRECISE pkill. Matching the
            // absolute binary path missed servers started as `./bin/frankenphp` (start.sh), so the
            // old process kept serving while the new one died on a port already in use - the
            // button said "restarting" and nothing restarted. "bin/frankenphp run" matches both
            // start styles and still spares `frankenphp php-cli <script>` children (an update).
            exec('sh -c ' . escapeshellarg(
                // 1. stop gracefully, 2. kill precisely, 3. WAIT for the process to be gone, 4. only
                // then relaunch, 5. wait until it actually answers. Starting before the old process
                // released :443 made the new one die on a busy port - the button reported success
                // and the dashboard went dark.
                'curl -s -X POST http://127.0.0.1:2019/stop >/dev/null 2>&1; '
                . 'for i in 1 2 3 4 5 6 7 8 9 10; do curl -sk -o /dev/null https://localhost/ || break; sleep 1; done; '
                // Fallback for a server that ignored the admin stop. The pattern is assembled at run
                // time on purpose: written literally it also matches THIS script's command line, and
                // pkill -f then kills the wrapper before it can relaunch anything (exit 143, silent).
                . 'P=bin/frank; pkill -f "${P}enphp run" 2>/dev/null; sleep 1; '
                . 'cd ' . escapeshellarg($baseDir)
                . ' && PHPRC=' . escapeshellarg($baseDir . '/bin')
                // Log to data/, not /dev/null: when a relaunch fails to bind, the reason has to
                // survive somewhere the user can read (data/frankenphp.log, gitignored with data/).
                . ' nohup ' . escapeshellarg($frankenphpBin)
                . ' run --config Caddyfile >> ' . escapeshellarg($baseDir . '/data/frankenphp.log') . ' 2>&1 & '
                . 'for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do '
                . 'curl -sk -o /dev/null https://localhost/ && break; sleep 1; done'
            ) . ' > /dev/null 2>&1 &');
        }

        if ($isHx) {
            hx_toast('Restarting FrankenPHP — page will reload in ~5s');
            exit;
        }
        echo json_encode(['success' => true, 'restarting' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
