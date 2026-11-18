<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/panel_lib.php';

sp_security_headers();
header('Cache-Control: no-store');

// ============================================================
// AUTH
// ============================================================
function admin_ok(): bool {
    $c = (string)($_COOKIE['adm_sess'] ?? '');
    if (!$c) return false;
    $parts = explode('.', $c, 2);
    if (count($parts) !== 2) return false;
    [$tok, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $tok, SECRET_SALT), $sig)) return false;
    $stored = setting_get('admin_token');
    return $stored && hash_equals((string)$stored, $tok);
}

// خروج (فقط با توکن CSRF معتبر؛ جلوی logout از سایت‌های دیگر را می‌گیرد)
if (isset($_GET['logout'])) {
    $c = (string)($_COOKIE['adm_csrf'] ?? '');
    if ($c !== '' && hash_equals($c, (string)($_GET['t'] ?? ''))) {
        setting_set('admin_token', '');
        sp_cookie('adm_sess', '', time() - 3600);
        sp_cookie('adm_csrf', '', time() - 3600, false);
    }
    header('Location: admin.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $ip = sp_client_ip();
    if (!rate_limit_ok($ip, 'admin_login', 8)) {
        $loginError = 'Too many attempts. Please try again later.';
    } elseif (admin_password_ok((string)$_POST['password'])) {
        $token = bin2hex(random_bytes(32));
        $sig   = hash_hmac('sha256', $token, SECRET_SALT);
        setting_set('admin_token', $token);
        sp_cookie('adm_sess', "$token.$sig", time() + 86400);
        sp_cookie('adm_csrf', bin2hex(random_bytes(24)), time() + 86400, false);
        header('Location: admin.php');
        exit;
    } else {
        usleep(400000);
        $loginError = 'Incorrect password.';
    }
}

$loggedIn = admin_ok();
$csrf = (string)($_COOKIE['adm_csrf'] ?? '');
$v = (int)@filemtime(__DIR__ . '/spider.css') . (int)@filemtime(__DIR__ . '/spider.js');
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SPIDER KEY SYSTEM</title>
<script>try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="spider.css?v=<?= $v ?>">
</head>
<body class="<?= $loggedIn ? 'has-sidebar' : '' ?>">

<?php if (!$loggedIn): ?>
<!-- ======================= LOGIN ======================= -->
<div class="login-wrap">
    <div class="login-logo"><i class="fa-solid fa-spider"></i></div>
    <h1 style="font-size:28px;font-weight:700;margin-bottom:8px">SPIDER KEY SYSTEM</h1>
    <p style="color:var(--text-mut);margin-bottom:28px;font-size:16px">Welcome back, please enter your details</p>
    <?php if ($loginError): ?><div class="login-error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="login-form" autocomplete="off">
        <input type="text" value="admin" readonly tabindex="-1" style="color:var(--text-mut)">
        <input name="password" type="password" placeholder="Password" required autofocus autocomplete="current-password">
        <button type="submit" class="btn-submit" style="display:flex;justify-content:center;align-items:center;gap:10px"><i class="fa-solid fa-arrow-right-to-bracket"></i> Login</button>
    </form>
    <div style="margin-top:60px;color:var(--text-mut);font-size:15px">Made with <i class="fa-solid fa-heart" style="color:var(--red)"></i> by SPIDER Team</div>
</div>

<?php else: ?>
<!-- ======================= APP ======================= -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><i class="fa-solid fa-spider" style="color:var(--red);font-size:24px"></i> SPIDER</div>
    <div class="nav-item active" data-page="dashboard"><i class="fa-solid fa-chart-line"></i> Dashboard</div>
    <div class="nav-item" data-page="users"><i class="fa-solid fa-users"></i> Users</div>
    <div class="nav-item" data-page="products"><i class="fa-solid fa-box"></i> Products</div>
    <div class="nav-item" data-page="panels"><i class="fa-solid fa-user-shield"></i> Panels</div>
    <div class="nav-item" data-page="devices"><i class="fa-solid fa-mobile-screen"></i> Devices</div>
    <div class="nav-item" data-page="logs"><i class="fa-solid fa-clipboard-list"></i> Logs</div>
    <div class="nav-item" data-page="settings"><i class="fa-solid fa-gear"></i> Settings</div>
    <div class="nav-spacer"></div>
    <div class="nav-item" id="nav-logout" style="color:var(--red)"><i class="fa-solid fa-right-from-bracket"></i> Logout</div>
</aside>

<div class="main-wrap">
<header class="app-header">
    <div class="header-brand">
        <button class="menu-btn mobile-only" id="menu-btn" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
        <span id="header-title">SPIDER KEY SYSTEM</span>
    </div>
    <div class="header-actions"><button class="menu-btn" id="theme-btn" aria-label="Theme"></button></div>
</header>

<main class="main-content">
    <!-- DASHBOARD -->
    <section class="page" id="page-dashboard">
        <div class="page-header"><div><h1 class="page-title">Dashboard</h1><p class="page-subtitle">System overview and activity</p></div>
            <button class="icon-btn" id="dash-refresh" title="Refresh"><i class="fa-solid fa-arrows-rotate"></i></button></div>
        <div id="dash-body"></div>
    </section>

    <!-- USERS -->
    <section class="page" id="page-users" hidden>
        <div class="page-header"><div><h1 class="page-title">Users</h1><p class="page-subtitle">Control, update and arrange user accounts</p></div>
            <button class="btn-create" id="btn-create-key"><i class="fa-solid fa-plus"></i> Create User</button></div>
        <div id="keys-root"></div>
    </section>

    <!-- PRODUCTS -->
    <section class="page" id="page-products" hidden>
        <div class="page-header"><div><h1 class="page-title">Products</h1><p class="page-subtitle">Manage products and API keys</p></div>
            <button class="btn-create" id="btn-new-product"><i class="fa-solid fa-plus"></i> New Product</button></div>
        <div id="products-body"></div>
    </section>

    <!-- PANELS -->
    <section class="page" id="page-panels" hidden>
        <div class="page-header"><div><h1 class="page-title">Panels</h1><p class="page-subtitle">Reseller panels, quotas and permissions</p></div>
            <button class="btn-create" id="btn-new-panel"><i class="fa-solid fa-user-plus"></i> New Panel</button></div>
        <div id="panels-body"></div>
    </section>

    <!-- DEVICES -->
    <section class="page" id="page-devices" hidden>
        <div class="page-header"><div><h1 class="page-title">Devices</h1><p class="page-subtitle">All connected hardware IDs</p></div></div>
        <div class="toolbar"><div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="dev-search" placeholder="Search device ID, model, IP..."></div>
            <button class="icon-btn" id="dev-refresh"><i class="fa-solid fa-arrows-rotate"></i></button></div>
        <div id="devices-body"></div><div class="pager" id="devices-pager"></div>
    </section>

    <!-- LOGS -->
    <section class="page" id="page-logs" hidden>
        <div class="page-header"><div><h1 class="page-title">Logs</h1><p class="page-subtitle">API, audit and system logs</p></div></div>
        <div class="chips" id="log-tabs">
            <button class="chip active" data-t="api">API</button><button class="chip" data-t="audit">Audit</button><button class="chip" data-t="system">System</button></div>
        <div class="toolbar"><div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="log-search" placeholder="Filter..."></div>
            <select class="select-sm" id="log-level" hidden><option value="">All levels</option><option value="error">Error</option><option value="warn">Warning</option><option value="info">Info</option><option value="debug">Debug</option></select>
            <button class="icon-btn" id="log-refresh"><i class="fa-solid fa-arrows-rotate"></i></button></div>
        <div id="logs-body"></div><div class="pager" id="logs-pager"></div>
    </section>

    <!-- SETTINGS -->
    <section class="page" id="page-settings" hidden>
        <div class="page-header"><div><h1 class="page-title">Settings</h1><p class="page-subtitle">System info, backup, export and maintenance</p></div></div>
        <div id="settings-body"></div>
    </section>
</main>
</div>

<script src="spider.js?v=<?= $v ?>"></script>
<script>
(function () {
'use strict';
const $ = s => document.querySelector(s);
const esc = SP.esc;
const api = SP.mkApi('admin_api.php', 'adm_csrf', { csrfFallback: <?= json_encode($csrf) ?> });
let PRODUCTS = [], kl = null, panelUrlCache = null;

SP.initTheme($('#theme-btn'));

// ------------------------------------------------------------
// Navigation
// ------------------------------------------------------------
const sidebar = $('#sidebar'), overlay = $('#sidebar-overlay');
const toggleSidebar = () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); };
$('#menu-btn').onclick = toggleSidebar; overlay.onclick = toggleSidebar;
$('#nav-logout').onclick = () => { location.href = 'admin.php?logout=1&t=' + encodeURIComponent(SP.cookie('adm_csrf')); };

const TITLES = { dashboard: 'Dashboard', users: 'Users', products: 'Products', panels: 'Panels', devices: 'Devices', logs: 'Logs', settings: 'Settings' };
const loaders = { dashboard: loadDashboard, users: initUsers, products: loadProducts, panels: loadPanels, devices: loadDevices, logs: loadLogs, settings: loadSettings };
function nav(p) {
    if (!loaders[p]) p = 'dashboard';
    SP.$$('.page').forEach(e => e.hidden = e.id !== 'page-' + p);
    SP.$$('.nav-item[data-page]').forEach(e => e.classList.toggle('active', e.dataset.page === p));
    $('#header-title').textContent = 'SPIDER · ' + TITLES[p];
    if (location.hash !== '#' + p) history.replaceState(null, '', '#' + p);
    sidebar.classList.remove('open'); overlay.classList.remove('open');
    window.scrollTo(0, 0);
    loaders[p]();
}
SP.$$('.nav-item[data-page]').forEach(e => e.onclick = () => nav(e.dataset.page));
window.addEventListener('hashchange', () => nav(location.hash.slice(1)));

async function getProducts(force) {
    if (PRODUCTS.length && !force) return PRODUCTS;
    const r = await api('products');
    if (r.ok) { PRODUCTS = r.products; if (kl) kl.setProducts(PRODUCTS); }
    return PRODUCTS;
}
async function getPanelUrl() {
    if (panelUrlCache) return panelUrlCache;
    const r = await api('settings');
    if (r.ok) panelUrlCache = r.settings.panel_url || r.settings.panel_url_default;
    return panelUrlCache || (location.origin + location.pathname.replace(/[^/]*$/, '') + 'panel.php');
}
function statusBadge(s) {
    s = String(s ?? '');
    const c = /ok|success|valid|active|created/i.test(s) ? 'green' : /fail|error|invalid|denied|banned|expired|limit/i.test(s) ? 'red' : '';
    return `<span class="badge ${c}">${esc(s || '—')}</span>`;
}
function genPass(n = 12) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const a = new Uint32Array(n); crypto.getRandomValues(a);
    return Array.from(a, x => chars[x % chars.length]).join('');
}

// ------------------------------------------------------------
// Dashboard
// ------------------------------------------------------------
$('#dash-refresh').onclick = () => loadDashboard();
async function loadDashboard() {
    const box = $('#dash-body');
    if (!box.dataset.ready) box.innerHTML = SP.skeleton(3);
    const r = await api('dashboard');
    if (!r.ok) { box.innerHTML = `<div class="error-box">${esc(SP.errText(r))}</div>`; return; }
    box.dataset.ready = '1';
    const s = r.stats;
    const cards = [
        ['sessions', 'Online Sessions', '', true], ['keys_active', 'Active Users', 'fa-solid fa-user-check'], ['keys_total', 'Total Keys', 'fa-solid fa-key'],
        ['keys_unused', 'Unused Keys', 'fa-regular fa-hourglass-half'], ['keys_banned', 'Banned', 'fa-solid fa-ban'], ['devices', 'Devices', 'fa-solid fa-mobile-screen'],
        ['resellers', 'Resellers', 'fa-solid fa-user-shield'], ['products', 'Products', 'fa-solid fa-box'], ['api_24h', 'API calls (24h)', 'fa-solid fa-bolt'],
        ['errors_24h', 'Errors (24h)', 'fa-solid fa-triangle-exclamation'],
    ];
    const max = Math.max(1, ...r.chart.map(d => Math.max(d.api, d.keys)));
    const pmax = Math.max(1, ...r.top_products.map(p => p.count));
    box.innerHTML = `
    <div class="stats-grid">${cards.map(([k, l, i, dot]) => `<div class="stat-card"><div class="label">${dot ? '<span class="stat-dot"></span>' : `<i class="${i}"></i>`} ${l}</div><div class="value">${s[k] ?? 0}</div></div>`).join('')}</div>
    <div class="two-col">
        <div class="card"><div class="card-title">Last 7 days</div>
            <div class="bars" style="margin-top:14px">${r.chart.map(d => `<div class="bar-col"><div class="bar-pair">
                <div class="bar" style="height:${Math.round(d.api / max * 100)}%" title="API calls: ${d.api}"></div>
                <div class="bar k" style="height:${Math.round(d.keys / max * 100)}%" title="New keys: ${d.keys}"></div></div>
                <div class="bar-label">${esc(d.date)}</div></div>`).join('')}</div>
            <div class="legend"><span>API calls</span><span class="k">New keys</span></div></div>
        <div class="card"><div class="card-title">Keys per product</div><div style="margin-top:10px">
            ${r.top_products.length ? r.top_products.map(p => `<div style="margin-bottom:12px"><div class="card-row" style="font-size:13px"><span>${esc(p.name)}</span><b>${p.count}</b></div>
                <div class="progress-bar-bg" style="margin:6px 0 0"><div class="progress-bar-fill" style="width:${Math.round(p.count / pmax * 100)}%"></div></div></div>`).join('') : '<div class="empty">No products</div>'}</div></div>
    </div>
    <div class="two-col">
        <div class="card"><div class="card-title">Recent API activity</div><div style="margin-top:8px">
            ${r.recent.length ? r.recent.map(x => `<div class="list-line"><span>${esc(x.action)} ${statusBadge(x.status)}</span><span class="t">${esc(SP.fmt.ago(x.time))}</span></div>`).join('') : '<div class="empty">No activity</div>'}</div></div>
        <div class="card"><div class="card-title">Recent admin actions</div><div style="margin-top:8px">
            ${r.audit.length ? r.audit.map(x => `<div class="list-line"><span>${esc(x.action)} <span style="color:var(--text-mut)">${esc(x.target || '')}${x.target_id ? ' #' + x.target_id : ''}</span></span><span class="t">${esc(SP.fmt.ago(x.time))}</span></div>`).join('') : '<div class="empty">No actions</div>'}</div></div>
    </div>`;
}

// ------------------------------------------------------------
// Users
// ------------------------------------------------------------
async function initUsers() {
    if (!kl) {
        kl = new SP.KeyList({ root: '#keys-root', api, showProduct: true, limit: 15 });
    } else kl.load();
    getProducts().then(p => kl.setProducts(p));
}
$('#btn-create-key').onclick = async () => {
    const prods = (await getProducts()).filter(p => p.status === 'active');
    if (!prods.length) return SP.fail('ابتدا یک محصول فعال بسازید');
    SP.createKeyDialog({ api, products: prods, onDone: () => { if (kl) kl.load(); } });
};

// ------------------------------------------------------------
// Products
// ------------------------------------------------------------
$('#btn-new-product').onclick = () => productDialog(null);
async function loadProducts() {
    const box = $('#products-body');
    box.innerHTML = SP.skeleton(3);
    const list = await getProducts(true);
    if (!list.length) { box.innerHTML = '<div class="card empty">No products</div>'; return; }
    box.innerHTML = list.map(p => `<div class="card" data-pid="${p.id}">
        <div class="card-row"><div class="card-title">${esc(p.name)} <span class="badge ${p.status === 'active' ? 'green' : 'red'}">${esc(p.status)}</span>${p.id <= 1 ? '<span class="badge blue">default</span>' : ''}</div>
            <span class="data-sub">#${p.id}</span></div>
        <div class="card-sub">Slug: <code>${esc(p.slug)}</code> · Keys: <b>${p.lic_count}</b> · Resellers: <b>${p.res_count}</b> · Created: ${esc(SP.fmt.date(p.created_at))}<br>
            API key: <code data-apikey="${esc(p.api_key)}">${esc('•'.repeat(10) + String(p.api_key || '').slice(-4))}</code>
            <button class="btn-sm" style="padding:3px 8px;margin-left:6px" data-a="reveal"><i class="fa-regular fa-eye"></i></button>
            <button class="btn-sm" style="padding:3px 8px" data-a="copykey"><i class="fa-regular fa-copy"></i></button></div>
        <div class="card-actions">
            <button class="btn-sm" data-a="keys"><i class="fa-solid fa-key"></i> View keys</button>
            <button class="btn-sm primary" data-a="panel"><i class="fa-solid fa-user-shield"></i> Create Panel</button>
            <button class="btn-sm" data-a="edit"><i class="fa-solid fa-pen"></i> Edit</button>
            ${p.id > 1 ? '<button class="btn-sm danger" data-a="delete"><i class="fa-solid fa-trash"></i> Delete</button>' : ''}</div></div>`).join('');
}
$('#products-body').addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const card = b.closest('[data-pid]'); const p = PRODUCTS.find(x => x.id === parseInt(card.dataset.pid, 10));
    if (!p) return;
    const a = b.dataset.a;
    if (a === 'reveal') {
        const c = SP.$('[data-apikey]', card); const shown = c.dataset.shown === '1';
        c.textContent = shown ? '•'.repeat(10) + String(p.api_key || '').slice(-4) : p.api_key; c.dataset.shown = shown ? '0' : '1';
    } else if (a === 'copykey') SP.copy(p.api_key, 'API key copied');
    else if (a === 'keys') { location.hash = '#users'; setTimeout(() => kl && kl.setProduct(p.id), 50); }
    else if (a === 'panel') panelDialog(p.id);
    else if (a === 'edit') productDialog(p);
    else if (a === 'delete') {
        if (!await SP.confirm(`Delete product "${p.name}" with ALL its keys, panels and devices? This cannot be undone.`, { title: 'Delete product', ok: 'Delete everything', danger: true })) return;
        const r = await api('delete_product', { body: { id: p.id } });
        if (!r.ok) return SP.fail(r);
        SP.ok('Product deleted'); loadProducts();
    }
});
function productDialog(p) {
    const d = SP.dialog(p ? 'Edit Product' : 'New Product', `
        <div class="form-group"><label>Name</label><input id="pd-name" maxlength="80" value="${esc(p ? p.name : '')}" placeholder="e.g. Spider VPN"></div>
        ${p ? `<div class="form-group"><label>Custom domain (optional)</label><input id="pd-domain" value="${esc(p.custom_domain || '')}" placeholder="api.example.com" autocapitalize="none"></div>` : ''}
        ${p ? `<div class="form-group"><label>Status</label><select id="pd-status"><option value="active" ${p.status === 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${p.status === 'inactive' ? 'selected' : ''} ${p.id <= 1 ? 'disabled' : ''}>Inactive</option></select></div>`
            : `<div class="form-group"><label>Slug (a-z, 0-9, -)</label><input id="pd-slug" maxlength="32" placeholder="e.g. spider-vpn" autocapitalize="none"></div>`}
        <button class="btn-submit" id="pd-ok">${p ? 'Save' : 'Create'}</button>`);
    d.$('#pd-ok').onclick = async () => {
        const name = d.$('#pd-name').value.trim();
        const body = p ? { id: p.id, name, status: d.$('#pd-status').value, custom_domain: d.$('#pd-domain').value.trim() } : { name, slug: d.$('#pd-slug').value.trim().toLowerCase() };
        if (!name && p) return SP.fail('نام را وارد کنید');
        if (!p && !body.slug) return SP.fail('اسلاگ را وارد کنید');
        const r = await api(p ? 'update_product' : 'create_product', { body });
        if (!r.ok) return SP.fail(r);
        d.close(); SP.ok(p ? 'Saved' : 'Product created'); loadProducts();
    };
}

// ------------------------------------------------------------
// Panels (resellers)
// ------------------------------------------------------------
const PERM_LABELS = { can_create_keys: 'Create keys', can_renew_keys: 'Renew keys', can_ban_keys: 'Ban / unban keys', can_reset_devices: 'Reset devices', can_edit_users: 'Edit max devices', can_delete_keys: 'Delete keys' };
let RESELLERS = [];
$('#btn-new-panel').onclick = () => panelDialog(0);
async function loadPanels() {
    const box = $('#panels-body');
    box.innerHTML = SP.skeleton(3);
    const r = await api('resellers');
    if (!r.ok) { box.innerHTML = `<div class="error-box">${esc(SP.errText(r))}</div>`; return; }
    RESELLERS = r.resellers;
    if (!RESELLERS.length) { box.innerHTML = '<div class="card empty">No panels yet — create the first one.</div>'; return; }
    const q = v => v ? v : '∞';
    box.innerHTML = RESELLERS.map(x => `<div class="card" data-rid="${x.id}">
        <div class="card-row"><div class="card-title">${esc(x.display_name)} <span class="badge ${x.status === 'active' ? 'green' : 'red'}">${esc(x.status)}</span>
            ${x.has_panel ? '<span class="badge blue">panel</span>' : '<span class="badge">bot only</span>'}</div><span class="data-sub">#${x.id}</span></div>
        <div class="card-sub">Username (Telegram ID): <code>${x.telegram_id}</code> · Product: <b>${esc(x.product_name)}</b><br>
            Keys: <b>${x.key_count}</b> / ${q(x.quota_max_keys)} · Max devices/key: <b>${q(x.quota_max_users)}</b> · Max duration: <b>${x.quota_max_duration ? Math.round(x.quota_max_duration / 86400) + 'd' : '∞'}</b><br>
            Permissions: ${Object.keys(PERM_LABELS).map(k => `<span class="badge ${x.perms[k] ? 'green' : 'red'}" style="margin:2px 3px 0 0">${esc(PERM_LABELS[k])}</span>`).join('')}</div>
        <div class="card-actions">
            <button class="btn-sm" data-a="keys"><i class="fa-solid fa-key"></i> Keys</button>
            <button class="btn-sm primary" data-a="edit"><i class="fa-solid fa-pen"></i> Edit</button>
            <button class="btn-sm" data-a="copy"><i class="fa-solid fa-link"></i> Panel link</button>
            <button class="btn-sm danger" data-a="delete"><i class="fa-solid fa-trash"></i> Delete</button></div></div>`).join('');
}
$('#panels-body').addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const x = RESELLERS.find(v => v.id === parseInt(b.closest('[data-rid]').dataset.rid, 10)); if (!x) return;
    if (b.dataset.a === 'edit') resellerDialog(x);
    else if (b.dataset.a === 'copy') SP.copy(await getPanelUrl(), 'Panel link copied');
    else if (b.dataset.a === 'keys') { location.hash = '#users'; setTimeout(() => { if (kl) { kl.state.page = 0; kl.setProduct(x.product_id); } }, 50); }
    else if (b.dataset.a === 'delete') {
        if (!await SP.confirm(`Delete panel/reseller "${x.display_name}"?`, { title: 'Delete reseller', ok: 'Delete', danger: true })) return;
        const r = await api('delete_reseller', { body: { id: x.id } });
        if (!r.ok) return SP.fail(r);
        SP.ok('Deleted'); loadPanels();
    }
});

async function panelDialog(productId) {
    const prods = (await getProducts()).filter(p => p.status === 'active' || p.id === productId);
    if (!prods.length) return SP.fail('ابتدا یک محصول بسازید');
    const d = SP.dialog('Create Product Panel', `
        <div class="form-group"><label>Product</label><select id="pn-product">${prods.map(p => `<option value="${p.id}" ${p.id === productId ? 'selected' : ''}>${esc(p.name)} (${esc(p.slug)})</option>`).join('')}</select></div>
        <div class="form-group"><label>Username (Telegram ID)</label><input type="number" id="pn-user" placeholder="e.g. 8872751291" inputmode="numeric"></div>
        <div class="form-group"><label>Display Name</label><input type="text" id="pn-name" maxlength="80" placeholder="e.g. VIP Reseller"></div>
        <div class="form-group"><label>Password (min 6 chars)</label>
            <div style="display:flex;gap:8px"><input type="text" id="pn-pass" autocomplete="off" placeholder="Enter secure password"><button class="btn-sm" type="button" id="pn-gen" title="Generate"><i class="fa-solid fa-wand-magic-sparkles"></i></button></div>
            <div class="form-hint">If this user already has a panel for this product, its password will be updated.</div></div>
        <button class="btn-submit" id="pn-ok">Create Panel</button>`);
    d.$('#pn-gen').onclick = () => { d.$('#pn-pass').value = genPass(); };
    d.$('#pn-ok').onclick = async () => {
        const body = {
            product_id: parseInt(d.$('#pn-product').value, 10), telegram_id: parseInt(d.$('#pn-user').value, 10),
            name: d.$('#pn-name').value.trim(), password: d.$('#pn-pass').value,
        };
        if (!body.telegram_id || body.name.length < 2 || body.password.length < 6) return SP.fail('همه فیلدها را درست پر کنید (رمز حداقل ۶ کاراکتر)');
        const btn = d.$('#pn-ok'); btn.disabled = true;
        const r = await api('create_panel', { body });
        btn.disabled = false;
        if (!r.ok) return SP.fail(r);
        d.close();
        const url = await getPanelUrl();
        const text = `Panel: ${url}\nUsername: ${body.telegram_id}\nPassword: ${body.password}`;
        const d2 = SP.dialog(r.updated ? '✅ Panel password updated' : '✅ Panel created', `<div class="key-big" style="text-align:left;white-space:pre-wrap">${esc(text)}</div>
            <button class="btn-sm primary" style="width:100%;justify-content:center;padding:12px" id="pn-copy"><i class="fa-regular fa-copy"></i> Copy credentials</button>`);
        d2.$('#pn-copy').onclick = () => SP.copy(text, 'Credentials copied');
        if (!$('#page-panels').hidden) loadPanels();
        else if (!$('#page-products').hidden) loadProducts();
    };
}

function resellerDialog(x) {
    const d = SP.dialog('Edit: ' + x.display_name, `
        <div class="form-row">
            <div class="form-group"><label>Display name</label><input id="rs-name" maxlength="80" value="${esc(x.display_name)}"></div>
            <div class="form-group"><label>Status</label><select id="rs-status"><option value="active" ${x.status === 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${x.status !== 'active' ? 'selected' : ''}>Inactive</option></select></div>
        </div>
        <div class="section-title" style="margin-top:4px">Quotas <span style="font-weight:400;color:var(--text-mut);font-size:12px">(empty = unlimited)</span></div>
        <div class="form-row">
            <div class="form-group"><label>Max keys</label><input type="number" min="0" id="rs-qk" value="${x.quota_max_keys ?? ''}" placeholder="∞"></div>
            <div class="form-group"><label>Max devices / key</label><input type="number" min="0" id="rs-qu" value="${x.quota_max_users ?? ''}" placeholder="∞"></div>
        </div>
        <div class="form-group"><label>Max duration per key (days)</label><input type="number" min="0" id="rs-qd" value="${x.quota_max_duration ? Math.round(x.quota_max_duration / 86400) : ''}" placeholder="∞"></div>
        <div class="section-title" style="margin-top:4px">Permissions</div>
        ${Object.keys(PERM_LABELS).map(k => `<label class="check-row"><input type="checkbox" data-perm="${k}" ${x.perms[k] ? 'checked' : ''}> ${esc(PERM_LABELS[k])}</label>`).join('')}
        <div class="form-group" style="margin-top:12px"><label>New panel password (optional)</label>
            <div style="display:flex;gap:8px"><input type="text" id="rs-pass" autocomplete="off" placeholder="Leave empty to keep"><button class="btn-sm" type="button" id="rs-gen"><i class="fa-solid fa-wand-magic-sparkles"></i></button></div></div>
        <button class="btn-submit" id="rs-ok">Save</button>`);
    d.$('#rs-gen').onclick = () => { d.$('#rs-pass').value = genPass(); };
    d.$('#rs-ok').onclick = async () => {
        const perms = {}; SP.$$('[data-perm]', d.el).forEach(c => perms[c.dataset.perm] = c.checked ? 1 : 0);
        const body = {
            id: x.id, display_name: d.$('#rs-name').value.trim(), status: d.$('#rs-status').value,
            quota_max_keys: d.$('#rs-qk').value, quota_max_users: d.$('#rs-qu').value, quota_max_duration_days: d.$('#rs-qd').value, perms,
        };
        const pass = d.$('#rs-pass').value; if (pass) body.password = pass;
        const r = await api('update_reseller', { body });
        if (!r.ok) return SP.fail(r);
        if (pass) { await SP.copy(pass, 'Saved — new password copied'); } else SP.ok('Saved');
        d.close(); loadPanels();
    };
}

// ------------------------------------------------------------
// Devices
// ------------------------------------------------------------
const dev = { page: 0, search: '' };
$('#dev-search').addEventListener('input', SP.debounce(() => { dev.search = $('#dev-search').value.trim(); dev.page = 0; loadDevices(); }));
$('#dev-refresh').onclick = () => loadDevices();
async function loadDevices() {
    const box = $('#devices-body');
    box.innerHTML = SP.skeleton(4);
    const r = await api('devices', { qs: new URLSearchParams({ page: dev.page, search: dev.search }).toString() });
    if (!r.ok) { box.innerHTML = `<div class="error-box">${esc(SP.errText(r))}</div>`; return; }
    box.innerHTML = SP.table(['Product', 'Key', 'Device', 'Model', 'Android', 'Last login', 'Logins', 'IP', ''],
        r.devices.map(d => [esc(d.product), `<span class="mono">${esc(d.key_masked)}</span>`, `<span class="mono">${esc(d.device_id)}</span>`, esc(d.model || '—'), esc(d.android || '—'),
            esc(SP.fmt.ago(d.last_login)), d.login_count, esc(d.ip || '—'),
            `<button class="btn-sm danger" data-lic="${d.license_id}" data-dev="${d.id}"><i class="fa-solid fa-link-slash"></i> Reset</button>`]));
    SP.pager($('#devices-pager'), r, p => { dev.page = p; loadDevices(); });
}
$('#devices-body').addEventListener('click', async e => {
    const b = e.target.closest('[data-dev]'); if (!b) return;
    if (!await SP.confirm('Reset this device? The user will need to re-register it.', { ok: 'Reset', danger: true })) return;
    const r = await api('reset_device', { body: { license_id: parseInt(b.dataset.lic, 10), device_row_id: parseInt(b.dataset.dev, 10) } });
    if (!r.ok) return SP.fail(r);
    SP.ok('Device reset'); loadDevices();
});

// ------------------------------------------------------------
// Logs
// ------------------------------------------------------------
const lg = { type: 'api', page: 0, q: '', level: '' };
SP.$$('#log-tabs .chip').forEach(c => c.onclick = () => {
    SP.$$('#log-tabs .chip').forEach(x => x.classList.toggle('active', x === c));
    lg.type = c.dataset.t; lg.page = 0; $('#log-level').hidden = lg.type !== 'system'; loadLogs();
});
$('#log-search').addEventListener('input', SP.debounce(() => { lg.q = $('#log-search').value.trim(); lg.page = 0; loadLogs(); }));
$('#log-level').onchange = () => { lg.level = $('#log-level').value; lg.page = 0; loadLogs(); };
$('#log-refresh').onclick = () => loadLogs();
async function loadLogs() {
    const box = $('#logs-body');
    box.innerHTML = SP.skeleton(4);
    const r = await api('logs', { qs: new URLSearchParams(lg).toString() });
    if (!r.ok) { box.innerHTML = `<div class="error-box">${esc(SP.errText(r))}</div>`; return; }
    const t = x => esc(SP.fmt.date(x));
    let html;
    if (lg.type === 'audit') html = SP.table(['Time', 'Admin', 'Action', 'Target'], r.logs.map(l => [t(l.time), esc(l.admin), `<b>${esc(l.action)}</b>`, esc(l.target || '') + (l.target_id ? ' #' + l.target_id : '')]));
    else if (lg.type === 'system') html = SP.table(['Time', 'Level', 'Message', 'Context'], r.logs.map(l => [t(l.time),
        `<span class="badge ${l.level === 'error' ? 'red' : l.level === 'warning' ? 'orange' : ''}">${esc(l.level)}</span>`, esc(l.message), `<span class="mono ellipsis" title="${esc(l.context)}">${esc(l.context)}</span>`]));
    else html = SP.table(['Time', 'Action', 'Status', 'License', 'IP', 'Message'], r.logs.map(l => [t(l.time), `<b>${esc(l.action)}</b>`, statusBadge(l.status), l.license_id ? '#' + l.license_id : '—', esc(l.ip || ''), `<span class="ellipsis" title="${esc(l.message)}">${esc(l.message || '')}</span>`]));
    box.innerHTML = html;
    SP.pager($('#logs-pager'), r, p => { lg.page = p; loadLogs(); });
}

// ------------------------------------------------------------
// Settings
// ------------------------------------------------------------
async function loadSettings() {
    const box = $('#settings-body');
    box.innerHTML = SP.skeleton(3);
    const [r, prods] = await Promise.all([api('settings'), getProducts()]);
    if (!r.ok) { box.innerHTML = `<div class="error-box">${esc(SP.errText(r))}</div>`; return; }
    const s = r.settings;
    const line = (label, val) => `<div class="list-line"><span><div class="data-sub">${esc(label)}</div><code>${esc(val)}</code></div><button class="btn-sm" data-copy="${esc(val)}"><i class="fa-regular fa-copy"></i></button></div>`;
    box.innerHTML = `
    <div class="card"><div class="card-title"><i class="fa-solid fa-circle-info"></i> System</div>
        <div class="kv" style="margin:12px 0 0"><div class="k">Version</div><div class="v">${esc(s.version || '—')}</div><div class="k">PHP</div><div class="v">${esc(s.php)}</div>
        <div class="k">Host</div><div class="v">${esc(s.host)}</div><div class="k">Database size</div><div class="v">${esc(SP.fmt.size(s.db_size))}</div></div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-link"></i> Endpoints</div><div style="margin-top:6px">${line('Generic API', s.api_generic)}</div>
        <div class="form-group" style="margin:14px 0 0"><label>API URL shown to clients</label>
            <input id="st-api-url" value="${esc(s.api_url)}" placeholder="https://example.com/api.php"></div>
        <div class="card-actions"><button class="btn-sm" id="st-webhook"><i class="fa-brands fa-telegram"></i> Register Telegram webhook</button>
            <button class="btn-sm" id="st-health"><i class="fa-solid fa-heart-pulse"></i> System health</button></div>
        <div class="form-group" style="margin:14px 0 0"><label>Reseller panel URL (shown to resellers)</label>
        <div style="display:flex;gap:8px"><input id="st-panel-url" value="${esc(s.panel_url)}" placeholder="${esc(s.panel_url_default)}"><button class="btn-sm primary" id="st-save-url">Save</button></div></div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-download"></i> Backup & Export</div>
        <div class="card-actions"><button class="btn-sm primary" id="st-backup"><i class="fa-solid fa-database"></i> Download DB backup</button></div>
        <div class="form-row" style="margin-top:16px">
            <div class="form-group"><label>Product</label><select id="ex-product"><option value="0">All products</option>${prods.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>
            <div class="form-group"><label>Status</label><select id="ex-filter"><option value="all">All</option><option value="active">Active</option><option value="unused">Unused</option><option value="expired">Expired</option><option value="banned">Banned</option></select></div>
        </div>
        <div class="card-actions" style="margin-top:0"><button class="btn-sm" data-ex="txt"><i class="fa-solid fa-file-lines"></i> Export TXT</button><button class="btn-sm" data-ex="csv"><i class="fa-solid fa-file-csv"></i> Export CSV</button></div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-broom"></i> Maintenance</div>
        <div class="card-sub">Delete API/system logs older than the given number of days.</div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap"><input type="number" id="pg-days" value="30" min="1" style="width:100px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text-main)"> days
            <label class="check-row" style="padding:0"><input type="checkbox" id="pg-audit"> include audit log</label>
            <button class="btn-sm danger" id="pg-ok"><i class="fa-solid fa-trash"></i> Purge logs</button></div></div>`;

    SP.$$('[data-copy]', box).forEach(b => b.onclick = () => SP.copy(b.dataset.copy));
    $('#st-save-url').onclick = async () => {
        const r2 = await api('save_settings', { body: { panel_url: $('#st-panel-url').value.trim(), api_url: $('#st-api-url').value.trim() } });
        if (!r2.ok) return SP.fail(r2);
        panelUrlCache = null; SP.ok('Saved');
    };
    $('#st-webhook').onclick = async () => {
        const r2 = await api('setup_webhook', { body: {} });
        if (r2.ok) SP.ok('Webhook registered'); else SP.fail(r2.error || 'Webhook failed');
    };
    $('#st-health').onclick = async () => {
        const r2 = await api('health');
        if (!r2.ok) return SP.fail(r2);
        const h = r2.health;
        const d = SP.dialog('System health', `<div class="kv">
            <div class="k">Webhook</div><div class="v">${h.webhook_ok ? '✅ OK' : '❌ ' + esc(h.webhook_error || 'not set')}</div>
            <div class="k">Pending updates</div><div class="v">${h.pending_updates}</div>
            <div class="k">Database</div><div class="v">${esc(SP.fmt.size(h.db_size))}</div>
            <div class="k">Online devices</div><div class="v">${r2.online}</div>
            <div class="k">Expired sessions</div><div class="v">${h.expired_sessions}</div>
            <div class="k">Errors (24h)</div><div class="v">${h.errors_24h}</div></div>
            <button class="btn-sm" id="hl-clean"><i class="fa-solid fa-broom"></i> Run cleanup now</button>`);
        d.$('#hl-clean').onclick = async () => {
            const r3 = await api('maintenance', { body: {} });
            if (r3.ok) SP.ok('Cleanup done'); else SP.fail(r3);
        };
    };
    $('#st-backup').onclick = async () => {
        const b = $('#st-backup'); b.disabled = true;
        try {
            const res = await fetch('admin_api.php?action=backup', { credentials: 'same-origin' });
            if ((res.headers.get('content-type') || '').includes('json')) { SP.fail(SP.errText(await res.json())); }
            else {
                const blob = await res.blob();
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
                a.download = 'spider_backup_' + new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14) + '.sqlite';
                document.body.appendChild(a); a.click(); a.remove(); SP.ok('Backup downloaded');
            }
        } catch (e) { SP.fail('NETWORK'); }
        b.disabled = false;
    };
    SP.$$('[data-ex]', box).forEach(b => b.onclick = async () => {
        const qs = new URLSearchParams({ format: b.dataset.ex, filter: $('#ex-filter').value });
        const pid = $('#ex-product').value; if (pid !== '0') qs.set('product_id', pid);
        const r2 = await api('export', { qs: qs.toString() });
        if (!r2.ok) return SP.fail(r2);
        SP.download(r2.filename, r2.content, b.dataset.ex === 'csv' ? 'text/csv' : 'text/plain'); SP.ok(r2.count + ' keys exported');
    });
    $('#pg-ok').onclick = async () => {
        const days = parseInt($('#pg-days').value, 10) || 0;
        if (days < 1) return SP.fail('تعداد روز نامعتبر است');
        if (!await SP.confirm(`Delete logs older than ${days} days?`, { ok: 'Purge', danger: true })) return;
        const r2 = await api('purge_logs', { body: { days, audit: $('#pg-audit').checked } });
        if (!r2.ok) return SP.fail(r2);
        SP.ok(r2.deleted + ' rows deleted');
    };
}

// ------------------------------------------------------------
nav(location.hash.slice(1) || 'dashboard');
})();
</script>
<?php endif; ?>
</body>
</html>
