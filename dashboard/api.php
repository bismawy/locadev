<?php
/**
 * Locadev Dashboard API
 * FrankenPHP + MariaDB local management API
 *
 * Two output modes:
 *  - JSON (default): consumed by the CLI / programmatic clients
 *  - HTML partial (HTMX: HX-Request header or ?partial=1): fragments for the dashboard UI
 */

define('LOCODEV_VERSION', '1.0.0');

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
    // Cache is versioned (classicpress-2.7.2.zip); new upstream release = new filename,
    // so the next site creation automatically picks up the latest version.
    $zipFile = null;
    if ($preset === 'classicpress') {
        foreach (glob($cmsDir . DIRECTORY_SEPARATOR . 'classicpress-*.zip') ?: [] as $f) {
            $zipFile = $f;
            break; // ponytail: keeps the newest version already in cache; delete the zip to force upgrade
        }
        if (!$zipFile) {
            $tag = latest_classicpress_tag();
            if ($tag) {
                $zipFile = $cmsDir . DIRECTORY_SEPARATOR . "classicpress-{$tag}.zip";
                @file_put_contents($zipFile, fopen("https://codeload.github.com/ClassicPress/ClassicPress-release/zip/refs/tags/{$tag}", 'r'));
            }
        }
    } else {
        foreach (glob($cmsDir . DIRECTORY_SEPARATOR . 'wordpress-*.zip') ?: [] as $f) {
            $zipFile = $f;
            break;
        }
        if (!$zipFile) {
            // wordpress.org/latest.zip is always current: download it once, read the
            // version inside, and store it under a versioned name so future releases
            // trigger a fresh download instead of reusing a stale cache forever.
            $tmp = $cmsDir . DIRECTORY_SEPARATOR . 'wp-download-' . bin2hex(random_bytes(4)) . '.zip';
            @file_put_contents($tmp, fopen('https://wordpress.org/latest.zip', 'r'));
            $version = null;
            $z = @new ZipArchive();
            if (file_exists($tmp) && $z->open($tmp) === true) {
                $vp = (string) $z->getFromName('wordpress/wp-includes/version.php');
                if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $vp, $m)) {
                    $version = $m[1];
                }
                $z->close();
            }
            if (!file_exists($tmp)) {
                return false;
            }
            $zipFile = $cmsDir . DIRECTORY_SEPARATOR . 'wordpress-' . ($version ?: 'latest') . '.zip';
            @rename($tmp, $zipFile);
        }
    }
    if (!$zipFile || !file_exists($zipFile)) {
        return false;
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
        if ($loc && preg_match('#/tag/([^/\?#]+)#', $loc, $m)) {
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

    // Idempotent: never re-run create-project over an existing Laravel app
    if (file_exists($absRoot . DIRECTORY_SEPARATOR . 'artisan')) {
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
    if ($code !== 0 || !file_exists($absRoot . DIRECTORY_SEPARATOR . 'artisan')) {
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
    $domains = array_filter(array_map('trim', explode(',', $site['domain'])));
    $domainList = [];
    foreach ($domains as $d) {
        $domainList[] = $d;
    }
    $domainStr = implode(', ', $domainList);
    $root = rtrim($site['root'], '/\\');
    // Normalize path for Caddyfile
    $root = str_replace('\\', '/', $root);
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

function reload_caddy($caddyfile) {
    global $baseDir;
    $bin = PHP_OS_FAMILY === 'Windows' 
        ? $baseDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'frankenphp.exe'
        : $baseDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'frankenphp';
    
    if (file_exists($bin)) {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" ' . escapeshellarg($bin) . ' reload --config ' . escapeshellarg($caddyfile);
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = escapeshellarg($bin) . ' reload --config ' . escapeshellarg($caddyfile) . ' > /dev/null 2>&1 &';
            exec($cmd);
        }
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'frankenphp binary not found'];
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
            $stmt = $pdo->query("SHOW DATABASES");
            $dbCount = $stmt->rowCount();
            $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (Exception $e) {}
    }

    $caddyOnline = false;
    $ch = curl_init('http://127.0.0.1:2019/config/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $caddyOnline = true;
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
if ($isHx) {
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/partials.php';
    require_once __DIR__ . '/components.php';
} else {
    header('Content-Type: application/json; charset=utf-8');
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

        save_sites_json($sitesJson, $filtered);

        // Optional drop DB
        if ($deleteDb && !empty($targetSite['database'])) {
            $pdo = get_pdo();
            if ($pdo) {
                $cleanDb = preg_replace('#[^a-zA-Z0-9_]#', '', $targetSite['database']);
                if (!empty($cleanDb) && !in_array($cleanDb, ['mysql', 'information_schema', 'performance_schema', 'sys'])) {
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
        $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
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
        $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        if (in_array($name, $systemDbs)) {
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
        $frankenphpBin = $baseDir . '/bin/frankenphp.exe';
        if (!file_exists($frankenphpBin)) {
            echo json_encode(['success' => false, 'error' => 'frankenphp.exe not found']);
            exit;
        }
        // Detached batch: wait for this response to flush, kill FrankenPHP, relaunch
        // (same flags as start.bat). MariaDB is not touched.
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
