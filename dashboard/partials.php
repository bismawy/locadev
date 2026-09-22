<?php
/**
 * Locadev Dashboard — HTML partial renderers for HTMX.
 * Only loaded by api.php in partial mode (HX-Request / partial=1).
 * All functions rely on helpers & state defined in api.php.
 */

/** HTML-escape helper. */
function e($str): string {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

/** Material Symbols icon — SVG sprite (Arnative production pattern, weight 400). */
function ico(string $name, int $size = 18): string {
    return '<svg class="msr" width="' . $size . '" height="' . $size . '" fill="currentColor" aria-hidden="true">'
        . '<use href="assets/icons.svg?v=5#' . e($name) . '"></use></svg>';
}

/**
 * htmx handlers that spin a button's icon while its request is in flight (CSS .btn .msr.spinning).
 * Inline, like the existing hx-on::before/after:request handlers - no extra JS file.
 */
function spin_attrs(): string {
    return ' hx-on::before:request="this.querySelector(\'.msr\').classList.add(\'spinning\')"'
        . ' hx-on::after:request="this.querySelector(\'.msr\').classList.remove(\'spinning\')"';
}

/** Build an api.php URL with query params (htmx-safe: values urlencoded). */
function hx_url(string $action, array $params = []): string {
    $params['action'] = $action;
    return 'api.php?' . http_build_query($params);
}

/** True when the current request is an HTMX partial request. */
function is_hx(): bool {
    return (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') || isset($_GET['partial']);
}

/** Send a toast to the client via HX-Trigger (call before any output). */
function hx_toast(string $message, bool $isError = false): void {
    header('HX-Trigger: ' . json_encode(['toast' => $message, 'toastError' => $isError ? $message : '']));
}

/* ================= Metrics ================= */

/** The 4 metric cards (inner content of #metrics). Arnative StatCard pattern. */
function render_metric_cards(array $st): string {
    $card = function (string $title, string $value, string $sub): string {
        return '<div class="metric-card">'
            . '<div class="metric-info">'
            . '<div class="metric-title">' . e($title) . '</div>'
            . '<div class="metric-value">' . e($value) . '</div>'
            . '<div class="metric-sub">' . e($sub) . '</div>'
            . '</div>'
            . '</div>';
    };
    return ''
        . $card('Active Sites', (string) (int) $st['active_sites'], 'Total ' . (int) $st['total_sites'] . ' sites configured')
        . $card('MariaDB Databases', (string) (int) $st['db_count'], 'Port 3306')
        . $card('PHP Runtime', 'PHP ' . ($st['php_version'] ?: 'PHP'), ($st['os'] ?: 'Native') . ' • FrankenPHP Engine')
        . $card('Admin API', 'Port 2019', 'Zero-downtime hot reload');
}

/** OOB fragment: refresh metric cards + topbar status dots. For the status poller (hx-swap="none"). */
function partial_status(): string {
    $st = get_status_data();
    $caddyDot = '<span id="caddy-dot" class="dot' . ($st['caddy_online'] ? ' online' : '') . '" hx-swap-oob="true"></span>';
    $dbDot = '<span id="db-dot" class="dot' . ($st['db_online'] ? ' online' : '') . '" hx-swap-oob="true"></span>';
    $versions = '<span id="locadev-version" hx-swap-oob="innerHTML">' . e(LOCODEV_VERSION) . '</span>';
    return '<div id="metrics" class="metrics-grid" hx-swap-oob="innerHTML">' . render_metric_cards($st) . '</div>' . $caddyDot . $dbDot . $versions;
}

/* ================= Pagination ================= */

function render_pagination(int $page, int $totalPages, string $action, array $extra, int $total, string $label): string {
    $btn = function (string $inner, ?int $to, bool $disabled) use ($action, $extra) {
        $attrs = $disabled ? ' disabled' : ' hx-get="' . e(hx_url($action, $extra + ['page' => $to])) . '" hx-target="#view"';
        return '<button class="pagination-page-btn" type="button"' . $attrs . '>' . $inner . '</button>';
    };
    $html = $btn('‹', $page - 1, $page === 1);
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || ($i >= $page - 1 && $i <= $page + 1)) {
            $active = $i === $page ? ' active' : '';
            $html .= '<button class="pagination-page-btn' . $active . '" type="button" hx-get="' . e(hx_url($action, $extra + ['page' => $i])) . '" hx-target="#view">' . $i . '</button>';
        } elseif ($i === $page - 2 || $i === $page + 2) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
    }
    $html .= $btn('›', $page + 1, $page === $totalPages);

    $start = ($page - 1) * 10 + 1;
    $end = min($page * 10, $total);
    return '<div class="table-pagination" style="display:flex">'
        . '<div>Showing ' . $start . '-' . $end . ' of ' . $total . ' ' . $label . '</div>'
        . '<div class="pagination-actions">' . $html . '</div>'
        . '</div>';
}

/* ================= Locadev update ================= */

/**
 * Tail of data/update.log and whether the run is over. A run with no new output for 30s
 * counts as over too - the poller must stop on its own when the updater dies mid-download.
 *
 * @return array{0:string,1:bool}
 */
function update_log_state(): array {
    global $baseDir;
    $log = $baseDir . '/data/update.log';
    if (!is_file($log)) {
        return ['', true];
    }
    $text = (string) file_get_contents($log);
    $done = (bool) preg_match('/\[update\] (Done|Cancelled|Already on|Cannot|Download failed|Extraction failed|WARNING|Unexpected|No bundled PHP)/', $text)
        || time() - filemtime($log) > 150; // > the updater's 120s download timeout: a slow link is not "finished"
    return [$text, $done];
}

/** The log box alone. */
function partial_update_log(): string {
    [$text, $done] = update_log_state();
    return '<pre id="update-log"'
        . ' style="max-height:16rem;overflow:auto;margin:0.75rem 1rem;padding:0.75rem;font-size:0.75rem;'
        . 'line-height:1.5;white-space:pre-wrap;border-radius:0.5rem;background:var(--surface-2, rgba(127,127,127,0.08))">'
        . e($text) . '</pre>';
}

/**
 * The invisible poller, out-of-band swapped into the System Info view. Same idea as the status
 * poller in index.php, with hx-swap="none" on purpose: if an update replaces api.php with a
 * version that has no update_log action yet, the reply is junk - and junk swapped into the DOM
 * would be worse than the reply being ignored. The poller survives, re-asks, and self-heals.
 * Finish = the reply carries a poller without hx-trigger, so polling stops by itself.
 */
function partial_update_poller(bool $active): string {
    return '<div id="update-poller" hidden hx-swap-oob="true" hx-get="api.php?action=update_log" hx-swap="none"'
        . ($active ? ' hx-trigger="every 1s"' : '') . '></div>';
}

/** Panel + poller. $oobPanel = false when the reply is already targeted at #update-panel. */
function partial_update_response(bool $oobPanel): string {
    [$text, $done] = update_log_state();
    $panel = partial_update();
    return ($oobPanel ? '<div id="update-panel" hx-swap-oob="innerHTML">' . $panel . '</div>' : $panel)
        . partial_update_poller(!$done);
}

/**
 * The one comparison behind both the panel line and the toast, so they cannot disagree.
 *
 * @return array{note:string,toast:string,error:bool,available:bool}
 */
function update_status_text(): array {
    global $baseDir;

    $installed = locadev_version($baseDir);
    $latest = locadev_latest_tag();

    if ($latest === '') {
        return ['note' => 'Could not reach GitHub — nothing was changed.', 'toast' => 'GitHub unreachable', 'error' => true, 'available' => false];
    }
    if ($installed === '') {
        return ['note' => 'Installed version unreadable — cannot tell if an update is needed.', 'toast' => 'Version unreadable', 'error' => true, 'available' => false];
    }
    if (version_compare($latest, $installed, '>')) {
        return [
            'note' => 'Locadev ' . $latest . ' is available — you have ' . $installed . '.',
            'toast' => $latest . ' available — you have ' . $installed,
            'error' => false,
            'available' => true,
        ];
    }
    if ($latest === $installed) {
        return ['note' => 'You are on the newest release.', 'toast' => 'Up to date — ' . $installed, 'error' => false, 'available' => false];
    }
    return [
        'note' => 'Ahead of the published release: you have ' . $installed . ', newest is ' . $latest . '.',
        'toast' => 'Ahead — ' . $installed . ' vs ' . $latest,
        'error' => false,
        'available' => false,
    ];
}

/** Version check + Update button + log of the last run. */
function partial_update(): string {
    $status = update_status_text();
    $note = $status['note'];
    $button = '';

    if ($status['available']) {
        $button = '<button type="button" class="btn btn-primary btn-sm"'
            . ' hx-post="api.php?action=update_run" hx-target="#update-panel" hx-swap="innerHTML" hx-disable="this"'
            . spin_attrs() . ' title="Update scripts, dashboard and CLI in place">'
            . ico('refresh', 14) . '<span>Update now</span></button>';
    }

    [$text, $done] = update_log_state();
    if ($text !== '' && $done && str_contains($text, '[update] Done')) {
        $button .= '<button type="button" class="btn btn-secondary btn-sm"'
            . ' hx-post="api.php?action=restart_server" hx-swap="none" hx-disable="this"' . spin_attrs()
            . ' title="Serve the updated files">' . ico('refresh', 14) . '<span>Restart server</span></button>';
    }

    $html = '<div class="unified-toolbar"><div class="toolbar-left">'
        . '<span class="cell-muted" style="font-size:0.8125rem">' . e($note) . '</span>'
        . '</div><div class="toolbar-right">' . $button . '</div></div>';

    if ($text !== '') {
        $html .= partial_update_log();
    }
    return $html;
}

/* ================= Sites view ================= */

function partial_sites(array $params): string {
    global $sitesJson, $sitesDir;

    $sites = load_sites_json($sitesJson, $sitesDir);

    $q = strtolower(trim((string) ($params['q'] ?? '')));
    $status = in_array($params['status'] ?? 'all', ['all', 'active', 'disabled'], true) ? ($params['status'] ?? 'all') : 'all';
    $page = max(1, (int) ($params['page'] ?? 1));

    // Filter counts always come from the full list (like the old client-side pills).
    $counts = ['all' => count($sites), 'active' => 0, 'disabled' => 0];
    foreach ($sites as $s) {
        $counts[!empty($s['enabled']) ? 'active' : 'disabled']++;
    }

    $filtered = array_values(array_filter($sites, function (array $s) use ($q, $status): bool {
        if ($status === 'active' && empty($s['enabled'])) return false;
        if ($status === 'disabled' && !empty($s['enabled'])) return false;
        if ($q !== '') {
            $hay = strtolower(implode(' ', [$s['name'] ?? '', $s['domain'] ?? '', $s['root'] ?? '', $s['database'] ?? '']));
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    }));

    $total = count($filtered);
    $totalPages = max(1, (int) ceil($total / 10));
    $page = min($page, $totalPages);
    $rows = array_slice($filtered, ($page - 1) * 10, 10);

    $st = get_status_data();
    $html = '<div class="metrics-grid" id="metrics">' . render_metric_cards($st) . '</div>';

    // Toolbar: filter pills (radios) + search
    $pill = function (string $value, string $label, int $count) use ($status): string {
        $checked = $status === $value;
        $id = 'filter-' . $value;
        return '<input type="radio" id="' . $id . '" name="status" value="' . $value . '"' . ($checked ? ' checked' : '')
            . ' hx-get="' . e(hx_url('sites')) . '" hx-trigger="click" hx-target="#view" hx-include="closest form">'
            . '<label for="' . $id . '" class="pill-btn">' . $label . ' <span class="pill-count">' . $count . '</span></label>';
    };
    $html .= '<div class="unified-toolbar">'
        . '<form id="sites-filters" class="toolbar-left" onsubmit="return false">'
        . render_search($params['q'] ?? '', [], [
            'id' => 'search-input',
            'name' => 'q',
            'class' => 'site-search',
            'hx-preserve' => 'hx-preserve',
            'hx-get' => hx_url('sites'),
            'hx-trigger' => 'input changed delay:300ms, search',
            'hx-target' => '#view',
            'hx-include' => 'closest form',
            'aria-label' => 'Search websites',
            'autocomplete' => 'off',
            'placeholder' => 'Search websites…',
        ])
        . '<div class="filter-pills">'
        . $pill('all', 'All', $counts['all'])
        . $pill('active', 'Active', $counts['active'])
        . $pill('disabled', 'Disabled', $counts['disabled'])
        . '</div>'
        . '</form>'
        . '<div class="toolbar-right">'
        . '<button type="button" class="btn btn-primary" onclick="openAddSiteModal()">' . ico('add', 14) . '<span>New Site</span></button>'
        . '</div>'
        . '</div>';

    // Table
    $html .= '<div class="table-container"><table>'
        . '<colgroup><col style="width:22%"><col style="width:24%"><col style="width:22%"><col style="width:11%"><col style="width:7%"><col style="width:200px"></colgroup>'
        . '<thead><tr><th>Website</th><th>Domain</th><th>Directory</th><th>Database</th><th>Status</th><th style="text-align:right">Action</th></tr></thead><tbody>';

    if ($rows === []) {
        $html .= '<tr><td colspan="6" class="empty-cell"><p>' . ($q !== '' || $status !== 'all' ? 'No websites found matching filter.' : 'No websites yet.') . '</p>'
            . '<button type="button" class="btn btn-secondary btn-sm" onclick="openAddSiteModal()">' . ico('add', 13) . ' Create a new site</button></td></tr>';
    } else {
        $tunnels = read_tunnels();
        foreach ($rows as $site) {
            $html .= render_site_row($site, $tunnels);
        }
    }

    $html .= '</tbody></table>';
    if ($totalPages > 1) {
        $html .= render_pagination($page, $totalPages, 'sites', array_filter(['q' => $params['q'] ?? '', 'status' => $status !== 'all' ? $status : '']), $total, 'websites');
    }
    $html .= '</div>';
    return $html;
}

/** Kontrol Cloudflare Tunnel di kolom Action (satu situs = satu tunnel publik). */
function render_tunnel_action(string $id, string $name, ?array $tunnel): string {
    $post = ' hx-post="' . e(hx_url('toggle_tunnel')) . '" hx-vals=\'{"id":"' . e($id) . '"}\''
        . ' hx-trigger="click" hx-target="#view" hx-include="#sites-filters" hx-disabled-elt="this"';

    if ($tunnel === null) {
        // Menerbitkan tunnel makan waktu (unduh cloudflared + spawn, bisa >5 detik):
        // data-progress memicu toast "sedang berjalan" di app.js.
        return '<button type="button" class="icon-btn"' . $post . ' data-progress="Starting the public tunnel…"'
            . ' title="Publish online via Cloudflare Tunnel" aria-label="Publish ' . e($name) . ' online">'
            . ico('language', 16) . '</button>';
    }

    // Ikon saja (tanpa label terpotong): nama domain publik muncul saat hover.
    return '<a class="icon-btn tunnel-on" href="' . e($tunnel['url']) . '" target="_blank" rel="noopener"'
        . ' title="' . e($tunnel['url']) . '" aria-label="Open the public URL of ' . e($name) . ' in a new tab">'
        . ico('link_2', 16) . '</a>'
        . '<button type="button" class="icon-btn tunnel-on"' . $post
        . ' title="Stop the public tunnel" aria-label="Stop the public tunnel for ' . e($name) . '">'
        . ico('language', 16) . '</button>';
}

function render_site_row(array $site, array $tunnels = []): string {
    $id = $site['id'];
    $primaryDomain = trim(explode(',', $site['domain'])[0]);
    $cleanHost = preg_replace('#^https?://#i', '', $primaryDomain);
    $url = 'https://' . $cleanHost;
    $enabled = !empty($site['enabled']);
    $preset = $site['preset'] ?? 'blank';
    $presetLabel = ['classicpress' => 'ClassicPress', 'wordpress' => 'WordPress', 'laravel' => 'Laravel'][$preset] ?? 'PHP';

    $row = '<tr' . ($enabled ? '' : ' style="opacity:0.55"') . '>'
        . '<td title="' . e($site['name']) . '"><div class="cell-name">' . e($site['name']) . '<span class="badge">' . $presetLabel . '</span></div></td>'
        . '<td><a href="' . e($url) . '" target="_blank" class="site-domain-link table-col-text">' . ico('link', 13) . ' ' . e($site['domain']) . '</a></td>'
        . '<td title="' . e($site['root']) . '"><span class="site-meta-item table-col-text">' . ico('folder', 13) . '<span class="table-col-text">' . e($site['root']) . '</span></span></td>'
        . '<td class="' . (!empty($site['database']) ? 'db-cell' : 'db-empty') . '">'
        . (!empty($site['database'])
            ? '<span class="site-meta-item table-col-text">' . ico('database', 13) . '<span class="table-col-text">' . e($site['database']) . '</span></span>'
            : '<span class="cell-muted">None</span>')
        . '</td>'
        . '<td>' . render_switch($enabled, $enabled ? 'Enabled (click to disable)' : 'Disabled (click to enable)', [
            'hx-post' => hx_url('toggle_site'),
            'hx-vals' => '{"id":' . json_encode($id) . '}',
            'hx-trigger' => 'click',
            'hx-target' => '#view',
            'hx-include' => '#sites-filters',
        ]) . '</td>'
        . '<td style="text-align:right"><div class="row-actions">'
        . render_tunnel_action($id, $site['name'], $tunnels[$id] ?? null)
        . '<button type="button" class="icon-btn" onclick="openEditSiteModal(\'' . e($id) . '\')" title="Edit site" aria-label="Edit site">' . ico('edit', 16) . '</button>'
        . '<button type="button" class="icon-btn icon-btn-danger"'
        . ' hx-post="' . e(hx_url('delete_site')) . '" hx-vals=\'{"id":"' . e($id) . '","delete_folder":true,"delete_db":true}\''
        . ' hx-confirm="Delete ' . e($site['name']) . '? This removes the site config, its project folder' . (!empty($site['database']) ? ', and its database ' . e($site['database']) : '') . '."'
        . ' hx-trigger="click" hx-target="#view" hx-include="#sites-filters"'
        . ' title="Delete site" aria-label="Delete site">'
        . ico('delete', 16) . '</button>'
        . '</div></td></tr>';
    return $row;
}

/* ================= Databases view ================= */

function partial_databases(array $params): string {
    global $sitesJson, $sitesDir;

    $page = max(1, (int) ($params['page'] ?? 1));
    $type = ($params['type'] ?? '') === 'system' ? 'system' : 'user';
    $q = strtolower(trim($params['q'] ?? ''));

    $html = '<div class="unified-toolbar">'
        . '<form id="db-filters" class="toolbar-left" onsubmit="return false">'
        . render_search($q, [], [
            'name' => 'q',
            'hx-get' => hx_url('databases'),
            'hx-trigger' => 'input changed delay:300ms, search',
            'hx-target' => '#view',
            'hx-include' => 'closest form',
            'aria-label' => 'Search databases',
            'autocomplete' => 'off',
            'placeholder' => 'Search databases…',
        ])
        . '<div class="filter-pills">'
        . '<input type="radio" id="db-filter-user" name="type" value="user"' . ($type === 'user' ? ' checked' : '')
        . ' hx-get="' . e(hx_url('databases')) . '" hx-trigger="click" hx-target="#view" hx-include="closest form">'
        . '<label for="db-filter-user" class="pill-btn">User Databases</label>'
        . '<input type="radio" id="db-filter-system" name="type" value="system"' . ($type === 'system' ? ' checked' : '')
        . ' hx-get="' . e(hx_url('databases')) . '" hx-trigger="click" hx-target="#view" hx-include="closest form">'
        . '<label for="db-filter-system" class="pill-btn">System</label>'
        . '</div>'
        . '</form>'
        . '<div class="toolbar-right">'
        . '<a class="btn btn-secondary" href="https://adminneo.localhost" target="_blank" rel="noopener" title="Open AdminNeo — DB management (login: root / locadev)">'
        . ico('open_in_new', 14) . '<span>AdminNeo</span></a>'
        . '<button type="button" class="btn btn-secondary" onclick="openCreateDbModal()">' . ico('add', 14) . '<span>Create Database</span></button>'
        . '</div></div>';

    $pdo = get_pdo();
    if (!$pdo) {
        $reason = extension_loaded('pdo_mysql')
            ? 'MariaDB is not reachable on port 3306.'
            : "PDO MySQL driver is not loaded — enable the pdo_mysql extension, then restart the server.";
        $html .= '<div class="empty-state">' . ico('database', 32) . '<p style="margin-top:12px">' . e($reason) . '</p></div>';
        return $html;
    }

    $stmt = $pdo->query("SELECT table_schema AS db_name, COUNT(table_name) AS total_tables,
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.tables GROUP BY table_schema");
    $dbStats = [];
    while ($row = $stmt->fetch()) {
        $dbStats[$row['db_name']] = ['tables' => (int) $row['total_tables'], 'size_mb' => (float) ($row['size_mb'] ?? 0)];
    }
    $allDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    $systemDbs = SYSTEM_DBS;
    $allDbs = array_values(array_filter($allDbs, fn(string $n) => $type === 'system' ? in_array($n, $systemDbs, true) : !in_array($n, $systemDbs, true)));
    if ($q !== '') {
        $allDbs = array_values(array_filter($allDbs, fn(string $n) => str_contains(strtolower($n), $q)));
    }

    $total = count($allDbs);
    $totalPages = max(1, (int) ceil($total / 10));
    $page = min($page, $totalPages);
    $rows = array_slice($allDbs, ($page - 1) * 10, 10);

    $html .= '<div class="table-container"><table>'
        . '<colgroup><col style="width:30%"><col style="width:20%"><col style="width:20%"><col style="width:15%"><col style="width:15%"></colgroup>'
        . '<thead><tr><th>Database Name</th><th>Type</th><th>Tables</th><th>Size</th><th style="text-align:right">Action</th></tr></thead><tbody>';

    if ($rows === []) {
        $html .= '<tr><td colspan="5" class="empty-cell">No databases found.</td></tr>';
    } else {
        foreach ($rows as $name) {
            $isSystem = in_array($name, $systemDbs, true);
            $html .= '<tr>'
                . '<td class="db-name-cell" title="' . e($name) . '"><span class="table-col-text">' . e($name) . '</span></td>'
                . '<td><span class="badge">' . ($isSystem ? 'System' : 'User Database') . '</span></td>'
                . '<td class="cell-muted">' . ($dbStats[$name]['tables'] ?? 0) . ' tables</td>'
                . '<td class="cell-muted">' . ($dbStats[$name]['size_mb'] ?? 0) . ' MB</td>'
                . '<td style="text-align:right">' . ($isSystem ? '' :
                    '<button type="button" class="icon-btn icon-btn-danger"'
                    . ' hx-post="' . e(hx_url('drop_database')) . '" hx-vals=\'{"name":"' . e($name) . '"}\''
                    . ' hx-confirm="Drop database ' . e($name) . '? This action cannot be undone."'
                    . ' hx-trigger="click" hx-target="#view"'
                    . ' title="Drop database" aria-label="Drop database">'
                    . ico('delete', 16) . '</button>') . '</td></tr>';
        }
    }

    $html .= '</tbody></table>';
    if ($totalPages > 1) {
        // carry the tab through pagination, otherwise page 2 of "System" renders "User"
        $dbExtra = array_filter(['q' => $q, 'type' => $type === 'system' ? 'system' : '']);
        $html .= render_pagination($page, $totalPages, 'databases', $dbExtra, $total, 'databases');
    }
    $html .= '</div>';
    return $html;
}

/* ================= System Info ================= */

function partial_system_info(): string {
    global $baseDir, $sitesJson;

    $sites = load_sites_json($sitesJson, dirname($sitesJson));

    // Versi dibaca dari binernya, bukan dari PHP yang sedang melayani: situs bisa jalan
    // di biner yang berbeda dari yang melayani dashboard ini.
    $rt = runtime_versions();
    $webServer = $rt['frankenphp'] !== ''
        ? 'FrankenPHP ' . $rt['frankenphp'] . ($rt['caddy'] !== '' ? ' — Caddy v' . $rt['caddy'] : '')
        : ($_SERVER['SERVER_SOFTWARE'] ?? 'FrankenPHP');
    $tunnelLine = $rt['cloudflared'] !== ''
        ? 'cloudflared ' . $rt['cloudflared']
        : 'cloudflared not downloaded yet (bin/cloudflared)';

    $rows = [
        ['Locadev', LOCODEV_VERSION . ' (use "Check for updates" above)'],
        ['Operating System', PHP_OS_FAMILY . ' — ' . php_uname('s') . ' ' . php_uname('r')],
        ['Machine', php_uname('m')],
        ['PHP Version', PHP_VERSION . ' (' . php_sapi_name() . ')'],
        ['PHP Configuration', php_ini_loaded_file() ?: 'no php.ini (PHP build defaults apply — create one on the Configuration page)'],
        ['PHP Extensions', count(get_loaded_extensions()) . ' loaded'],
        ['Memory Limit', ini_get('memory_limit')],
        ['Web Server', $webServer],
        ['Cloudflare Tunnel', $tunnelLine],
        ['Document Root', $_SERVER['DOCUMENT_ROOT'] ?? '-'],
        ['Locadev Directory', $baseDir],
    ];

    $pdo = get_pdo();
    if ($pdo) {
        $mariadbVer = $pdo->query('SELECT VERSION()')->fetchColumn();
        $datadir = $pdo->query("SHOW VARIABLES LIKE 'datadir'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? '-';
        $userDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $systemDbs = SYSTEM_DBS;
        $userDbs = array_diff($userDbs, $systemDbs);
        $rows[] = ['MariaDB', 'v' . $mariadbVer . ' — connected (127.0.0.1:3306)'];
        $rows[] = ['MariaDB Data Directory', $datadir];
        $rows[] = ['Databases', count($userDbs) . ' user, ' . (count($userDbs) + count($systemDbs)) . ' total'];
    } else {
        $rows[] = ['MariaDB', 'not connected (127.0.0.1:3306)'];
        $rows[] = ['Databases', '-'];
    }

    $rows[] = ['Websites', count($sites) . ' registered'];

    $diskTotal = @disk_total_space($baseDir);
    $diskFree = @disk_free_space($baseDir);
    if ($diskTotal && $diskFree) {
        $rows[] = ['Disk', sprintf('%.1f GB free of %.1f GB', $diskFree / 1e9, $diskTotal / 1e9)];
    }

    // Ringkasan teks polos untuk laporan bug: versinya menempel di data-copy tombol,
    // jadi tidak perlu elemen tersembunyi kedua yang bisa menyimpang dari tabel.
    $summary = implode("\n", [
        'Locadev ' . LOCODEV_VERSION,
        PHP_OS_FAMILY . ' — ' . php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
        $webServer,
        'PHP ' . PHP_VERSION . ' (' . php_sapi_name() . ') — ' . count(get_loaded_extensions()) . ' extensions',
        $pdo ? 'MariaDB v' . $mariadbVer : 'MariaDB not connected',
        $tunnelLine,
    ]);

    $html = '<div class="unified-toolbar"><div class="toolbar-left">'
        . '<span class="cell-muted" style="font-size:0.8125rem">What Locadev runs, and which build of it — versions come from the binaries themselves.</span>'
        . '</div><div class="toolbar-right">'
        . '<button type="button" class="btn btn-secondary btn-sm"'
        . ' hx-get="api.php?action=update" hx-target="#update-panel" hx-swap="innerHTML"'
        . ' title="Compare with the newest GitHub release">'
        . ico('refresh', 14) . '<span>Check for updates</span></button>'
        . '<button type="button" class="btn btn-secondary btn-sm" data-copy="' . e($summary) . '"'
        . ' title="Copy a plain-text version summary for bug reports">'
        . ico('content_copy', 14) . '<span>Copy version summary</span></button>'
        . '</div></div>';

    $html .= '<div id="update-panel"></div>';

    $html .= '<div class="table-container"><table>'
        . '<colgroup><col style="width:30%"><col style="width:70%"></colgroup>'
        . '<thead><tr><th>Component</th><th>Value</th></tr></thead><tbody>';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr><td>' . e($label) . '</td><td class="cell-muted" style="word-break:break-all">' . e($value) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

/* ================= PHP Configuration ================= */

function config_directives(): array {
    return [
        'memory_limit' => [
            'label' => 'Memory Limit',
            'desc' => 'Max RAM per PHP request',
            'type' => 'select',
            'options' => ['128M', '256M', '512M', '1G', '2G', '4G'],
            'recommended' => '256M',
        ],
        'upload_max_filesize' => [
            'label' => 'Upload Max Filesize',
            'desc' => 'Max size of one uploaded file',
            'type' => 'select',
            'options' => ['2M', '8M', '16M', '32M', '64M', '128M', '256M', '512M', '1G'],
            'recommended' => '64M',
        ],
        'post_max_size' => [
            'label' => 'Post Max Size',
            'desc' => 'Max total POST body (must be >= upload size)',
            'type' => 'select',
            'options' => ['8M', '16M', '32M', '64M', '128M', '256M', '512M', '1G'],
            'recommended' => '64M',
        ],
        'max_execution_time' => [
            'label' => 'Max Execution Time',
            'desc' => 'Seconds a script may run (0 = unlimited)',
            'type' => 'select',
            'options' => ['30', '60', '120', '300', '600', '0'],
            'recommended' => '300',
        ],
        'max_input_vars' => [
            'label' => 'Max Input Vars',
            'desc' => 'Max form/input fields per request',
            'type' => 'select',
            'options' => ['1000', '3000', '5000', '10000'],
            'recommended' => '3000',
        ],
        'display_errors' => [
            'label' => 'Display Errors',
            'desc' => 'Show PHP errors in the browser',
            'type' => 'switch',
            'options' => ['On', 'Off'],
        ],
    ];
}

/**
 * Lines of the bundled php.ini, and [] when there is none.
 *
 * Linux/macOS installs carry no php.ini at all - FrankenPHP is a static build and settings live
 * in the Caddyfile - so "missing" is a normal state, not an error. Two readers used to call
 * file()/file_get_contents() blind, which put a PHP Warning in front of the JSON reply and broke
 * every programmatic client on those platforms. Guard here, once, for every reader.
 */
function php_ini_lines(string $path = ''): array {
    global $baseDir;
    $path = $path !== '' ? $path : $baseDir . '/bin/php.ini';
    return is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
}

/**
 * Text of a fresh bin/php.ini: every directive the Configuration page manages, at the value it
 * recommends (switches on, matching the php.ini the Windows bundle ships).
 */
function php_ini_defaults(): string {
    $out = "; bin/php.ini created by Locadev (this install had none: static FrankenPHP).\n"
        . "; start.sh exports PHPRC=bin, so the server and the bundled CLI read this file.\n"
        . "; Extensions are compiled into the binary and cannot be toggled from here.\n\n";
    foreach (config_directives() as $name => $d) {
        $value = $d['recommended'] ?? ($d['type'] === 'switch' ? 'On' : (string) reset($d['options']));
        $out .= $name . ' = ' . $value . "\n";
    }
    return $out;
}

function config_ini_values(string $iniPath): array {
    $raw = implode("\n", php_ini_lines($iniPath));
    $values = [];
    foreach (array_keys(config_directives()) as $name) {
        // Last uncommented occurrence wins (Locadev tuning block overrides defaults above).
        if (preg_match_all('/^' . $name . '\\s*=\\s*(.+)$/mi', $raw, $m)) {
            $values[$name] = trim(end($m[1]));
        }
    }
    return $values;
}

function partial_configuration(): string {
    global $baseDir;

    $iniPath = $baseDir . '/bin/php.ini';
    $directives = config_directives();
    $hasIni = is_file($iniPath);
    $values = $hasIni ? config_ini_values($iniPath) : [];

    $spin = spin_attrs();
    $restartBtn = '<button type="button" class="btn btn-secondary" hx-post="' . e(hx_url('restart_server')) . '" hx-swap="none"'
        . ' hx-on::before:request="this.disabled = true" hx-on::after:request="this.disabled = false"' . $spin
        . ' title="Restart FrankenPHP to apply configuration">' . ico('refresh', 14) . '<span>Restart Server</span></button>';

    // No php.ini (Linux/macOS: static FrankenPHP). The file is not required - PHPRC makes PHP read
    // bin/php.ini when it exists (start.sh exports it), so creating one here is what turns this
    // page from an explanation into a working editor.
    if (!$hasIni) {
        $createBtn = '<button type="button" class="btn btn-primary" hx-post="' . e(hx_url('create_php_ini')) . '"'
            . ' hx-target="#view" hx-swap="innerHTML" hx-disable="this"' . $spin
            . ' title="Write a php.ini with the recommended values">' . ico('add', 14) . '<span>Create php.ini</span></button>';
        return '<div class="unified-toolbar"><div class="toolbar-left">'
            . '<span class="cell-muted" style="font-size:0.8125rem">No bin/php.ini on this install (static FrankenPHP). Create one to edit the directives below — it is loaded through PHPRC and applied after a restart.</span>'
            . '</div><div class="toolbar-right">' . $createBtn . $restartBtn . '</div></div>';
    }

    $html = '<div class="unified-toolbar"><div class="toolbar-left">'
        . '<span class="cell-muted" style="font-size:0.8125rem">Changes are written to bin/php.ini and applied after a server restart.</span>'
        . '</div><div class="toolbar-right">'
        . '<button type="button" class="btn btn-primary" id="save-config-btn" disabled hx-post="' . e(hx_url('save_configuration')) . '" hx-include="#config-form" hx-swap="none"'
        . ' hx-on::after:request="resetConfigSnapshot()">'
        . ico('check', 14) . '<span>Save Configuration</span></button>'
        . $restartBtn
        . '</div></div>';

    $html .= '<div class="table-container"><form id="config-form" onsubmit="return false">'
        . '<table><colgroup><col style="width:26%"><col style="width:44%"><col style="width:30%"></colgroup>'
        . '<thead><tr><th>Directive</th><th>Value</th><th>Description</th></tr></thead><tbody>';

    foreach ($directives as $name => $d) {
        $current = $values[$name] ?? null;
        $html .= '<tr><td><span class="table-col-text">' . e($d['label']) . '</span><br>'
            . '<code class="cell-muted" style="font-size:0.75rem">' . e($name) . '</code></td><td>';
        if ($d['type'] === 'switch') {
            $on = strtoupper((string) $current) === 'ON';
            $html .= render_switch($on, $d['label'], [], ['name' => $name, 'value' => $on ? 'On' : 'Off']);
        } else {
            $opts = $d['options'];
            if ($current !== null && !in_array($current, $opts, true)) {
                array_unshift($opts, $current);
            }
            $labels = $name === 'max_execution_time' ? ['0' => '0 (unlimited)'] : [];
            // Tandai nilai yang direkomendasikan — label item menu + trigger label
            if (isset($d['recommended']) && in_array($d['recommended'], $opts, true)) {
                $labels[$d['recommended']] = ($labels[$d['recommended']] ?? $d['recommended']) . ' (Recommended)';
            }
            $html .= render_custom_select($name, (string) $current, $opts, $labels);
        }
        $html .= '</td><td class="cell-muted">' . e($d['desc']) . '</td></tr>';
    }

    $html .= '</tbody></table></form></div>';
    return $html;
}

/* ================= PHP Extensions ================= */

/**
 * Scan bin/php.ini + bin/ext/*.dll + get_loaded_extensions().
 * Returns toggleable extensions (declared in php.ini) and built-ins (compiled in, no ini line).
 */
/** English one-liner descriptions for extensions (toggleable + common built-ins). */
function ext_description(string $name): string {
    static $map = [
        // toggleable (php.ini)
        'bz2' => 'Read and write bzip2 compressed data',
        'curl' => 'HTTP requests and transfers to remote servers',
        'exif' => 'Read image metadata such as JPEG EXIF tags',
        'ffi' => 'Call native C functions and libraries from PHP',
        'ftp' => 'Client for File Transfer Protocol connections',
        'fileinfo' => 'Detect file types by content magic numbers',
        'gd' => 'Image creation and manipulation (resize, crop, draw)',
        'gettext' => 'Internationalization and translation (GNU gettext)',
        'gmp' => 'Arbitrary precision mathematics on large numbers',
        'intl' => 'Unicode localization: formatting, transliteration, collation',
        'ldap' => 'Authenticate and query LDAP directory servers',
        'mbstring' => 'Multibyte string handling for UTF-8 text',
        'mysqli' => 'MariaDB / MySQL database driver (this stack uses MariaDB)',
        'odbc' => 'ODBC database abstraction interface',
        'openssl' => 'TLS encryption, certificates, and cryptographic signing',
        'pdo_firebird' => 'PDO driver for Firebird databases',
        'pdo_mysql' => 'PDO driver for MySQL and MariaDB',
        'pdo_odbc' => 'PDO driver for ODBC data sources',
        'pdo_pgsql' => 'PDO driver for PostgreSQL databases',
        'pdo_sqlite' => 'PDO driver for SQLite embedded databases',
        'pgsql' => 'PostgreSQL database driver',
        'shmop' => 'Shared memory segment operations',
        'snmp' => 'Query and manage SNMP-enabled network devices',
        'soap' => 'SOAP web service client and server',
        'sockets' => 'Low-level TCP/UDP socket networking',
        'sodium' => 'Modern cryptography: encryption, hashing, signatures',
        'sqlite3' => 'SQLite 3 embedded database driver',
        'tidy' => 'Clean and repair HTML markup',
        'xsl' => 'XSLT transformations using libxslt',
        'zip' => 'Read and write ZIP archives',
        // common built-ins
        'bcmath' => 'Arbitrary precision mathematics',
        'calendar' => 'Date conversion between calendars',
        'ctype' => 'Character type validation checks',
        'date' => 'Date and time handling',
        'dom' => 'XML/HTML DOM manipulation',
        'filter' => 'Input validation and sanitization',
        'hash' => 'Hashing algorithms (SHA, MD5, and more)',
        'iconv' => 'Character set conversion',
        'json' => 'JSON encoding and decoding',
        'lexbor' => 'Fast HTML5 parser',
        'libxml' => 'libxml2 bindings for XML processing',
        'mysqlnd' => 'MySQL native driver used by mysqli and PDO MySQL',
        'pcre' => 'Perl-compatible regular expressions',
        'pdo' => 'PHP database abstraction layer',
        'phar' => 'PHP archive packaging and execution',
        'random' => 'Cryptographic random number generators',
        'readline' => 'Interactive line editing for the CLI',
        'reflection' => 'Runtime introspection of classes and functions',
        'session' => 'Session state management',
        'simplexml' => 'Simple XML parsing and traversal',
        'spl' => 'Standard data structures and iterators',
        'standard' => 'PHP standard library functions',
        'tokenizer' => 'PHP source code tokenization',
        'uri' => 'URL parsing (RFC 3986)',
        'xml' => 'Event-based XML parsing',
        'xmlreader' => 'Pull-based XML reader',
        'xmlwriter' => 'XML document writer',
        'zend opcache' => 'Bytecode caching for PHP',
        'zlib' => 'Compression (gzip, deflate)',
    ];
    return $map[strtolower($name)] ?? 'PHP core extension';
}

function get_extensions_data(): array {
    global $baseDir;
    $iniPath = $baseDir . '/bin/php.ini';
    // No php.ini (Linux/macOS): no toggleable extensions, only the compiled-in ones - which is
    // exactly what the Extensions page should show there.
    $lines = php_ini_lines();
    $loaded = array_map('strtolower', get_loaded_extensions());

    $dlls = [];
    foreach (glob($baseDir . '/bin/ext/php_*.dll') ?: [] as $f) {
        $dlls[strtolower(substr(basename($f), 4, -4))] = basename($f);
    }

    $exts = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^;?extension\s*=\s*([a-z0-9_]+)$/i', $t, $m)) {
            $name = strtolower($m[1]);
            $exts[$name] = [
                'name' => $name,
                'dll' => $dlls[$name] ?? null,
                'ini_enabled' => $t[0] !== ';',
                'loaded' => in_array($name, $loaded, true),
            ];
        }
    }
    $builtins = array_values(array_filter($loaded, fn(string $n) => !isset($exts[$n])));
    sort($builtins);

    return [
        'ini_path' => $iniPath,
        'exts' => $exts,
        'builtins' => $builtins,
        'loaded' => $loaded,
        'dlls' => $dlls,
    ];
}

function partial_extensions(array $params = []): string {
    $data = get_extensions_data();
    $loadedCount = count($data['loaded']);
    $dllCount = count($data['dlls']);

    // With nothing toggleable (no php.ini, no DLLs) the Available tab is empty by definition, so
    // open the tab that actually has content; an explicit ?filter= still wins.
    $default = ($data['exts'] === [] && $data['dlls'] === []) ? 'builtin' : 'available';
    $wanted = (string) ($params['filter'] ?? '');
    $filter = in_array($wanted, ['builtin', 'available'], true) ? $wanted : $default;
    $page = max(1, (int) ($params['page'] ?? 1));
    $q = strtolower(trim($params['q'] ?? ''));

    if ($filter === 'builtin') {
        $rows = array_map(fn(string $n) => [
            'name' => $n, 'dll' => null, 'ini_enabled' => true, 'loaded' => true, 'builtin' => true,
        ], $data['builtins']);
    } else {
        $rows = array_map(fn(array $e) => $e + ['builtin' => false], array_values($data['exts']));
    }

    if ($q !== '') {
        $rows = array_values(array_filter($rows, fn(array $e) =>
            str_contains(strtolower($e['name']), $q)
            || str_contains(strtolower(ext_description($e['name'])), $q)
        ));
    }

    $total = count($rows);
    $totalPages = max(1, (int) ceil($total / 10));
    $page = min($page, $totalPages);
    $pageRows = array_slice($rows, ($page - 1) * 10, 10);

    $isAvail = $filter === 'available';
    $isBuiltin = $filter === 'builtin';

    $html = '<div class="unified-toolbar">'
        . '<form id="ext-filters" class="toolbar-left" onsubmit="return false">'
        . render_search($q, [], [
            'name' => 'q',
            'hx-get' => hx_url('extensions'),
            'hx-trigger' => 'input changed delay:300ms, search',
            'hx-target' => '#view',
            'hx-include' => 'closest form',
            'aria-label' => 'Search extensions',
            'autocomplete' => 'off',
            'placeholder' => 'Search…',
        ])
        . '<div class="filter-pills">'
        . '<input type="radio" id="ext-filter-available" name="filter" value="available"' . ($isAvail ? ' checked' : '')
        . ' hx-get="' . e(hx_url('extensions')) . '" hx-trigger="click" hx-target="#view" hx-include="closest form">'
        . '<label for="ext-filter-available" class="pill-btn">Available (' . count($data['exts']) . ')</label>'
        . '<input type="radio" id="ext-filter-builtin" name="filter" value="builtin"' . ($isBuiltin ? ' checked' : '')
        . ' hx-get="' . e(hx_url('extensions')) . '" hx-trigger="click" hx-target="#view" hx-include="closest form">'
        . '<label for="ext-filter-builtin" class="pill-btn">Built In (' . count($data['builtins']) . ')</label>'
        . '</div>'
        . '<span class="ext-counts">'
        . '<strong>' . $loadedCount . ' loaded</strong>'
        . '<span class="ext-counts-sep">•</span>'
        . '<span>' . $dllCount . ' available (.dll)</span>'
        . '</span>'
        . '</form>'
        . '<div class="toolbar-right">'
        . '<button type="button" class="btn btn-secondary" id="restart-btn" title="Restart FrankenPHP — apply extension changes"'
        . ' hx-post="' . e(hx_url('restart_server')) . '" hx-swap="none"'
        . ' hx-on::before:request="this.disabled = true"'
        . ' hx-on::after:request="if (ctx?.response?.status < 400) { this.querySelector(\'span\').textContent = \'Restarting...\'; setTimeout(() => location.reload(), 5000); } else { this.disabled = false; }">'
        . ico('refresh', 14) . '<span>Restart Server</span></button>'
        . '</div></div>';

    $html .= '<div class="table-container"><table>'
        . '<colgroup><col style="width:14%"><col style="width:34%"><col style="width:21%"><col style="width:13%"><col style="width:18%"></colgroup>'
        . '<thead><tr><th>Extension</th><th>Description</th><th>Module File</th><th>Status</th><th>Enabled</th></tr></thead><tbody>';

    if ($pageRows === []) {
        // Empty because there is nothing to toggle (Linux/macOS: no php.ini, no bin/ext/*.dll) is
        // a different state from "your filter matched nothing" - say which one it is.
        $html .= '<tr><td colspan="5" class="empty-cell">'
            . ($data['exts'] === [] && $data['dlls'] === []
                ? 'Nothing to toggle: this install runs a static FrankenPHP with '
                    . count($data['builtins']) . ' extensions compiled in — see the Built-in tab. '
                    . 'Toggling needs a PHP that loads extensions from disk (the Windows install ships one).'
                : 'No extensions found.')
            . '</td></tr>';
    }

    foreach ($pageRows as $ext) {
        if ($ext['builtin']) {
            $html .= '<tr>'
                . '<td class="ext-name">' . e($ext['name']) . '</td>'
                . '<td class="cell-muted">' . e(ext_description($ext['name'])) . '</td>'
                . '<td class="cell-muted">—</td>'
                . '<td><span class="badge badge-info">Built-in</span></td>'
                . '<td>' . render_switch(true, 'Compiled into PHP — cannot be disabled', ['disabled' => 'disabled']) . '</td>'
                . '</tr>';
            continue;
        }
        $noDll = $ext['dll'] === null;
        $status = $ext['loaded']
            ? '<span class="badge badge-success">Loaded</span>'
            : '<span class="badge">Available</span>';
        $html .= '<tr>'
            . '<td class="ext-name">' . e($ext['name']) . '</td>'
            . '<td class="cell-muted">' . e(ext_description($ext['name'])) . '</td>'
            . '<td class="cell-muted">' . ($ext['dll'] ? e($ext['dll']) : '—') . '</td>'
            . '<td>' . $status . '</td>'
            . '<td>' . render_switch($ext['ini_enabled'], $ext['ini_enabled'] ? 'Enabled (click to disable)' : 'Disabled (click to enable)', [
                'hx-post' => hx_url('toggle_extension'),
                'hx-vals' => '{"name":' . json_encode($ext['name']) . '}',
                'hx-trigger' => 'change',
                'hx-target' => '#view',
                'hx-swap' => 'innerHTML',
            ], ['class' => 'ext-toggle', 'data-name' => $ext['name']] + ($noDll ? ['disabled' => 'disabled', 'title' => 'Module file not found in bin/ext'] : [])) . '</td>'
            . '</tr>';
    }

    $html .= '</tbody></table>';
    $pageExtra = ['filter' => $filter];
    if ($q !== '') {
        $pageExtra['q'] = $q;
    }
    $html .= render_pagination($page, $totalPages, 'extensions', $pageExtra, $total, 'extensions');
    $html .= '</div>';

    return $html;
}
