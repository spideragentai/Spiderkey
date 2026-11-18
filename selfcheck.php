<?php
declare(strict_types=1);
// selfcheck.php — بررسی سلامت نصب بدون ترمینال.
// باز کردن: https://دامنه-شما/selfcheck.php?token=WEBHOOK_SECRET
// ⚠️ بعد از اتمام کار این فایل را از هاست پاک کنید.
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');
if (WEBHOOK_SECRET === '' || !hash_equals((string)WEBHOOK_SECRET, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('Forbidden');
}

$rows = [];
function row(string $name, bool $ok, string $detail = ''): void {
    global $rows; $rows[] = [$name, $ok, $detail];
}

// --- محیط ---
row('PHP ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
foreach (['pdo_sqlite', 'openssl', 'curl', 'mbstring', 'json'] as $ext) row("extension: $ext", extension_loaded($ext));
$dir = dirname(DB_PATH);
row('پوشه‌ی database قابل نوشتن', is_dir($dir) ? is_writable($dir) : @mkdir($dir, 0750, true), $dir);
try {
    $v = (string)db()->query('select sqlite_version()')->fetchColumn();
    row('SQLite ≥ 3.27 (برای بکاپ VACUUM INTO)', version_compare($v, '3.27.0', '>='), $v);
    row('نسخه‌ی اسکیما', (int)db()->query('PRAGMA user_version')->fetchColumn() >= SCHEMA_VERSION, (string)db()->query('PRAGMA user_version')->fetchColumn());
} catch (Throwable $e) { row('اتصال دیتابیس', false, $e->getMessage()); }

// --- تنظیمات ---
row('config.local.php موجود است', is_file(__DIR__ . '/config.local.php'));
row('BOT_TOKEN تنظیم شده', BOT_TOKEN !== '');
row('ADMIN_IDS تنظیم شده', count(ADMIN_IDS) > 0);
row('ADMIN_PANEL_PASSWORD تنظیم شده', ADMIN_PANEL_PASSWORD !== '');
row('API_SECRET ≥ 32 کاراکتر', strlen((string)API_SECRET) >= 32);

// --- سینتکس همه‌ی فایل‌های PHP (جایگزین php -l) ---
$files = array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/api/*.php') ?: []);
foreach ($files as $f) {
    $name = str_replace(__DIR__ . '/', '', $f);
    try {
        token_get_all((string)file_get_contents($f), TOKEN_PARSE);
        row("syntax: $name", true);
    } catch (Throwable $e) {
        row("syntax: $name", false, $e->getMessage() . ' (خط ' . $e->getLine() . ')');
    }
}

// --- تلگرام ---
if (BOT_TOKEN !== '') {
    require_once __DIR__ . '/tg.php';
    $me = tg('getMe');
    row('اتصال به API تلگرام', tg_ok($me), tg_ok($me) ? '@' . ($me['result']['username'] ?? '?') : 'توکن اشتباه است یا هاست به api.telegram.org دسترسی ندارد');
}

$bad = count(array_filter($rows, fn($r) => !$r[1]));
echo '<!doctype html><meta charset="utf-8"><title>Self-check</title><body style="font-family:system-ui;max-width:760px;margin:30px auto;padding:0 16px">';
echo '<h2>' . ($bad ? "❌ $bad مورد مشکل دارد" : '✅ همه‌چیز سالم است') . '</h2><table cellpadding="8" style="border-collapse:collapse;width:100%">';
foreach ($rows as [$n, $ok, $d]) {
    echo '<tr style="border-top:1px solid #ddd"><td>' . ($ok ? '✅' : '❌') . '</td><td>' . h($n) . '</td><td style="color:#666;font-size:13px">' . h($d) . '</td></tr>';
}
echo '</table><p style="color:#b45309">⚠️ بعد از بررسی، selfcheck.php را از هاست حذف کنید.</p>';
