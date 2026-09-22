<?php
// Saw the same constant api.php serves instead of a second copy of the number: a release
// bumps one file, and this fallback cannot go stale between releases. The status poll keeps
// #locadev-version refreshed out-of-band from LOCODEV_VERSION anyway.
preg_match("/define\('LOCODEV_VERSION',\s*'([^']+)'/", (string) @file_get_contents(__DIR__ . '/api.php'), $m);
$sidebarVersion = $m[1] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Locadev</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script>
    // Theme initialization before render to avoid flash
    (function() {
      const savedTheme = localStorage.getItem('locadev-theme') || 'system';
      const isDark = savedTheme === 'dark' || (savedTheme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.classList.toggle('dark', isDark);
    })();
  </script>
  <link rel="stylesheet" href="assets/css/app.css?v=31">
  <script src="assets/htmx.min.js?v=2" defer></script>
  <script src="assets/app.js?v=16" defer></script>
</head>
<body>

<a href="#view" class="skip-link">Skip to content</a>

<div class="app-shell">

  <!-- ============ Sidebar (shadcn-style, collapsible) ============ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
      <a href="./" class="brand">
        <img src="assets/logo.svg" alt="Locadev" class="brand-logo">
        <span class="brand-title">Locadev</span>
      </a>
      <button type="button" class="icon-btn sidebar-collapse-btn" id="sidebar-collapse"
              aria-expanded="true" title="Tutup sidebar" aria-label="Tutup sidebar">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#dock_to_right"></use></svg>
      </button>
    </div>

    <nav class="sidebar-nav" id="main-nav">
      <div class="nav-group-label">Server</div>
      <a href="#" class="nav-item active" data-nav="sites"
         hx-get="api.php?action=sites" hx-target="#view" hx-swap="innerHTML">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#language"></use></svg>
        <svg class="msr msr-fill" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#language-fill"></use></svg>
        <span class="nav-label">Websites</span>
      </a>
      <a href="#" class="nav-item" data-nav="databases"
         hx-get="api.php?action=databases" hx-target="#view" hx-swap="innerHTML">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#database"></use></svg>
        <svg class="msr msr-fill" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#database-fill"></use></svg>
        <span class="nav-label">Databases</span>
      </a>
      <a href="#" class="nav-item" data-nav="system_info"
         hx-get="api.php?action=system_info" hx-target="#view" hx-swap="innerHTML">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#desktop_windows"></use></svg>
        <svg class="msr msr-fill" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#desktop_windows-fill"></use></svg>
        <span class="nav-label">System Information</span>
      </a>
      <div class="nav-group-label">PHP</div>
      <a href="#" class="nav-item" data-nav="extensions"
         hx-get="api.php?action=extensions" hx-target="#view" hx-swap="innerHTML">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#extension"></use></svg>
        <svg class="msr msr-fill" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#extension-fill"></use></svg>
        <span class="nav-label">Extensions</span>
      </a>
      <a href="#" class="nav-item" data-nav="configuration"
         hx-get="api.php?action=configuration" hx-target="#view" hx-swap="innerHTML">
        <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#tune"></use></svg>
        <svg class="msr msr-fill" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#tune-fill"></use></svg>
        <span class="nav-label">Configuration</span>
      </a>
    </nav>

    <div class="sidebar-foot">
      <div class="server-status-group">
        <div class="status-item">
          <span class="dot" id="caddy-dot"></span>
          <span class="status-label">FrankenPHP</span>
          <span class="status-port">443</span>
        </div>
        <div class="status-separator"></div>
        <div class="status-item">
          <span class="dot" id="db-dot"></span>
          <span class="status-label">MariaDB</span>
          <span class="status-port">3306</span>
        </div>
      </div>
      <!-- fallback only: the status poll refreshes this from LOCODEV_VERSION -->
      <div class="foot-versions">Locadev <span id="locadev-version"><?= htmlspecialchars($sidebarVersion, ENT_QUOTES) ?></span></div>
    </div>
  </aside>

  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

  <!-- ============ Main column ============ -->
  <div class="app-main">

    <!-- Topbar -->
    <header class="app-header">
      <div class="header-inner">
        <div class="header-actions" style="margin-right: auto;">
          <!-- Sidebar Expand Toggle (Dock to right Filled) when sidebar collapsed -->
          <button type="button" class="icon-btn sidebar-expand-btn" id="sidebar-expand-btn" aria-label="Buka sidebar" title="Buka sidebar">
            <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#dock_to_right-fill"></use></svg>
          </button>

          <button type="button" class="icon-btn menu-toggle" id="menu-toggle" aria-label="Open menu">
            <svg class="msr" width="20" height="20" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#menu"></use></svg>
          </button>
        </div>

        <div class="header-actions">
          <button class="btn btn-secondary btn-header btn-reload" type="button"
                  hx-post="api.php?action=reload_server" hx-swap="none" hx-disable="this"
                  title="Reload Caddy configuration without downtime">
            <svg class="msr" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#refresh"></use></svg>
            <span class="btn-text">Reload Server</span>
          </button>

          <!-- Theme Selector Dropdown -->
          <div class="theme-dropdown" id="theme-dropdown">
            <button class="btn btn-secondary theme-btn" id="theme-btn" type="button" onclick="toggleThemeDropdown(event)"
                    title="Change Theme (Light / Dark / System)">
              <svg class="msr" id="theme-icon-slot" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#desktop_windows"></use></svg>
            </button>
            <div class="theme-menu" id="theme-menu">
              <div class="theme-menu-item" data-theme="light" onclick="selectTheme('light')">
                <svg class="msr" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#light_mode"></use></svg>
                <span>Light</span>
                <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
              </div>
              <div class="theme-menu-item" data-theme="dark" onclick="selectTheme('dark')">
                <svg class="msr" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#dark_mode"></use></svg>
                <span>Dark</span>
                <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
              </div>
              <div class="theme-menu-item" data-theme="system" onclick="selectTheme('system')">
                <svg class="msr" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#desktop_windows"></use></svg>
                <span>System</span>
                <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- Main content: swapped by HTMX partials -->
    <main class="main-container" id="view"
          hx-get="api.php?action=sites" hx-trigger="load" hx-swap="innerHTML">
      <div class="empty-state">Loading…</div>
    </main>
  </div>
</div>

<!-- Status poller: refreshes metric cards + topbar dots out-of-band every 30s -->
<div id="status-poll" hidden
     hx-get="api.php?action=status" hx-trigger="load delay:500ms, every 30s" hx-swap="none"></div>

<!-- ============ Dialog: Add / Edit Site (+ deploy progress) ============ -->
<dialog class="modal" id="site-modal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div class="modal-title" id="site-modal-title">Create New Website</div>
      <div class="modal-subtitle">Configure host, root directory, and FrankenPHP runner</div>
    </div>

    <form id="site-form" onsubmit="submitSiteForm(event)">
      <input type="hidden" id="site-id">

      <div class="form-group" id="group-preset">
        <label class="form-label">Installation Preset</label>
        <input type="hidden" id="site-preset" value="blank">
        <div class="custom-select" id="custom-preset-select">
          <button type="button" class="select-trigger" onclick="togglePresetDropdown(event)" id="preset-trigger">
            <span id="preset-label">Custom / Blank PHP</span>
          <svg class="msr select-arrow" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#keyboard_arrow_down"></use></svg>
          </button>
          <div class="select-menu" id="preset-menu">
            <div class="select-item active" data-value="blank" onclick="selectPreset('blank', 'Custom / Blank PHP')">
              <span>Custom / Blank PHP</span>
              <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
            </div>
            <div class="select-item" data-value="classicpress" onclick="selectPreset('classicpress', 'ClassicPress (One-Click Install)')">
              <span>ClassicPress (One-Click Install)</span>
              <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
            </div>
            <div class="select-item" data-value="wordpress" onclick="selectPreset('wordpress', 'WordPress (One-Click Install)')">
              <span>WordPress (One-Click Install)</span>
              <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
            </div>
            <div class="select-item" data-value="laravel" onclick="selectPreset('laravel', 'Laravel (Composer Auto-Install)')">
              <span>Laravel (Composer Auto-Install)</span>
              <svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>
            </div>
          </div>
        </div>
        <div id="preset-hint" style="display: none;" class="form-hint">
          Automatically extracts the CMS files, creates a MariaDB database, and writes a ready-to-use wp-config.php.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Site Name</label>
        <input type="text" id="site-name" class="form-input" placeholder="e.g. My Project" required oninput="onSiteNameChange()">
      </div>

      <div class="form-group">
        <label class="form-label">Domain (Host)</label>
        <input type="text" id="site-domain" class="form-input" placeholder="e.g. myproject.localhost" required>
        <div class="form-hint">Any .localhost subdomain automatically points to 127.0.0.1 with automatic local SSL/HTTPS.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Document Root Path</label>
        <input type="text" id="site-root" class="form-input" placeholder="./sites/myproject" required>
      </div>

      <div class="form-group" style="margin-top: 12px;">
        <label class="form-checkbox-label">
          <input type="checkbox" id="site-php" class="form-checkbox" checked>
          <span>Enable PHP Server (`php_server` worker)</span>
        </label>
      </div>

      <div class="form-group" id="group-create-folder">
        <label class="form-checkbox-label">
          <input type="checkbox" id="site-create-folder" class="form-checkbox" checked>
          <span>Auto-create directory &amp; sample `index.php` if missing</span>
        </label>
      </div>

      <div class="form-group" id="group-create-db">
        <label class="form-checkbox-label">
          <input type="checkbox" id="site-create-db" class="form-checkbox" onchange="toggleDbInput()">
          <span>Create MariaDB database for this site</span>
        </label>
      </div>

      <div class="form-group" id="group-db-name" style="display: none;">
        <label class="form-label">Database Name</label>
        <input type="text" id="site-db-name" class="form-input" placeholder="e.g. myproject_db">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeSiteModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btn-submit-site">Save Site</button>
      </div>
    </form>

    <!-- Live Progress View -->
    <div id="site-progress-view" style="display: none;">
      <div style="margin-top: 4px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <span id="progress-stage-title" style="font-size: 13px; font-weight: 500;">Deploying Website...</span>
          <span id="progress-percent" style="font-size: 13px; font-family: monospace; color: var(--muted-foreground);">0%</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill" id="progress-fill" style="width: 0%;"></div>
        </div>
      </div>

      <div class="step-log-box" id="step-log-box"></div>

      <div class="modal-footer" id="progress-footer" style="display: none; margin-top: 20px;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeSiteModal()">Done</button>
        <a href="#" target="_blank" rel="noopener" class="btn btn-primary btn-sm" id="progress-open-btn">
          Open Website
          <svg class="msr" width="13" height="13" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#open_in_new"></use></svg>
        </a>
      </div>

      <div class="modal-footer" id="progress-error-footer" style="display: none; margin-top: 20px;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="backToForm()">Back to Form</button>
      </div>
    </div>
  </div>
</dialog>

<!-- ============ Dialog: Create Database (HTMX form) ============ -->
<dialog class="modal" id="db-modal" style="max-width: 400px;">
  <div class="modal-dialog">
    <div class="modal-header">
      <div class="modal-title">Create Database</div>
      <div class="modal-subtitle">Add a new MySQL/MariaDB database</div>
    </div>
    <form id="db-form"
          hx-post="api.php?action=create_database" hx-target="#view"
          hx-on::after:request="if((event.detail?.ctx?.response?.status ?? 500) < 400) document.getElementById('db-modal').close()">
      <div class="form-group">
        <label class="form-label">Database Name</label>
        <input type="text" id="new-db-name" name="name" class="form-input" placeholder="e.g. blog_db"
               required pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('db-modal').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create</button>
      </div>
    </form>
  </div>
</dialog>

<!-- Toast Container -->
<div class="toast-container" id="toast-container"></div>

</body>
</html>
