<?php
/**
 * Locadev Dashboard — Reusable component renderers.
 * Markup komponen Arnative dalam SATU tempat: halaman cukup memanggil
 * fungsi ini, styling ada di assets/css/components/<komponen>.css.
 * Blueprint dokumentasi: ~/.pi/agent/skills/arnative-design/components/.
 */

/** Escaper diambil dari partials.php bila sudah termuat. */
if (!function_exists('e')) {
    require_once __DIR__ . '/partials.php';
}

/**
 * Switch toggle (instant-apply) — styling: assets/css/components/switch.css.
 * $attrs: atribut tambahan untuk <input> (hx-post, hx-vals, hx-trigger, dst).
 * Komponen switch.css: [components/switch] — markup terkunci di sini.
 */
function render_switch(bool $checked, string $title, array $attrs = [], array $inputAttrs = []): string {
    $attr = '';
    foreach ($attrs as $k => $v) {
        $attr .= ' ' . $k . '="' . e($v) . '"';
    }
    foreach ($inputAttrs as $k => $v) {
        $attr .= ' ' . $k . '="' . e($v) . '"';
    }
    return '<label class="switch" title="' . e($title) . '">'
        . '<input type="checkbox"' . ($checked ? ' checked' : '') . $attr . '>'
        . '<span class="slider"></span></label>';
}

/**
 * Search box dengan icon — styling: assets/css/components/search.css.
 * $attrs: atribut untuk wrapper div; $inputAttrs: atribut untuk <input>
 * (name, hx-get + hx-trigger="input changed delay:300ms", hx-include, dst).
 */
function render_search(string $value = '', array $attrs = [], array $inputAttrs = []): string {
    $inputAttr = '';
    foreach ($inputAttrs as $k => $v) {
        $inputAttr .= ' ' . $k . '="' . e($v) . '"';
    }
    return '<div class="search-box">'
        . '<svg class="msr" width="14" height="14" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#search"></use></svg>'
        . '<input type="search" class="form-input" value="' . e($value) . '"' . $inputAttr . '>'
        . '</div>';
}

/**
 * Custom select dropdown (shadcn-style) — styling: assets/css/components/forms.css
 * (.custom-select / .select-trigger / .select-menu / .select-arrow).
 * data-generic + hidden input menandai instance mandiri: perilaku buka/tutup &
 * pilih opsi via event delegation di app.js (aman untuk konten hasil swap htmx).
 * $labels: label tampilan per value (fallback: value itu sendiri).
 */
function render_custom_select(string $name, string $current, array $options, array $labels = []): string {
    $menu = '';
    foreach ($options as $opt) {
        $label = $labels[$opt] ?? $opt;
        $menu .= '<div class="select-item' . ($opt === $current ? ' active' : '') . '" data-value="' . e($opt) . '">'
            . '<span>' . e($label) . '</span>'
            . '<svg class="msr check-icon" width="15" height="15" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#check"></use></svg>'
            . '</div>';
    }
    return '<div class="custom-select" data-generic>'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($current) . '">'
        . '<button type="button" class="select-trigger">'
        . '<span class="select-label">' . e($labels[$current] ?? $current) . '</span>'
        . '<svg class="msr select-arrow" width="16" height="16" fill="currentColor" aria-hidden="true"><use href="assets/icons.svg#keyboard_arrow_down"></use></svg>'
        . '</button>'
        . '<div class="select-menu">' . $menu . '</div>'
        . '</div>';
}
