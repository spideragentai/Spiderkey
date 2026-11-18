<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/panel_lib.php';

sp_security_headers();
header('Cache-Control: no-store');

$token   = (string)($_COOKIE['sp_panel'] ?? '');
$session = $token ? reseller_session_get($token) : null;
if ($session && (($session['status'] ?? 'active') !== 'active')) $session = null;
$loggedIn = (bool)$session;
$reseller = $session ?: null;
$product  = $loggedIn ? product_get((int)$session['product_id']) : null;

// توکن CSRF: اگر کوکی معتبر وجود دارد همان استفاده می‌شود (قبلاً با هر بار باز کردن صفحه عوض می‌شد
// و تب‌های دیگر خطای CSRF می‌گرفتند)
$csrf = (string)($_COOKIE['sp_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{40}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(20));
    sp_cookie('sp_csrf', $csrf, time() + 604800, false);
}

$prefillToken = (string)($_GET['token'] ?? '');
$v = (int)@filemtime(__DIR__ . '/spider.css') . (int)@filemtime(__DIR__ . '/spider.js');
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SPIDER Reseller Panel</title>
<script>try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="spider.css?v=<?= $v ?>">
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ======================= LOGIN ======================= -->
<div class="login-wrap">
    <div class="login-logo"><i class="fa-solid fa-spider"></i></div>
    <h1 style="font-size:28px;font-weight:700;margin-bottom:8px">SPIDER PANEL</h1>
    <p style="color:var(--text-mut);margin-bottom:28px;font-size:16px">Welcome back, please enter your details</p>
    <div id="login-error" class="login-error" hidden></div>
    <div class="login-form">
        <input type="text" id="login-user" placeholder="Username (Telegram ID)" inputmode="numeric" autocomplete="username" autofocus>
        <input type="password" id="login-pass" placeholder="Password" autocomplete="current-password">
        <button id="login-btn" class="btn-submit" style="display:flex;justify-content:center;align-items:center;gap:10px"><i class="fa-solid fa-arrow-right-to-bracket"></i> Login</button>
    </div>
    <div style="margin-top:60px;color:var(--text-mut);font-size:15px">Made with <i class="fa-solid fa-heart" style="color:var(--red)"></i> by SPIDER Team</div>
</div>
<script src="spider.js?v=<?= $v ?>"></script>
<script>
(function () {
'use strict';
// noReload: پاسخ 401 (رمز اشتباه) نباید صفحه را رفرش کند
const api = SP.mkApi('panel_api.php', 'sp_csrf', { noReload: true, csrfFallback: <?= json_encode($csrf) ?> });
const err = document.getElementById('login-error');
const showErr = m => { err.textContent = m; err.hidden = false; };

async function doLogin() {
    const u = document.getElementById('login-user').value.trim();
    const p = document.getElementById('login-pass').value;
    if (!u || !p) return showErr('نام کاربری و رمز عبور را وارد کنید');
    const btn = document.getElementById('login-btn'); btn.disabled = true;
    const r = await api('login', { body: { username: u, password: p } });
    btn.disabled = false;
    if (r.ok) location.reload(); else showErr(SP.errText(r));
}
document.getElementById('login-btn').onclick = doLogin;
['login-user', 'login-pass'].forEach(id => document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); }));

<?php if ($prefillToken !== ''): ?>
// ورود با لینک یک‌بارمصرف
(async () => {
    history.replaceState(null, '', location.pathname);   // توکن از آدرس پاک شود
    const r = await api('token_login', { body: { token: <?= json_encode($prefillToken) ?> } });
    if (r.ok) location.replace('panel.php');
    else showErr('لینک ورود منقضی شده است. لطفاً با رمز وارد شوید.');
})();
<?php endif; ?>
})();
</script>

<?php else: ?>
<!-- ======================= APP ======================= -->
<header class="app-header">
    <div class="header-brand">
        <button class="menu-btn" id="settings-btn" aria-label="Settings"><i class="fa-solid fa-gear"></i></button>
        <span>SPIDER Panel</span>
    </div>
    <div class="header-actions">
        <button class="menu-btn" id="theme-btn" aria-label="Theme"></button>
        <button class="menu-btn" id="logout-btn" style="color:var(--red)" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></button>
    </div>
</header>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Users</h1>
            <p class="page-subtitle"><?= $product ? htmlspecialchars((string)$product['name'], ENT_QUOTES, 'UTF-8') . ' · ' : '' ?>Control, update and arrange user accounts</p>
        </div>
        <button class="btn-create" id="btn-create"><i class="fa-solid fa-plus"></i> Create User</button>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="label"><span class="stat-dot"></span> Online Users</div><div class="value" id="stat-online">-</div></div>
        <div class="stat-card"><div class="label"><i class="fa-solid fa-user-check"></i> Active Users</div><div class="value" id="stat-active">-</div></div>
        <div class="stat-card"><div class="label"><i class="fa-solid fa-users"></i> Users</div><div class="value" id="stat-total">-</div></div>
    </div>

    <div class="card" id="quota-card" hidden></div>

    <div id="keys-root"></div>
</main>

<script src="spider.js?v=<?= $v ?>"></script>
<script>
(function () {
'use strict';
const $ = s => document.querySelector(s);
const esc = SP.esc;
const api = SP.mkApi('panel_api.php', 'sp_csrf', { csrfFallback: <?= json_encode($csrf) ?> });
let ME = null, kl = null;

SP.initTheme($('#theme-btn'));

$('#logout-btn').onclick = async () => { await api('logout', { body: {} }); location.reload(); };

// ---------- stats / quota ----------
async function loadStats() {
    const r = await api('stats');
    if (!r.ok) return;
    $('#stat-online').textContent = r.stats.online;
    $('#stat-active').textContent = r.stats.active;
    $('#stat-total').textContent = r.stats.total;
}
const days = s => s ? Math.floor(s / 86400) : 0;
function renderQuota() {
    const q = ME.reseller, box = $('#quota-card');
    if (!q.quota_max_keys && !q.quota_max_users && !q.quota_max_duration) { box.hidden = true; return; }
    const pct = q.quota_max_keys ? Math.min(100, Math.round(q.used_keys / q.quota_max_keys * 100)) : 0;
    box.hidden = false;
    box.innerHTML = `<div class="card-row"><div class="card-title"><i class="fa-solid fa-gauge-high"></i> Your quota</div></div>
        ${q.quota_max_keys ? `<div class="card-sub" style="margin-bottom:6px">Keys: <b>${q.used_keys}</b> of <b>${q.quota_max_keys}</b> used</div>
            <div class="progress-bar-bg" style="margin:0"><div class="progress-bar-fill ${pct >= 100 ? 'full' : pct >= 80 ? 'warn' : ''}" style="width:${pct}%"></div></div>` : ''}
        <div class="card-sub">${q.quota_max_users ? `Max devices per key: <b>${q.quota_max_users}</b> · ` : ''}${q.quota_max_duration ? `Max duration per key: <b>${days(q.quota_max_duration)} days</b>` : ''}</div>`;
}
async function refreshMe() {
    const r = await api('me');
    if (r.ok) { ME = r; renderQuota(); }
}

// ---------- create ----------
$('#btn-create').onclick = () => {
    const q = ME.reseller;
    let maxCount = 50;
    if (q.quota_max_keys) {
        const left = q.quota_max_keys - q.used_keys;
        if (left <= 0) return SP.fail('سهمیه کلید شما تمام شده است');
        maxCount = Math.min(50, left);
    }
    SP.createKeyDialog({
        api, limits: { maxUsers: q.quota_max_users || 0, maxDays: days(q.quota_max_duration), maxCount },
        onDone: () => { kl.load(); loadStats(); refreshMe(); },
    });
};

// ---------- settings ----------
$('#settings-btn').onclick = () => {
    const q = ME.reseller, p = ME.product;
    const d = SP.dialog('Settings', `
        <div class="form-group"><label>Current Password ${q.has_password ? '' : '(none set — leave blank)'}</label><input type="password" id="pwd-old" placeholder="Current Password" autocomplete="current-password"></div>
        <div class="form-group"><label>New Password (min 6 chars)</label><input type="password" id="pwd-new" placeholder="New Password" autocomplete="new-password"></div>
        <button class="btn-submit" id="pwd-save">Save Password</button>
        <hr style="border:0;border-top:1px solid var(--border);margin:20px 0">
        <div class="kv" style="margin:0">
            <div class="k">Name</div><div class="v">${esc(q.display_name)}</div>
            <div class="k">Product</div><div class="v">${esc(p ? p.name : '—')}</div>
            <div class="k">Username</div><div class="v">${esc(q.telegram_id)}</div>
            <div class="k">Keys created</div><div class="v">${q.used_keys}${q.quota_max_keys ? ' / ' + q.quota_max_keys : ''}</div>
        </div>`);
    d.$('#pwd-save').onclick = async () => {
        const old = d.$('#pwd-old').value, nw = d.$('#pwd-new').value;
        if (nw.length < 6) return SP.fail('رمز جدید باید حداقل ۶ کاراکتر باشد');
        const b = d.$('#pwd-save'); b.disabled = true;
        const r = await api('change_password', { body: { old, new: nw } });
        b.disabled = false;
        if (!r.ok) return SP.fail(r);
        SP.ok('Password changed'); d.close(); refreshMe();
    };
};

// ---------- boot ----------
(async function boot() {
    const r = await api('me');
    if (!r.ok) return;                       // 401 → خودکار رفرش و نمایش صفحه ورود
    ME = r;
    const perms = ME.reseller.perms, q = ME.reseller;
    if (!perms.can_create_keys) $('#btn-create').hidden = true;
    renderQuota();
    kl = new SP.KeyList({
        root: '#keys-root', api, limit: 10,
        maxRenewDays: days(q.quota_max_duration),
        can: { renew: perms.can_renew_keys, ban: perms.can_ban_keys, reset: perms.can_reset_devices, delete: perms.can_delete_keys, edit: 1 },
        onChange: () => { loadStats(); refreshMe(); },
    });
    loadStats();
    setInterval(loadStats, 60000);           // آمار آنلاین هر ۶۰ ثانیه تازه می‌شود
})();
})();
</script>
<?php endif; ?>
</body>
</html>
