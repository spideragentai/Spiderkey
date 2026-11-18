<?php
declare(strict_types=1);
/**
 * panel_lib.php — توابع مشترک بین admin_api.php و panel_api.php
 * (باید کنار config.php و key.php قرار بگیرد)
 */
if (defined('SPIDER_LIB')) return;
define('SPIDER_LIB', 1);

// ------------------------------------------------------------
// عمومی
// ------------------------------------------------------------
function sp_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function sp_cookie(string $name, string $value, int $expires, bool $httpOnly = true): void {
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => sp_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Strict',
    ]);
}

function sp_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

function sp_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function sp_like(string $q): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}

function sp_str($v, int $max = 200): string {
    $s = trim((string)$v);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    return substr($s, 0, $max);
}

// ------------------------------------------------------------
// کمک به اسکیما (SQLite)
// ------------------------------------------------------------
function sp_col_exists(string $table, string $col): bool {
    $k = $table . '.' . $col;
    $GLOBALS['__sp_cols'] = $GLOBALS['__sp_cols'] ?? [];
    if (array_key_exists($k, $GLOBALS['__sp_cols'])) return $GLOBALS['__sp_cols'][$k];
    $found = false;
    foreach (db()->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $r) {
        if (strcasecmp((string)($r['name'] ?? ''), $col) === 0) { $found = true; break; }
    }
    return $GLOBALS['__sp_cols'][$k] = $found;
}

function sp_ensure_col(string $table, string $col, string $def): void {
    if (sp_col_exists($table, $col)) return;
    try {
        db()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $def);
    } catch (Throwable $e) {
        log_msg('error', 'ensure_col', ['table' => $table, 'col' => $col, 'err' => $e->getMessage()]);
    }
    unset($GLOBALS['__sp_cols'][$table . '.' . $col]);
}

const SP_PERMS = ['can_create_keys', 'can_ban_keys', 'can_renew_keys', 'can_reset_devices', 'can_edit_users', 'can_delete_keys'];

function sp_ensure_schema(): void {
    sp_ensure_col('licenses', 'note', "TEXT DEFAULT ''");
}

// ------------------------------------------------------------
// لایسنس‌ها
// ------------------------------------------------------------
function sp_products_map(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query('SELECT id, slug, name FROM products')->fetchAll() as $p) {
            $map[(int)$p['id']] = $p;
        }
    }
    return $map;
}

function sp_lic_row(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare('SELECT l.*, p.slug AS product_slug, p.name AS product_name, p.status AS product_status
        FROM licenses l LEFT JOIN products p ON p.id = l.product_id WHERE l.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** scope: product_id (int|0)، reseller_id (null = همه، 0 = ساخته‌شده توسط ادمین، >0 = نماینده) */
function sp_scope_where(array $scope, array &$params): array {
    $where = [];
    if (!empty($scope['product_id'])) {
        $where[] = 'l.product_id = ?';
        $params[] = (int)$scope['product_id'];
    }
    if (array_key_exists('reseller_id', $scope) && $scope['reseller_id'] !== null) {
        if ((int)$scope['reseller_id'] === 0) {
            $where[] = '(l.reseller_id IS NULL OR l.reseller_id = 0)';
        } else {
            $where[] = 'l.reseller_id = ?';
            $params[] = (int)$scope['reseller_id'];
        }
    }
    return $where;
}

function sp_lic_search_ids(string $q): array {
    $ids = [];
    if (preg_match('/^[A-Z0-9]+-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/i', $q)) {
        $l = key_get_by_raw($q);
        return $l ? [(int)$l['id']] : [];
    }
    if (ctype_digit($q)) $ids[] = (int)$q;
    $like = sp_like($q);
    $pdo = db();
    $st = $pdo->prepare("SELECT DISTINCT license_id FROM license_devices
        WHERE device_id LIKE ? ESCAPE '\\' OR device_model LIKE ? ESCAPE '\\' OR last_ip LIKE ? ESCAPE '\\' LIMIT 500");
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    if (sp_col_exists('licenses', 'note')) {
        $st = $pdo->prepare("SELECT id FROM licenses WHERE note LIKE ? ESCAPE '\\' LIMIT 500");
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    }
    return array_values(array_unique($ids));
}

/**
 * لیست لایسنس‌ها؛ وضعیت با compute_license_status خود سیستم محاسبه می‌شود
 * تا فیلتر و شمارنده‌ها دقیقاً با چیزی که نمایش داده می‌شود یکی باشند.
 */
function sp_lic_list(array $scope, string $filter, string $search, string $sort, int $page, int $limit): array {
    $params = [];
    $where  = sp_scope_where($scope, $params);
    if ($search !== '') {
        $ids = sp_lic_search_ids($search);
        if (!$ids) return ['rows' => [], 'total' => 0, 'counts' => ['all' => 0]];
        $where[] = 'l.id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $orders = [
        'new'    => 'l.id DESC',
        'old'    => 'l.id ASC',
        'login'  => 'l.last_login DESC, l.id DESC',
        'expiry' => '(CASE WHEN l.expires_at IS NULL OR l.expires_at = 0 THEN 1 ELSE 0 END), l.expires_at ASC',
    ];
    $sql = 'SELECT l.*, p.slug AS product_slug, p.name AS product_name, p.status AS product_status
            FROM licenses l LEFT JOIN products p ON p.id = l.product_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY ' . ($orders[$sort] ?? $orders['new']) . ' LIMIT 50000';
    $st = db()->prepare($sql);
    $st->execute($params);

    $counts = ['all' => 0];
    $matched = [];
    while ($row = $st->fetch()) {
        $s = (string)compute_license_status($row);
        $counts['all']++;
        $counts[$s] = ($counts[$s] ?? 0) + 1;
        if ($filter === 'all' || $filter === $s) $matched[] = $row;
    }
    return [
        'rows'   => array_slice($matched, $page * $limit, $limit),
        'total'  => count($matched),
        'counts' => $counts,
        'all_rows_matched' => $matched,
    ];
}

function sp_lic_scope_ids(array $ids, array $scope): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    $ids = array_slice($ids, 0, 500);
    if (!$ids) return [];
    $params = [];
    $where = sp_scope_where($scope, $params);
    $where[] = 'l.id IN (' . implode(',', $ids) . ')';
    $st = db()->prepare('SELECT l.id FROM licenses l WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function sp_lic_format(array $l): array {
    $raw = decrypt_key($l['key_encrypted']);
    $exp = !empty($l['expires_at']) ? (int)$l['expires_at'] : 0;
    $pid = (int)($l['product_id'] ?? 0);
    $pm  = sp_products_map();
    return [
        'id'                 => (int)$l['id'],
        'key'                => $raw,
        'key_masked'         => mask_key($raw),
        'product_id'         => $pid,
        'product'            => $l['product_name'] ?? ($pm[$pid]['name'] ?? ''),
        'product_slug'       => $l['product_slug'] ?? ($pm[$pid]['slug'] ?? ''),
        'reseller_id'        => !empty($l['reseller_id']) ? (int)$l['reseller_id'] : 0,
        'status'             => (string)compute_license_status($l),
        'duration'           => (int)($l['duration_seconds'] ?? 0),
        'max_users'          => max(1, (int)($l['max_users'] ?? 1)),
        'current_users'      => count_license_devices((int)$l['id']),
        'expires_at'         => $exp,
        'remaining'          => $exp ? max(0, $exp - now()) : -1,
        'first_activated_at' => !empty($l['first_activated_at']) ? (int)$l['first_activated_at'] : 0,
        'created_at'         => (int)($l['created_at'] ?? 0),
        'last_login'         => !empty($l['last_login']) ? (int)$l['last_login'] : 0,
        'login_count'        => (int)($l['login_count'] ?? 0),
        'note'               => (string)($l['note'] ?? ''),
    ];
}

function sp_dev_format(array $d): array {
    return [
        'id'              => (int)$d['id'],
        'device_id'       => (string)$d['device_id'],
        'device_id_short' => substr((string)$d['device_id'], 0, 16) . '…',
        'model'           => (string)($d['device_model'] ?? ''),
        'android'         => (string)($d['android_version'] ?? ''),
        'first_login'     => (int)($d['first_login'] ?? 0),
        'last_login'      => (int)($d['last_login'] ?? 0),
        'login_count'     => (int)($d['login_count'] ?? 0),
        'ip'              => (string)($d['last_ip'] ?? ''),
    ];
}

function sp_clean_filter(string $f): string {
    $f = preg_replace('/[^a-z_]/', '', strtolower($f));
    return $f !== '' ? $f : 'all';
}

function sp_clean_duration(int $seconds): int {
    return max(0, min($seconds, 3650 * 86400)); // حداکثر ۱۰ سال
}

function sp_lic_bulk(string $op, array $ids, int $seconds = 0): array {
    $done = 0; $failed = 0;
    foreach ($ids as $id) {
        try {
            switch ($op) {
                case 'ban':    key_ban((int)$id); break;
                case 'unban':  key_unban((int)$id); break;
                case 'delete': key_delete((int)$id); break;
                case 'renew':
                    $r = key_renew((int)$id, $seconds);
                    if (empty($r['ok'])) throw new RuntimeException('renew failed');
                    break;
                default: throw new RuntimeException('bad op');
            }
            $done++;
        } catch (Throwable $e) {
            $failed++;
        }
    }
    return ['done' => $done, 'failed' => $failed];
}

function sp_lic_update(int $id, array $input): void {
    $sets = []; $p = [];
    if (array_key_exists('max_users', $input)) {
        $sets[] = 'max_users = ?';
        $p[] = max(1, min(10000, (int)$input['max_users']));
    }
    if (array_key_exists('note', $input) && sp_col_exists('licenses', 'note')) {
        $sets[] = 'note = ?';
        $p[] = sp_str($input['note'], 200);
    }
    if (!$sets) return;
    $p[] = $id;
    db()->prepare('UPDATE licenses SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($p);
}

function sp_set_note(int $id, string $note): void {
    if ($note === '' || $id <= 0 || !sp_col_exists('licenses', 'note')) return;
    db()->prepare('UPDATE licenses SET note = ? WHERE id = ?')->execute([sp_str($note, 200), $id]);
}

// ------------------------------------------------------------
// سهمیه نماینده (۰ یا خالی = نامحدود)
// ------------------------------------------------------------
function sp_reseller_used_keys(array $r): int {
    // key_create_for_product خودش used_keys را زیاد می‌کند؛ همان عددی که ربات نشان می‌دهد
    return (int)($r['used_keys'] ?? 0);
}

function sp_reseller_quota_error(array $r, int $duration, int $maxUsers, int $count): ?string {
    $qkRaw = $r['quota_max_keys'] ?? null;          // null = نامحدود ؛ 0 = هیچ کلیدی (مطابق reseller_check_limits)
    $qu = (int)($r['quota_max_users_per_key'] ?? 0);
    $qd = (int)($r['quota_max_duration'] ?? 0);
    if ($qu > 0 && $maxUsers > $qu) return "حداکثر تعداد کاربر (دستگاه) برای هر کلید: {$qu}";
    if ($qd > 0) {
        if ($duration <= 0) return 'ساخت کلید نامحدود برای شما مجاز نیست';
        if ($duration > $qd) return 'حداکثر مدت مجاز: ' . intdiv($qd, 86400) . ' روز';
    }
    if ($qkRaw !== null && $qkRaw !== '' && (int)$qkRaw >= 0) {
        $qk = (int)$qkRaw;
        $used = sp_reseller_used_keys($r);
        if ($used + $count > $qk) return "سهمیه کلید شما کافی نیست (استفاده‌شده {$used} از {$qk})";
    }
    return null;
}

// ------------------------------------------------------------
// خروجی
// ------------------------------------------------------------
function sp_export_text(array $rows, string $format): string {
    if ($format !== 'csv') {
        return implode("\n", array_map(fn($l) => decrypt_key($l['key_encrypted']), $rows)) . "\n";
    }
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['id', 'key', 'status', 'product', 'max_users', 'devices', 'expires_at', 'created_at', 'note']);
    foreach ($rows as $l) {
        $f = sp_lic_format($l);
        $note = $f['note'];
        if ($note !== '' && strpbrk($note[0], "=+-@\t\r") !== false) $note = "'" . $note; // CSV injection
        fputcsv($fh, [
            $f['id'], $f['key'], $f['status'], $f['product_slug'], $f['max_users'], $f['current_users'],
            $f['expires_at'] ? gmdate('Y-m-d H:i:s', $f['expires_at']) : '',
            $f['created_at'] ? gmdate('Y-m-d H:i:s', $f['created_at']) : '',
            $note,
        ]);
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return (string)$out;
}
