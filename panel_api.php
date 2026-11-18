<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/panel_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sp_security_headers();

function panel_response(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$input  = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$ip     = sp_client_ip();
$ua     = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

sp_ensure_schema();

// ---------- نشست ----------
$session = null;
$cookie  = (string)($_COOKIE['sp_panel'] ?? '');
if ($cookie) {
    $session = reseller_session_get($cookie);
    // نماینده غیرفعال‌شده فوراً بیرون می‌افتد
    if ($session && (($session['status'] ?? 'active') !== 'active')) {
        reseller_session_destroy($cookie);
        $session = null;
    }
}

function require_auth(?array $session): array {
    if (!$session) panel_response(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
    return $session;
}

function require_csrf(array $input): void {
    $csrf = (string)($_COOKIE['sp_csrf'] ?? '');
    $sent = (string)($input['csrf'] ?? '');
    if (!$csrf || !$sent || !hash_equals($csrf, $sent)) {
        panel_response(['ok' => false, 'error' => 'CSRF_INVALID'], 403);
    }
}

/** اگر ستون دسترسی در دیتابیس نباشد، پیش‌فرض «مجاز» است (قبلاً برعکس بود و همه‌چیز بلاک می‌شد) */
function require_perm(?array $res, string $perm): void {
    if (!$res) panel_response(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
    if ((int)($res[$perm] ?? 1) === 0) {
        panel_response(['ok' => false, 'error' => 'PERMISSION_DENIED', 'message' => 'شما دسترسی به این بخش را ندارید'], 403);
    }
}

function perm_list(array $s): array {
    $out = [];
    foreach (SP_PERMS as $p) $out[$p] = (int)($s[$p] ?? 1);
    return $out;
}

function owned_license(array $s, int $id): array {
    $lic = sp_lic_row($id);
    if (!$lic || (int)$lic['reseller_id'] !== (int)$s['id'] || (int)$lic['product_id'] !== (int)$s['product_id']) {
        panel_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }
    return $lic;
}

function panel_audit(array $s, string $action, ?int $targetId = null, array $meta = []): void {
    try {
        audit_log((int)$s['telegram_id'], 'Panel: ' . $s['display_name'], $action, 'license', $targetId, $meta);
    } catch (Throwable $e) { /* لاگ نباید عملیات را خراب کند */ }
}

function scope_of(array $s): array {
    return ['product_id' => (int)$s['product_id'], 'reseller_id' => (int)$s['id']];
}

function max_renew_error(array $s, int $seconds): ?string {
    $qd = (int)($s['quota_max_duration'] ?? 0);
    if ($qd > 0 && $seconds > $qd) return 'حداکثر مدت مجاز: ' . intdiv($qd, 86400) . ' روز';
    return null;
}

try {
    switch ($action) {
        // ==================== Auth ====================
        case 'login': {
            require_csrf($input);
            $u = trim((string)($input['username'] ?? ''));
            $p = (string)($input['password'] ?? '');
            if ($u === '' || $p === '') panel_response(['ok' => false, 'error' => 'MISSING'], 400);
            if (!rate_limit_ok($ip, 'panel_login', 10)) panel_response(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            $res = reseller_login_username($u, $p);
            if (!$res) { usleep(400000); panel_response(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401); }
            if (($res['status'] ?? 'active') !== 'active') panel_response(['ok' => false, 'error' => 'INACTIVE'], 403);
            reseller_session_cleanup();
            $s = reseller_session_create((int)$res['id'], $ip, $ua);
            sp_cookie('sp_panel', $s['token'], now() + 604800);
            panel_response(['ok' => true]);
        }

        case 'token_login': {
            $t = (string)($input['token'] ?? '');
            if ($t === '') panel_response(['ok' => false, 'error' => 'MISSING'], 400);
            if (!rate_limit_ok($ip, 'panel_login')) panel_response(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            $resId = reseller_consume_login_token($t);
            if (!$resId) panel_response(['ok' => false, 'error' => 'TOKEN_INVALID'], 401);
            $res = reseller_get($resId);
            if (!$res || $res['status'] !== 'active') panel_response(['ok' => false, 'error' => 'INACTIVE'], 403);
            $s = reseller_session_create($resId, $ip, $ua);
            sp_cookie('sp_panel', $s['token'], now() + 604800);
            panel_response(['ok' => true]);
        }

        case 'logout': {
            if ($cookie) reseller_session_destroy($cookie);
            sp_cookie('sp_panel', '', now() - 3600);
            panel_response(['ok' => true]);
        }

        // ==================== Read ====================
        case 'me': {
            $s = require_auth($session);
            $product = product_get((int)$s['product_id']);
            panel_response([
                'ok' => true,
                'reseller' => [
                    'id' => (int)$s['id'],
                    'display_name' => $s['display_name'],
                    'telegram_id' => (int)$s['telegram_id'],
                    'status' => $s['status'],
                    'quota_max_keys' => $s['quota_max_keys'] !== null ? (int)$s['quota_max_keys'] : null,
                    'quota_max_users' => !empty($s['quota_max_users_per_key']) ? (int)$s['quota_max_users_per_key'] : null,
                    'quota_max_duration' => !empty($s['quota_max_duration']) ? (int)$s['quota_max_duration'] : null,
                    'used_keys' => sp_reseller_used_keys($s),
                    'has_password' => !empty($s['panel_password_hash']),
                    'perms' => perm_list($s),
                ],
                'product' => $product ? [
                    'id' => (int)$product['id'], 'slug' => $product['slug'], 'name' => $product['name'],
                ] : null,
            ]);
        }

        case 'stats': {
            $s = require_auth($session);
            $r = sp_lic_list(scope_of($s), 'all', '', 'new', 0, 1);
            panel_response(['ok' => true, 'stats' => [
                'total'  => (int)$r['counts']['all'],
                'active' => (int)($r['counts']['active'] ?? 0),
                'online' => active_sessions_count((int)$s['product_id'], (int)$s['id']),
                'counts' => $r['counts'],
            ]]);
        }

        case 'keys': {
            $s = require_auth($session);
            $page  = max(0, (int)($_GET['page'] ?? 0));
            $limit = max(5, min(50, (int)($_GET['limit'] ?? 10)));
            $r = sp_lic_list(scope_of($s), sp_clean_filter((string)($_GET['filter'] ?? 'all')),
                trim((string)($_GET['search'] ?? '')), (string)($_GET['sort'] ?? 'new'), $page, $limit);
            panel_response([
                'ok' => true,
                'keys' => array_map('sp_lic_format', $r['rows']),
                'total' => $r['total'], 'page' => $page,
                'pages' => max(1, (int)ceil($r['total'] / $limit)),
                'counts' => $r['counts'],
            ]);
        }

        case 'key': {
            $s = require_auth($session);
            $id = (int)($_GET['id'] ?? 0);
            $lic = owned_license($s, $id);
            $devices = list_license_devices($id);
            $key = sp_lic_format($lic);
            $key['current_users'] = count($devices);
            panel_response(['ok' => true, 'key' => $key, 'devices' => array_map('sp_dev_format', $devices)]);
        }

        case 'export': {
            $s = require_auth($session);
            $format = ($_GET['format'] ?? 'txt') === 'csv' ? 'csv' : 'txt';
            $r = sp_lic_list(scope_of($s), sp_clean_filter((string)($_GET['filter'] ?? 'all')), '', 'old', 0, 1);
            panel_response(['ok' => true, 'count' => $r['total'],
                'content' => sp_export_text($r['all_rows_matched'], $format),
                'filename' => 'keys_' . date('Ymd_His') . '.' . $format]);
        }

        // ==================== Write ====================
        case 'create_key': {
            $s = require_auth($session);
            require_perm($s, 'can_create_keys');
            require_csrf($input);
            $duration = sp_clean_duration((int)($input['duration'] ?? 0));
            $maxUsers = max(1, min(10000, (int)($input['max_users'] ?? 1)));
            $count    = max(1, min(50, (int)($input['count'] ?? 1)));
            $note     = sp_str($input['note'] ?? '', 200);

            $err = sp_reseller_quota_error($s, $duration, $maxUsers, $count);
            if ($err) panel_response(['ok' => false, 'error' => $err], 400);

            $created = []; $lastErr = '';
            for ($i = 0; $i < $count; $i++) {
                $res = key_create_for_product((int)$s['product_id'], $duration, $maxUsers, (int)$s['telegram_id'], (int)$s['id'], $note !== '' ? $note : null);
                if (!empty($res['ok'])) {
                    $created[] = $res['key'];
                } else {
                    $lastErr = (string)($res['msg'] ?? 'خطا در ساخت کلید');
                    break;
                }
            }
            if (!$created) panel_response(['ok' => false, 'error' => $lastErr ?: 'خطا در ساخت کلید'], 400);
            panel_audit($s, 'key_create', null, ['qty' => count($created)]);
            panel_response(['ok' => true, 'count' => count($created), 'keys' => $created, 'key' => $created[0],
                'partial' => count($created) < $count, 'message' => $lastErr]);
        }

        case 'update_key': {
            $s = require_auth($session);
            require_csrf($input);
            $id = (int)($input['id'] ?? 0);
            $lic = owned_license($s, $id);
            $upd = [];
            if (array_key_exists('note', $input)) $upd['note'] = $input['note'];
            if (array_key_exists('max_users', $input)) {
                $mu = max(1, min(10000, (int)$input['max_users']));
                if ($mu !== (int)$lic['max_users']) { // فقط اگر واقعاً تغییر کرده باشد دسترسی بررسی می‌شود
                    require_perm($s, 'can_edit_users');
                    $qu = (int)($s['quota_max_users_per_key'] ?? 0);
                    if ($qu > 0 && $mu > $qu) panel_response(['ok' => false, 'error' => "حداکثر تعداد کاربر (دستگاه) برای هر کلید: {$qu}"], 400);
                    $upd['max_users'] = $mu;
                }
            }
            sp_lic_update($id, $upd);
            panel_audit($s, 'key_update', $id);
            panel_response(['ok' => true]);
        }

        case 'renew_key': {
            $s = require_auth($session);
            require_perm($s, 'can_renew_keys');
            require_csrf($input);
            $id  = (int)($input['id'] ?? 0);
            $sec = (int)($input['seconds'] ?? 0);
            owned_license($s, $id);
            if ($sec <= 0) panel_response(['ok' => false, 'error' => 'مدت تمدید نامعتبر است'], 400);
            if ($e = max_renew_error($s, $sec)) panel_response(['ok' => false, 'error' => $e], 400);
            $r = key_renew($id, sp_clean_duration($sec));
            if (empty($r['ok'])) panel_response(['ok' => false, 'error' => $r['msg'] ?? 'خطا در تمدید'], 400);
            panel_audit($s, 'key_renew', $id, ['seconds' => $sec]);
            panel_response(['ok' => true]);
        }

        case 'ban_key':
        case 'unban_key': {
            $s = require_auth($session);
            require_perm($s, 'can_ban_keys');
            require_csrf($input);
            $id = (int)($input['id'] ?? 0);
            owned_license($s, $id);
            if ($action === 'ban_key') key_ban($id); else key_unban($id);
            panel_audit($s, $action, $id);
            panel_response(['ok' => true]);
        }

        case 'delete_key': {
            $s = require_auth($session);
            require_perm($s, 'can_delete_keys');
            require_csrf($input);
            $id = (int)($input['id'] ?? 0);
            owned_license($s, $id);
            key_delete($id);
            panel_audit($s, 'key_delete', $id);
            panel_response(['ok' => true]);
        }

        case 'bulk': {
            $s = require_auth($session);
            require_csrf($input);
            $op = (string)($input['op'] ?? '');
            $permFor = ['ban' => 'can_ban_keys', 'unban' => 'can_ban_keys', 'renew' => 'can_renew_keys', 'delete' => 'can_delete_keys'];
            if (!isset($permFor[$op])) panel_response(['ok' => false, 'error' => 'عملیات نامعتبر'], 400);
            require_perm($s, $permFor[$op]);
            $ids = sp_lic_scope_ids((array)($input['ids'] ?? []), scope_of($s));
            if (!$ids) panel_response(['ok' => false, 'error' => 'کلیدی انتخاب نشده'], 400);
            $sec = sp_clean_duration((int)($input['seconds'] ?? 0));
            if ($op === 'renew') {
                if ($sec <= 0) panel_response(['ok' => false, 'error' => 'مدت تمدید نامعتبر است'], 400);
                if ($e = max_renew_error($s, $sec)) panel_response(['ok' => false, 'error' => $e], 400);
            }
            $r = sp_lic_bulk($op, $ids, $sec);
            panel_audit($s, 'bulk_' . $op, null, ['ids' => count($ids), 'done' => $r['done']]);
            panel_response(['ok' => true] + $r);
        }

        case 'reset_all_devices': {
            $s = require_auth($session);
            require_perm($s, 'can_reset_devices');
            require_csrf($input);
            $id = (int)($input['license_id'] ?? 0);
            owned_license($s, $id);
            device_reset_all($id);
            panel_audit($s, 'devices_reset_all', $id);
            panel_response(['ok' => true]);
        }

        case 'reset_device':
        case 'delete_device': {
            $s = require_auth($session);
            require_perm($s, 'can_reset_devices');
            require_csrf($input);
            $licId = (int)($input['license_id'] ?? 0);
            $devId = (int)($input['device_row_id'] ?? 0);
            owned_license($s, $licId);
            $st = db()->prepare('SELECT * FROM license_devices WHERE id=? AND license_id=?');
            $st->execute([$devId, $licId]);
            $dev = $st->fetch();
            if (!$dev) panel_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            device_reset_one($licId, $dev['device_id']);
            panel_audit($s, 'device_reset', $licId);
            panel_response(['ok' => true]);
        }

        case 'change_password': {
            $s = require_auth($session);
            require_csrf($input);
            if (!rate_limit_ok($ip, 'panel_pwd')) panel_response(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            $old = (string)($input['old'] ?? '');
            $new = (string)($input['new'] ?? '');
            if (strlen($new) < 6) panel_response(['ok' => false, 'error' => 'کلمه عبور حداقل ۶ کاراکتر باشد'], 400);
            if (!empty($s['panel_password_hash']) && !password_verify($old, (string)$s['panel_password_hash'])) {
                panel_response(['ok' => false, 'error' => 'رمز فعلی اشتباه است'], 400);
            }
            reseller_set_password((int)$s['id'], $new);
            panel_audit($s, 'password_change');
            panel_response(['ok' => true]);
        }

        default:
            panel_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 404);
    }
} catch (Throwable $e) {
    log_msg('error', 'panel_api', ['action' => $action, 'err' => $e->getMessage()]);
    panel_response(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'خطای داخلی سرور'], 500);
}
