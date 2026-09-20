/* ============================================================
   Locadev Dashboard — app.js
   The UI is HTMX + server-rendered partials; this file only holds
   what HTMX deliberately does not do: theme, sidebar state,
   toasts, and the streaming deploy flow (SSE).
   ============================================================ */

'use strict';

/* ---------- Toast ---------- */

function showToast(msg, isError = false) {
  if (!msg) return;
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = 'toast' + (isError ? ' error' : '');
  toast.innerHTML = `<svg class="msr" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#${isError ? 'error' : 'check_circle'}"></use></svg><span>${escapeHtml(msg)}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    setTimeout(() => toast.remove(), 200);
  }, 3000);
}

// htmx 4 config: DOM morphing by default (preserves inputs/toolbars in-place without flicker/recreation).
// 4xx/5xx tidak di-swap (perilaku lama v2) — error tetap memicu toast/ignore.
htmx.config.defaultSwap = 'innerMorph';
htmx.config.transitions = false;
htmx.config.noSwap = [204, 304, '4xx', '5xx'];

// HTMX 4 safety guard: ensure radio.checked DOM boolean property stays synced on swaps
document.body.addEventListener('htmx:after:swap', (e) => {
  const target = e.detail?.target || e.target;
  if (target?.querySelectorAll) {
    for (const r of target.querySelectorAll('input[type="radio"][checked]')) {
      r.checked = true;
    }
  }
});

// Toasts triggered by the server via the HX-Trigger response header.
// htmx 4 dispatches these with detail = { value: <payload>, elt: <element> }.
document.body.addEventListener('toast', (e) => showToast(e.detail?.value ?? e.detail));
document.body.addEventListener('toastError', (e) => {
  const msg = e.detail?.value ?? e.detail;
  if (msg) showToast(msg, true);
});

/* Toast progres: sebagian aksi makan beberapa detik (unduh cloudflared + spawn
   tunnel), jadi user harus tahu prosesnya jalan — bukan diam lalu tiba-tiba selesai. */
function showProgressToast(msg) {
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = 'toast progress';
  toast.innerHTML = `<svg class="msr spinning" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#progress_activity"></use></svg><span>${escapeHtml(msg)}</span>`;
  container.appendChild(toast);
  return toast;
}

// Elemen ber-atribut data-progress menampilkan toast itu selama requestnya berjalan.
document.body.addEventListener('htmx:before:request', (e) => {
  const elt = e.detail?.elt || e.target;
  if (elt?.dataset?.progress) elt._progressToast = showProgressToast(elt.dataset.progress);
});
document.body.addEventListener('htmx:after:request', (e) => {
  const elt = e.detail?.elt || e.target;
  if (elt?._progressToast) {
    elt._progressToast.remove(); // hasil akhirnya tetap dari toast server (HX-Trigger)
    elt._progressToast = null;
  }
});

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ---------- Sidebar (Arnative Full Collapse & Dock to Right) ---------- */

const SIDEBAR_KEY = 'locadev-sidebar';

function applySidebarState(collapsed) {
  document.body.classList.toggle('sidebar-collapsed', collapsed);
  const btn = document.getElementById('sidebar-collapse');
  if (btn) btn.setAttribute('aria-expanded', String(!collapsed));
}

document.getElementById('sidebar-collapse')?.addEventListener('click', (e) => {
  e.stopPropagation();
  localStorage.setItem(SIDEBAR_KEY, 'collapsed');
  applySidebarState(true);
});

document.getElementById('sidebar-expand-btn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  localStorage.setItem(SIDEBAR_KEY, 'expanded');
  applySidebarState(false);
});

// Mobile off-canvas toggle
document.getElementById('menu-toggle')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
document.getElementById('sidebar-backdrop')?.addEventListener('click', () => document.body.classList.remove('sidebar-open'));

// Active nav item (Websites / Databases)
document.getElementById('main-nav')?.addEventListener('click', (e) => {
  const item = e.target.closest('.nav-item');
  if (!item) return;
  document.querySelectorAll('#main-nav .nav-item').forEach((el) => el.classList.remove('active'));
  item.classList.add('active');
  document.body.classList.remove('sidebar-open');
});

applySidebarState(localStorage.getItem(SIDEBAR_KEY) === 'collapsed');

/* ---------- Theme (light / dark / system, persisted) ---------- */

const themeIcons = { light: 'light_mode', dark: 'dark_mode', system: 'desktop_windows' };

function getCurrentTheme() {
  return localStorage.getItem('locadev-theme') || 'system';
}

function applyTheme(theme) {
  const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', isDark);

  const slot = document.getElementById('theme-icon-slot');
  if (slot) slot.querySelector('use').setAttribute('href', 'assets/icons.svg#' + (themeIcons[theme] || themeIcons.system));

  document.querySelectorAll('.theme-menu-item').forEach((el) => {
    el.classList.toggle('active', el.getAttribute('data-theme') === theme);
  });
}

function selectTheme(theme) {
  localStorage.setItem('locadev-theme', theme);
  applyTheme(theme);
  document.getElementById('theme-dropdown')?.classList.remove('open');
}

function toggleThemeDropdown(e) {
  e.stopPropagation();
  document.getElementById('theme-dropdown')?.classList.toggle('open');
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if (getCurrentTheme() === 'system') applyTheme('system');
});

window.addEventListener('click', (e) => {
  const dropdown = document.getElementById('theme-dropdown');
  if (dropdown && !dropdown.contains(e.target)) dropdown.classList.remove('open');
  const select = document.getElementById('custom-preset-select');
  if (select && !select.contains(e.target)) select.classList.remove('open');
});

/* Klik backdrop (di luar kotak dialog) menutup modal — native dialog tidak melakukannya otomatis */
document.querySelectorAll('dialog.modal').forEach((dlg) => {
  dlg.addEventListener('click', (e) => {
    if (e.target !== dlg) return;
    // site-modal: jangan tutup saat instalasi berjalan (progress view tanpa footer)
    const installing = dlg.id === 'site-modal'
      && document.getElementById('site-progress-view').style.display === 'block'
      && document.getElementById('progress-footer').style.display === 'none';
    if (!installing) dlg.close();
  });
});

applyTheme(getCurrentTheme());

/* ---------- Database modal (create) ---------- */

function openCreateDbModal() {
  document.getElementById('new-db-name').value = '';
  document.getElementById('db-modal').showModal();
}

// Reset the form whenever the dialog closes (success or cancel)
document.getElementById('db-modal')?.addEventListener('close', () => {
  document.getElementById('db-form')?.reset();
});

/* ---------- Site modal: create / edit + streaming deploy ---------- */

let isEditing = false;

async function fetchSitesJson() {
  try {
    const res = await fetch('api.php?action=sites');
    const data = await res.json();
    return data.sites || [];
  } catch (e) {
    return [];
  }
}

function getUniqueSlug(baseSlug, allSites) {
  let candidate = baseSlug;
  let counter = 2;
  const existingDomains = allSites.map((s) => (s.domain || '').toLowerCase());
  const existingRoots = allSites.map((s) => (s.root || '').toLowerCase());
  const existingIds = allSites.map((s) => (s.id || '').toLowerCase());

  while (
    existingIds.includes(candidate.toLowerCase()) ||
    existingDomains.some((d) => d.includes(`${candidate.toLowerCase()}.localhost`)) ||
    existingRoots.some((r) => r.endsWith(`/${candidate.toLowerCase()}`) || r.endsWith(`\\${candidate.toLowerCase()}`))
  ) {
    candidate = `${baseSlug}-${counter}`;
    counter++;
  }
  return candidate;
}

function onSiteNameChange() {
  if (isEditing) return;
  const name = document.getElementById('site-name').value.trim();
  let slug = name.toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  if (slug) {
    slug = getUniqueSlug(slug, window.__allSites || []);
    document.getElementById('site-domain').value = `${slug}.localhost`;
    document.getElementById('site-root').value = `./sites/${slug}`;
    document.getElementById('site-db-name').value = slug.replace(/-/g, '_');
  }
}

function togglePresetDropdown(e) {
  e.stopPropagation();
  document.getElementById('custom-preset-select').classList.toggle('open');
}

function selectPreset(val, label) {
  document.getElementById('site-preset').value = val;
  document.getElementById('preset-label').innerText = label;
  document.querySelectorAll('#preset-menu .select-item').forEach((el) => {
    el.classList.toggle('active', el.dataset.value === val);
  });
  document.getElementById('custom-preset-select').classList.remove('open');
  onPresetChange();
}

function onPresetChange() {
  const preset = document.getElementById('site-preset').value;
  const isCms = preset === 'classicpress' || preset === 'wordpress' || preset === 'laravel';
  const hintEl = document.getElementById('preset-hint');
  hintEl.style.display = isCms ? 'block' : 'none';
  if (preset === 'laravel') {
    hintEl.innerText = 'Runs composer create-project automatically (composer.phar is downloaded once), creates a MariaDB database, writes .env, and serves from the /public directory.';
  } else if (isCms) {
    hintEl.innerText = 'Automatically extracts the CMS files, creates a MariaDB database, and writes a ready-to-use wp-config.php.';
  }
  if (isCms) {
    document.getElementById('site-php').checked = true;
    document.getElementById('site-create-folder').checked = true;
    document.getElementById('site-create-db').checked = true;
    document.getElementById('group-db-name').style.display = 'block';
    const name = document.getElementById('site-name').value.trim();
    const slug = name.toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    if (slug && !document.getElementById('site-db-name').value) {
      document.getElementById('site-db-name').value = slug.replace(/-/g, '_');
    }
  }
}

function toggleDbInput() {
  const checked = document.getElementById('site-create-db').checked;
  document.getElementById('group-db-name').style.display = checked ? 'block' : 'none';
}

function backToForm() {
  document.getElementById('site-progress-view').style.display = 'none';
  document.getElementById('site-form').style.display = 'block';
  document.getElementById('site-modal-title').innerText = isEditing ? 'Edit Website' : 'Create New Website';
  const submitBtn = document.getElementById('btn-submit-site');
  submitBtn.disabled = false;
  submitBtn.innerText = isEditing ? 'Save Changes' : 'Save Site';
}

async function openAddSiteModal() {
  isEditing = false;
  // Slug suggestions must not collide with existing sites
  window.__allSites = await fetchSitesJson();
  backToForm();
  document.getElementById('site-modal-title').innerText = 'Create New Website';
  document.getElementById('btn-submit-site').innerText = 'Create Website';
  document.getElementById('site-form').reset();
  document.getElementById('site-id').value = '';
  document.getElementById('group-preset').style.display = 'block';
  selectPreset('blank', 'Custom / Blank PHP');
  document.getElementById('preset-hint').style.display = 'none';
  document.getElementById('group-create-folder').style.display = 'block';
  document.getElementById('group-create-db').style.display = 'block';
  document.getElementById('group-db-name').style.display = 'none';
  document.getElementById('site-modal').showModal();
}

async function openEditSiteModal(id) {
  const sites = await fetchSitesJson();
  const site = sites.find((s) => s.id === id);
  if (!site) return;
  isEditing = true;
  backToForm();
  document.getElementById('site-modal-title').innerText = 'Edit Website';
  document.getElementById('btn-submit-site').innerText = 'Save Changes';
  document.getElementById('site-id').value = site.id;
  document.getElementById('site-name').value = site.name;
  document.getElementById('site-domain').value = site.domain;
  document.getElementById('site-root').value = site.root;
  document.getElementById('site-php').checked = !!site.php;
  document.getElementById('group-preset').style.display = 'none';
  document.getElementById('group-create-folder').style.display = 'none';
  document.getElementById('group-create-db').style.display = 'none';
  document.getElementById('group-db-name').style.display = 'none';
  document.getElementById('site-modal').showModal();
}

function closeSiteModal() {
  /* Jangan reset di sini — reset saat close membuat tombol terlihat berubah
     ('Create Website' → 'Save Site') selama animasi tutup. Kedua path open
     (openAddSiteModal/openEditSiteModal) sudah memanggil backToForm() sendiri. */
  document.getElementById('site-modal').close();
}

async function submitSiteForm(e) {
  e.preventDefault();
  const id = document.getElementById('site-id').value;
  const name = document.getElementById('site-name').value.trim();
  const domain = document.getElementById('site-domain').value.trim();
  const root = document.getElementById('site-root').value.trim();
  const php = document.getElementById('site-php').checked;
  const preset = document.getElementById('site-preset').value;
  const createFolder = document.getElementById('site-create-folder').checked;
  const createDb = document.getElementById('site-create-db').checked;
  const dbName = document.getElementById('site-db-name').value.trim();

  // Switch to progress view
  document.getElementById('site-form').style.display = 'none';
  document.getElementById('site-progress-view').style.display = 'block';
  document.getElementById('progress-footer').style.display = 'none';
  document.getElementById('progress-error-footer').style.display = 'none';
  document.getElementById('site-modal-title').innerText = `Installing ${name}`;
  document.getElementById('progress-stage-title').innerText = 'Starting installation...';
  document.getElementById('progress-stage-title').style.color = '';
  document.getElementById('progress-percent').innerText = '0%';
  document.getElementById('progress-fill').style.width = '0%';
  document.getElementById('step-log-box').innerHTML = '';

  const stepsMap = {};

  try {
    const res = await fetch('api.php?action=save_site&stream=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id,
        name,
        domain,
        root,
        php,
        preset,
        create_folder: createFolder,
        create_db: createDb,
        database: createDb ? dbName : ''
      })
    });

    const reader = res.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const blocks = buffer.split('\n\n');
      buffer = blocks.pop();
      for (const block of blocks) {
        const line = block.trim();
        if (line.startsWith('data: ')) {
          try {
            const data = JSON.parse(line.substring(6));
            updateProgressStep(data, stepsMap);
          } catch (err) {
            console.error(err);
          }
        }
      }
    }

    // Refresh the sites view + metrics via HTMX
    htmx.ajax('GET', 'api.php?action=sites', '#view');
  } catch (err) {
    document.getElementById('progress-stage-title').innerText = 'Installation stopped: ' + err.message;
    document.getElementById('progress-error-footer').style.display = 'flex';
  }
}

function updateProgressStep(data, stepsMap) {
  const fill = document.getElementById('progress-fill');
  const percentText = document.getElementById('progress-percent');
  const stageTitle = document.getElementById('progress-stage-title');
  const stepLogBox = document.getElementById('step-log-box');

  if (data.error) {
    stageTitle.innerText = 'Error: ' + data.error;
    stageTitle.style.color = 'var(--danger)';
    document.getElementById('progress-error-footer').style.display = 'flex';
    return;
  }

  fill.style.width = `${data.percent}%`;
  percentText.innerText = `${data.percent}%`;
  stageTitle.innerText = data.title;

  // Mark previous active step as completed
  for (const k in stepsMap) {
    if (k !== data.step && stepsMap[k].classList.contains('active')) {
      stepsMap[k].classList.remove('active');
      stepsMap[k].classList.add('completed');
      stepsMap[k].querySelector('.step-icon').innerHTML = '<svg class="msr" width="16" height="16" fill="currentColor" aria-hidden="true" style="color:var(--success)"><use href="assets/icons.svg#check"></use></svg>';
    }
  }

  if (!stepsMap[data.step]) {
    const stepEl = document.createElement('div');
    stepEl.className = 'step-item active';
    stepEl.innerHTML = `
      <div class="step-icon"><svg class="msr spinning" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#progress_activity"></use></svg></div>
      <div class="step-content">
        <div class="step-title">${escapeHtml(data.title)}</div>
        ${data.detail ? `<div class="step-detail">${escapeHtml(data.detail)}</div>` : ''}
      </div>`;
    stepLogBox.appendChild(stepEl);
    stepLogBox.scrollTop = stepLogBox.scrollHeight;
    stepsMap[data.step] = stepEl;
  } else if (data.detail) {
    const detailEl = stepsMap[data.step].querySelector('.step-detail');
    if (detailEl) detailEl.innerText = data.detail;
  }

  if (data.done) {
    stepsMap[data.step].classList.remove('active');
    stepsMap[data.step].classList.add('completed');
    stepsMap[data.step].querySelector('.step-icon').innerHTML = '<svg class="msr" width="16" height="16" fill="currentColor" aria-hidden="true" style="color:var(--success)"><use href="assets/icons.svg#check"></use></svg>';
    stageTitle.innerText = 'Website is ready to use!';
    const openBtn = document.getElementById('progress-open-btn');
    openBtn.href = data.detail.startsWith('http') ? data.detail : `http://${data.detail}`;
    document.getElementById('progress-footer').style.display = 'flex';
    showToast('Website created & online!');
  }
}


// ===== Generic custom select (data-generic) — pola inline yang sama dgn preset =====
// Hanya toggle class .open pada wrapper; menu tetap di dalam markup (top: 100%,
// selebar trigger). Aman setelah htmx swap karena pakai event delegation.
document.addEventListener('click', (e) => {
  const trigger = e.target.closest('.custom-select[data-generic] > .select-trigger');
  if (trigger) {
    e.stopPropagation();
    const wrap = trigger.closest('.custom-select');
    const wasOpen = wrap.classList.contains('open');
    document.querySelectorAll('.custom-select[data-generic].open').forEach((el) => el.classList.remove('open'));
    if (!wasOpen) wrap.classList.add('open');
    return;
  }

  const item = e.target.closest('.custom-select[data-generic] .select-item');
  if (item) {
    const wrap = item.closest('.custom-select');
    const label = item.querySelector('span')?.textContent ?? item.dataset.value;
    wrap.querySelector('input[type="hidden"]').value = item.dataset.value;
    wrap.querySelector('input[type="hidden"]').dispatchEvent(new Event('change', { bubbles: true }));
    wrap.querySelector('.select-label').textContent = label;
    wrap.querySelectorAll('.select-item').forEach((el) => el.classList.toggle('active', el === item));
    wrap.classList.remove('open');
    return;
  }

  document.querySelectorAll('.custom-select[data-generic].open').forEach((el) => el.classList.remove('open'));
});

// ===== Configuration: Save Configuration disabled sampai ada perubahan =====
// Snapshot nilai form saat view terbuka; tombol aktif hanya jika nilai sekarang beda.
let configSnapshot = null;

function snapshotConfig() {
  const form = document.getElementById('config-form');
  if (!form) return null;
  return [...form.querySelectorAll('input')].map((i) =>
    i.type === 'checkbox' ? (i.checked ? i.value : '') : i.value).join('\u0000');
}

function updateConfigDirty() {
  const btn = document.getElementById('save-config-btn');
  if (!btn || configSnapshot === null) return;
  btn.disabled = snapshotConfig() === configSnapshot;
}

function resetConfigSnapshot() {
  configSnapshot = snapshotConfig();
  updateConfigDirty();
}
window.resetConfigSnapshot = resetConfigSnapshot; // dipanggil hx-on::after:request

document.addEventListener('change', (e) => {
  if (e.target.closest('#config-form')) updateConfigDirty();
});
document.body.addEventListener('htmx:after:swap', () => { // htmx 4: nama event berkoma
  configSnapshot = snapshotConfig();
  updateConfigDirty();
});
resetConfigSnapshot(); // initial load
