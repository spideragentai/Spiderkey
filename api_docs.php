<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';

$slug = trim((string)($_GET['product'] ?? ''));
$product = $slug ? product_by_slug($slug) : null;

if (!$product) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>API Docs — Not Found</title><body style="background:#05050a;color:#e0e0e0;font-family:system-ui;padding:40px;text-align:center"><h1>❌ محصول یافت نشد</h1><p style="color:#888">اسلاگ محصول در URL نامعتبر است.</p></body>';
    exit;
}

$base = base_url();
$dedicatedApi = "{$base}/{$product['slug']}/api.php";
$genericApi   = "{$base}/api.php?product={$product['slug']}";
// کلید API محصول در صفحه‌ی عمومی نمایش داده نمی‌شود (قبلاً برای هر بازدیدکننده‌ای لو می‌رفت)
$apiKeyPlaceholder = 'YOUR_PRODUCT_API_KEY';
$keyExample   = strtoupper(substr(preg_replace('/[^A-Z0-9]/i','',$product['slug']),0,6)) . '-XXXX-XXXX-XXXX';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>API Docs — <?= h($product['name']) ?></title>
<style>
:root{--bg:#05050a;--card:rgba(255,255,255,.04);--border:rgba(30,136,229,.35);--pri:#1e88e5;--pri2:#b388ff;--txt:#e0e0e0;--mut:#888}
*{box-sizing:border-box;margin:0;padding:0}
body{background:radial-gradient(circle at top left,#141428,#05050a 70%);color:var(--txt);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;line-height:1.7;padding:40px 20px}
.wrap{max-width:900px;margin:0 auto}
.card{background:var(--card);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:18px;padding:28px;margin-bottom:20px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
h1{font-size:26px;background:linear-gradient(90deg,var(--pri),var(--pri2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px}
h2{font-size:18px;color:#4fc3f7;margin:20px 0 10px}
h3{font-size:15px;color:var(--pri2);margin:15px 0 8px}
p{color:#aaa;margin-bottom:10px}
.url-box{background:#000;padding:14px;border-radius:10px;font-family:'Courier New',monospace;font-size:13px;color:#4fc3f7;word-break:break-all;border:1px solid var(--pri);margin:10px 0}
pre{background:#0a0a14;padding:16px;border-radius:10px;overflow-x:auto;font-size:13px;border:1px solid #222;margin:10px 0}
code{font-family:'Courier New',monospace;color:#4fc3f7}
.method{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;margin-left:6px}
.post{background:rgba(76,175,80,.2);color:#66bb6a;border:1px solid rgba(76,175,80,.4)}
.badge{display:inline-block;padding:3px 10px;border-radius:8px;font-size:12px;font-weight:600;background:rgba(76,175,80,.15);color:#66bb6a;border:1px solid rgba(76,175,80,.4)}
table{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px}
th,td{text-align:right;padding:8px 10px;border-bottom:1px solid #222}
th{color:var(--mut);font-weight:600;font-size:12px}
.note{background:rgba(255,179,0,.1);border:1px solid rgba(255,179,0,.4);border-radius:10px;padding:14px;margin:14px 0;color:#ffc107;font-size:13px}
</style>
</head>
<body>
<div class="wrap">

<div class="card">
  <h1>🕷 مستندات API — <?= h($product['name']) ?></h1>
  <p>این صفحه راهنمای اتصال مود منو به سیستم لایسنس SPIDER است.</p>
  <p><span class="badge">محصول: <?= h($product['slug']) ?></span></p>
</div>

<div class="card">
  <h2>🌐 آدرس‌های API</h2>
  <p><strong>آدرس اختصاصی (توصیه شده):</strong></p>
  <div class="url-box"><?= h($dedicatedApi) ?></div>
  <p><strong>آدرس عمومی:</strong></p>
  <div class="url-box"><?= h($genericApi) ?></div>
  <div class="note">⚠️ همیشه از HTTPS استفاده کنید. تمام درخواست‌ها باید POST با بدنه JSON باشند.</div>
</div>

<div class="card">
  <h2>🔐 احراز هویت</h2>
  <p>در صورت استفاده از آدرس عمومی، هدر <code>X-API-Key</code> الزامی است:</p>
  <div class="url-box">X-API-Key: <?= $apiKeyPlaceholder ?></div>
  <p>با آدرس اختصاصی، نیازی به هدر نیست (سیستم خودکار محصول را تشخیص می‌دهد). کلید API را از پنل ادمین → Products بردارید.</p>
</div>

<div class="card">
  <h2>📤 فعال‌سازی اولیه</h2>
  <p><span class="method post">POST</span> <code>?action=activate</code></p>
  <pre><code>curl -X POST "<?= h($dedicatedApi) ?>?action=activate" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <?= $apiKeyPlaceholder ?>" \
  -d '{
    "license_key": "<?= $keyExample ?>",
    "device_id": "550e8400-e29b-41d4-a716-446655440000",
    "public_key": "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...",
    "device_model": "Samsung Galaxy S23",
    "android_version": "14",
    "app_version": "1.0.0"
  }'</code></pre>
  <h3>پاسخ موفق:</h3>
  <pre><code>{
  "success": true,
  "status": "active",
  "message": "Login successful",
  "license": "<?= substr($keyExample,0,10) ?>-****-****",
  "expires_at": 1790000000,
  "remaining_seconds": 604800,
  "session_id": "sess_...",
  "access_token": "tok_...",
  "session_expires_at": 1790000000,
  "current_users": 1,
  "max_users": 1
}</code></pre>
  <h3>پاسخ خطا:</h3>
  <pre><code>{
  "success": false,
  "error": "INVALID_KEY",
  "message": "Invalid license key for this product"
}</code></pre>
</div>

<div class="card">
  <h2>🔄 Endpointها</h2>
  <table>
    <tr><th>Endpoint</th><th>توضیح</th><th>Auth</th></tr>
    <tr><td><code>?action=activate</code></td><td>فعال‌سازی اولیه</td><td>کلید</td></tr>
    <tr><td><code>?action=validate</code></td><td>اعتبارسنجی نشست</td><td>✅ token</td></tr>
    <tr><td><code>?action=heartbeat</code></td><td>بررسی دوره‌ای</td><td>✅ token</td></tr>
    <tr><td><code>?action=status</code></td><td>اطلاعات لایسنس</td><td>✅ token</td></tr>
    <tr><td><code>?action=logout</code></td><td>خروج</td><td>✅ token</td></tr>
    <tr><td><code>?action=device_info</code></td><td>اطلاعات دستگاه</td><td>✅ token</td></tr>
    <tr><td><code>?action=reset_request</code></td><td>درخواست ریست</td><td>✅ token</td></tr>
  </table>
</div>

<div class="card">
  <h2>🔐 درخواست‌های Authenticated</h2>
  <p>پس از فعال‌سازی، در هر درخواست این فیلدها را ارسال کنید:</p>
  <pre><code>{
  "access_token": "tok_...",
  "device_id": "550e8400-...",
  "timestamp": 1790000000,
  "nonce": "a1b2c3d4e5f6...",
  "signature": "base64_rsa_signature"
}</code></pre>
  <p>امضای <code>signature</code> با کلید خصوصی دستگاه روی این رشته:</p>
  <pre><code>device_id|timestamp|nonce|action</code></pre>
</div>

<div class="card">
  <h2>❌ کدهای خطا</h2>
  <table>
    <tr><th>کد</th><th>معنی</th></tr>
    <tr><td><code>INVALID_KEY</code></td><td>کلید نامعتبر</td></tr>
    <tr><td><code>EXPIRED_KEY</code></td><td>کلید منقضی</td></tr>
    <tr><td><code>BANNED_KEY</code></td><td>کلید مسدود</td></tr>
    <tr><td><code>DEVICE_MISMATCH</code></td><td>دستگاه متفاوت</td></tr>
    <tr><td><code>USERS_EXCEEDED</code></td><td>ظرفیت پر</td></tr>
    <tr><td><code>PRODUCT_MISMATCH</code></td><td>کلید متعلق به محصول دیگر</td></tr>
    <tr><td><code>RATE_LIMIT</code></td><td>درخواست زیاد</td></tr>
    <tr><td><code>INVALID_SESSION</code></td><td>نشست نامعتبر</td></tr>
    <tr><td><code>SESSION_EXPIRED</code></td><td>نشست منقضی؛ دوباره activate کنید</td></tr>
    <tr><td><code>BAD_SIGNATURE</code></td><td>امضای نامعتبر</td></tr>
    <tr><td><code>NONCE_REUSED</code></td><td>nonce تکراری</td></tr>
    <tr><td><code>TIMESTAMP_INVALID</code></td><td>اختلاف ساعت بیش از ±۵ دقیقه</td></tr>
  </table>
</div>

<div class="card" style="text-align:center;color:#555;font-size:12px">
  🕷 SPIDER KEY SYSTEM v<?= SKY_VERSION ?> — <?= h($product['name']) ?>
</div>
</div>
</body>
</html>