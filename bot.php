<?php
declare(strict_types=1);
/**
 * SPIDER KEY SYSTEM — Telegram Admin Bot (v12)
 *  - فقط ادمین‌های ADMIN_IDS
 *  - همه‌ی زمینه‌ی (context) ساخت کلید داخل callback_data می‌آید (دیگر «آخرین pending سراسری» استفاده نمی‌شود)
 *  - همه‌ی متن‌های پویا escape می‌شوند (نام محصول با < یا & دیگر پیام را خراب نمی‌کند)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/tg.php';

// ============ Webhook setup ============
if (isset($_GET['setup'])) {
    if (!hash_equals((string)WEBHOOK_SECRET, (string)$_GET['setup'])) { http_response_code(403); exit('Forbidden'); }
    json_response(tg_setup_webhook(base_url() . '/bot.php'));
}

// ============ Security ============
$secretHeader = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (WEBHOOK_SECRET === '' || !hash_equals((string)WEBHOOK_SECRET, $secretHeader)) { http_response_code(403); exit('Forbidden'); }

$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) { http_response_code(200); exit('ok'); }

$updateId = (int)($update['update_id'] ?? 0);
if ($updateId) {
    $st = db()->prepare('INSERT OR IGNORE INTO bot_processed_updates(update_id, processed_at) VALUES(?,?)');
    $st->execute([$updateId, now()]);
    if ($st->rowCount() === 0) { http_response_code(200); exit('ok'); }
}

http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
@set_time_limit(120);

// ============ Router (ADMIN ONLY) ============
$routeChat = 0;
try {
    if (isset($update['message'])) {
        $m = $update['message'];
        $from = $m['from'] ?? [];
        if (!is_super_admin($from)) exit;
        $routeChat = (int)($m['chat']['id'] ?? 0);
        handle_message($routeChat, trim((string)($m['text'] ?? '')), $from);
    } elseif (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $from = $cb['from'] ?? [];
        if (!is_super_admin($from)) {
            if (!empty($cb['id'])) tg_answer((string)$cb['id'], '🚫 دسترسی ندارید', true);
            exit;
        }
        if (!empty($cb['id'])) tg_answer((string)$cb['id']);
        $routeChat = (int)($cb['message']['chat']['id'] ?? 0);
        handle_callback($routeChat, (int)($cb['message']['message_id'] ?? 0), (string)($cb['data'] ?? ''), $from);
    }
} catch (Throwable $e) {
    log_msg('error', 'bot_exception', ['err' => $e->getMessage(), 'line' => $e->getLine(), 'file' => basename($e->getFile())]);
    if ($routeChat) tg_send($routeChat, "⚠️ خطای داخلی: <code>" . h(mb_substr($e->getMessage(), 0, 200)) . "</code>", [[btn('🏠 منو', 'sup:main', 'primary')]]);
}

// ============================================================
// HELPERS
// ============================================================
function is_super_admin(array $u): bool { return in_array((int)($u['id'] ?? 0), ADMIN_IDS, true); }

function admin_name(array $u): string {
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n !== '' ? $n : ('@' . ($u['username'] ?? (string)($u['id'] ?? '?')));
}
function audit(array $u, string $action, string $type = '', ?int $id = null, array $details = []): void {
    audit_log((int)($u['id'] ?? 0), admin_name($u), $action, $type, $id, $details);
}
/** اگر $mid>0 پیام قبلی ویرایش می‌شود، وگرنه پیام جدید */
function reply(int $chat, int $mid, string $txt, array $kb): void {
    if ($mid > 0) tg_edit($chat, $mid, $txt, $kb); else tg_send($chat, $txt, $kb);
}
function back_row(string $to = 'sup:main', string $label = '🔙 بازگشت'): array { return [btn($label, $to, 'danger')]; }
function cancel_kb(string $to = 'nav:cancel'): array { return [[btn('❌ لغو', $to, 'danger')]]; }

// ---------- State (مکالمه‌ی چندمرحله‌ای) ----------
function state_get(int $c): ?array {
    $s = db()->prepare('SELECT * FROM bot_states WHERE chat_id=?'); $s->execute([$c]); $r = $s->fetch();
    if (!$r || (int)$r['updated_at'] < now() - 1800) { if ($r) state_clear($c); return null; }
    $r['d'] = json_decode((string)$r['data'], true) ?: [];
    return $r;
}
function state_set(int $c, string $st, $d = null): void {
    db()->prepare('INSERT INTO bot_states(chat_id,state,data,updated_at) VALUES(?,?,?,?)
        ON CONFLICT(chat_id) DO UPDATE SET state=excluded.state,data=excluded.data,updated_at=excluded.updated_at')
        ->execute([$c, $st, is_string($d) ? $d : json_encode($d ?? []), now()]);
}
function state_clear(int $c): void { db()->prepare('DELETE FROM bot_states WHERE chat_id=?')->execute([$c]); }

// ---------- Pending (کانتکست کوتاه‌مدت در callback) ----------
function pend_get(string $id, int $chat): ?array { $r = pending_get($id, $chat); return $r ? ($r['payload'] ?: []) : null; }
function pend_set(string $id, array $payload): void {
    db()->prepare('UPDATE pending_actions SET payload=? WHERE id=?')->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
}

// ---------- Display ----------
function status_icon(array $lic): string {
    return match (compute_license_status($lic)) { 'active' => '🟢', 'unused' => '🟡', 'expired' => '⚫', 'banned' => '🔴', default => '❔' };
}
function status_label(array $lic): string {
    return match (compute_license_status($lic)) {
        'active' => '🟢 فعال', 'unused' => '🟡 استفاده‌نشده', 'expired' => '⚫ منقضی', 'banned' => '🔴 مسدود', default => '❔',
    };
}
function ago(int $ts): string {
    if ($ts <= 0) return '—';
    $d = max(0, now() - $ts);
    if ($d < 60) return 'همین الان';
    if ($d < 3600) return intdiv($d, 60) . ' دقیقه پیش';
    if ($d < 86400) return intdiv($d, 3600) . ' ساعت پیش';
    return intdiv($d, 86400) . ' روز پیش';
}
function fmt_bytes(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024, 1) . ' KB'; }
function key_line(array $lic): string {
    $raw = decrypt_key($lic['key_encrypted']);
    $note = !empty($lic['note']) ? ' • ' . mb_substr((string)$lic['note'], 0, 14) : '';
    return status_icon($lic) . ' ' . mask_key($raw) . ' • 👥' . count_license_devices((int)$lic['id']) . '/' . $lic['max_users'] . $note;
}
function product_name(int $pid): string { $p = product_get($pid); return $p ? (string)$p['name'] : '?'; }

// ============================================================
// MENUS
// ============================================================
function super_admin_menu(): array {
    return [
        [btn('📊 داشبورد', 'sup:dashboard'), btn('🏢 محصولات', 'sup:products')],
        [btn('👥 نمایندگان', 'sup:allresellers'), btn('🔑 کلیدها', 'sup:allkeys:all:0')],
        [btn('🔍 جستجو', 'sup:search', 'success'), btn('🟢 آنلاین', 'sup:online', 'success')],
        [btn('⏰ رو‌به‌انقضا', 'sup:expiring:3', 'success'), btn('📈 نمودار ۷ روزه', 'sup:chart7', 'success')],
        [btn('🛠 ابزارها', 'sup:advanced'), btn('💚 سلامت', 'sup:health')],
        [btn('📜 لاگ', 'sup:logs'), btn('🐞 Debug', 'sup:debug')],
    ];
}
function advanced_tools_menu(): array {
    return [
        [btn('📦 Bulk ساخت', 'sup:bulkstart', 'success'), btn('🧩 قالب‌ها', 'sup:templates', 'success')],
        [btn('📤 خروجی', 'sup:export'), btn('📢 پیام همگانی', 'sup:broadcast')],
        [btn('💾 بکاپ', 'sup:backup'), btn('📜 Audit', 'sup:audit')],
        [btn('🧹 پاکسازی', 'sup:cleanup'), btn('🔗 ثبت Webhook', 'sup:webhook')],
        [btn('🚨 Panic Lock', 'sup:panic', 'danger')],
        back_row(),
    ];
}
function duration_menu(string $pid): array {
    return [
        [btn('1h', "dur:3600:$pid"), btn('3h', "dur:10800:$pid"), btn('6h', "dur:21600:$pid")],
        [btn('12h', "dur:43200:$pid"), btn('24h', "dur:86400:$pid")],
        [btn('3d', "dur:259200:$pid", 'success'), btn('7d', "dur:604800:$pid", 'success')],
        [btn('15d', "dur:1296000:$pid", 'success'), btn('30d', "dur:2592000:$pid", 'success')],
        [btn('90d', "dur:7776000:$pid", 'success'), btn('♾', "dur:0:$pid", 'success')],
        [btn('✏️ دلخواه', "dur:custom:$pid"), btn('❌ لغو', 'nav:cancel', 'danger')],
    ];
}
function users_menu(string $pid): array {
    return [
        [btn('1', "usr:1:$pid"), btn('2', "usr:2:$pid"), btn('3', "usr:3:$pid", 'success')],
        [btn('5', "usr:5:$pid", 'success'), btn('10', "usr:10:$pid", 'success')],
        [btn('✏️ دلخواه', "usr:custom:$pid"), btn('❌ لغو', 'nav:cancel', 'danger')],
    ];
}
function bulk_qty_menu(string $pid): array {
    return [
        [btn('5', "bqty:5:$pid"), btn('10', "bqty:10:$pid"), btn('25', "bqty:25:$pid", 'success')],
        [btn('50', "bqty:50:$pid", 'success'), btn('100', "bqty:100:$pid", 'success')],
        [btn('✏️ دلخواه', "bqty:custom:$pid"), btn('❌ لغو', 'nav:cancel', 'danger')],
    ];
}

// ============================================================
// MESSAGE HANDLER
// ============================================================
function handle_message(int $chat, string $text, array $user): void {
    // /cmd@BotName arg → cmd , arg
    $cmd = ''; $arg = '';
    if (str_starts_with($text, '/')) {
        $parts = explode(' ', $text, 2);
        $cmd = strtolower(explode('@', substr($parts[0], 1))[0]);
        $arg = trim($parts[1] ?? '');
    }

    switch ($cmd) {
        case 'start':
            state_clear($chat);
            $s = stats_for_product(0); $h = system_health();
            $txt  = "🕷 <b>" . h(SKY_NAME) . " v" . h(SKY_VERSION) . "</b>\n\n👑 <b>Admin Console</b>\n\n";
            $txt .= "📊 محصولات: <b>" . max(0, count(product_list_all()) - 1) . "</b>\n";
            $txt .= "🔑 کلیدها: <b>{$s['keys_active']}</b> فعال / {$s['keys_total']}\n";
            $txt .= "🟢 آنلاین: <b>" . active_sessions_count() . "</b>\n";
            $txt .= "📱 دستگاه‌ها: <b>{$s['devices']}</b>\n";
            $txt .= "👥 نمایندگان: <b>{$s['resellers']}</b>\n\n";
            $txt .= $h['webhook_ok'] ? '✅ Webhook سالم' : '⚠️ Webhook مشکل دارد';
            tg_send($chat, $txt, super_admin_menu());
            audit($user, 'login_admin');
            return;
        case 'cancel': state_clear($chat); tg_send($chat, '✅ لغو شد.', super_admin_menu()); return;
        case 'help': send_help($chat); return;
        case 'stats': show_analytics($chat, 0); return;
        case 'online': show_online($chat, 0); return;
        case 'expiring': show_expiring($chat, 0, 3); return;
        case 'health': show_health($chat, 0); return;
        case 'backup': do_backup($chat, $user); return;
        case 'audit': show_audit($chat, 0, 0); return;
        case 'key':
        case 'status':
            if ($arg === '') { tg_send($chat, "📝 استفاده: <code>/key SPIDER-XXXX-XXXX-XXXX</code> یا شناسه‌ی عددی یا بخشی از یادداشت/دستگاه"); return; }
            perform_global_search($chat, $arg);
            return;
    }

    $st = state_get($chat);
    if (!$st) { tg_send($chat, 'برای شروع /start بزنید.', super_admin_menu()); return; }
    $d = $st['d'];

    switch ($st['state']) {
        case 'new_product_slug': {
            $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', strtolower($text)));
            if (strlen($slug) < 2) { tg_send($chat, '❌ اسلاگ باید حداقل ۲ کاراکتر (a-z، 0-9، -) باشد.', cancel_kb()); return; }
            if (product_by_slug($slug)) { tg_send($chat, '❌ این اسلاگ قبلاً استفاده شده.', cancel_kb()); return; }
            state_set($chat, 'new_product_name', ['slug' => $slug]);
            tg_send($chat, "✅ اسلاگ: <code>" . h($slug) . "</code>\n\n📝 نام نمایشی محصول را بفرستید (یا <code>-</code> برای استفاده از اسلاگ):", cancel_kb());
            return;
        }
        case 'new_product_name': {
            state_clear($chat);
            $slug = (string)($d['slug'] ?? '');
            $name = ($text === '-' || $text === '') ? $slug : mb_substr($text, 0, 80);
            $res = product_create($slug, $name, (int)$user['id']);
            if ($res['ok']) {
                audit($user, 'product_create', 'product', (int)$res['id']);
                tg_send($chat, "✅ محصول ساخته شد\n📦 <code>" . h($res['slug']) . "</code>\n🔐 <code>" . h($res['api_key']) . "</code>", [[btn('🏢 مدیریت', 'prd:menu:' . $res['id'])]]);
            } else tg_send($chat, '❌ ' . h($res['msg']), [back_row('sup:products')]);
            return;
        }
        case 'edit_product_name': {
            state_clear($chat);
            $pid = (int)($d['id'] ?? 0); $name = mb_substr(trim($text), 0, 80);
            if ($pid <= 0) return;
            if (mb_strlen($name) < 2) { tg_send($chat, '❌ نام خیلی کوتاه است.'); return; }
            db()->prepare('UPDATE products SET name=? WHERE id=?')->execute([$name, $pid]);
            audit($user, 'product_rename', 'product', $pid, ['name' => $name]);
            show_product_info($chat, 0, $pid, '✅ نام به‌روز شد');
            return;
        }
        case 'edit_domain': {
            state_clear($chat);
            $pid = (int)($d['id'] ?? 0);
            $dom = strtolower(trim($text));
            $dom = preg_replace('#^https?://#', '', $dom); $dom = rtrim((string)$dom, '/');
            if ($dom === '-' || $dom === '0') $dom = '';
            if ($dom !== '' && !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $dom)) { tg_send($chat, '❌ دامنه نامعتبر است.'); return; }
            product_set_domain($pid, $dom ?: null);
            audit($user, 'product_domain', 'product', $pid, ['domain' => $dom]);
            show_product_info($chat, 0, $pid, $dom ? '✅ دامنه ذخیره شد' : '✅ دامنه حذف شد');
            return;
        }
        case 'new_reseller_tg': {
            $tgId = (int)trim($text);
            if ($tgId < 100000) { tg_send($chat, '❌ آیدی عددی نامعتبر است.', cancel_kb()); return; }
            $d['tg'] = $tgId;
            state_set($chat, 'new_reseller_name', $d);
            tg_send($chat, '👤 نام نماینده را بفرستید:', cancel_kb());
            return;
        }
        case 'new_reseller_name': {
            $name = mb_substr(trim($text), 0, 80);
            if (mb_strlen($name) < 2) { tg_send($chat, '❌ نام خیلی کوتاه است.'); return; }
            $tgId = (int)($d['tg'] ?? 0); $pid = (int)($d['product_id'] ?? 0);
            if ($pid > 0) {
                state_clear($chat);
                create_reseller_flow($chat, 0, $user, $tgId, $name, $pid);
                return;
            }
            state_set($chat, 'new_reseller_product', ['tg' => $tgId, 'name' => $name]);
            $rows = [];
            foreach (product_list_all() as $pr) { if ((int)$pr['id'] === 1) continue; $rows[] = [btn('📦 ' . $pr['name'], 'selprod:' . $pr['id'])]; }
            $rows[] = [btn('❌ لغو', 'nav:cancel', 'danger')];
            tg_send($chat, '📦 محصول را انتخاب کنید:', $rows);
            return;
        }
        case 'custom_duration': {
            $sec = parse_duration($text);
            if ($sec === null) { tg_send($chat, '❌ فرمت نامعتبر. مثال: <code>3d</code> ، <code>12h</code> ، <code>90m</code> یا <code>0</code> برای نامحدود', cancel_kb()); return; }
            $pid = (string)($d['pid'] ?? ''); $ctx = pend_get($pid, $chat);
            state_clear($chat);
            if (!$ctx) { tg_send($chat, '❌ منقضی شد؛ از ابتدا شروع کنید.', [back_row()]); return; }
            $ctx['duration'] = $sec; pend_set($pid, $ctx);
            tg_send($chat, '✅ مدت: <b>' . h(format_duration($sec)) . "</b>\n\n👥 تعداد کاربر (دستگاه):", users_menu($pid));
            return;
        }
        case 'custom_users': {
            $u = (int)$text;
            if ($u < 1 || $u > 1000) { tg_send($chat, '❌ عددی بین ۱ تا ۱۰۰۰ بفرستید.', cancel_kb()); return; }
            $pid = (string)($d['pid'] ?? ''); $ctx = pend_get($pid, $chat);
            state_clear($chat);
            if (!$ctx) { tg_send($chat, '❌ منقضی شد؛ از ابتدا شروع کنید.', [back_row()]); return; }
            $ctx['max_users'] = $u; pend_set($pid, $ctx);
            if (!empty($ctx['bulk'])) { tg_send($chat, "👥 کاربر: <b>$u</b>\n\n📦 تعداد کلید:", bulk_qty_menu($pid)); return; }
            pending_delete($pid);
            finalize_key_creation($chat, 0, $ctx, $user);
            return;
        }
        case 'bulk_custom_qty': {
            $q = (int)$text;
            if ($q < 1 || $q > 100) { tg_send($chat, '❌ عددی بین ۱ تا ۱۰۰ بفرستید.', cancel_kb()); return; }
            $pid = (string)($d['pid'] ?? ''); $ctx = pend_get($pid, $chat);
            state_clear($chat);
            if (!$ctx) { tg_send($chat, '❌ منقضی شد؛ از ابتدا شروع کنید.', [back_row()]); return; }
            pending_delete($pid);
            finalize_bulk_creation($chat, 0, $ctx, $q, $user);
            return;
        }
        case 'edit_quota_value': {
            state_clear($chat);
            $resId = (int)($d['res_id'] ?? 0); $field = (string)($d['field'] ?? ''); $pid = (int)($d['product_id'] ?? 0);
            $val = trim(strtolower($text));
            if (in_array($val, ['unlimited', '0', '∞', 'نامحدود'], true)) $v = null;
            elseif ($field === 'quota_max_duration' && ($sec = parse_duration($val)) !== null) $v = $sec ?: null;
            elseif (ctype_digit($val)) $v = (int)$val;
            else { tg_send($chat, '❌ مقدار نامعتبر. عدد یا <code>unlimited</code> (برای مدت: <code>30d</code>)'); return; }
            reseller_update_quota($resId, $field, $v);
            audit($user, 'reseller_quota', 'reseller', $resId, [$field => $v]);
            show_reseller_detail($chat, 0, $resId, $pid, '✅ ذخیره شد');
            return;
        }
        case 'search_global': state_clear($chat); perform_global_search($chat, $text); return;
        case 'broadcast_message': {
            state_clear($chat);
            if (mb_strlen($text) < 3) { tg_send($chat, '❌ متن خیلی کوتاه است.'); return; }
            $pid = isset($d['product_id']) ? (int)$d['product_id'] : null;
            $targets = broadcast_targets($pid ?: null);
            $ids = array_unique(array_map(fn($r) => (int)$r['telegram_id'], $targets));
            $bid = pending_create($chat, 'broadcast', ['product_id' => $pid, 'text' => mb_substr($text, 0, 3000)]);
            tg_send($chat, "📢 <b>پیش‌نمایش</b>\n\n" . h($text) . "\n\n👥 گیرندگان: <b>" . count($ids) . '</b>', [
                [btn('✅ ارسال', "sup:bsend:$bid", 'success')],
                [btn('❌ لغو', 'sup:advanced', 'danger')],
            ]);
            return;
        }
        case 'panic_confirm': {
            state_clear($chat);
            if (strtoupper(trim($text)) !== 'CONFIRM') { tg_send($chat, '❌ لغو شد.', [back_row('sup:advanced')]); return; }
            $pid = isset($d['product_id']) ? (int)$d['product_id'] : null;
            $aff = panic_lock($pid ?: null);
            audit($user, 'panic_lock', 'product', $pid ?: null, ['affected' => $aff]);
            tg_send($chat, "🚨 <b>Panic Lock</b>\n\n🔴 $aff کلید مسدود شد", [[btn('🔙', 'sup:advanced')]]);
            return;
        }
        case 'edit_note': {
            state_clear($chat);
            $id = (int)($d['id'] ?? 0);
            key_set_note($id, $text === '-' ? '' : $text);
            audit($user, 'key_note', 'license', $id);
            show_key_panel($chat, 0, $id, $user, '✅ یادداشت ذخیره شد');
            return;
        }
        case 'renew_custom': {
            state_clear($chat);
            $id = (int)($d['id'] ?? 0);
            $sec = parse_duration($text);
            if (!$sec) { tg_send($chat, '❌ فرمت نامعتبر. مثال: <code>7d</code>'); return; }
            do_renew($chat, 0, $id, $sec, $user);
            return;
        }
        case 'key_users_custom': {
            state_clear($chat);
            $id = (int)($d['id'] ?? 0); $u = (int)$text;
            if ($u < 1 || $u > 1000) { tg_send($chat, '❌ عددی بین ۱ تا ۱۰۰۰'); return; }
            key_set_max_users($id, $u);
            audit($user, 'key_max_users', 'license', $id, ['max' => $u]);
            show_key_panel($chat, 0, $id, $user, '✅ ظرفیت ذخیره شد');
            return;
        }
        case 'tpl_name': {
            state_clear($chat);
            $r = template_save(mb_substr($text, 0, 40), (int)($d['product_id'] ?? 0) ?: null, (int)($d['duration'] ?? 0), (int)($d['max_users'] ?? 1), (int)$user['id']);
            tg_send($chat, $r['ok'] ? '✅ قالب ذخیره شد.' : '❌ ' . h($r['msg']), [[btn('🧩 قالب‌ها', 'sup:templates')]]);
            return;
        }
        case 'msg_reseller': {
            state_clear($chat);
            $res = reseller_get((int)($d['id'] ?? 0));
            if (!$res || mb_strlen($text) < 1) return;
            $ok = tg_ok(tg('sendMessage', ['chat_id' => $res['telegram_id'], 'text' => "📩 <b>پیام مدیریت</b>\n\n" . h($text), 'parse_mode' => 'HTML']));
            audit($user, 'reseller_message', 'reseller', (int)$res['id'], ['ok' => $ok]);
            tg_send($chat, $ok ? '✅ ارسال شد.' : '❌ ارسال نشد (کاربر ربات را استارت نکرده یا بلاک کرده).', [[btn('🔙', 'rsl:detail:' . $res['id'])]]);
            return;
        }
    }
    tg_send($chat, 'برای شروع /start بزنید.', super_admin_menu());
}

function send_help(int $chat): void {
    tg_send($chat,
        "🕷 <b>" . h(SKY_NAME) . ' v' . h(SKY_VERSION) . "</b>\n\n" .
        "📌 <b>دستورات:</b>\n" .
        "/start — منوی اصلی\n" .
        "/stats — آمار کامل\n" .
        "/online — کاربران آنلاین\n" .
        "/expiring — کلیدهای رو‌به‌انقضا\n" .
        "/health — سلامت سیستم\n" .
        "/backup — بکاپ فوری\n" .
        "/audit — Audit Log\n" .
        "/key &lt;کلید | شناسه | یادداشت | مدل دستگاه | IP&gt; — جستجو\n" .
        "/cancel — لغو عملیات",
        super_admin_menu());
}

// ============================================================
// CALLBACK ROUTER
// ============================================================
function handle_callback(int $chat, int $mid, string $data, array $user): void {
    $p = explode(':', $data);
    $cmd = $p[0] ?? '';
    try {
        switch ($cmd) {
            case 'sup': handle_super($chat, $mid, $p, $user); break;
            case 'prd': handle_product($chat, $mid, $p, $user); break;
            case 'rsl': handle_reseller_admin($chat, $mid, $p, $user); break;
            case 'rslnew': handle_rsl_new($chat, $mid, $p); break;
            case 'res': handle_reseller($chat, $mid, $p); break;
            case 'dur': handle_duration($chat, $mid, $p); break;
            case 'usr': handle_users($chat, $mid, $p, $user); break;
            case 'bqty': handle_bulk_qty($chat, $mid, $p, $user); break;
            case 'view': show_key_panel($chat, $mid, (int)($p[1] ?? 0), $user); break;
            case 'nav':
                if (($p[1] ?? '') === 'cancel') { state_clear($chat); tg_edit($chat, $mid, '❌ لغو شد.', super_admin_menu()); }
                break;
            case 'selprod': handle_select_product($chat, $mid, (int)($p[1] ?? 0), $user); break;
            case 'ban': case 'unban': case 'kr': case 'krn': case 'krc': case 'ku': case 'kus': case 'kuc':
            case 'kn': case 'kc': case 'kdel': case 'kdelok': case 'drall': case 'drallok': case 'devs': case 'devreset':
                handle_key_action($chat, $mid, $p, $user); break;
            case 'kmore': case 'tplsv': case 'tpl': case 'tplp': case 'tplq':
                handle_template_action($chat, $mid, $p, $user); break;
        }
    } catch (Throwable $e) {
        log_msg('error', 'cb', ['data' => $data, 'err' => $e->getMessage(), 'line' => $e->getLine()]);
        tg_send($chat, '⚠️ خطا: <code>' . h(mb_substr($e->getMessage(), 0, 160)) . '</code>', [[btn('🏠 منو', 'sup:main')]]);
    }
}

// ============================================================
// SUPER ADMIN
// ============================================================
function handle_super(int $chat, int $mid, array $p, array $user): void {
    $sub = $p[1] ?? '';
    switch ($sub) {
        case 'main': tg_edit($chat, $mid, '👑 <b>Admin Console</b>', super_admin_menu()); return;
        case 'dashboard': show_analytics($chat, $mid); return;
        case 'products': show_products_list($chat, $mid); return;
        case 'allresellers': show_all_resellers($chat, $mid); return;
        case 'advanced': tg_edit($chat, $mid, '🛠 <b>ابزارهای پیشرفته</b>', advanced_tools_menu()); return;
        case 'health': show_health($chat, $mid); return;
        case 'logs': show_recent_activity($chat, $mid); return;
        case 'audit': show_audit($chat, $mid, max(0, (int)($p[2] ?? 0))); return;
        case 'debug': show_debug($chat); return;
        case 'online': show_online($chat, $mid); return;
        case 'expiring': show_expiring($chat, $mid, max(1, min(90, (int)($p[2] ?? 3)))); return;
        case 'chart7': show_chart7($chat, $mid); return;
        case 'search': state_set($chat, 'search_global'); tg_edit($chat, $mid, "🔍 کلید، شناسه، یادداشت، مدل/IP دستگاه، آیدی تلگرام نماینده یا اسلاگ محصول را بفرستید:", cancel_kb()); return;
        case 'allkeys': show_keys_screen($chat, $mid, ['title' => '🔑 همه‌ی کلیدها', 'filter' => $p[2] ?? 'all', 'page' => max(0, (int)($p[3] ?? 0)),
            'back' => 'sup:main', 'cb' => fn($f, $pg) => "sup:allkeys:$f:$pg", 'showProduct' => true]); return;
        case 'newproduct': state_set($chat, 'new_product_slug'); tg_edit($chat, $mid, "➕ اسلاگ محصول جدید را بفرستید (حروف انگلیسی کوچک، عدد و -):", cancel_kb()); return;
        case 'newreseller': state_clear($chat); state_set($chat, 'new_reseller_tg'); tg_edit($chat, $mid, '➕ آیدی عددی تلگرام نماینده:', cancel_kb()); return;
        case 'backup': do_backup($chat, $user); return;
        case 'cleanup':
            $r = maintenance_run();
            audit($user, 'cleanup', 'system', null, $r);
            tg_edit($chat, $mid, "🧹 <b>پاکسازی انجام شد</b>\n\n" . implode("\n", array_map(fn($k, $v) => "• $k: <b>$v</b>", array_keys($r), $r)), [[btn('🔙', 'sup:advanced')]]);
            return;
        case 'webhook':
            $res = tg_setup_webhook(base_url() . '/bot.php');
            audit($user, 'webhook_setup', 'system');
            tg_edit($chat, $mid, (tg_ok($res['telegram']) ? '✅ Webhook ثبت شد' : '❌ خطا در ثبت Webhook') . "\n<code>" . h($res['webhook']) . '</code>', [[btn('🔙', 'sup:advanced')]]);
            return;
        case 'panic':
            $rows = [[btn('🌐 همه‌ی محصولات', 'sup:panicp:0', 'danger')]];
            foreach (product_list_all() as $pr) $rows[] = [btn('📦 ' . $pr['name'], 'sup:panicp:' . $pr['id'], 'danger')];
            $rows[] = [btn('❌ لغو', 'sup:advanced', 'success')];
            tg_edit($chat, $mid, "🚨 <b>Panic Lock</b>\nتمام کلیدهای محصول انتخابی مسدود و نشست‌ها قطع می‌شوند.\n\nمحصول را انتخاب کنید:", $rows);
            return;
        case 'panicp':
            $pid = (int)($p[2] ?? 0);
            state_set($chat, 'panic_confirm', ['product_id' => $pid ?: null]);
            tg_edit($chat, $mid, "🚨 برای تأیید روی «" . ($pid ? h(product_name($pid)) : 'همه‌ی محصولات') . "» عبارت <code>CONFIRM</code> را بفرستید:", [[btn('❌ لغو', 'sup:advanced', 'danger')]]);
            return;
        case 'bulkstart':
            $rows = [];
            foreach (product_list_all() as $pr) if ((int)$pr['id'] !== 1) $rows[] = [btn('📦 ' . $pr['name'], 'prd:bulk:' . $pr['id'])];
            $rows[] = [btn('🔙', 'sup:advanced', 'danger')];
            tg_edit($chat, $mid, '📦 محصول برای ساخت گروهی:', $rows);
            return;
        case 'export':
            $rows = [[btn('TXT همه', 'sup:export_product:0', 'success'), btn('CSV همه', 'sup:export_csv:0', 'success')]];
            foreach (product_list_all() as $pr) $rows[] = [btn('📦 ' . $pr['name'], 'sup:export_product:' . $pr['id']), btn('CSV', 'sup:export_csv:' . $pr['id'], 'success')];
            $rows[] = [btn('🔙', 'sup:advanced', 'danger')];
            tg_edit($chat, $mid, '📤 نوع خروجی:', $rows);
            return;
        case 'export_product': do_export($chat, $mid, (int)($p[2] ?? 0) ?: null, 'txt', $user); return;
        case 'export_csv': do_export($chat, $mid, (int)($p[2] ?? 0) ?: null, 'csv', $user); return;
        case 'broadcast':
            $rows = [[btn('🌐 همه‌ی نمایندگان', 'sup:broadcast_start:0', 'success')]];
            foreach (product_list_all() as $pr) if ((int)$pr['id'] !== 1) $rows[] = [btn('📦 ' . $pr['name'], 'sup:broadcast_start:' . $pr['id'])];
            $rows[] = [btn('🔙', 'sup:advanced', 'danger')];
            tg_edit($chat, $mid, '📢 انتخاب مخاطب:', $rows);
            return;
        case 'broadcast_start':
            state_set($chat, 'broadcast_message', ['product_id' => (int)($p[2] ?? 0) ?: null]);
            tg_edit($chat, $mid, '📢 متن پیام را بفرستید (متن ساده):', [[btn('❌ لغو', 'sup:advanced', 'danger')]]);
            return;
        case 'bsend': do_broadcast($chat, $mid, (string)($p[2] ?? ''), $user); return;
        case 'templates': show_templates($chat, $mid); return;
    }
}

// ============================================================
// PRODUCT
// ============================================================
function handle_product(int $chat, int $mid, array $p, array $user): void {
    $sub = $p[1] ?? ''; $productId = (int)($p[2] ?? 0);
    $product = product_get($productId);
    if (!$product) { tg_edit($chat, $mid, '❌ محصول یافت نشد.', [back_row('sup:products')]); return; }
    $n = h($product['name']);
    switch ($sub) {
        case 'menu': tg_edit($chat, $mid, "📦 <b>$n</b>\n<code>" . h($product['slug']) . '</code>', product_admin_menu($productId)); return;
        case 'bulk': start_key_flow($chat, $mid, ['product_id' => $productId, 'bulk' => true, 'created_by' => (int)$user['id']], '📦 ساخت گروهی — مدت اعتبار:'); return;
        case 'newkey': start_key_flow($chat, $mid, ['product_id' => $productId, 'created_by' => (int)$user['id']], "🔑 کلید جدید برای <b>$n</b>\n\n⏳ مدت اعتبار:"); return;
        case 'export': do_export($chat, $mid, $productId, 'txt', $user); return;
        case 'info': show_product_info($chat, $mid, $productId); return;
        case 'editname': state_set($chat, 'edit_product_name', ['id' => $productId]); tg_edit($chat, $mid, '✏️ نام جدید را بفرستید:', [[btn('❌ لغو', "prd:menu:$productId", 'danger')]]); return;
        case 'domain': state_set($chat, 'edit_domain', ['id' => $productId]); tg_edit($chat, $mid, "🌐 دامنه‌ی اختصاصی را بفرستید (مثال <code>api.example.com</code>) یا <code>-</code> برای حذف:", [[btn('❌ لغو', "prd:menu:$productId", 'danger')]]); return;
        case 'togglestatus':
            $new = $product['status'] === 'active' ? 'inactive' : 'active';
            db()->prepare('UPDATE products SET status=? WHERE id=?')->execute([$new, $productId]);
            audit($user, 'product_status', 'product', $productId, ['status' => $new]);
            show_product_info($chat, $mid, $productId, $new === 'active' ? '✅ فعال شد' : '🚫 غیرفعال شد');
            return;
        case 'delete': show_delete_confirmation($chat, $mid, $productId); return;
        case 'deleteconfirm':
            if ($productId <= 1) return;
            product_delete_cascade($productId);
            audit($user, 'product_delete', 'product', $productId);
            show_products_list($chat, $mid, '✅ حذف شد');
            return;
        case 'apiurl':
            $b = base_url();
            tg_edit($chat, $mid, "🌐 <b>API URLs</b>\n\nاختصاصی:\n<code>" . h("$b/{$product['slug']}/api.php") . "</code>\n\nعمومی:\n<code>" . h("$b/api.php?product={$product['slug']}") . '</code>'
                . (!empty($product['custom_domain']) ? "\n\nدامنه‌ی اختصاصی:\n<code>" . h('https://' . $product['custom_domain'] . '/api.php') . '</code>' : ''),
                [[btn('🔙', "prd:menu:$productId", 'danger')]]);
            return;
        case 'apikey': tg_edit($chat, $mid, "🔐 <b>API Key</b>\n\n<code>" . h($product['api_key']) . '</code>', [[btn('🔙', "prd:menu:$productId", 'danger')]]); return;
        case 'keys':
            show_keys_screen($chat, $mid, ['title' => "📋 کلیدهای $n", 'product_id' => $productId, 'filter' => $p[3] ?? 'all', 'page' => max(0, (int)($p[4] ?? 0)),
                'back' => "prd:menu:$productId", 'cb' => fn($f, $pg) => "prd:keys:$productId:$f:$pg"]);
            return;
        case 'resellers': show_product_resellers($chat, $mid, $productId); return;
        case 'stats': show_product_stats($chat, $mid, $productId); return;
        case 'panellinks': show_panel_links($chat, $mid, $productId); return;
        case 'genpanel': {
            $resId = (int)($p[3] ?? 0);
            if (!reseller_get($resId)) return;
            send_panel_link($chat, $mid, $resId, "prd:panellinks:$productId");
            return;
        }
    }
}

function product_admin_menu(int $productId): array {
    $product = product_get($productId);
    if (!$product) return [back_row('sup:products')];
    $statusBtn = $product['status'] === 'active' ? btn('🚫 غیرفعال کردن', "prd:togglestatus:$productId", 'danger') : btn('✅ فعال کردن', "prd:togglestatus:$productId", 'success');
    $docsUrl = base_url() . '/api_docs.php?product=' . urlencode((string)$product['slug']);
    return [
        [btn('🔑 ساخت کلید', "prd:newkey:$productId", 'success'), btn('📦 Bulk', "prd:bulk:$productId", 'success')],
        [btn('📋 کلیدها', "prd:keys:$productId:all:0"), btn('👥 نمایندگان', "prd:resellers:$productId")],
        [btn('📊 آمار', "prd:stats:$productId", 'success'), btn('📋 جزئیات', "prd:info:$productId")],
        [btn('🌐 API URL', "prd:apiurl:$productId"), btn('🔐 API Key', "prd:apikey:$productId")],
        [btn('📖 مستندات', '', 'primary', $docsUrl)],
        [btn('📤 Export', "prd:export:$productId"), btn('✏️ نام', "prd:editname:$productId")],
        [btn('🔗 لینک پنل', "prd:panellinks:$productId"), btn('🌍 دامنه', "prd:domain:$productId")],
        [$statusBtn, btn('🗑 حذف', "prd:delete:$productId", 'danger')],
        back_row('sup:products'),
    ];
}

function handle_rsl_new(int $chat, int $mid, array $p): void {
    $pid = (int)($p[1] ?? 0); $product = product_get($pid);
    if (!$product) { tg_send($chat, '❌ محصول یافت نشد.'); return; }
    state_clear($chat); state_set($chat, 'new_reseller_tg', ['product_id' => $pid]);
    tg_edit($chat, $mid, '➕ نماینده برای «' . h($product['name']) . "»\n\nآیدی عددی تلگرام:", cancel_kb());
}

// ============================================================
// RESELLER (admin side)
// ============================================================
function handle_reseller_admin(int $chat, int $mid, array $p, array $user): void {
    $sub = $p[1] ?? ''; $resId = (int)($p[2] ?? 0); $res = reseller_get($resId);
    if (!$res) { tg_edit($chat, $mid, '❌ نماینده یافت نشد.', [back_row('sup:allresellers')]); return; }
    $pid = (int)$res['product_id'];
    switch ($sub) {
        case 'detail': show_reseller_detail($chat, $mid, $resId, $pid); return;
        case 'quota': show_reseller_quota($chat, $mid, $resId, $pid); return;
        case 'qset': handle_quota_set($chat, $mid, $resId, $pid, (string)($p[3] ?? ''), (string)($p[4] ?? ''), $user); return;
        case 'qcustom':
            state_set($chat, 'edit_quota_value', ['res_id' => $resId, 'field' => (string)($p[3] ?? ''), 'product_id' => $pid]);
            tg_edit($chat, $mid, "✏️ مقدار را بفرستید (عدد یا <code>unlimited</code>؛ برای مدت مثلاً <code>30d</code>):", [[btn('❌ لغو', "rsl:quota:$resId", 'danger')]]);
            return;
        case 'perms': show_reseller_perms($chat, $mid, $resId, $pid); return;
        case 'toggle':
            reseller_toggle_perm($resId, (string)($p[3] ?? ''));
            audit($user, 'reseller_perm', 'reseller', $resId, ['perm' => $p[3] ?? '']);
            show_reseller_perms($chat, $mid, $resId, $pid);
            return;
        case 'resetused': reseller_reset_used($resId); audit($user, 'reseller_reset_used', 'reseller', $resId); show_reseller_detail($chat, $mid, $resId, $pid, '✅ شمارنده ریست شد'); return;
        case 'togglestatus':
            $new = $res['status'] === 'active' ? 'inactive' : 'active';
            reseller_update_status($resId, $new);
            if ($new === 'inactive') db()->prepare('DELETE FROM reseller_sessions WHERE reseller_id=?')->execute([$resId]);
            audit($user, 'reseller_status', 'reseller', $resId, ['status' => $new]);
            show_reseller_detail($chat, $mid, $resId, $pid, $new === 'active' ? '✅ فعال شد' : '🚫 غیرفعال شد');
            return;
        case 'delete': tg_edit($chat, $mid, '⚠️ حذف <b>' . h($res['display_name']) . '</b>؟ (کلیدهای او حذف نمی‌شوند)', [[btn('✅ بله، حذف', "rsl:deleteconfirm:$resId", 'danger'), btn('❌ لغو', "rsl:detail:$resId", 'success')]]); return;
        case 'deleteconfirm': reseller_delete($resId); audit($user, 'reseller_delete', 'reseller', $resId); show_product_resellers($chat, $mid, $pid); return;
        case 'quota_quick': {
            $q = match ((string)($p[3] ?? 'basic')) {
                'basic' => ['k' => 10, 'u' => 1, 'd' => 2592000],
                'standard' => ['k' => 50, 'u' => 3, 'd' => 7776000],
                'pro' => ['k' => 200, 'u' => 10, 'd' => null],
                'unlimited' => ['k' => null, 'u' => null, 'd' => null],
                default => ['k' => 10, 'u' => 1, 'd' => 2592000],
            };
            reseller_update_quota($resId, 'quota_max_keys', $q['k']);
            reseller_update_quota($resId, 'quota_max_users_per_key', $q['u']);
            reseller_update_quota($resId, 'quota_max_duration', $q['d']);
            audit($user, 'reseller_quota', 'reseller', $resId, ['preset' => $p[3] ?? '']);
            show_reseller_detail($chat, $mid, $resId, $pid, '✅ اعمال شد');
            return;
        }
        case 'genpanel': send_panel_link($chat, $mid, $resId, "rsl:detail:$resId"); return;
        case 'newpass': {
            $pw = reseller_reset_password($resId);
            audit($user, 'reseller_password_reset', 'reseller', $resId);
            tg_edit($chat, $mid, "🔑 <b>رمز جدید پنل</b>\n\n👤 " . h($res['display_name']) . "\nUsername: <code>{$res['telegram_id']}</code>\nPassword: <code>" . h($pw) . "</code>\n\n⚠️ این رمز فقط همین یک‌بار نمایش داده می‌شود.\n🌐 " . h(panel_base_url()),
                [[btn('🔙', "rsl:detail:$resId", 'primary')]]);
            return;
        }
        case 'newkey':
            start_key_flow($chat, $mid, ['product_id' => $pid, 'reseller_id' => $resId, 'created_by' => (int)$user['id']], '🔑 ساخت کلید برای <b>' . h($res['display_name']) . "</b>\n(سهمیه‌ی او مصرف می‌شود)\n\n⏳ مدت اعتبار:");
            return;
        case 'msg':
            state_set($chat, 'msg_reseller', ['id' => $resId]);
            tg_edit($chat, $mid, '✉️ متن پیام برای <b>' . h($res['display_name']) . '</b> را بفرستید:', [[btn('❌ لغو', "rsl:detail:$resId", 'danger')]]);
            return;
    }
}

function panel_base_url(): string {
    $b = (string)setting_get('panel_url', '');
    return $b !== '' ? $b : base_url() . '/panel.php';
}
function send_panel_link(int $chat, int $mid, int $resId, string $back): void {
    $res = reseller_get($resId); if (!$res) return;
    $token = reseller_generate_login_token($resId, 86400);
    $url = rtrim(panel_base_url(), '?&') . (str_contains(panel_base_url(), '?') ? '&' : '?') . 'token=' . $token;
    tg_edit($chat, $mid, "🔗 <b>لینک ورود (یک‌بارمصرف، ۲۴ ساعت)</b>\n\n<b>" . h($res['display_name']) . "</b>\n\n<code>" . h($url) . '</code>', [[btn('🔙', $back, 'primary')]]);
}

function handle_reseller(int $chat, int $mid, array $p): void {
    if (($p[1] ?? '') === 'keys') {
        $resId = (int)($p[2] ?? 0); $prodId = (int)($p[3] ?? 0);
        $res = reseller_get($resId); if (!$res) return;
        show_keys_screen($chat, $mid, ['title' => '📋 کلیدهای ' . h($res['display_name']), 'product_id' => $prodId, 'reseller_id' => $resId, 'filter' => 'all',
            'page' => max(0, (int)($p[4] ?? 0)), 'back' => "rsl:detail:$resId", 'no_filters' => true, 'cb' => fn($f, $pg) => "res:keys:$resId:$prodId:$pg"]);
    }
}

function create_reseller_flow(int $chat, int $mid, array $user, int $tgId, string $name, int $productId): void {
    $res = reseller_create($tgId, $name, $productId, ['max_keys' => 10, 'max_users' => 1, 'max_duration' => 2592000], (int)$user['id']);
    if ($res['ok']) {
        audit($user, 'reseller_create', 'reseller', (int)$res['id']);
        $tag = !empty($res['reactivated']) ? '♻️ دوباره فعال شد' : '✅ ساخته شد';
        reply($chat, $mid, "$tag\n\n👤 " . h($name) . "\n🆔 <code>$tgId</code>\n📦 " . h($res['product']['name']), [[btn('⚙️ مدیریت', 'rsl:detail:' . $res['id'])]]);
    } else {
        $back = !empty($res['existing_id']) ? btn('⚙️ نماینده‌ی موجود', 'rsl:detail:' . $res['existing_id']) : btn('🔙', 'sup:allresellers');
        reply($chat, $mid, '❌ ' . h($res['msg']), [[$back]]);
    }
}
function handle_select_product(int $chat, int $mid, int $productId, array $user): void {
    $st = state_get($chat);
    if (!$st || $st['state'] !== 'new_reseller_product') { tg_edit($chat, $mid, '❌ منقضی شده. از ابتدا شروع کنید.', [back_row()]); return; }
    $tgId = (int)($st['d']['tg'] ?? 0); $name = trim((string)($st['d']['name'] ?? ''));
    state_clear($chat);
    if ($tgId <= 0 || mb_strlen($name) < 2 || $productId <= 0) { tg_edit($chat, $mid, '❌ اطلاعات ناقص.', [back_row()]); return; }
    create_reseller_flow($chat, $mid, $user, $tgId, $name, $productId);
}

// ============================================================
// KEY CREATION (flow با context داخل callback)
// ============================================================
function start_key_flow(int $chat, int $mid, array $ctx, string $title): void {
    $pid = pending_create($chat, 'create_key', $ctx);
    reply($chat, $mid, $title, duration_menu($pid));
}
function handle_duration(int $chat, int $mid, array $p): void {
    $v = $p[1] ?? ''; $pid = $p[2] ?? '';
    $ctx = pend_get($pid, $chat);
    if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده؛ دوباره شروع کنید.', [back_row()]); return; }
    if ($v === 'custom') {
        state_set($chat, 'custom_duration', ['pid' => $pid]);
        tg_edit($chat, $mid, "✏️ مدت را بفرستید (مثال: <code>3d</code> ، <code>12h</code> ، <code>0</code> = نامحدود):", cancel_kb());
        return;
    }
    $ctx['duration'] = max(0, (int)$v); pend_set($pid, $ctx);
    tg_edit($chat, $mid, '✅ مدت: <b>' . h(format_duration($ctx['duration'])) . "</b>\n\n👥 تعداد کاربر (دستگاه):", users_menu($pid));
}
function handle_users(int $chat, int $mid, array $p, array $user): void {
    $cnt = $p[1] ?? '1'; $pid = $p[2] ?? '';
    $ctx = pend_get($pid, $chat);
    if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده؛ دوباره شروع کنید.', [back_row()]); return; }
    if ($cnt === 'custom') {
        state_set($chat, 'custom_users', ['pid' => $pid]);
        tg_edit($chat, $mid, '✏️ تعداد کاربران (۱ تا ۱۰۰۰):', cancel_kb());
        return;
    }
    $ctx['max_users'] = max(1, min(1000, (int)$cnt)); pend_set($pid, $ctx);
    if (!empty($ctx['bulk'])) { tg_edit($chat, $mid, "👥 کاربر: <b>{$ctx['max_users']}</b>\n\n📦 تعداد کلید:", bulk_qty_menu($pid)); return; }
    pending_delete($pid);
    finalize_key_creation($chat, $mid, $ctx, $user);
}
function handle_bulk_qty(int $chat, int $mid, array $p, array $user): void {
    $qty = $p[1] ?? ''; $pid = $p[2] ?? '';
    $ctx = pend_get($pid, $chat);
    if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده؛ دوباره شروع کنید.', [back_row()]); return; }
    if ($qty === 'custom') {
        state_set($chat, 'bulk_custom_qty', ['pid' => $pid]);
        tg_edit($chat, $mid, '✏️ تعداد کلید (۱ تا ۱۰۰):', cancel_kb());
        return;
    }
    pending_delete($pid);
    finalize_bulk_creation($chat, $mid, $ctx, (int)$qty, $user);
}
function finalize_key_creation(int $chat, int $mid, array $ctx, array $user): void {
    $productId = (int)($ctx['product_id'] ?? 0);
    if (!$productId) { reply($chat, $mid, '❌ محصول نامشخص', [back_row()]); return; }
    $resellerId = !empty($ctx['reseller_id']) ? (int)$ctx['reseller_id'] : null;
    $duration = (int)($ctx['duration'] ?? 0); $maxUsers = max(1, (int)($ctx['max_users'] ?? 1));
    $res = key_create_for_product($productId, $duration, $maxUsers, (int)$user['id'], $resellerId, isset($ctx['note']) ? (string)$ctx['note'] : null);
    if (!$res['ok']) { reply($chat, $mid, '❌ ' . h($res['msg']), [back_row("prd:menu:$productId", '🔙 محصول')]); return; }
    audit($user, 'key_create', 'license', (int)$res['id'], ['product' => $productId, 'reseller' => $resellerId]);
    $again = pending_create($chat, 'key_ctx', $ctx);
    $txt = "🟢 <b>کلید ساخته شد</b>\n\n📦 " . h($res['product']['name']) . "\n🔑 <code>" . h($res['key']) . "</code>\n⏳ " . h(format_duration($duration)) . "\n👥 $maxUsers کاربر";
    reply($chat, $mid, $txt, [
        [btn('👁 مشاهده', 'view:' . $res['id']), btn('➕ یکی دیگر مثل این', "kmore:$again", 'success')],
        [btn('🧩 ذخیره به‌عنوان قالب', "tplsv:$again"), btn('🏢 محصول', "prd:menu:$productId")],
    ]);
}
function finalize_bulk_creation(int $chat, int $mid, array $ctx, int $qty, array $user): void {
    $productId = (int)($ctx['product_id'] ?? 0);
    if (!$productId) { reply($chat, $mid, '❌ محصول نامشخص', [back_row()]); return; }
    $resellerId = !empty($ctx['reseller_id']) ? (int)$ctx['reseller_id'] : null;
    $duration = (int)($ctx['duration'] ?? 0); $maxUsers = max(1, (int)($ctx['max_users'] ?? 1));
    $qty = max(1, min(100, $qty));
    reply($chat, $mid, "⏳ در حال ساخت <b>$qty</b> کلید…", []);
    $res = bulk_create_keys($productId, $duration, $maxUsers, $qty, (int)$user['id'], $resellerId);
    if (!$res['ok']) { tg_send($chat, '❌ ' . h($res['msg'])); return; }
    audit($user, 'bulk_create', 'license', null, ['product' => $productId, 'qty' => $res['count'], 'reseller' => $resellerId]);
    $lines = array_map(fn($k) => $k['key'], $res['created']);
    $failed = count($res['failed'] ?? []);
    $txt = "✅ <b>ساخت گروهی</b>\n\n🟢 موفق: <b>{$res['count']}</b>" . ($failed ? "\n🔴 ناموفق: <b>$failed</b> (" . h((string)$res['failed'][0]) . ')' : '')
        . "\n⏳ " . h(format_duration($duration)) . "\n👥 $maxUsers";
    tg_send($chat, $txt, [[btn('🏢 محصول', "prd:menu:$productId")]]);
    if ($lines) tg_document($chat, "bulk_{$productId}_" . date('Ymd_His') . '.txt', implode("\n", $lines), "📦 {$res['count']} کلید");
}

// ============================================================
// TEMPLATES
// ============================================================
function show_templates(int $chat, int $mid): void {
    $list = template_list();
    $txt = "🧩 <b>قالب‌های ساخت کلید</b>\n\n" . ($list ? '' : 'هنوز قالبی ندارید. بعد از ساخت یک کلید، گزینه‌ی «ذخیره به‌عنوان قالب» را بزنید.');
    $rows = [];
    foreach ($list as $t) {
        $pn = $t['product_id'] ? product_name((int)$t['product_id']) : 'همه‌ی محصولات';
        $rows[] = [btn("▶️ {$t['name']} • " . format_duration((int)$t['duration_seconds']) . " • 👥{$t['max_users']} • $pn", 'tpl:use:' . $t['id']), btn('🗑', 'tpl:del:' . $t['id'], 'danger')];
    }
    $rows[] = back_row('sup:advanced');
    reply($chat, $mid, $txt, $rows);
}
function handle_template_action(int $chat, int $mid, array $p, array $user): void {
    $cmd = $p[0];
    if ($cmd === 'kmore') {          // ساخت یک کلید دیگر با همان پارامترها
        $ctx = pend_get($p[1] ?? '', $chat);
        if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده.', [back_row()]); return; }
        finalize_key_creation($chat, 0, $ctx, $user);
        return;
    }
    if ($cmd === 'tplsv') {          // ذخیره‌ی قالب
        $ctx = pend_get($p[1] ?? '', $chat);
        if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده.', [back_row()]); return; }
        state_set($chat, 'tpl_name', ['product_id' => $ctx['product_id'] ?? 0, 'duration' => $ctx['duration'] ?? 0, 'max_users' => $ctx['max_users'] ?? 1]);
        tg_send($chat, '🧩 نام قالب را بفرستید (مثلاً <code>ماهانه ۱ کاربر</code>):', cancel_kb());
        return;
    }
    if ($cmd === 'tpl') {
        $sub = $p[1] ?? ''; $id = (int)($p[2] ?? 0);
        $t = template_get($id);
        if (!$t) { show_templates($chat, $mid); return; }
        if ($sub === 'del') { template_delete($id); audit($user, 'template_delete', 'template', $id); show_templates($chat, $mid); return; }
        if ($sub === 'use') {
            if (!$t['product_id']) {
                $rows = [];
                foreach (product_list_all() as $pr) $rows[] = [btn('📦 ' . $pr['name'], "tplp:$id:{$pr['id']}")];
                $rows[] = back_row('sup:templates');
                tg_edit($chat, $mid, '📦 قالب برای کدام محصول ساخته شود؟', $rows);
                return;
            }
            tpl_qty_menu($chat, $mid, $id, (int)$t['product_id']);
            return;
        }
    }
    if ($cmd === 'tplp') { tpl_qty_menu($chat, $mid, (int)($p[1] ?? 0), (int)($p[2] ?? 0)); return; }
    if ($cmd === 'tplq') {           // tplq:<tpl>:<product>:<qty>
        $t = template_get((int)($p[1] ?? 0)); $pid = (int)($p[2] ?? 0); $qty = (int)($p[3] ?? 1);
        if (!$t || !$pid) return;
        $ctx = ['product_id' => $pid, 'duration' => (int)$t['duration_seconds'], 'max_users' => (int)$t['max_users']];
        if ($qty <= 1) finalize_key_creation($chat, $mid, $ctx, $user); else finalize_bulk_creation($chat, $mid, $ctx, $qty, $user);
    }
}
function tpl_qty_menu(int $chat, int $mid, int $tplId, int $pid): void {
    $t = template_get($tplId); if (!$t) return;
    $row = [];
    foreach ([1, 5, 10, 25, 50] as $q) $row[] = btn((string)$q, "tplq:$tplId:$pid:$q", 'success');
    tg_edit($chat, $mid, '🧩 <b>' . h($t['name']) . "</b>\n⏳ " . h(format_duration((int)$t['duration_seconds'])) . " • 👥 {$t['max_users']}\n📦 " . h(product_name($pid)) . "\n\nچند کلید ساخته شود؟", [$row, back_row('sup:templates')]);
}

// ============================================================
// SCREENS
// ============================================================
function show_analytics(int $chat, int $mid): void {
    $s = stats_for_product(0);
    $pdo = db();
    $q = function (string $sql, array $p = []) use ($pdo): int { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
    $apiT  = $q('SELECT COUNT(*) FROM api_logs WHERE created_at>?', [now() - 86400]);
    $errs  = $q("SELECT COUNT(*) FROM logs WHERE level='error' AND created_at>?", [now() - 86400]);
    $new24 = $q('SELECT COUNT(*) FROM licenses WHERE created_at>?', [now() - 86400]);
    $act24 = $q("SELECT COUNT(*) FROM api_logs WHERE action='activate' AND status='ok' AND created_at>?", [now() - 86400]);
    $soon  = $q("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at IS NOT NULL AND expires_at>? AND expires_at<=?", [now(), now() + 3 * 86400]);
    $txt  = "📊 <b>داشبورد</b>\n\n";
    $txt .= '🏢 محصولات: <b>' . max(0, count(product_list_all()) - 1) . "</b>\n";
    $txt .= "🔑 کلیدها: <b>{$s['keys_total']}</b>\n   🟢 فعال {$s['keys_active']} • 🟡 بلااستفاده {$s['keys_unused']}\n   ⚫ منقضی {$s['keys_expired']} • 🔴 مسدود {$s['keys_banned']}\n";
    $txt .= "👥 نمایندگان: <b>{$s['resellers']}</b>\n";
    $txt .= "📱 دستگاه‌ها: <b>{$s['devices']}</b>\n";
    $txt .= '🟢 آنلاین (' . intdiv((int)ONLINE_WINDOW, 60) . "د): <b>" . active_sessions_count() . "</b>\n";
    $txt .= "⏰ منقضی تا ۳ روز: <b>$soon</b>\n\n";
    $txt .= "🕐 <b>۲۴ ساعت اخیر</b>\n➕ کلید جدید: <b>$new24</b>\n🚀 فعال‌سازی: <b>$act24</b>\n📡 API: <b>$apiT</b>\n❗ خطا: <b>$errs</b>";
    reply($chat, $mid, $txt, [
        [btn('📈 نمودار', 'sup:chart7', 'success'), btn('⏰ رو‌به‌انقضا', 'sup:expiring:3', 'success')],
        [btn('🔄 تازه‌سازی', 'sup:dashboard'), btn('📜 Audit', 'sup:audit')],
        [btn('🔙', 'sup:main')],
    ]);
}
function show_health(int $chat, int $mid): void {
    $h = system_health();
    $free = @disk_free_space(__DIR__);
    $txt  = "💚 <b>سلامت سیستم</b>\n\n";
    $txt .= ($h['webhook_ok'] ? '✅ Webhook فعال' : '❌ Webhook مشکل') . "\n";
    if (!empty($h['webhook_error'])) $txt .= '⚠️ ' . h(mb_substr($h['webhook_error'], 0, 120)) . "\n";
    $txt .= "📬 Pending: <b>{$h['pending_updates']}</b>\n";
    $txt .= '💾 DB: <b>' . fmt_bytes((int)$h['db_size']) . '</b>' . ($free !== false ? ' • 💽 آزاد: <b>' . fmt_bytes((int)$free) . '</b>' : '') . "\n";
    $txt .= "🔐 Session منقضی: <b>{$h['expired_sessions']}</b> • Pending قدیمی: <b>{$h['stale_actions']}</b>\n";
    $txt .= "🚦 Rate-limit (۱س): <b>{$h['rate_hits_1h']}</b>\n";
    $txt .= "❗ خطا ۲۴س: <b>{$h['errors_24h']}</b>\n";
    $txt .= '🐘 PHP ' . PHP_VERSION . ' • v' . h(SKY_VERSION) . "\n\n";
    $txt .= $h['errors_24h'] > 10 ? '⚠️ خطاها زیاد است!' : '✅ سالم';
    reply($chat, $mid, $txt, [
        [btn('🔄 تازه‌سازی', 'sup:health'), btn('🧹 پاکسازی', 'sup:cleanup', 'success')],
        [btn('🔗 ثبت مجدد Webhook', 'sup:webhook')],
        [btn('🔙', 'sup:main')],
    ]);
}
function show_debug(int $chat): void {
    $st = state_get($chat);
    $q = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
    tg_send($chat, "🐞 <b>Debug</b>\n\n📦 محصولات: <b>" . $q('SELECT COUNT(*) FROM products WHERE id>1') . "</b>\n👥 نمایندگان: <b>" . $q('SELECT COUNT(*) FROM resellers')
        . "</b>\n🔑 کلیدها: <b>" . $q('SELECT COUNT(*) FROM licenses') . "</b>\n📋 State: " . ($st ? '<code>' . h($st['state']) . '</code>' : 'خالی')
        . "\n🧾 Schema: <code>" . (int)db()->query('PRAGMA user_version')->fetchColumn() . '</code>', [[btn('🔙', 'sup:main')]]);
}
function show_online(int $chat, int $mid): void {
    $list = online_devices(15);
    $txt = '🟢 <b>کاربران آنلاین</b> (' . active_sessions_count() . ")\n\n";
    $rows = [];
    if (!$list) $txt .= 'هیچ دستگاهی در ' . intdiv((int)ONLINE_WINDOW, 60) . ' دقیقه‌ی اخیر فعال نبوده.';
    foreach ($list as $o) {
        $raw = decrypt_key($o['key_encrypted']);
        $txt .= '• ' . h($o['product_name']) . ' • <code>' . h(mask_key($raw)) . '</code>' . "\n   📱 " . h($o['device_model'] ?: '?') . ' • ' . h($o['ip'] ?: '?') . ' • ' . ago((int)$o['last_heartbeat']) . "\n";
        $rows[] = [btn('👁 ' . mask_key($raw), 'view:' . $o['license_id'])];
    }
    $rows[] = [btn('🔄 تازه‌سازی', 'sup:online'), btn('🔙', 'sup:main')];
    reply($chat, $mid, $txt, $rows);
}
function show_expiring(int $chat, int $mid, int $days): void {
    $list = key_expiring($days, 15);
    $txt = "⏰ <b>کلیدهای منقضی‌شونده تا $days روز آینده</b>\n\n";
    $rows = [];
    if (!$list) $txt .= 'موردی نیست ✅';
    foreach ($list as $l) {
        $rem = max(0, (int)$l['expires_at'] - now());
        $txt .= '• <code>' . h(mask_key(decrypt_key($l['key_encrypted']))) . '</code> • ' . h($l['product_name']) . ' • ⏱ ' . h(format_duration($rem)) . (!empty($l['note']) ? ' • ' . h(mb_substr((string)$l['note'], 0, 20)) : '') . "\n";
        $rows[] = [btn('👁 #' . $l['id'], 'view:' . $l['id']), btn('⏳ +30d', 'krn:' . $l['id'] . ':2592000', 'success')];
    }
    $rows[] = [btn('۳ روز', 'sup:expiring:3'), btn('۷ روز', 'sup:expiring:7'), btn('۳۰ روز', 'sup:expiring:30')];
    $rows[] = [btn('🔙', 'sup:main')];
    reply($chat, $mid, $txt, $rows);
}
function show_products_list(int $chat, int $mid, string $notice = ''): void {
    $products = product_list_all();
    $txt = ($notice ? "📢 $notice\n\n" : '') . '🏢 <b>محصولات</b> (' . count($products) . ')';
    $rows = [];
    foreach ($products as $pr) {
        $icon = (int)$pr['id'] === 1 ? '⚙️' : '📦';
        $st = ($pr['status'] ?? 'active') === 'active' ? '🟢' : '🔴';
        $rows[] = [btn("$icon$st {$pr['name']} ({$pr['slug']}) • {$pr['lic_count']}🔑 • {$pr['res_count']}👥", 'prd:menu:' . $pr['id'])];
    }
    $rows[] = [btn('➕ محصول جدید', 'sup:newproduct', 'success')];
    $rows[] = back_row();
    reply($chat, $mid, $txt, $rows);
}
function show_product_info(int $chat, int $mid, int $productId, string $notice = ''): void {
    $product = product_get($productId); if (!$product) return;
    $s = stats_for_product($productId);
    $sl = ($product['status'] ?? 'active') === 'active' ? '🟢 فعال' : '🔴 غیرفعال';
    $txt = ($notice ? "📢 $notice\n\n" : '') . "📋 <b>جزئیات محصول</b>\n\n📦 <b>" . h($product['name']) . "</b>\n🏷 <code>" . h($product['slug']) . "</code>\n🔐 <code>" . h($product['api_key']) . "</code>\n"
        . (!empty($product['custom_domain']) ? '🌍 <code>' . h($product['custom_domain']) . "</code>\n" : '')
        . '📅 ' . date('Y-m-d H:i', (int)$product['created_at']) . "\n📊 $sl\n\n📈 <b>آمار:</b>\n🔑 {$s['keys_total']} (🟢 {$s['keys_active']})\n👥 {$s['resellers']}\n📱 {$s['devices']}";
    reply($chat, $mid, $txt, [
        [btn('✏️ نام', "prd:editname:$productId"), btn('🌍 دامنه', "prd:domain:$productId")],
        [btn('🗑 حذف', "prd:delete:$productId", 'danger')],
        [btn('🔙', "prd:menu:$productId")],
    ]);
}
function show_delete_confirmation(int $chat, int $mid, int $productId): void {
    if ($productId <= 1) { tg_edit($chat, $mid, '❌ محصول پیش‌فرض قابل حذف نیست.', [[btn('🔙', "prd:menu:$productId")]]); return; }
    $product = product_get($productId); if (!$product) return;
    $s = stats_for_product($productId);
    tg_edit($chat, $mid, "⚠️ <b>حذف محصول</b>\n\n📦 <b>" . h($product['name']) . "</b>\n\n🔴 غیرقابل بازگشت!\n• {$s['keys_total']} کلید\n• {$s['resellers']} نماینده",
        [[btn('🗑 بله، حذف شود', "prd:deleteconfirm:$productId", 'danger')], [btn('❌ لغو', "prd:menu:$productId", 'success')]]);
}
function show_all_resellers(int $chat, int $mid): void {
    $list = db()->query('SELECT r.*, p.slug AS product_slug FROM resellers r JOIN products p ON p.id=r.product_id ORDER BY r.id DESC LIMIT 40')->fetchAll();
    $rows = [];
    foreach ($list as $r) {
        $st = $r['status'] === 'active' ? '✅' : '🚫';
        $rows[] = [btn("$st {$r['display_name']} • {$r['product_slug']} • {$r['used_keys']}/" . quota_display($r['quota_max_keys']), 'rsl:detail:' . $r['id'])];
    }
    $rows[] = [btn('➕ نماینده جدید', 'sup:newreseller', 'success')];
    $rows[] = back_row();
    reply($chat, $mid, '👥 <b>نمایندگان</b> (' . count($list) . ')', $rows);
}
function show_recent_activity(int $chat, int $mid): void {
    $logs = db()->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 25')->fetchAll();
    $txt = "📜 <b>API Logs</b>\n\n";
    if (!$logs) $txt .= 'خالی';
    foreach ($logs as $log) {
        $ic = match ($log['status']) { 'ok' => '✅', 'expired' => '⚫', 'banned' => '🚫', 'mismatch', 'users_exceeded' => '⚠️', 'invalid' => '❌', 'ratelimit' => '🚦', default => '•' };
        $txt .= "$ic <code>" . date('m-d H:i', (int)$log['created_at']) . '</code> ' . h($log['action']) . ' • ' . h($log['status']) . ' • <code>' . h($log['ip']) . "</code>\n";
    }
    reply($chat, $mid, $txt, [[btn('🔄', 'sup:logs'), btn('🔙', 'sup:main')]]);
}
function show_reseller_detail(int $chat, int $mid, int $resId, int $productId, string $notice = ''): void {
    $res = reseller_get($resId); if (!$res) return;
    $pl = fn($v) => (int)$v === 1 ? '✅' : '❌';
    $sl = $res['status'] === 'active' ? '🟢 فعال' : '🔴 غیرفعال';
    $kc = (int)db()->query('SELECT COUNT(*) FROM licenses WHERE reseller_id=' . $resId)->fetchColumn();
    $txt = ($notice ? "📢 $notice\n\n" : '') . '👤 <b>' . h($res['display_name']) . "</b>\n🆔 <code>{$res['telegram_id']}</code>\n📦 " . h(product_name($productId)) . "\n📊 $sl • " . (!empty($res['panel_password_hash']) ? '🖥 پنل دارد' : '🤖 فقط ربات') . "\n\n";
    $txt .= "⚙️ <b>سهمیه:</b>\n🔑 {$res['used_keys']}/" . quota_display($res['quota_max_keys']) . " (کلید موجود: $kc)\n👥 " . quota_display($res['quota_max_users_per_key']) . "\n⏳ " . duration_display($res['quota_max_duration']) . "\n\n";
    $txt .= "🔐 <b>دسترسی:</b>\n" . $pl($res['can_create_keys']) . ' ساخت ' . $pl($res['can_ban_keys']) . ' مسدود ' . $pl($res['can_renew_keys']) . ' تمدید ' . $pl($res['can_reset_devices']) . ' ریست ' . $pl($res['can_edit_users'] ?? 1) . ' ویرایش ' . $pl($res['can_delete_keys'] ?? 1) . ' حذف';
    reply($chat, $mid, $txt, [
        [btn('🔑 کلیدها', "res:keys:$resId:$productId:0"), btn('➕ ساخت کلید', "rsl:newkey:$resId", 'success')],
        [btn('✏️ سهمیه', "rsl:quota:$resId"), btn('🔐 دسترسی', "rsl:perms:$resId")],
        [btn('🔗 لینک ورود', "rsl:genpanel:$resId"), btn('🔑 رمز جدید پنل', "rsl:newpass:$resId", 'success')],
        [btn('✉️ پیام', "rsl:msg:$resId"), btn('🔄 ریست مصرف', "rsl:resetused:$resId", 'success')],
        [btn($res['status'] === 'active' ? '🚫 غیرفعال' : '✅ فعال', "rsl:togglestatus:$resId", 'danger'), btn('🗑 حذف', "rsl:delete:$resId", 'danger')],
        [btn('🔙', "prd:resellers:$productId")],
    ]);
}
function show_reseller_quota(int $chat, int $mid, int $resId, int $productId): void {
    $res = reseller_get($resId); if (!$res) return;
    tg_edit($chat, $mid, "✏️ <b>سهمیه</b>\n\n👤 " . h($res['display_name']) . "\n\n🔑 " . quota_display($res['quota_max_keys']) . "\n👥 " . quota_display($res['quota_max_users_per_key']) . "\n⏳ " . duration_display($res['quota_max_duration']), [
        [btn('🔑 کلید', "rsl:qset:$resId:keys:menu"), btn('👥 کاربر', "rsl:qset:$resId:users:menu"), btn('⏳ مدت', "rsl:qset:$resId:duration:menu")],
        [btn('⚡ Basic', "rsl:quota_quick:$resId:basic", 'success'), btn('⚡ Standard', "rsl:quota_quick:$resId:standard", 'success')],
        [btn('⚡ Pro', "rsl:quota_quick:$resId:pro", 'success'), btn('♾ نامحدود', "rsl:quota_quick:$resId:unlimited", 'success')],
        [btn('🔙', "rsl:detail:$resId", 'danger')],
    ]);
}
function handle_quota_set(int $chat, int $mid, int $resId, int $productId, string $field, string $value, array $user): void {
    $menus = [
        'keys' => ['🔑 حداکثر کلید:', 'quota_max_keys', [5, 10, 50, 100, 500]],
        'users' => ['👥 حداکثر کاربر در هر کلید:', 'quota_max_users_per_key', [1, 3, 5, 10, 25, 50]],
    ];
    if ($value === 'menu') {
        if (isset($menus[$field])) {
            [$title, $col, $opts] = $menus[$field];
            $rows = array_chunk(array_map(fn($n) => btn((string)$n, "rsl:qset:$resId:$field:$n"), $opts), 3);
            $rows[] = [btn('♾ نامحدود', "rsl:qset:$resId:$field:null", 'success')];
            $rows[] = [btn('✏️ دلخواه', "rsl:qcustom:$resId:$col")];
            $rows[] = [btn('🔙', "rsl:quota:$resId", 'danger')];
            tg_edit($chat, $mid, $title, $rows);
            return;
        }
        if ($field === 'duration') {
            tg_edit($chat, $mid, '⏳ حداکثر مدت هر کلید:', [
                [btn('1 روز', "rsl:qset:$resId:duration:86400"), btn('7 روز', "rsl:qset:$resId:duration:604800")],
                [btn('30 روز', "rsl:qset:$resId:duration:2592000"), btn('90 روز', "rsl:qset:$resId:duration:7776000")],
                [btn('1 سال', "rsl:qset:$resId:duration:31536000")],
                [btn('♾ نامحدود', "rsl:qset:$resId:duration:null", 'success')],
                [btn('✏️ دلخواه', "rsl:qcustom:$resId:quota_max_duration")],
                [btn('🔙', "rsl:quota:$resId", 'danger')],
            ]);
            return;
        }
    }
    $col = match ($field) { 'keys' => 'quota_max_keys', 'users' => 'quota_max_users_per_key', 'duration' => 'quota_max_duration', default => null };
    if (!$col) return;
    $v = $value === 'null' ? null : (int)$value;
    reseller_update_quota($resId, $col, $v);
    audit($user, 'reseller_quota', 'reseller', $resId, [$col => $v]);
    show_reseller_detail($chat, $mid, $resId, $productId, '✅ ذخیره شد');
}
function show_reseller_perms(int $chat, int $mid, int $resId, int $productId): void {
    $res = reseller_get($resId); if (!$res) return;
    $p = fn($c, $l) => btn(((int)($res[$c] ?? 1) === 1 ? '✅ ' : '❌ ') . $l, "rsl:toggle:$resId:$c", (int)($res[$c] ?? 1) === 1 ? 'success' : 'danger');
    tg_edit($chat, $mid, '🔐 <b>دسترسی‌های ' . h($res['display_name']) . '</b>', [
        [$p('can_create_keys', 'ساخت کلید')],
        [$p('can_ban_keys', 'مسدودسازی / رفع مسدودی')],
        [$p('can_renew_keys', 'تمدید')],
        [$p('can_reset_devices', 'ریست دستگاه')],
        [$p('can_edit_users', 'ویرایش ظرفیت کاربر')],
        [$p('can_delete_keys', 'حذف کلید')],
        [btn('🔙', "rsl:detail:$resId")],
    ]);
}
function show_product_resellers(int $chat, int $mid, int $productId): void {
    $list = reseller_list_by_product($productId);
    $rows = []; $active = 0; $inactive = 0;
    foreach ($list as $r) {
        if ($r['status'] === 'active') $active++; else $inactive++;
        $rows[] = [btn(($r['status'] === 'active' ? '✅' : '🚫') . " {$r['display_name']} — {$r['used_keys']}/" . quota_display($r['quota_max_keys']), 'rsl:detail:' . $r['id'])];
    }
    $rows[] = [btn('➕ نماینده جدید', "rslnew:$productId", 'success')];
    $rows[] = [btn('🔙', "prd:menu:$productId", 'danger')];
    reply($chat, $mid, "👥 <b>نمایندگان</b>\n\n✅ $active | 🚫 $inactive | کل " . count($list), $rows);
}
function show_product_stats(int $chat, int $mid, int $productId): void {
    $s = stats_for_product($productId); $product = product_get($productId);
    $on = active_sessions_count($productId);
    reply($chat, $mid, '📊 <b>' . h($product['name'] ?? '?') . "</b>\n\n🔑 {$s['keys_total']}\n🟢 {$s['keys_active']}\n🟡 {$s['keys_unused']}\n⚫ {$s['keys_expired']}\n🔴 {$s['keys_banned']}\n\n🟢 آنلاین: $on\n📱 {$s['devices']}\n👥 {$s['resellers']}",
        [[btn('🔙', "prd:menu:$productId")]]);
}
function show_panel_links(int $chat, int $mid, int $productId): void {
    $rows = [];
    foreach (reseller_list_by_product($productId) as $r) $rows[] = [btn("🔗 {$r['display_name']} ({$r['used_keys']}/" . quota_display($r['quota_max_keys']) . ')', "prd:genpanel:$productId:{$r['id']}")];
    if (!$rows) $rows[] = [btn('➕ نماینده', "rslnew:$productId", 'success')];
    $rows[] = [btn('🔙', "prd:menu:$productId", 'danger')];
    tg_edit($chat, $mid, '🔗 <b>لینک ورود نمایندگان</b>', $rows);
}

/** لیست صفحه‌بندی‌شده‌ی کلیدها با فیلتر وضعیت (برای کلی/محصول/نماینده) */
function show_keys_screen(int $chat, int $mid, array $o): void {
    $filter = in_array($o['filter'] ?? 'all', ['all', 'active', 'unused', 'expired', 'banned'], true) ? $o['filter'] : 'all';
    $page = (int)($o['page'] ?? 0); $limit = 8;
    $pid = (int)($o['product_id'] ?? 0); $rid = (int)($o['reseller_id'] ?? 0);
    $cb = $o['cb'];
    $labels = ['all' => 'همه', 'active' => 'فعال', 'unused' => 'بلااستفاده', 'expired' => 'منقضی', 'banned' => 'مسدود'];
    $total = key_count($pid, $rid, $filter);
    $items = key_list($pid, $rid, $filter, $page * $limit, $limit);
    $rows = [];
    foreach ($items as $lic) {
        $line = key_line($lic) . (!empty($o['showProduct']) ? ' • ' . $lic['product_slug'] : '');
        $rows[] = [btn($line, 'view:' . $lic['id'])];
    }
    $tp = max(1, (int)ceil($total / $limit)); $nav = [];
    if ($page > 0) $nav[] = btn('⬅️', $cb($filter, $page - 1));
    $nav[] = btn(($page + 1) . '/' . $tp, $cb($filter, $page));
    if ($page + 1 < $tp) $nav[] = btn('➡️', $cb($filter, $page + 1));
    if (count($nav) > 1) $rows[] = $nav;
    if (empty($o['no_filters'])) {
        $fr = [];
        foreach ($labels as $f => $l) $fr[] = btn(($f === $filter ? '• ' : '') . $l, $cb($f, 0), $f === $filter ? 'success' : 'primary');
        $rows[] = array_slice($fr, 0, 3); $rows[] = array_slice($fr, 3);
    }
    $rows[] = [btn('🔙', $o['back'], 'danger')];
    $txt = ($o['title'] ?? '🔑 کلیدها') . ' — <b>' . $labels[$filter] . "</b> ($total)" . ($items ? '' : "\n\n📭 موردی نیست");
    reply($chat, $mid, $txt, $rows);
}

// ============================================================
// KEY PANEL
// ============================================================
function show_key_panel(int $chat, int $mid, int $id, array $user, string $notice = ''): void {
    $lic = key_get($id);
    if (!$lic) { reply($chat, $mid, '❌ کلید یافت نشد (شاید حذف شده).', [back_row()]); return; }
    $raw = decrypt_key($lic['key_encrypted']);
    $st = compute_license_status($lic);
    $rem = $lic['expires_at'] ? max(0, (int)$lic['expires_at'] - now()) : -1;
    $cnt = count_license_devices($id);
    $res = !empty($lic['reseller_id']) ? reseller_get((int)$lic['reseller_id']) : null;
    $txt = ($notice ? "📢 $notice\n\n" : '') . "🔑 <b>کلید #$id</b>\n\n";
    $txt .= '📦 ' . h($lic['product_name']) . "\n🔑 <code>" . h($raw) . "</code>\n📊 " . status_label($lic) . "\n";
    $txt .= '⏳ مدت: ' . h(format_duration((int)$lic['duration_seconds'])) . "\n👥 $cnt/{$lic['max_users']}\n";
    if ($lic['first_activated_at']) $txt .= '🚀 فعال‌سازی: ' . date('Y-m-d H:i', (int)$lic['first_activated_at']) . "\n";
    if ($lic['expires_at']) { $txt .= '⌛ انقضا: ' . date('Y-m-d H:i', (int)$lic['expires_at']) . "\n"; if ($rem > 0) $txt .= '⏱ باقی‌مانده: ' . h(format_duration($rem)) . "\n"; }
    $txt .= '🕐 ساخت: ' . date('Y-m-d H:i', (int)$lic['created_at']) . "\n";
    $txt .= '🔢 ورود: ' . (int)$lic['login_count'] . ($lic['last_login'] ? ' • آخرین: ' . ago((int)$lic['last_login']) : '') . "\n";
    if ($res) $txt .= '👤 نماینده: ' . h($res['display_name']) . "\n";
    if (!empty($lic['note'])) $txt .= '📝 ' . h($lic['note']) . "\n";
    $back = $res ? "rsl:detail:{$lic['reseller_id']}" : ($lic['product_id'] ? "prd:menu:{$lic['product_id']}" : 'sup:main');
    reply($chat, $mid, rtrim($txt), [
        [$st === 'banned' ? btn('✅ رفع مسدودی', "unban:$id", 'success') : btn('🚫 مسدود', "ban:$id", 'danger'), btn('📱 دستگاه‌ها', "devs:$id")],
        [btn('⏳ تمدید', "kr:$id", 'success'), btn('👥 ظرفیت', "ku:$id")],
        [btn('📝 یادداشت', "kn:$id"), btn('📋 ارسال کلید', "kc:$id")],
        [btn('🔄 ریست دستگاه‌ها', "drall:$id"), btn('🗑 حذف', "kdel:$id", 'danger')],
        [btn('🔙', $back)],
    ]);
}

function do_renew(int $chat, int $mid, int $id, int $sec, array $user): void {
    $r = key_renew($id, $sec);
    if (!$r['ok']) { reply($chat, $mid, '❌ ' . h($r['msg']), [[btn('🔙', "view:$id")]]); return; }
    audit($user, 'key_renew', 'license', $id, ['seconds' => $sec]);
    show_key_panel($chat, $mid, $id, $user, '✅ تمدید شد: +' . format_duration($sec));
}

function handle_key_action(int $chat, int $mid, array $p, array $user): void {
    $cmd = $p[0]; $id = (int)($p[1] ?? 0);
    $lic = key_get($id);
    if (!$lic) { reply($chat, $mid, '❌ کلید یافت نشد.', [back_row()]); return; }
    switch ($cmd) {
        case 'ban': key_ban($id); audit($user, 'key_ban', 'license', $id); show_key_panel($chat, $mid, $id, $user, '🚫 مسدود شد'); return;
        case 'unban': key_unban($id); audit($user, 'key_unban', 'license', $id); show_key_panel($chat, $mid, $id, $user, '✅ رفع مسدودی شد'); return;
        case 'kr':
            tg_edit($chat, $mid, "⏳ <b>تمدید کلید #$id</b>\n\nچقدر اضافه شود؟", [
                [btn('+1d', "krn:$id:86400"), btn('+7d', "krn:$id:604800"), btn('+30d', "krn:$id:2592000", 'success')],
                [btn('+90d', "krn:$id:7776000", 'success'), btn('+180d', "krn:$id:15552000", 'success'), btn('+1y', "krn:$id:31536000", 'success')],
                [btn('✏️ دلخواه', "krc:$id")],
                [btn('🔙', "view:$id", 'danger')],
            ]);
            return;
        case 'krn': do_renew($chat, $mid, $id, max(1, (int)($p[2] ?? 0)), $user); return;
        case 'krc': state_set($chat, 'renew_custom', ['id' => $id]); tg_edit($chat, $mid, "✏️ مدت تمدید را بفرستید (مثال <code>7d</code> یا <code>12h</code>):", [[btn('❌ لغو', "view:$id", 'danger')]]); return;
        case 'ku':
            tg_edit($chat, $mid, "👥 <b>ظرفیت دستگاه کلید #$id</b>\nفعلی: {$lic['max_users']}", [
                [btn('1', "kus:$id:1"), btn('2', "kus:$id:2"), btn('3', "kus:$id:3"), btn('5', "kus:$id:5"), btn('10', "kus:$id:10")],
                [btn('✏️ دلخواه', "kuc:$id")],
                [btn('🔙', "view:$id", 'danger')],
            ]);
            return;
        case 'kus': {
            $u = max(1, (int)($p[2] ?? 1));
            key_set_max_users($id, $u); audit($user, 'key_max_users', 'license', $id, ['max' => $u]);
            show_key_panel($chat, $mid, $id, $user, '✅ ظرفیت ذخیره شد');
            return;
        }
        case 'kuc': state_set($chat, 'key_users_custom', ['id' => $id]); tg_edit($chat, $mid, '✏️ ظرفیت جدید را بفرستید (۱ تا ۱۰۰۰):', [[btn('❌ لغو', "view:$id", 'danger')]]); return;
        case 'kn': state_set($chat, 'edit_note', ['id' => $id]); tg_edit($chat, $mid, "📝 یادداشت را بفرستید (<code>-</code> برای حذف):\n\nفعلی: " . h($lic['note'] ?: '—'), [[btn('❌ لغو', "view:$id", 'danger')]]); return;
        case 'kc': tg_send($chat, '🔑 <code>' . h(decrypt_key($lic['key_encrypted'])) . '</code>'); return;
        case 'kdel':
            tg_edit($chat, $mid, "⚠️ کلید #$id برای همیشه حذف شود؟\n<code>" . h(mask_key(decrypt_key($lic['key_encrypted']))) . '</code>', [[btn('🗑 بله، حذف', "kdelok:$id", 'danger'), btn('❌ لغو', "view:$id", 'success')]]);
            return;
        case 'kdelok':
            key_delete($id); audit($user, 'key_delete', 'license', $id);
            $back = !empty($lic['reseller_id']) ? "rsl:detail:{$lic['reseller_id']}" : "prd:menu:{$lic['product_id']}";
            tg_edit($chat, $mid, "🗑 کلید #$id حذف شد.", [[btn('🔙', $back)]]);
            return;
        case 'drall':
            tg_edit($chat, $mid, "🔄 همه‌ی دستگاه‌های کلید #$id ریست شوند؟ (کاربر باید دوباره وارد شود)", [[btn('✅ بله', "drallok:$id", 'danger'), btn('❌ لغو', "view:$id", 'success')]]);
            return;
        case 'drallok': device_reset_all($id); audit($user, 'devices_reset_all', 'license', $id); show_key_panel($chat, $mid, $id, $user, '✅ دستگاه‌ها ریست شدند'); return;
        case 'devs': show_devices($chat, $mid, $id, $lic); return;
        case 'devreset': {
            $devRowId = (int)($p[2] ?? 0);
            $st = db()->prepare('SELECT * FROM license_devices WHERE id=? AND license_id=?'); $st->execute([$devRowId, $id]);
            if ($dev = $st->fetch()) { device_reset_one($id, $dev['device_id']); audit($user, 'device_reset', 'license', $id); }
            show_devices($chat, $mid, $id, $lic);
            return;
        }
    }
}
function show_devices(int $chat, int $mid, int $id, array $lic): void {
    $devs = list_license_devices($id);
    $txt = "📱 <b>دستگاه‌های کلید #$id</b>\n" . count($devs) . " / {$lic['max_users']}\n\n";
    $rows = [];
    if (!$devs) $txt .= 'خالی';
    foreach ($devs as $i => $d) {
        $n = $i + 1;
        $txt .= "<b>$n.</b> " . h($d['device_model'] ?: '?') . ($d['android_version'] ? ' • Android ' . h($d['android_version']) : '') . "\nID: <code>" . h(substr((string)$d['device_id'], 0, 12)) . "…</code>\nIP: <code>" . h($d['last_ip'] ?: '?') . "</code>\nآخرین: " . ago((int)$d['last_login']) . " • ورود: {$d['login_count']}\n\n";
        $rows[] = [btn("🗑 حذف #$n", "devreset:$id:{$d['id']}", 'danger')];
    }
    if (count($devs) > 1) $rows[] = [btn('🔄 ریست همه', "drall:$id", 'danger')];
    $rows[] = [btn('🔙', "view:$id")];
    reply($chat, $mid, $txt, $rows);
}

// ============================================================
// SEARCH
// ============================================================
function perform_global_search(int $chat, string $q): void {
    $q = trim($q);
    if ($q === '') { tg_send($chat, '❌ عبارت جستجو خالی است.', [back_row()]); return; }
    $rows = [];
    $txt = "🔍 نتایج «" . h(mb_substr($q, 0, 40)) . "»\n";

    $keys = key_search($q, 10);
    if ($keys) { $txt .= "\n🔑 کلیدها: " . count($keys); foreach ($keys as $l) $rows[] = [btn(key_line($l) . ' • ' . $l['product_slug'], 'view:' . $l['id'])]; }

    $rs = [];
    if (ctype_digit($q)) { $st = db()->prepare('SELECT * FROM resellers WHERE telegram_id=? LIMIT 10'); $st->execute([(int)$q]); $rs = $st->fetchAll(); }
    if (!$rs && mb_strlen($q) >= 2) {
        $st = db()->prepare("SELECT * FROM resellers WHERE display_name LIKE ? ESCAPE '\\' LIMIT 10");
        $st->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%']);
        $rs = $st->fetchAll();
    }
    if ($rs) { $txt .= "\n👥 نمایندگان: " . count($rs); foreach ($rs as $r) $rows[] = [btn("👤 {$r['display_name']} — " . product_name((int)$r['product_id']), 'rsl:detail:' . $r['id'])]; }

    $pr = product_by_slug(strtolower($q));
    if ($pr) { $txt .= "\n📦 محصول"; $rows[] = [btn('📦 ' . $pr['name'], 'prd:menu:' . $pr['id'])]; }

    if (!$rows) $txt .= "\nنتیجه‌ای پیدا نشد.";
    $rows[] = back_row();
    tg_send($chat, $txt, $rows);
}

// ============================================================
// ACTIONS
// ============================================================
function do_backup(int $chat, array $user): void {
    $snap = db_snapshot();
    if (!$snap || !is_file($snap)) { tg_send($chat, '❌ ساخت بکاپ ناموفق بود.'); return; }
    $size = (int)filesize($snap);
    if ($size > 49 * 1024 * 1024) { @unlink($snap); tg_send($chat, '❌ حجم بکاپ بیش از حد مجاز تلگرام (۵۰MB) است.'); return; }
    $ok = tg_document($chat, 'spider_backup_' . date('Ymd_His') . '.sqlite', (string)file_get_contents($snap), "💾 <b>بکاپ</b>\n📅 " . date('Y-m-d H:i') . "\n💽 " . fmt_bytes($size));
    @unlink($snap);
    audit($user, 'backup', 'system', null, ['size' => $size, 'ok' => $ok]);
    if (!$ok) tg_send($chat, '❌ ارسال بکاپ ناموفق بود.');
}
function do_export(int $chat, int $mid, ?int $productId, string $format, array $user): void {
    $content = export_keys($productId, $format);
    $name = 'spider_' . ($productId ?: 'all') . '_' . date('Ymd_His') . '.' . $format;
    $pn = $productId ? (product_get($productId)['name'] ?? '?') : 'همه';
    reply($chat, $mid, '📤 در حال ارسال…', [[btn('🔙', 'sup:advanced')]]);
    tg_document($chat, $name, $content, "📤 <b>خروجی</b>\n📦 " . h($pn));
    audit($user, 'export', 'license', null, ['product' => $productId, 'format' => $format]);
}
function do_broadcast(int $chat, int $mid, string $bid, array $user): void {
    $ctx = pend_get($bid, $chat);
    if (!$ctx) { tg_edit($chat, $mid, '❌ منقضی شده؛ دوباره پیام را بنویسید.', [[btn('🔙', 'sup:advanced')]]); return; }
    pending_delete($bid);
    $productId = !empty($ctx['product_id']) ? (int)$ctx['product_id'] : null;
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['telegram_id'], broadcast_targets($productId))));
    if (!$ids) { tg_edit($chat, $mid, '❌ گیرننده‌ای نیست.', [[btn('🔙', 'sup:advanced')]]); return; }
    reply($chat, $mid, '📤 در حال ارسال به ' . count($ids) . ' نفر…', []);
    $msg = "📢 <b>اطلاعیه مدیریت</b>\n\n" . h((string)$ctx['text']);
    $sent = 0; $failed = 0;
    foreach ($ids as $tgId) {
        tg_ok(tg('sendMessage', ['chat_id' => $tgId, 'text' => $msg, 'parse_mode' => 'HTML'])) ? $sent++ : $failed++;
        usleep(60000); // ≈۱۶ پیام در ثانیه؛ زیر محدودیت تلگرام
    }
    broadcast_save($productId, (string)$ctx['text'], $sent, $failed, (int)$user['id']);
    audit($user, 'broadcast', 'product', $productId, ['sent' => $sent, 'failed' => $failed]);
    tg_send($chat, "✅ <b>ارسال شد</b>\n\n📤 موفق: $sent\n❌ ناموفق: $failed", [[btn('🔙', 'sup:advanced')]]);
}
function show_audit(int $chat, int $mid, int $page): void {
    $per = 20;
    $list = audit_list_page($per, $page * $per);
    $txt = "📜 <b>Audit Log</b>\n\n";
    if (!$list) $txt .= 'خالی';
    foreach ($list as $a) {
        $ic = match (true) {
            str_contains($a['action'], 'delete') => '🗑', str_contains($a['action'], 'create') => '➕', str_contains($a['action'], 'ban') => '🚫',
            str_contains($a['action'], 'renew') => '⏳', str_contains($a['action'], 'panic') => '🚨', str_contains($a['action'], 'backup') => '💾',
            str_contains($a['action'], 'broadcast') => '📢', $a['action'] === 'login_admin' => '🔓', default => '•',
        };
        $txt .= "$ic <code>" . date('m-d H:i', (int)$a['created_at']) . '</code> <b>' . h(mb_substr((string)$a['admin_name'], 0, 16)) . '</b> — ' . h($a['action']) . (!empty($a['target_id']) ? ' #' . (int)$a['target_id'] : '') . "\n";
    }
    $nav = [];
    if ($page > 0) $nav[] = btn('⬅️', 'sup:audit:' . ($page - 1));
    if (count($list) === $per) $nav[] = btn('➡️', 'sup:audit:' . ($page + 1));
    $rows = []; if ($nav) $rows[] = $nav;
    $rows[] = [btn('🔙', 'sup:main')];
    reply($chat, $mid, $txt, $rows);
}
function show_chart7(int $chat, int $mid): void {
    $data = analytics_last_days(7);
    $maxC = max(1, max(array_column($data, 'created')));
    $maxA = max(1, max(array_column($data, 'activations')));
    $txt = "📈 <b>۷ روز اخیر</b>\n\n🔑 <b>کلیدهای ساخته‌شده:</b>\n";
    foreach ($data as $d) $txt .= "<code>{$d['date']}</code> " . str_repeat('█', (int)round(($d['created'] / $maxC) * 15)) . " <b>{$d['created']}</b>\n";
    $txt .= "\n🚀 <b>فعال‌سازی:</b>\n";
    foreach ($data as $d) $txt .= "<code>{$d['date']}</code> " . str_repeat('▓', (int)round(($d['activations'] / $maxA) * 15)) . " <b>{$d['activations']}</b>\n";
    reply($chat, $mid, $txt, [[btn('🔙', 'sup:main')]]);
}
