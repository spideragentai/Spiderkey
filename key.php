<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// ============ Products ============
function product_create(string $slug, string $name, int $by): array {
    $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', strtolower($slug)));
    if (strlen($slug) < 2) return ['ok'=>false, 'msg'=>'اسلاگ نامعتبر'];
    if (product_by_slug($slug)) return ['ok'=>false, 'msg'=>'اسلاگ تکراری'];
    $api = 'pk_' . random_hex(24);
    db()->prepare("INSERT INTO products(slug,name,api_key,created_at,created_by) VALUES(?,?,?,?,?)")
        ->execute([$slug, $name, $api, now(), $by]);
    return ['ok'=>true, 'id'=>(int)db()->lastInsertId(), 'api_key'=>$api, 'slug'=>$slug];
}
function product_get(int $id): ?array {
    $s = db()->prepare("SELECT * FROM products WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null;
}
function product_by_slug(string $slug): ?array {
    $s = db()->prepare("SELECT * FROM products WHERE slug=?"); $s->execute([$slug]); return $s->fetch() ?: null;
}
function product_by_api_key(string $k): ?array {
    $s = db()->prepare("SELECT * FROM products WHERE api_key=? AND status='active'"); $s->execute([$k]); return $s->fetch() ?: null;
}
function product_by_host(string $h): ?array {
    $h = preg_replace('/:\d+$/', '', strtolower(trim($h)));
    if (!$h) return null;
    $s = db()->prepare("SELECT * FROM products WHERE custom_domain=? AND status='active'"); $s->execute([$h]); return $s->fetch() ?: null;
}
function product_list_all(): array {
    return db()->query("SELECT p.*,
        (SELECT COUNT(*) FROM licenses WHERE product_id=p.id) lic_count,
        (SELECT COUNT(*) FROM resellers WHERE product_id=p.id) res_count
        FROM products p ORDER BY p.id DESC")->fetchAll();
}
function product_set_domain(int $id, ?string $d): void {
    $d = $d ? preg_replace('/[^a-z0-9\.\-]/i', '', strtolower(trim($d))) : null;
    if ($d === '') $d = null;
    db()->prepare("UPDATE products SET custom_domain=? WHERE id=?")->execute([$d, $id]);
}

// ============ Resellers ============
function reseller_create(int $tgId, string $name, int $pid, array $opts, int $by): array {
    if ($tgId <= 0) return ['ok'=>false, 'msg'=>'آیدی نامعتبر'];
    $name = trim($name);
    if (mb_strlen($name) < 1) return ['ok'=>false, 'msg'=>'نام خالی'];
    $prod = product_get($pid);
    if (!$prod || $prod['status'] !== 'active') return ['ok'=>false, 'msg'=>'محصول نامعتبر'];

    $st = db()->prepare("SELECT * FROM resellers WHERE telegram_id=? AND product_id=?");
    $st->execute([$tgId, $pid]); $ex = $st->fetch();
    if ($ex) {
        if ($ex['status'] === 'active') {
            return ['ok'=>false, 'msg'=>'قبلاً نماینده این محصول است', 'existing_id'=>$ex['id'], 'product_id'=>$pid];
        }
        db_write(function($pdo) use ($ex, $name, $opts) {
            $pdo->prepare("UPDATE resellers SET status='active', display_name=?, quota_max_keys=?, quota_max_users_per_key=?, quota_max_duration=? WHERE id=?")
                ->execute([$name, $opts['max_keys'] ?? 10, $opts['max_users'] ?? 1, $opts['max_duration'] ?? 2592000, $ex['id']]);
        });
        return ['ok'=>true, 'id'=>(int)$ex['id'], 'reactivated'=>true, 'product'=>$prod];
    }
    db_write(function($pdo) use ($tgId, $name, $pid, $opts, $by) {
        $pdo->prepare("INSERT INTO resellers(telegram_id, display_name, product_id,
            quota_max_keys, quota_max_users_per_key, quota_max_duration,
            can_create_keys, can_ban_keys, can_renew_keys, can_reset_devices, can_edit_users, can_view_all_keys,
            created_at, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tgId, $name, $pid,
                $opts['max_keys'] ?? 10, $opts['max_users'] ?? 1, $opts['max_duration'] ?? 2592000,
                1, 1, 1, 1, 1, 0, now(), $by]);
    });
    return ['ok'=>true, 'id'=>(int)db()->lastInsertId(), 'reactivated'=>false, 'product'=>$prod];
}
function reseller_get(int $id): ?array {
    $s = db()->prepare("SELECT * FROM resellers WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null;
}
function reseller_by_telegram(int $tg): array {
    $s = db()->prepare("SELECT * FROM resellers WHERE telegram_id=? AND status='active'"); $s->execute([$tg]); return $s->fetchAll();
}
function reseller_update_status(int $id, string $st): void {
    db()->prepare("UPDATE resellers SET status=? WHERE id=?")->execute([$st, $id]);
}
function reseller_update_quota(int $id, string $f, ?int $v): void {
    if (!in_array($f, ['quota_max_keys','quota_max_users_per_key','quota_max_duration'], true)) return;
    db()->prepare("UPDATE resellers SET $f=? WHERE id=?")->execute([$v, $id]);
}
function reseller_toggle_perm(int $id, string $f): void {
    if (!in_array($f, ['can_create_keys','can_ban_keys','can_renew_keys','can_reset_devices','can_edit_users','can_view_all_keys','can_delete_keys'], true)) return;
    db()->prepare("UPDATE resellers SET $f=1-$f WHERE id=?")->execute([$id]);
}
function reseller_reset_used(int $id): void {
    db()->prepare("UPDATE resellers SET used_keys=0 WHERE id=?")->execute([$id]);
}
function reseller_delete(int $id): void {
    db()->prepare("DELETE FROM resellers WHERE id=?")->execute([$id]);
}
function reseller_list_by_product(int $pid): array {
    $s = db()->prepare("SELECT * FROM resellers WHERE product_id=? ORDER BY id DESC"); $s->execute([$pid]); return $s->fetchAll();
}
function reseller_can_create(array $r): array {
    if ((int)($r['can_create_keys'] ?? 1) === 0) return ['ok'=>false, 'msg'=>'اجازه ساخت کلید ندارید'];
    if ($r['status'] !== 'active') return ['ok'=>false, 'msg'=>'حساب غیرفعال است'];
    return ['ok'=>true];
}
function reseller_check_limits(array $r, int $dur, int $users): array {
    $c = reseller_can_create($r); if (!$c['ok']) return $c;
    if ($r['quota_max_keys'] !== null && (int)$r['quota_max_keys'] >= 0 && (int)$r['used_keys'] >= (int)$r['quota_max_keys'])
        return ['ok'=>false, 'msg'=>"سهمیه کلید تمام شد ({$r['used_keys']}/{$r['quota_max_keys']})"];
    if ($r['quota_max_users_per_key'] !== null && (int)$r['quota_max_users_per_key'] > 0 && $users > (int)$r['quota_max_users_per_key'])
        return ['ok'=>false, 'msg'=>"حداکثر کاربر مجاز: {$r['quota_max_users_per_key']}"];
    if ($r['quota_max_duration'] !== null && (int)$r['quota_max_duration'] > 0 && $dur > (int)$r['quota_max_duration'])
        return ['ok'=>false, 'msg'=>"حداکثر مدت مجاز: " . format_duration((int)$r['quota_max_duration'])];
    return ['ok'=>true];
}
function reseller_set_password(int $resellerId, string $password): void {
    if (strlen($password) < 6) throw new InvalidArgumentException('رمز حداقل ۶ کاراکتر');
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
    db()->prepare("UPDATE resellers SET panel_password_hash=? WHERE id=?")->execute([$hash, $resellerId]);
}
function reseller_login_username(string $username, string $password): ?array {
    $st = db()->prepare("SELECT * FROM resellers WHERE telegram_id=? AND panel_password_hash IS NOT NULL AND status='active'");
    $st->execute([(int)$username]); $r = $st->fetch();
    if (!$r) return null;
    if (!password_verify($password, $r['panel_password_hash'])) return null;
    return $r;
}
function reseller_generate_login_token(int $resellerId, int $ttl = 3600): string {
    $token = 'rl_' . random_hex(24);
    db()->prepare("INSERT INTO reseller_login_tokens(token, reseller_id, created_at, expires_at) VALUES(?,?,?,?)")
        ->execute([$token, $resellerId, now(), now() + $ttl]);
    return $token;
}
function reseller_consume_login_token(string $token): ?int {
    $st = db()->prepare("SELECT * FROM reseller_login_tokens WHERE token=?"); $st->execute([$token]);
    $r = $st->fetch();
    if (!$r || $r['used_at'] !== null || (int)$r['expires_at'] < now()) return null;
    db()->prepare("UPDATE reseller_login_tokens SET used_at=? WHERE token=?")->execute([now(), $token]);
    return (int)$r['reseller_id'];
}
function reseller_session_create(int $resellerId, string $ip, string $ua): array {
    $token = 'rs_' . random_hex(32);
    $exp = now() + 604800;
    db()->prepare("INSERT INTO reseller_sessions(reseller_id, session_token, created_at, expires_at, last_seen, ip, user_agent) VALUES(?,?,?,?,?,?,?)")
        ->execute([$resellerId, $token, now(), $exp, now(), $ip, substr($ua, 0, 500)]);
    return ['token'=>$token, 'expires_at'=>$exp];
}
function reseller_session_get(string $token): ?array {
    $st = db()->prepare("SELECT rs.session_token, rs.expires_at as sess_exp, r.*
        FROM reseller_sessions rs JOIN resellers r ON r.id=rs.reseller_id
        WHERE rs.session_token=? AND rs.expires_at>? AND r.status='active'");
    $st->execute([$token, now()]); $r = $st->fetch();
    if (!$r) return null;
    db()->prepare("UPDATE reseller_sessions SET last_seen=? WHERE session_token=?")->execute([now(), $token]);
    return $r;
}
function reseller_session_destroy(string $token): void {
    db()->prepare("DELETE FROM reseller_sessions WHERE session_token=?")->execute([$token]);
}
function reseller_session_cleanup(): void {
    db()->prepare("DELETE FROM reseller_sessions WHERE expires_at<?")->execute([now()]);
    db()->prepare("DELETE FROM reseller_login_tokens WHERE expires_at<?")->execute([now() - 86400]);
}

// ============ Plans ============
function plan_get_all(): array {
    return db()->query("SELECT * FROM plans ORDER BY stars_price ASC")->fetchAll();
}
function plan_get_by_id(int $id): ?array {
    $s = db()->prepare("SELECT * FROM plans WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null;
}
function plan_get_by_slug(string $slug): ?array {
    $s = db()->prepare("SELECT * FROM plans WHERE slug=?"); $s->execute([$slug]); return $s->fetch() ?: null;
}
function plan_update_price(int $id, int $stars): void {
    db()->prepare("UPDATE plans SET stars_price=?, updated_at=? WHERE id=?")->execute([$stars, now(), $id]);
}

// ============ Payments ============
function payment_create(int $userId, int $planId, int $stars, string $payload): int {
    db()->prepare("INSERT INTO payments(user_id, plan_id, stars_amount, payload, status, created_at) VALUES(?,?,?,?,?,?)")
        ->execute([$userId, $planId, $stars, $payload, 'pending', now()]);
    return (int)db()->lastInsertId();
}
function payment_complete(int $paymentId, string $tgCharge, string $provCharge): void {
    db()->prepare("UPDATE payments SET status='completed', telegram_charge_id=?, provider_charge_id=? WHERE id=?")
        ->execute([$tgCharge, $provCharge, $paymentId]);
}
function payment_get(int $id): ?array {
    $s = db()->prepare("SELECT * FROM payments WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null;
}

function grant_plan_to_user(int $userId, string $username, string $firstName, int $planId, string $tgCharge, string $provCharge): array {
    $plan = plan_get_by_id($planId);
    if (!$plan) return ['ok'=>false, 'msg'=>'پلن یافت نشد'];

    $slugBase = 'panel_' . $userId . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $slug = $slugBase; $i = 1;
    while (product_by_slug($slug)) { $slug = $slugBase . '_' . $i++; if ($i > 10) break; }

    $prodRes = product_create($slug, ($firstName ?: $username ?: 'User') . "'s Panel", $userId);
    if (!$prodRes['ok']) return ['ok'=>false, 'msg'=>$prodRes['msg']];

    $pid = (int)$prodRes['id'];
    $resRes = reseller_create($userId, $firstName ?: $username ?: 'Owner', $pid, [
        'max_keys' => $plan['max_keys'] ? (int)$plan['max_keys'] : null,
        'max_users' => $plan['max_users'] ? (int)$plan['max_users'] : null,
        'max_duration' => $plan['max_duration'] ? (int)$plan['max_duration'] : null,
    ], $userId);

    if (!$resRes['ok']) return ['ok'=>false, 'msg'=>$resRes['msg']];
    return ['ok'=>true, 'product'=>$prodRes, 'reseller'=>$resRes, 'plan'=>$plan];
}

// ============ License Creation ============
function key_create_for_product(int $productId, int $duration, int $maxUsers, int $createdBy, ?int $resellerId = null, ?string $note = null): array {
    $product = product_get($productId);
    if (!$product || $product['status'] !== 'active') return ['ok'=>false, 'msg'=>'محصول نامعتبر'];

    if ($resellerId) {
        $res = reseller_get($resellerId);
        if (!$res) return ['ok'=>false, 'msg'=>'نماینده یافت نشد'];
        $check = reseller_check_limits($res, $duration, $maxUsers);
        if (!$check['ok']) return $check;
    }

    $raw = generate_license_key($product['slug']);
    $hash = hash_license_key($raw); $enc = encrypt_key($raw);
    db()->prepare("INSERT INTO licenses(key_hash, key_encrypted, product_id, reseller_id, duration_seconds, max_users, created_at, created_by, status, note)
        VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$hash, $enc, $productId, $resellerId, $duration, max(1, $maxUsers), now(), $createdBy, 'unused', $note]);

    if ($resellerId) {
        db()->prepare("UPDATE resellers SET used_keys=used_keys+1 WHERE id=?")->execute([$resellerId]);
    }

    return ['ok'=>true, 'id'=>(int)db()->lastInsertId(), 'key'=>$raw, 'product'=>$product, 'duration'=>$duration, 'max_users'=>max(1, $maxUsers)];
}

// ============ Lookup ============
function key_get(int $id): ?array {
    $st = db()->prepare("SELECT l.*, p.slug as product_slug, p.name as product_name
        FROM licenses l JOIN products p ON p.id=l.product_id WHERE l.id=?");
    $st->execute([$id]); return $st->fetch() ?: null;
}
function key_get_by_raw(string $raw, ?string $productSlug = null): ?array {
    $sql = "SELECT l.*, p.slug as product_slug, p.name as product_name FROM licenses l JOIN products p ON p.id=l.product_id WHERE l.key_hash=?";
    $params = [hash_license_key($raw)];
    if ($productSlug) { $sql .= " AND p.slug=?"; $params[] = $productSlug; }
    $st = db()->prepare($sql); $st->execute($params); return $st->fetch() ?: null;
}
function key_list(int $productId = 0, int $resellerId = 0, string $filter = 'all', int $offset = 0, int $limit = 20): array {
    $w = []; $p = [];
    if ($productId) { $w[] = "l.product_id=?"; $p[] = $productId; }
    if ($resellerId) { $w[] = "l.reseller_id=?"; $p[] = $resellerId; }
    [$fwhere, $fparams] = key_filter_sql($filter);
    if ($fwhere) { $w[] = ltrim(str_ireplace('WHERE ', '', $fwhere)); $p = array_merge($p, $fparams); }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $p[] = $limit; $p[] = $offset;
    $st = db()->prepare("SELECT l.*, p.slug as product_slug FROM licenses l JOIN products p ON p.id=l.product_id
        $where ORDER BY l.id DESC LIMIT ? OFFSET ?");
    $st->execute($p); return $st->fetchAll();
}
function key_count(int $productId = 0, int $resellerId = 0, string $filter = 'all'): int {
    $w = []; $p = [];
    if ($productId) { $w[] = "product_id=?"; $p[] = $productId; }
    if ($resellerId) { $w[] = "reseller_id=?"; $p[] = $resellerId; }
    [$fwhere, $fparams] = key_filter_sql($filter);
    if ($fwhere) { $w[] = ltrim(str_ireplace('WHERE ', '', $fwhere)); $p = array_merge($p, $fparams); }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $st = db()->prepare("SELECT COUNT(*) c FROM licenses $where"); $st->execute($p);
    return (int)$st->fetch()['c'];
}
function key_filter_sql(string $filter): array {
    return match ($filter) {
        'active'  => ["WHERE status='active' AND (expires_at IS NULL OR expires_at>?)", [now()]],
        'unused'  => ["WHERE first_activated_at IS NULL AND status!='banned'", []],
        'expired' => ["WHERE first_activated_at IS NOT NULL AND expires_at IS NOT NULL AND expires_at<=? AND status!='banned'", [now()]],
        'banned'  => ["WHERE status='banned'", []],
        default   => ["", []],
    };
}

// ============ Key Actions ============
function key_ban(int $id): bool {
    db()->prepare("UPDATE licenses SET status='banned' WHERE id=?")->execute([$id]);
    db()->prepare("UPDATE sessions SET status='revoked' WHERE license_id=?")->execute([$id]);
    return true;
}
function key_unban(int $id): bool {
    $lic = key_get($id); if (!$lic) return false;
    $st = $lic['first_activated_at']
        ? (($lic['expires_at'] === null || (int)$lic['expires_at'] > now()) ? 'active' : 'expired')
        : 'unused';
    db()->prepare("UPDATE licenses SET status=? WHERE id=?")->execute([$st, $id]);
    return true;
}
function key_renew(int $id, int $addSec): array {
    $lic = key_get($id); if (!$lic) return ['ok'=>false, 'msg'=>'کلید یافت نشد'];
    if ($lic['status'] === 'banned') return ['ok'=>false, 'msg'=>'کلید مسدود است'];
    if ($lic['first_activated_at'] === null) {
        db()->prepare("UPDATE licenses SET duration_seconds=duration_seconds+? WHERE id=?")->execute([$addSec, $id]);
    } else {
        if ($lic['expires_at'] === null) return ['ok'=>false, 'msg'=>'کلید نامحدود قابل تمدید نیست'];
        db()->prepare("UPDATE licenses SET expires_at=?, status='active' WHERE id=?")
            ->execute([max(now(), (int)$lic['expires_at']) + $addSec, $id]);
    }
    return ['ok'=>true];
}
function key_delete(int $id): bool {
    db()->prepare("DELETE FROM licenses WHERE id=?")->execute([$id]); return true;
}

// ============ Devices ============
function get_license_device(int $licenseId, string $deviceId): ?array {
    $st = db()->prepare("SELECT * FROM license_devices WHERE license_id=? AND device_id=?");
    $st->execute([$licenseId, $deviceId]); return $st->fetch() ?: null;
}
function count_license_devices(int $licenseId): int {
    $st = db()->prepare("SELECT COUNT(*) c FROM license_devices WHERE license_id=?"); $st->execute([$licenseId]);
    return (int)$st->fetch()['c'];
}
function list_license_devices(int $licenseId): array {
    $st = db()->prepare("SELECT * FROM license_devices WHERE license_id=? ORDER BY last_login DESC");
    $st->execute([$licenseId]); return $st->fetchAll();
}
function bind_device(int $licenseId, string $deviceId, string $pubkey, string $model, string $android, string $appver, string $ip): void {
    db()->prepare("INSERT INTO license_devices(license_id, device_id, device_public_key, device_model, android_version, app_version, first_login, last_login, login_count, last_ip)
        VALUES(?,?,?,?,?,?,?,?,1,?)
        ON CONFLICT(license_id, device_id) DO UPDATE SET last_login=excluded.last_login, login_count=login_count+1,
        last_ip=excluded.last_ip, device_model=excluded.device_model, android_version=excluded.android_version, app_version=excluded.app_version")
        ->execute([$licenseId, $deviceId, $pubkey, $model, $android, $appver, now(), now(), $ip]);
}
function device_reset_one(int $licenseId, string $deviceId): void {
    db()->prepare("DELETE FROM license_devices WHERE license_id=? AND device_id=?")->execute([$licenseId, $deviceId]);
    db()->prepare("UPDATE sessions SET status='revoked' WHERE license_id=? AND device_id=?")->execute([$licenseId, $deviceId]);
}
function device_reset_all(int $licenseId): void {
    db()->prepare("DELETE FROM license_devices WHERE license_id=?")->execute([$licenseId]);
    db()->prepare("UPDATE sessions SET status='revoked' WHERE license_id=?")->execute([$licenseId]);
}

// ============ Stats ============
function stats_for_product(int $productId = 0): array {
    $pid = (int)$productId;
    $w = $pid ? "WHERE product_id=$pid" : "";
    $and = $pid ? "AND" : "WHERE";
    $pdo = db();
    $n = now();
    return [
        'keys_total'   => (int)$pdo->query("SELECT COUNT(*) FROM licenses $w")->fetchColumn(),
        'keys_active'  => (int)$pdo->query("SELECT COUNT(*) FROM licenses $w $and status='active' AND (expires_at IS NULL OR expires_at>$n)")->fetchColumn(),
        'keys_unused'  => (int)$pdo->query("SELECT COUNT(*) FROM licenses $w $and first_activated_at IS NULL AND status!='banned'")->fetchColumn(),
        'keys_expired' => (int)$pdo->query("SELECT COUNT(*) FROM licenses $w $and first_activated_at IS NOT NULL AND expires_at IS NOT NULL AND expires_at<=$n AND status!='banned'")->fetchColumn(),
        'keys_banned'  => (int)$pdo->query("SELECT COUNT(*) FROM licenses $w $and status='banned'")->fetchColumn(),
        'devices'      => (int)$pdo->query($pid
            ? "SELECT COUNT(*) FROM license_devices WHERE license_id IN (SELECT id FROM licenses WHERE product_id=$pid)"
            : "SELECT COUNT(*) FROM license_devices")->fetchColumn(),
        'resellers'    => (int)$pdo->query("SELECT COUNT(*) FROM resellers " . ($pid ? "WHERE product_id=$pid AND status='active'" : ""))->fetchColumn(),
    ];
}

// ============ Display Helpers ============
function quota_display($v): string {
    if ($v === null || $v === '') return '♾';
    $n = (int)$v; if ($n < 0) return '♾';
    return (string)$n;
}
function duration_display($v): string {
    if ($v === null || $v === '') return '♾';
    $n = (int)$v; if ($n < 0) return '♾'; if ($n === 0) return 'نامحدود';
    return format_duration($n);
}

// ============ Audit Log ============
function audit_log(int $adminId, string $adminName, string $action, string $targetType = '', ?int $targetId = null, array $details = []): void {
    try {
        db()->prepare("INSERT INTO audit_log(admin_id, admin_name, action, target_type, target_id, details, created_at) VALUES(?,?,?,?,?,?,?)")
            ->execute([$adminId, $adminName, $action, $targetType, $targetId, json_encode($details, JSON_UNESCAPED_UNICODE), now()]);
    } catch (Throwable $e) {}
}
function audit_list(int $limit = 30): array {
    $st = db()->prepare("SELECT * FROM audit_log ORDER BY id DESC LIMIT ?"); $st->execute([$limit]);
    return $st->fetchAll();
}

// ============ Templates ============
function template_save(string $name, ?int $productId, int $duration, int $maxUsers, int $createdBy): array {
    $name = trim($name);
    if (mb_strlen($name) < 2) return ['ok'=>false, 'msg'=>'نام قالب حداقل ۲ کاراکتر'];
    try {
        db()->prepare("INSERT INTO key_templates(name, product_id, duration_seconds, max_users, created_at, created_by) VALUES(?,?,?,?,?,?)")
            ->execute([$name, $productId, $duration, $maxUsers, now(), $createdBy]);
        return ['ok'=>true, 'id'=>(int)db()->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok'=>false, 'msg'=>'این نام قبلاً ثبت شده'];
    }
}
function template_get(int $id): ?array {
    $st = db()->prepare("SELECT * FROM key_templates WHERE id=?"); $st->execute([$id]); return $st->fetch() ?: null;
}
function template_list(?int $productId = null): array {
    if ($productId) {
        $st = db()->prepare("SELECT * FROM key_templates WHERE product_id=? OR product_id IS NULL ORDER BY id DESC");
        $st->execute([$productId]); return $st->fetchAll();
    }
    return db()->query("SELECT * FROM key_templates ORDER BY id DESC")->fetchAll();
}
function template_delete(int $id): void {
    db()->prepare("DELETE FROM key_templates WHERE id=?")->execute([$id]);
}

// ============ Bulk Key Creation ============
function bulk_create_keys(int $productId, int $duration, int $maxUsers, int $count, int $createdBy, ?int $resellerId = null): array {
    $created = []; $failed = [];
    $count = max(1, min(100, $count));
    db()->beginTransaction();
    try {
        for ($i = 0; $i < $count; $i++) {
            $res = key_create_for_product($productId, $duration, $maxUsers, $createdBy, $resellerId);
            if ($res['ok']) $created[] = $res; else $failed[] = $res['msg'];
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok'=>false, 'msg'=>'خطا: ' . $e->getMessage()];
    }
    return ['ok'=>true, 'created'=>$created, 'failed'=>$failed, 'count'=>count($created)];
}

// ============ Panic Lock ============
function panic_lock(?int $productId = null): int {
    $where = $productId ? "AND product_id=$productId" : "";
    $st = db()->prepare("UPDATE licenses SET status='banned' WHERE status != 'banned' $where");
    $st->execute();
    $affected = $st->rowCount();
    if ($productId) {
        db()->exec("UPDATE sessions SET status='revoked' WHERE license_id IN (SELECT id FROM licenses WHERE product_id=$productId)");
    } else {
        db()->exec("UPDATE sessions SET status='revoked'");
    }
    log_msg('warn', 'panic_lock', ['product_id'=>$productId, 'affected'=>$affected]);
    return $affected;
}

// ============ Export ============
function export_keys(?int $productId = null, string $format = 'txt'): string {
    $sql = "SELECT l.*, p.slug as product_slug FROM licenses l JOIN products p ON p.id = l.product_id";
    if ($productId) $sql .= " WHERE l.product_id = " . (int)$productId;
    $sql .= " ORDER BY l.id DESC";
    $rows = db()->query($sql)->fetchAll();
    $out = '';
    if ($format === 'csv') {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['ID', 'Key', 'Product', 'Status', 'Duration', 'MaxUsers', 'ExpiresAt', 'CreatedAt', 'Note']);
        foreach ($rows as $r) {
            $note = (string)($r['note'] ?? '');
            if ($note !== '' && strpbrk($note[0], "=+-@\t\r") !== false) $note = "'" . $note;
            fputcsv($fh, [
                $r['id'], decrypt_key($r['key_encrypted']), $r['product_slug'],
                compute_license_status($r), $r['duration_seconds'], $r['max_users'],
                $r['expires_at'] ? date('Y-m-d H:i', (int)$r['expires_at']) : 'unlimited',
                date('Y-m-d H:i', (int)$r['created_at']), $note,
            ]);
        }
        rewind($fh); $out = (string)stream_get_contents($fh); fclose($fh);
    } else {
        $out = "=== SPIDER KEY SYSTEM — Export ===\nDate: " . date('Y-m-d H:i:s') . "\nTotal: " . count($rows) . " keys\n" . str_repeat("=", 60) . "\n\n";
        foreach ($rows as $r) {
            $out .= "Key: " . decrypt_key($r['key_encrypted']) . "\n  Product: {$r['product_slug']}\n  Status: " . compute_license_status($r) . "\n  Duration: " . format_duration((int)$r['duration_seconds']) . "\n  Max Users: {$r['max_users']}\n  ID: #{$r['id']}\n";
            if ($r['expires_at']) $out .= "  Expires: " . date('Y-m-d H:i', (int)$r['expires_at']) . "\n";
            $out .= "\n";
        }
    }
    return $out;
}

// ============ Broadcast ============
function broadcast_save(?int $productId, string $message, int $sent, int $failed, int $createdBy): int {
    db()->prepare("INSERT INTO broadcasts(product_id, message, sent_count, failed_count, created_at, created_by) VALUES(?,?,?,?,?,?)")
        ->execute([$productId, $message, $sent, $failed, now(), $createdBy]);
    return (int)db()->lastInsertId();
}
function broadcast_targets(?int $productId = null): array {
    if ($productId) {
        $st = db()->prepare("SELECT * FROM resellers WHERE product_id=? AND status='active'");
        $st->execute([$productId]); return $st->fetchAll();
    }
    return db()->query("SELECT * FROM resellers WHERE status='active'")->fetchAll();
}

// ============ Health ============
function system_health(): array {
    $webhook = null;
    try {
        $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/getWebhookInfo');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5]);
        $res = curl_exec($ch); curl_close($ch);
        $webhook = is_string($res) ? json_decode($res, true) : null;
    } catch (Throwable $e) {}

    $dbSize = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
    $walSize = is_file(DB_PATH . '-wal') ? filesize(DB_PATH . '-wal') : 0;
    $expiredSessions = (int)db()->query("SELECT COUNT(*) FROM sessions WHERE status='active' AND expires_at < " . now())->fetchColumn();
    $staleActions = (int)db()->query("SELECT COUNT(*) FROM pending_actions WHERE created_at < " . (now() - 3600))->fetchColumn();
    $recentRateHits = (int)db()->query("SELECT COUNT(*) FROM rate_limits WHERE window_start > " . (now() - 3600))->fetchColumn();
    $recentErrors = (int)db()->query("SELECT COUNT(*) FROM logs WHERE level='error' AND created_at > " . (now() - 86400))->fetchColumn();

    return [
        'webhook_ok' => isset($webhook['result']['url']) && empty($webhook['result']['last_error_message']),
        'webhook_url' => $webhook['result']['url'] ?? '',
        'webhook_error' => $webhook['result']['last_error_message'] ?? '',
        'pending_updates' => $webhook['result']['pending_update_count'] ?? 0,
        'db_size' => $dbSize + $walSize,
        'expired_sessions' => $expiredSessions,
        'stale_actions' => $staleActions,
        'rate_hits_1h' => $recentRateHits,
        'errors_24h' => $recentErrors,
    ];
}

// ============ Analytics ============
function analytics_last_days(int $days = 7, ?int $productId = null): array {
    $result = [];
    $where = $productId ? "AND product_id=$productId" : "";
    for ($i = $days - 1; $i >= 0; $i--) {
        $dayStart = strtotime(date('Y-m-d', strtotime("-$i days")));
        $dayEnd = $dayStart + 86400;
        $st = db()->prepare("SELECT COUNT(*) FROM licenses WHERE created_at >= ? AND created_at < ? $where");
        $st->execute([$dayStart, $dayEnd]); $created = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT COUNT(*) FROM api_logs WHERE action='activate' AND status='ok' AND created_at >= ? AND created_at < ?");
        $st->execute([$dayStart, $dayEnd]); $activations = (int)$st->fetchColumn();
        $result[] = ['date'=>date('m-d', $dayStart), 'created'=>$created, 'activations'=>$activations];
    }
    return $result;
}

// ============ Product delete (cascade) ============
function product_delete_cascade(int $productId): bool {
    if($productId<=1)return false;
    $product=product_get($productId); if(!$product)return false;
    $st=db()->prepare("SELECT id FROM licenses WHERE product_id=?"); $st->execute([$productId]);
    $licIds=$st->fetchAll(PDO::FETCH_COLUMN);
    if(!empty($licIds)){
        $in=implode(',',array_map('intval',$licIds));
        db()->exec("DELETE FROM sessions WHERE license_id IN ($in)");
        db()->exec("DELETE FROM license_devices WHERE license_id IN ($in)");
    }
    $st=db()->prepare("SELECT id FROM resellers WHERE product_id=?"); $st->execute([$productId]);
    $resIds=$st->fetchAll(PDO::FETCH_COLUMN);
    if(!empty($resIds)){
        $in=implode(',',array_map('intval',$resIds));
        db()->exec("DELETE FROM reseller_sessions WHERE reseller_id IN ($in)");
        db()->exec("DELETE FROM reseller_login_tokens WHERE reseller_id IN ($in)");
    }
    db()->prepare("DELETE FROM licenses WHERE product_id=?")->execute([$productId]);
    db()->prepare("DELETE FROM resellers WHERE product_id=?")->execute([$productId]);
    db()->prepare("DELETE FROM products WHERE id=?")->execute([$productId]);
    log_msg('info','product_deleted',['product_id'=>$productId,'licenses'=>count($licIds),'resellers'=>count($resIds)]);
    return true;
}


// ============ v12 helpers ============
function key_set_note(int $id, string $note): void {
    db()->prepare("UPDATE licenses SET note=? WHERE id=?")->execute([mb_substr(trim($note), 0, 200), $id]);
}
function key_set_max_users(int $id, int $max): void {
    db()->prepare("UPDATE licenses SET max_users=? WHERE id=?")->execute([max(1, min(10000, $max)), $id]);
}
/** کلیدهایی که در $days روز آینده منقضی می‌شوند (فقط فعال‌ها، نزدیک‌ترین اول) */
function key_expiring(int $days = 3, int $limit = 20, int $productId = 0): array {
    $p = [now(), now() + $days * 86400];
    $w = "l.status='active' AND l.expires_at IS NOT NULL AND l.expires_at>? AND l.expires_at<=?";
    if ($productId) { $w .= " AND l.product_id=?"; $p[] = $productId; }
    $p[] = $limit;
    $st = db()->prepare("SELECT l.*, p.slug AS product_slug, p.name AS product_name FROM licenses l JOIN products p ON p.id=l.product_id
        WHERE $w ORDER BY l.expires_at ASC LIMIT ?");
    $st->execute($p);
    return $st->fetchAll();
}
/** جستجوی گسترده: کلید کامل، شناسه، یادداشت، مدل/آی‌پی/شناسه‌ی دستگاه */
function key_search(string $q, int $limit = 15): array {
    $q = trim($q);
    if ($q === '') return [];
    $ids = [];
    if (preg_match('/^[A-Z0-9]+-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/i', $q)) {
        $l = key_get_by_raw($q);
        return $l ? [$l] : [];
    }
    if (ctype_digit($q)) $ids[] = (int)$q;
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = db()->prepare("SELECT DISTINCT license_id FROM license_devices
        WHERE device_id LIKE ? ESCAPE '\\' OR device_model LIKE ? ESCAPE '\\' OR last_ip LIKE ? ESCAPE '\\' LIMIT 50");
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[] = (int)$i;
    $st = db()->prepare("SELECT id FROM licenses WHERE note LIKE ? ESCAPE '\\' LIMIT 50");
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[] = (int)$i;
    $out = [];
    foreach (array_slice(array_values(array_unique($ids)), 0, $limit) as $i) {
        $l = key_get($i);
        if ($l) $out[] = $l;
    }
    return $out;
}
/** رمز تصادفی جدید برای پنل نماینده؛ رمز خام فقط یک‌بار برگردانده می‌شود */
function reseller_reset_password(int $resellerId, int $len = 12): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < $len; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    reseller_set_password($resellerId, $pw);
    db()->prepare("DELETE FROM reseller_sessions WHERE reseller_id=?")->execute([$resellerId]); // نشست‌های قبلی بسته شوند
    return $pw;
}
/** لیست دستگاه‌های آنلاین (آخرین heartbeat در ONLINE_WINDOW) */
function online_devices(int $limit = 20): array {
    $st = db()->prepare("SELECT s.device_id, s.ip, s.last_heartbeat, l.id AS license_id, l.key_encrypted, p.name AS product_name, d.device_model
        FROM sessions s JOIN licenses l ON l.id=s.license_id JOIN products p ON p.id=l.product_id
        LEFT JOIN license_devices d ON d.license_id=s.license_id AND d.device_id=s.device_id
        WHERE s.status='active' AND s.expires_at>? AND s.last_heartbeat>?
        GROUP BY s.license_id, s.device_id ORDER BY s.last_heartbeat DESC LIMIT ?");
    $st->execute([now(), now() - (int)ONLINE_WINDOW, $limit]);
    return $st->fetchAll();
}
function audit_list_page(int $limit = 20, int $offset = 0): array {
    $st = db()->prepare("SELECT * FROM audit_log ORDER BY id DESC LIMIT ? OFFSET ?");
    $st->execute([$limit, $offset]);
    return $st->fetchAll();
}

// ============ Backup / Maintenance (v12) ============
/** نسخه‌ی یکدست از دیتابیس. کپی مستقیم فایل در حالت WAL ممکن بود آخرین تغییرات را نداشته باشد. */
function db_snapshot(): ?string {
    $tmp = sys_get_temp_dir() . '/spider_snap_' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($tmp));
        if (is_file($tmp) && filesize($tmp) > 0) return $tmp;
    } catch (Throwable $e) {
        log_msg('warn', 'snapshot_vacuum_failed', ['err' => $e->getMessage()]);
    }
    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (@copy(DB_PATH, $tmp)) return $tmp;
    } catch (Throwable $e) { /* ignore */ }
    return null;
}

/** پاکسازی دوره‌ای (cleanup.php و دکمه‌ی ربات). خروجی: تعداد ردیف حذف‌شده برای هر جدول */
function maintenance_run(): array {
    $pdo = db();
    $n = now();
    $r = [];
    $del = function (string $label, string $sql, array $p = []) use ($pdo, &$r) {
        $st = $pdo->prepare($sql); $st->execute($p); $r[$label] = $st->rowCount();
    };
    $del('sessions',          "DELETE FROM sessions WHERE expires_at < ? OR (status != 'active' AND created_at < ?)", [$n, $n - 86400]);
    $del('pending_actions',   'DELETE FROM pending_actions WHERE created_at < ?', [$n - 3600]);
    $del('rate_limits',       'DELETE FROM rate_limits WHERE window_start < ?', [$n - 7200]);
    $del('used_nonces',       'DELETE FROM used_nonces WHERE created_at < ?', [$n - 3600]);
    $del('bot_updates',       'DELETE FROM bot_processed_updates WHERE processed_at < ?', [$n - 86400]);
    $del('bot_states',        'DELETE FROM bot_states WHERE updated_at < ?', [$n - 3600]);
    $del('reseller_sessions', 'DELETE FROM reseller_sessions WHERE expires_at < ?', [$n]);
    $del('login_tokens',      'DELETE FROM reseller_login_tokens WHERE expires_at < ?', [$n - 86400]);
    $del('api_logs',          'DELETE FROM api_logs WHERE created_at < ?', [$n - (int)LOG_RETENTION_DAYS * 86400]);
    $del('logs',              'DELETE FROM logs WHERE created_at < ?', [$n - (int)LOG_RETENTION_DAYS * 86400]);
    $del('audit_log',         'DELETE FROM audit_log WHERE created_at < ?', [$n - (int)AUDIT_RETENTION_DAYS * 86400]);
    try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $pdo->exec('PRAGMA optimize'); } catch (Throwable $e) { /* ignore */ }
    return $r;
}
