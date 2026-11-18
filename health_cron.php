<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/tg.php';   // قبلاً نبود و tg_send خطای fatal می‌داد

// Cron: */30 * * * * php /home/USER/public_html/health_cron.php
if (PHP_SAPI !== 'cli') {
    $t = (string)($_GET['token'] ?? '');
    if (WEBHOOK_SECRET === '' || !hash_equals((string)WEBHOOK_SECRET, $t)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $h = system_health();
    $alerts = [];
    if (!$h['webhook_ok']) $alerts[] = '❌ Webhook: ' . h(mb_substr((string)$h['webhook_error'], 0, 150));
    if ($h['errors_24h'] > 20) $alerts[] = "❗ خطاهای ۲۴س: {$h['errors_24h']}";
    if ($h['pending_updates'] > 50) $alerts[] = "📬 Pending updates: {$h['pending_updates']}";
    if ($h['db_size'] > 30 * 1024 * 1024) $alerts[] = '💾 DB size: ' . round($h['db_size'] / 1024 / 1024, 1) . ' MB';
    $free = @disk_free_space(__DIR__);
    if ($free !== false && $free < 200 * 1024 * 1024) $alerts[] = '💽 فضای دیسک کم است: ' . round($free / 1048576) . ' MB';

    // نمایندگانی که سهمیه‌شان رو به اتمام است (≥ ۹۰٪)
    foreach (db()->query("SELECT display_name, used_keys, quota_max_keys FROM resellers WHERE status='active' AND quota_max_keys IS NOT NULL AND quota_max_keys>0 AND used_keys*10 >= quota_max_keys*9")->fetchAll() as $r) {
        $alerts[] = '📉 سهمیه‌ی ' . h($r['display_name']) . ": {$r['used_keys']}/{$r['quota_max_keys']}";
    }

    // خلاصه‌ی روزانه‌ی کلیدهای رو‌به‌انقضا (روزی یک‌بار)
    $digest = '';
    if ((int)setting_get('last_expiry_digest', '0') < now() - 86400) {
        $soon = key_expiring(1, 50);
        if ($soon) $digest = "\n\n⏰ <b>" . count($soon) . " کلید تا ۲۴ ساعت آینده منقضی می‌شوند</b>";
        setting_set('last_expiry_digest', (string)now());
    }

    if ($alerts || $digest) {
        $msg = "🚨 <b>گزارش سلامت SPIDER</b>\n\n" . ($alerts ? implode("\n", $alerts) : '✅ مشکلی نیست') . $digest . "\n\n⏰ " . date('Y-m-d H:i');
        tg_notify_admins($msg, $digest ? [[btn('⏰ مشاهده', 'sup:expiring:1', 'primary')]] : null);
        if ($alerts) log_msg('warn', 'health_alert', ['alerts' => $alerts]);
    }
    echo "✅ Health check done\n";
} catch (Throwable $e) {
    log_msg('error', 'health_cron', ['err' => $e->getMessage()]);
    echo "❌ Error\n";
}
