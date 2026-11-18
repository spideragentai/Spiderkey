<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';
require_once __DIR__ . '/panel_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sp_security_headers();

// ============================================================
// AUTH
// ============================================================
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_cookie_valid(): bool {
    $cookie = $_COOKIE['adm_sess'] ?? '';
    if (!$cookie) return false;
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2) return false;
    [$token, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $token, SECRET_SALT), $sig)) return false;
    $stored = setting_get('admin_token');
    return $stored && hash_equals((string)$stored, $token);
}

if (!admin_cookie_valid()) respond(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

if ($method === 'POST') {
    $csrf_cookie = (string)($_COOKIE['adm_csrf'] ?? '');
    $csrf_body   = (string)($input['csrf'] ?? '');
    if (!$csrf_cookie || !$csrf_body || !hash_equals($csrf_cookie, $csrf_body)) {
        respond(['ok' => false, 'error' => 'CSRF_INVALID'], 403);
    }
}

sp_ensure_schema();
$action = (string)($_GET['action'] ?? '');

// کمک‌کننده‌ها
function adm_int(array $in, string $k, int $d = 0): int { return (int)($in[$k] ?? $d); }
function adm_post_only(string $method): void {
    if ($method !== 'POST') respond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
function adm_quota(array $in, string $k, $current, int $mul = 1) {
    if (!array_key_exists($k, $in)) return $current;
    $v = $in[$k];
    if ($v === null || $v === '' || $v === 'unlimited') return null;
    $n = max(0, (int)$v) * $mul;
    return $n === 0 ? null : $n;
}

// ============================================================
// ROUTER
// ============================================================
try {
    switch ($action) {

        // ---------------------------------------------------- DASHBOARD
        case 'dashboard': {
            $pdo = db();
            $s = stats_for_product(0);
            $now = now();

            $q = function (string $sql, array $p = []) use ($pdo): int {
                $st = $pdo->prepare($sql);
                $st->execute($p);
                return (int)$st->fetchColumn();
            };

            $products = $q('SELECT COUNT(*) FROM products WHERE id > 1');
            $errs24h  = $q("SELECT COUNT(*) FROM logs WHERE level='error' AND created_at > ?", [$now - 86400]);
            $api24h   = $q('SELECT COUNT(*) FROM api_logs WHERE created_at > ?', [$now - 86400]);
            $devices  = $q('SELECT COUNT(*) FROM license_devices');

            // نمودار ۷ روز اخیر
            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $ts = $now - $i * 86400;
                $days[gmdate('Y-m-d', $ts)] = ['date' => gmdate('m/d', $ts), 'api' => 0, 'keys' => 0];
            }
            $since = $now - 7 * 86400;
            $st = $pdo->prepare("SELECT strftime('%Y-%m-%d', created_at, 'unixepoch') d, COUNT(*) c FROM api_logs WHERE created_at > ? GROUP BY d");
            $st->execute([$since]);
            foreach ($st->fetchAll() as $r) if (isset($days[$r['d']])) $days[$r['d']]['api'] = (int)$r['c'];
            $st = $pdo->prepare("SELECT strftime('%Y-%m-%d', created_at, 'unixepoch') d, COUNT(*) c FROM licenses WHERE created_at > ? GROUP BY d");
            $st->execute([$since]);
            foreach ($st->fetchAll() as $r) if (isset($days[$r['d']])) $days[$r['d']]['keys'] = (int)$r['c'];

            $topProducts = $pdo->query("SELECT p.name, COUNT(l.id) c FROM products p
                LEFT JOIN licenses l ON l.product_id = p.id GROUP BY p.id ORDER BY c DESC LIMIT 5")->fetchAll();

            $recent = $pdo->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 8')->fetchAll();
            $audit  = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();

            respond([
                'ok' => true,
                'stats' => [
                    'keys_total'  => (int)($s['keys_total'] ?? 0),
                    'keys_active' => (int)($s['keys_active'] ?? 0),
                    'keys_unused' => (int)($s['keys_unused'] ?? 0),
                    'keys_banned' => (int)($s['keys_banned'] ?? 0),
                    'devices'     => $devices,
                    'resellers'   => (int)($s['resellers'] ?? 0),
                    'products'    => $products,
                    'sessions'    => (int)active_sessions_count(),
                    'api_24h'     => $api24h,
                    'errors_24h'  => $errs24h,
                ],
                'recent' => array_map(fn($r) => [
                    'action' => $r['action'], 'status' => $r['status'], 'ip' => $r['ip'] ?? '', 'time' => (int)$r['created_at'],
                ], $recent),
                'audit' => array_map(fn($r) => [
                    'action' => $r['action'], 'target' => $r['target_type'],
                    'target_id' => $r['target_id'] ? (int)$r['target_id'] : null, 'time' => (int)$r['created_at'],
                ], $audit),
                'top_products' => array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['c']], $topProducts),
                'chart' => array_values($days),
            ]);
        }

        // ---------------------------------------------------- KEYS
        case 'keys': {
            $page  = max(0, (int)($_GET['page'] ?? 0));
            $limit = max(5, min(100, (int)($_GET['limit'] ?? 20)));
            $scope = [];
            $pid = (int)($_GET['product_id'] ?? 0);
            if ($pid > 0) $scope['product_id'] = $pid;
            if (isset($_GET['reseller_id']) && $_GET['reseller_id'] !== '') $scope['reseller_id'] = (int)$_GET['reseller_id'];

            $r = sp_lic_list($scope, sp_clean_filter((string)($_GET['filter'] ?? 'all')),
                trim((string)($_GET['search'] ?? '')), (string)($_GET['sort'] ?? 'new'), $page, $limit);
            respond([
                'ok' => true,
                'keys' => array_map('sp_lic_format', $r['rows']),
                'total' => $r['total'], 'page' => $page,
                'pages' => max(1, (int)ceil($r['total'] / $limit)),
                'counts' => $r['counts'],
            ]);
        }

        case 'key': {
            $id = (int)($_GET['id'] ?? 0);
            $lic = sp_lic_row($id);
            if (!$lic) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            $devs = list_license_devices($id);
            $key = sp_lic_format($lic);
            $key['current_users'] = count($devs);
            respond(['ok' => true, 'key' => $key, 'devices' => array_map('sp_dev_format', $devs)]);
        }

        case 'create_key': {
            adm_post_only($method);
            $productId = adm_int($input, 'product_id');
            $duration  = sp_clean_duration(adm_int($input, 'duration'));
            $maxUsers  = max(1, min(10000, adm_int($input, 'max_users', 1)));
            $count     = max(1, min(100, adm_int($input, 'count', 1)));
            $note      = sp_str($input['note'] ?? '', 200);

            if ($productId <= 0 || !product_get($productId)) respond(['ok' => false, 'error' => 'محصول نامعتبر است'], 400);

            if ($count === 1) {
                $res = key_create_for_product($productId, $duration, $maxUsers, 0, null, $note !== '' ? $note : null);
                if (empty($res['ok'])) respond(['ok' => false, 'error' => $res['msg'] ?? 'خطا در ساخت کلید'], 400);
                audit_log(0, 'Admin Panel', 'key_create', 'license', $res['id'], ['product' => $productId]);
                respond(['ok' => true, 'key' => $res['key'], 'keys' => [$res['key']], 'count' => 1, 'id' => $res['id']]);
            }
            $res = bulk_create_keys($productId, $duration, $maxUsers, $count, 0, null);
            if (empty($res['ok'])) respond(['ok' => false, 'error' => $res['msg'] ?? 'خطا در ساخت کلیدها'], 400);
            $keys = [];
            foreach ($res['created'] as $k) {
                $keys[] = $k['key'];
                if (!empty($k['id'])) sp_set_note((int)$k['id'], $note);
            }
            audit_log(0, 'Admin Panel', 'bulk_create', 'license', null, ['product' => $productId, 'qty' => $res['count']]);
            respond(['ok' => true, 'count' => $res['count'], 'keys' => $keys]);
        }

        case 'update_key': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if (!sp_lic_row($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            sp_lic_update($id, $input);
            audit_log(0, 'Admin Panel', 'key_update', 'license', $id);
            respond(['ok' => true]);
        }

        case 'ban_key': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if (!key_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            key_ban($id);
            audit_log(0, 'Admin Panel', 'key_ban', 'license', $id);
            respond(['ok' => true]);
        }
        case 'unban_key': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if (!key_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            key_unban($id);
            audit_log(0, 'Admin Panel', 'key_unban', 'license', $id);
            respond(['ok' => true]);
        }
        case 'renew_key': {
            adm_post_only($method);
            $id  = adm_int($input, 'id');
            $sec = adm_int($input, 'seconds');
            if ($sec <= 0) respond(['ok' => false, 'error' => 'مدت تمدید نامعتبر است'], 400);
            if (!key_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            $r = key_renew($id, sp_clean_duration($sec));
            if (empty($r['ok'])) respond(['ok' => false, 'error' => $r['msg'] ?? 'خطا در تمدید'], 400);
            audit_log(0, 'Admin Panel', 'key_renew', 'license', $id, ['seconds' => $sec]);
            respond(['ok' => true]);
        }
        case 'delete_key': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if (!key_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            key_delete($id);
            audit_log(0, 'Admin Panel', 'key_delete', 'license', $id);
            respond(['ok' => true]);
        }

        case 'bulk': {
            adm_post_only($method);
            $op  = (string)($input['op'] ?? '');
            $ids = sp_lic_scope_ids((array)($input['ids'] ?? []), []);
            if (!in_array($op, ['ban', 'unban', 'delete', 'renew'], true)) respond(['ok' => false, 'error' => 'عملیات نامعتبر'], 400);
            if (!$ids) respond(['ok' => false, 'error' => 'کلیدی انتخاب نشده'], 400);
            $sec = sp_clean_duration(adm_int($input, 'seconds'));
            if ($op === 'renew' && $sec <= 0) respond(['ok' => false, 'error' => 'مدت تمدید نامعتبر است'], 400);
            $r = sp_lic_bulk($op, $ids, $sec);
            audit_log(0, 'Admin Panel', 'bulk_' . $op, 'license', null, ['ids' => count($ids), 'done' => $r['done']]);
            respond(['ok' => true] + $r);
        }

        // ---------------------------------------------------- DEVICES
        case 'reset_device': {
            adm_post_only($method);
            $licId    = adm_int($input, 'license_id');
            $devRowId = adm_int($input, 'device_row_id');
            $st = db()->prepare('SELECT * FROM license_devices WHERE id=? AND license_id=?');
            $st->execute([$devRowId, $licId]);
            $dev = $st->fetch();
            if (!$dev) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            device_reset_one($licId, $dev['device_id']);
            audit_log(0, 'Admin Panel', 'device_reset', 'license', $licId, ['device' => $dev['device_id']]);
            respond(['ok' => true]);
        }
        case 'reset_all_devices': {
            adm_post_only($method);
            $licId = adm_int($input, 'license_id');
            if (!key_get($licId)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            device_reset_all($licId);
            audit_log(0, 'Admin Panel', 'devices_reset_all', 'license', $licId);
            respond(['ok' => true]);
        }

        case 'devices': {
            $page  = max(0, (int)($_GET['page'] ?? 0));
            $limit = 20;
            $q     = trim((string)($_GET['search'] ?? ''));
            $pdo   = db();
            $where = ''; $p = [];
            if ($q !== '') {
                $like  = sp_like($q);
                $where = "WHERE (d.device_id LIKE ? ESCAPE '\\' OR d.device_model LIKE ? ESCAPE '\\' OR d.last_ip LIKE ? ESCAPE '\\')";
                $p = [$like, $like, $like];
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM license_devices d $where");
            $st->execute($p);
            $total = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT d.*, l.key_encrypted, p.name AS product_name
                FROM license_devices d
                JOIN licenses l ON l.id = d.license_id
                JOIN products p ON p.id = l.product_id
                $where ORDER BY d.last_login DESC LIMIT ? OFFSET ?");
            $st->execute(array_merge($p, [$limit, $page * $limit]));
            $rows = $st->fetchAll();
            respond([
                'ok' => true,
                'devices' => array_map(fn($d) => [
                    'id' => (int)$d['id'], 'license_id' => (int)$d['license_id'],
                    'product' => $d['product_name'],
                    'key_masked' => mask_key(decrypt_key($d['key_encrypted'])),
                    'device_id' => substr((string)$d['device_id'], 0, 20) . '…',
                    'model' => $d['device_model'], 'android' => $d['android_version'],
                    'last_login' => (int)$d['last_login'], 'login_count' => (int)$d['login_count'],
                    'ip' => $d['last_ip'],
                ], $rows),
                'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $limit)),
            ]);
        }

        // ---------------------------------------------------- PRODUCTS
        case 'products': {
            $list = product_list_all();
            respond(['ok' => true, 'products' => array_map(fn($p) => [
                'id' => (int)$p['id'], 'slug' => $p['slug'], 'name' => $p['name'],
                'status' => $p['status'], 'api_key' => $p['api_key'], 'custom_domain' => (string)($p['custom_domain'] ?? ''),
                'lic_count' => (int)$p['lic_count'], 'res_count' => (int)$p['res_count'],
                'created_at' => (int)$p['created_at'],
            ], $list)]);
        }
        case 'create_product': {
            adm_post_only($method);
            $slug = strtolower(trim((string)($input['slug'] ?? '')));
            $name = sp_str($input['name'] ?? '', 80);
            if ($name === '') $name = $slug;
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $slug)) {
                respond(['ok' => false, 'error' => 'اسلاگ نامعتبر (۲ تا ۳۲ کاراکتر: حروف انگلیسی کوچک، عدد و -)'], 400);
            }
            $res = product_create($slug, $name, 0);
            if (empty($res['ok'])) respond(['ok' => false, 'error' => $res['msg'] ?? 'خطا'], 400);
            audit_log(0, 'Admin Panel', 'product_create', 'product', $res['id']);
            respond(['ok' => true, 'id' => $res['id'], 'slug' => $res['slug'], 'api_key' => $res['api_key']]);
        }
        case 'update_product': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            $p = product_get($id);
            if (!$p) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            $name = sp_str($input['name'] ?? $p['name'], 80);
            if ($name === '') respond(['ok' => false, 'error' => 'نام محصول خالی است'], 400);
            $status = in_array($input['status'] ?? '', ['active', 'inactive'], true) ? $input['status'] : $p['status'];
            if ($id <= 1 && $status !== 'active') respond(['ok' => false, 'error' => 'محصول پیش‌فرض را نمی‌توان غیرفعال کرد'], 400);
            db()->prepare('UPDATE products SET name=?, status=? WHERE id=?')->execute([$name, $status, $id]);
            if (array_key_exists('custom_domain', $input)) {
                $dom = strtolower(trim((string)$input['custom_domain']));
                if ($dom !== '' && !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $dom)) respond(['ok' => false, 'error' => 'دامنه نامعتبر است'], 400);
                product_set_domain($id, $dom !== '' ? $dom : null);
            }
            audit_log(0, 'Admin Panel', 'product_update', 'product', $id, ['name' => $name, 'status' => $status]);
            respond(['ok' => true]);
        }
        case 'delete_product': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if ($id <= 1) respond(['ok' => false, 'error' => 'محصول پیش‌فرض قابل حذف نیست'], 400);
            if (!product_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            product_delete_cascade($id);
            audit_log(0, 'Admin Panel', 'product_delete', 'product', $id);
            respond(['ok' => true]);
        }

        // ---------------------------------------------------- RESELLERS & PANELS
        case 'resellers': {
            $list = db()->query('SELECT r.*, p.slug AS product_slug, p.name AS product_name,
                (SELECT COUNT(*) FROM licenses l WHERE l.reseller_id = r.id) AS key_count
                FROM resellers r JOIN products p ON p.id = r.product_id ORDER BY r.id DESC')->fetchAll();
            respond(['ok' => true, 'resellers' => array_map(function ($r) {
                $perms = [];
                foreach (SP_PERMS as $perm) $perms[$perm] = (int)($r[$perm] ?? 1);
                return [
                    'id' => (int)$r['id'],
                    'telegram_id' => (int)$r['telegram_id'],
                    'display_name' => $r['display_name'],
                    'product_id' => (int)$r['product_id'],
                    'product_name' => $r['product_name'],
                    'status' => $r['status'],
                    'has_panel' => !empty($r['panel_password_hash']),
                    'key_count' => (int)$r['key_count'],
                    'used_keys' => max((int)$r['used_keys'], (int)$r['key_count']),
                    'quota_max_keys' => $r['quota_max_keys'] !== null ? (int)$r['quota_max_keys'] : null,
                    'quota_max_users' => $r['quota_max_users_per_key'] !== null ? (int)$r['quota_max_users_per_key'] : null,
                    'quota_max_duration' => $r['quota_max_duration'] !== null ? (int)$r['quota_max_duration'] : null,
                    'perms' => $perms,
                    'created_at' => (int)$r['created_at'],
                ];
            }, $list)]);
        }

        case 'create_reseller': {
            adm_post_only($method);
            $tg   = adm_int($input, 'telegram_id');
            $name = sp_str($input['name'] ?? '', 80);
            $pid  = adm_int($input, 'product_id');
            if ($tg <= 0 || strlen($name) < 2 || $pid <= 0) respond(['ok' => false, 'error' => 'اطلاعات ناقص'], 400);
            $res = reseller_create($tg, $name, $pid, ['max_keys' => 10, 'max_users' => 1, 'max_duration' => 2592000], 0);
            if (empty($res['ok'])) respond(['ok' => false, 'error' => $res['msg'] ?? 'خطا'], 400);
            audit_log(0, 'Admin Panel', 'reseller_create', 'reseller', $res['id']);
            respond(['ok' => true, 'id' => $res['id']]);
        }

        case 'create_panel': {
            adm_post_only($method);
            $tg   = adm_int($input, 'telegram_id');
            $name = sp_str($input['name'] ?? '', 80);
            $pid  = adm_int($input, 'product_id');
            $pass = (string)($input['password'] ?? '');
            if ($tg <= 0 || strlen($name) < 2 || $pid <= 0 || strlen($pass) < 6) {
                respond(['ok' => false, 'error' => 'اطلاعات ناقص است (رمز حداقل ۶ کاراکتر)'], 400);
            }
            if (!product_get($pid)) respond(['ok' => false, 'error' => 'محصول نامعتبر است'], 400);

            $st = db()->prepare('SELECT id FROM resellers WHERE telegram_id=? AND product_id=?');
            $st->execute([$tg, $pid]);
            $existing = $st->fetch();

            if ($existing) {
                $rid = (int)$existing['id'];
                db()->prepare("UPDATE resellers SET display_name=?, panel_password_hash=?, status='active' WHERE id=?")
                    ->execute([$name, password_hash($pass, PASSWORD_BCRYPT), $rid]);
                audit_log(0, 'Admin Panel', 'panel_update', 'reseller', $rid);
                respond(['ok' => true, 'id' => $rid, 'updated' => true]);
            }

            $res = reseller_create($tg, $name, $pid, ['max_keys' => 10000, 'max_users' => 1000, 'max_duration' => 0], 0);
            if (empty($res['ok'])) respond(['ok' => false, 'error' => $res['msg'] ?? 'خطا'], 400);
            reseller_set_password((int)$res['id'], $pass);
            audit_log(0, 'Admin Panel', 'panel_create', 'reseller', $res['id']);
            respond(['ok' => true, 'id' => $res['id']]);
        }

        case 'update_reseller': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            $r = reseller_get($id);
            if (!$r) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);

            $name = sp_str($input['display_name'] ?? $r['display_name'], 80);
            if (strlen($name) < 2) respond(['ok' => false, 'error' => 'نام نامعتبر است'], 400);
            $status = in_array($input['status'] ?? '', ['active', 'inactive'], true) ? $input['status'] : $r['status'];

            $qk = adm_quota($input, 'quota_max_keys', $r['quota_max_keys']);
            $qu = adm_quota($input, 'quota_max_users', $r['quota_max_users_per_key']);
            $qd = adm_quota($input, 'quota_max_duration_days', $r['quota_max_duration'], 86400);

            db()->prepare('UPDATE resellers SET display_name=?, status=?, quota_max_keys=?, quota_max_users_per_key=?, quota_max_duration=? WHERE id=?')
                ->execute([$name, $status, $qk, $qu, $qd, $id]);

            if (isset($input['perms']) && is_array($input['perms'])) {
                foreach (SP_PERMS as $perm) {
                    if (!array_key_exists($perm, $input['perms'])) continue;
                    sp_ensure_col('resellers', $perm, 'INTEGER DEFAULT 1');
                    if (sp_col_exists('resellers', $perm)) {
                        db()->prepare("UPDATE resellers SET $perm = ? WHERE id = ?")->execute([!empty($input['perms'][$perm]) ? 1 : 0, $id]);
                    }
                }
            }

            $newPass = (string)($input['password'] ?? '');
            if ($newPass !== '') {
                if (strlen($newPass) < 6) respond(['ok' => false, 'error' => 'رمز جدید حداقل ۶ کاراکتر باشد'], 400);
                reseller_set_password($id, $newPass);
            }
            audit_log(0, 'Admin Panel', 'reseller_update', 'reseller', $id);
            respond(['ok' => true]);
        }

        case 'delete_reseller': {
            adm_post_only($method);
            $id = adm_int($input, 'id');
            if (!reseller_get($id)) respond(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            reseller_delete($id);
            audit_log(0, 'Admin Panel', 'reseller_delete', 'reseller', $id);
            respond(['ok' => true]);
        }

        // ---------------------------------------------------- LOGS
        case 'logs': {
            $type  = (string)($_GET['type'] ?? 'api');
            $page  = max(0, (int)($_GET['page'] ?? 0));
            $q     = trim((string)($_GET['q'] ?? ''));
            $level = trim((string)($_GET['level'] ?? ''));
            $limit = 30;
            $pdo   = db();
            $like  = sp_like($q);

            if ($type === 'audit') {
                $table = 'audit_log'; $w = []; $p = [];
                if ($q !== '') { $w[] = "(action LIKE ? ESCAPE '\\' OR target_type LIKE ? ESCAPE '\\' OR admin_name LIKE ? ESCAPE '\\')"; array_push($p, $like, $like, $like); }
                $map = fn($r) => ['id' => (int)$r['id'], 'admin' => $r['admin_name'], 'action' => $r['action'],
                    'target' => $r['target_type'], 'target_id' => $r['target_id'] ? (int)$r['target_id'] : null, 'time' => (int)$r['created_at']];
            } elseif ($type === 'system') {
                $table = 'logs'; $w = []; $p = [];
                if ($q !== '') { $w[] = "(message LIKE ? ESCAPE '\\' OR context LIKE ? ESCAPE '\\')"; array_push($p, $like, $like); }
                if (in_array($level, ['error', 'warn', 'warning', 'info', 'debug'], true)) { $w[] = 'level = ?'; $p[] = $level; }
                $map = fn($r) => ['id' => (int)$r['id'], 'level' => $r['level'], 'message' => $r['message'],
                    'context' => $r['context'], 'time' => (int)$r['created_at']];
            } else {
                $table = 'api_logs'; $w = []; $p = [];
                if ($q !== '') { $w[] = "(action LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\' OR message LIKE ? ESCAPE '\\' OR status LIKE ? ESCAPE '\\')"; array_push($p, $like, $like, $like, $like); }
                $map = fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'license_id' => $r['license_id'] ? (int)$r['license_id'] : null,
                    'ip' => $r['ip'], 'status' => $r['status'], 'message' => $r['message'], 'time' => (int)$r['created_at']];
            }
            $whereSql = $w ? ' WHERE ' . implode(' AND ', $w) : '';
            $st = $pdo->prepare("SELECT COUNT(*) FROM $table$whereSql"); $st->execute($p);
            $total = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT * FROM $table$whereSql ORDER BY id DESC LIMIT ? OFFSET ?");
            $st->execute(array_merge($p, [$limit, $page * $limit]));
            respond(['ok' => true, 'logs' => array_map($map, $st->fetchAll()), 'total' => $total,
                'page' => $page, 'pages' => max(1, (int)ceil($total / $limit))]);
        }

        case 'purge_logs': {
            adm_post_only($method);
            $days = max(1, min(3650, adm_int($input, 'days', 30)));
            $cut  = now() - $days * 86400;
            $pdo  = db();
            $n = 0;
            foreach (['api_logs', 'logs'] as $t) {
                $st = $pdo->prepare("DELETE FROM $t WHERE created_at < ?");
                $st->execute([$cut]);
                $n += $st->rowCount();
            }
            if (!empty($input['audit'])) {
                $st = $pdo->prepare('DELETE FROM audit_log WHERE created_at < ?');
                $st->execute([$cut]);
                $n += $st->rowCount();
            }
            audit_log(0, 'Admin Panel', 'purge_logs', 'system', null, ['days' => $days, 'deleted' => $n]);
            respond(['ok' => true, 'deleted' => $n]);
        }

        // ---------------------------------------------------- SETTINGS / BACKUP / EXPORT
        case 'settings': {
            $scheme = sp_https() ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            $base = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
            $url  = "{$scheme}://{$host}{$base}";
            respond([
                'ok' => true,
                'settings' => [
                    'panel_url'   => (string)setting_get('panel_url', ''),
                    'panel_url_default' => "{$url}/panel.php",
                    'version'     => defined('SKY_VERSION') ? SKY_VERSION : '',
                    'php'         => PHP_VERSION,
                    'host'        => $host,
                    'scheme'      => $scheme,
                    'api_generic' => "{$url}/api.php",
                    'api_url'     => (string)setting_get('api_url', '') ?: ((string)DEFAULT_API_URL ?: "{$url}/api.php"),
                    'db_size'     => is_file(DB_PATH) ? (int)filesize(DB_PATH) : 0,
                ],
            ]);
        }
        case 'save_settings': {
            adm_post_only($method);
            $u = trim((string)($input['panel_url'] ?? ''));
            if ($u !== '' && !preg_match('#^https?://[^\s]+$#i', $u)) respond(['ok' => false, 'error' => 'آدرس نامعتبر است'], 400);
            setting_set('panel_url', $u);
            $a = trim((string)($input['api_url'] ?? ''));
            if ($a !== '' && !preg_match('#^https://[^\s]+$#i', $a)) respond(['ok' => false, 'error' => 'آدرس API باید با https:// شروع شود'], 400);
            if (array_key_exists('api_url', $input)) setting_set('api_url', $a);
            audit_log(0, 'Admin Panel', 'settings_save', 'system');
            respond(['ok' => true]);
        }

        case 'backup': {
            // اسنپ‌شات یکدست (VACUUM INTO)؛ کپی خام فایل در حالت WAL ممکن بود ناقص باشد
            $snap = db_snapshot();
            if (!$snap || !is_file($snap)) respond(['ok' => false, 'error' => 'ساخت بکاپ ناموفق بود'], 500);
            $size = (int)filesize($snap);
            audit_log(0, 'Admin Panel', 'backup', 'system', null, ['size' => $size]);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="spider_backup_' . date('Ymd_His') . '.sqlite"');
            header('Content-Length: ' . $size);
            readfile($snap);
            @unlink($snap);
            exit;
        }

        case 'setup_webhook': {
            adm_post_only($method);
            require_once __DIR__ . '/tg.php';
            $res = tg_setup_webhook(base_url() . '/bot.php');
            audit_log(0, 'Admin Panel', 'webhook_setup', 'system');
            respond(['ok' => tg_ok($res['telegram']), 'webhook' => $res['webhook'], 'error' => $res['telegram']['description'] ?? '']);
        }

        case 'health': {
            $h = system_health();
            respond(['ok' => true, 'health' => $h, 'online' => active_sessions_count()]);
        }

        case 'maintenance': {
            adm_post_only($method);
            $r = maintenance_run();
            audit_log(0, 'Admin Panel', 'cleanup', 'system', null, $r);
            respond(['ok' => true, 'result' => $r]);
        }

        case 'export': {
            $scope = [];
            $pid = (int)($_GET['product_id'] ?? 0);
            if ($pid > 0) $scope['product_id'] = $pid;
            $format = ($_GET['format'] ?? 'txt') === 'csv' ? 'csv' : 'txt';
            $r = sp_lic_list($scope, sp_clean_filter((string)($_GET['filter'] ?? 'all')), '', 'old', 0, 1);
            audit_log(0, 'Admin Panel', 'export', 'license', null, ['count' => $r['total']]);
            respond(['ok' => true, 'count' => $r['total'],
                'content' => sp_export_text($r['all_rows_matched'], $format),
                'filename' => 'keys_' . date('Ymd_His') . '.' . $format]);
        }

        default:
            respond(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 404);
    }
} catch (Throwable $e) {
    log_msg('error', 'admin_api', ['action' => $action, 'err' => $e->getMessage()]);
    respond(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'خطای داخلی سرور'], 500);
}
