<?php
declare(strict_types=1);
/**
 * tg.php — توابع تلگرام (مشترک بین bot.php ، health_cron.php ، admin_api.php و …)
 * قبلاً این توابع فقط داخل bot.php بودند و health_cron.php به‌خاطر نبودنشان خطا می‌داد.
 */
require_once __DIR__ . '/config.php';

if (!function_exists('tg')) {

/** فراخوانی متد Bot API. آرایه‌ها خودکار JSON می‌شوند. در خطای 429 یک‌بار صبر و تکرار می‌کند. */
function tg(string $method, array $p = [], int $timeout = 15): ?array {
    $clean = [];
    foreach ($p as $k => $v) {
        if ($v === null) continue;
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        $clean[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . "/$method");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($clean),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $r = curl_exec($ch);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($r === false || $r === '') {
            log_msg('error', 'telegram_curl', ['m' => $method, 'err' => $cerr]);
            return null;
        }
        $d = json_decode((string)$r, true);
        if (!is_array($d)) return null;
        if (($d['ok'] ?? true) === false) {
            $retry = (int)($d['parameters']['retry_after'] ?? 0);
            if ((int)($d['error_code'] ?? 0) === 429 && $retry > 0 && $retry <= 5 && $attempt === 0) {
                sleep($retry);
                continue;
            }
            $desc = (string)($d['description'] ?? '');
            // خطاهای بی‌ضرر نباید لاگ error بسازند
            if (!str_contains($desc, 'not modified') && !str_contains($desc, 'bot was blocked') && !str_contains($desc, 'chat not found')) {
                log_msg('error', 'telegram_api', ['m' => $method, 'r' => $d]);
            }
        }
        return $d;
    }
    return null;
}

function tg_ok(?array $r): bool { return is_array($r) && ($r['ok'] ?? false) === true; }

/** متن‌های بلندتر از حد تلگرام (۴۰۹۶) به چند پیام شکسته می‌شوند؛ کیبورد فقط روی آخرین پیام می‌آید. */
function tg_send(int $c, string $t, ?array $kb = null): ?array {
    $chunks = [];
    while (mb_strlen($t) > 3900) {
        $cut = mb_strrpos(mb_substr($t, 0, 3900), "\n");
        if ($cut === false || $cut < 1500) $cut = 3900;
        $chunks[] = mb_substr($t, 0, $cut);
        $t = mb_substr($t, $cut);
    }
    $chunks[] = $t;
    $res = null;
    foreach ($chunks as $i => $part) {
        $p = ['chat_id' => $c, 'text' => $part, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($kb !== null && $i === count($chunks) - 1) $p['reply_markup'] = json_encode(['inline_keyboard' => $kb], JSON_UNESCAPED_UNICODE);
        $res = tg('sendMessage', $p);
    }
    return $res;
}

/** ویرایش پیام؛ اگر پیام قابل ویرایش نبود (قدیمی/حذف‌شده) پیام جدید می‌فرستد. */
function tg_edit(int $c, int $m, string $t, ?array $kb = null): void {
    if ($m <= 0) { tg_send($c, $t, $kb); return; }
    if (mb_strlen($t) > 4000) $t = mb_substr($t, 0, 3990) . "\n…";
    $p = ['chat_id' => $c, 'message_id' => $m, 'text' => $t, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb !== null) $p['reply_markup'] = json_encode(['inline_keyboard' => $kb], JSON_UNESCAPED_UNICODE);
    $r = tg('editMessageText', $p);
    if (is_array($r) && ($r['ok'] ?? true) === false) {
        $desc = (string)($r['description'] ?? '');
        if (str_contains($desc, 'not modified')) return;
        tg_send($c, $t, $kb);
    }
}

function tg_document(int $c, string $fn, string $content, string $cap = ''): bool {
    $tmp = tempnam(sys_get_temp_dir(), 'sky_');
    file_put_contents($tmp, $content);
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $c,
            'caption' => mb_substr($cap, 0, 1000),
            'parse_mode' => 'HTML',
            'document' => new CURLFile($tmp, 'application/octet-stream', $fn),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    @unlink($tmp);
    $d = is_string($r) ? json_decode($r, true) : null;
    if (!is_array($d) || !($d['ok'] ?? false)) {
        log_msg('error', 'telegram_document', ['fn' => $fn, 'r' => $d]);
        return false;
    }
    return true;
}

function tg_answer(string $cb, string $t = '', bool $alert = false): void {
    tg('answerCallbackQuery', ['callback_query_id' => $cb, 'text' => mb_substr($t, 0, 190), 'show_alert' => $alert]);
}

/** ارسال پیام به همه‌ی ادمین‌ها */
function tg_notify_admins(string $html, ?array $kb = null): void {
    foreach (ADMIN_IDS as $id) tg_send((int)$id, $html, $kb);
}

/** ساخت دکمه. callback_data حداکثر ۶۴ بایت است؛ بیشتر از آن کل کیبورد رد می‌شود. */
function btn(string $t, string $d, string $s = 'primary', ?string $url = null): array {
    $b = ['text' => $t];
    if ($url !== null && $url !== '') {
        $b['url'] = $url;
    } else {
        if (strlen($d) > 64) {
            log_msg('warn', 'callback_data_too_long', ['d' => $d]);
            $d = substr($d, 0, 64);
        }
        $b['callback_data'] = $d;
        if (STYLE_BUTTONS) {
            if (!in_array($s, ['primary', 'success', 'danger'], true)) $s = 'primary';
            $b['style'] = $s;
        }
    }
    return $b;
}

/** ثبت webhook و دستورات ربات */
function tg_setup_webhook(string $url): array {
    $res = tg('setWebhook', [
        'url' => $url,
        'secret_token' => WEBHOOK_SECRET,
        'allowed_updates' => ['message', 'callback_query'],
        'drop_pending_updates' => true,
    ]);
    $cmds = [
        ['command' => 'start',   'description' => '🏠 منوی اصلی'],
        ['command' => 'stats',   'description' => '📊 آمار کامل'],
        ['command' => 'online',  'description' => '🟢 کاربران آنلاین'],
        ['command' => 'expiring','description' => '⏰ کلیدهای رو‌به‌انقضا'],
        ['command' => 'health',  'description' => '💚 سلامت سیستم'],
        ['command' => 'backup',  'description' => '💾 بکاپ فوری'],
        ['command' => 'audit',   'description' => '📜 Audit Log'],
        ['command' => 'key',     'description' => '🔍 استعلام کلید / آیدی'],
        ['command' => 'help',    'description' => 'ℹ️ راهنما'],
        ['command' => 'cancel',  'description' => '❌ لغو عملیات'],
    ];
    $cm = tg('setMyCommands', ['commands' => $cmds]);
    return ['webhook' => $url, 'telegram' => $res, 'commands' => $cm];
}

} // function_exists
