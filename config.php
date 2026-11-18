<?php
declare(strict_types=1);
// ==========================================================
// SPIDER KEY SYSTEM v12 — Core config
// ==========================================================

// مقادیر حساس (توکن ربات، رمزها و …) در فایل config.local.php نگهداری می‌شوند
// تا هیچ‌وقت داخل کد/بکاپ کد/گیت نیفتند. نمونه: config.local.php.example
if (is_file(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';

function cfg_default(string $name, $value): void { if (!defined($name)) define($name, $value); }

cfg_default('SKY_VERSION', '12.0.0');
cfg_default('SKY_NAME', 'SPIDER KEY SYSTEM');

// ---------- Telegram ----------
cfg_default('BOT_TOKEN', '');
cfg_default('ADMIN_IDS', []);
cfg_default('WEBHOOK_SECRET', '');
cfg_default('STYLE_BUTTONS', true);

// ---------- Database ----------
cfg_default('DB_PATH', __DIR__ . '/database/spider.sqlite');

// ---------- Security ----------
cfg_default('SECRET_SALT', '');
cfg_default('SESSION_DURATION', 604800);
cfg_default('TIMESTAMP_WINDOW', 300);
cfg_default('NONCE_TTL', 600);
cfg_default('RATE_LIMIT_PER_MIN', 60);
cfg_default('ONLINE_WINDOW', 900);          // کاربر تا ۱۵ دقیقه بعد از آخرین heartbeat «آنلاین» حساب می‌شود
cfg_default('LOG_RETENTION_DAYS', 30);      // نگهداری api_logs و logs
cfg_default('AUDIT_RETENTION_DAYS', 180);

// ---------- Charon-style API (api/activate.php + api/verify.php) ----------
cfg_default('API_SECRET', '');

// ---------- Panel ----------
// می‌تواند متن ساده یا هش bcrypt باشد (password_hash) — هش توصیه می‌شود
cfg_default('ADMIN_PANEL_PASSWORD', '');
cfg_default('DEFAULT_API_URL', '');

if (SECRET_SALT === '' || strlen((string)SECRET_SALT) < 32) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration error: SECRET_SALT (min 32 chars) is missing. Create config.local.php (see config.local.php.example).\n");
}

const SCHEMA_VERSION = 3;

// ============================================================
// DB Bootstrap
// ============================================================
function db_open(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=8000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    // اسکیما فقط وقتی نسخه‌اش قدیمی است اجرا می‌شود (قبلاً در هر درخواست ~۳۰ کوئری اجرا می‌شد)
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver < SCHEMA_VERSION) {
        init_database($pdo);
        $pdo->exec('PRAGMA user_version=' . SCHEMA_VERSION);
    }
    return $pdo;
}

function db_quarantine(string $reason): void {
    // فایل خراب هیچ‌وقت حذف نمی‌شود؛ فقط کنار گذاشته می‌شود تا قابل بازیابی باشد
    $bak = DB_PATH . '.corrupt.' . date('YmdHis');
    @rename(DB_PATH, $bak);
    foreach (['-wal', '-shm'] as $x) if (is_file(DB_PATH . $x)) @rename(DB_PATH . $x, $bak . $x);
    @file_put_contents(dirname(DB_PATH) . '/corrupt.log', date('c') . " — DB quarantined to $bak ($reason)\n", FILE_APPEND);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    // پوشه دیتابیس نباید از وب قابل دانلود باشد (فایل‌های -wal و -shm را .htaccess اصلی پوشش نمی‌داد)
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');

    if (is_file(DB_PATH) && filesize(DB_PATH) > 0) {
        $h = @fopen(DB_PATH, 'rb');
        $magic = $h ? fread($h, 16) : '';
        if ($h) fclose($h);
        if ($magic !== "SQLite format 3\0") db_quarantine('bad header');
    }

    try {
        return $pdo = db_open();
    } catch (Throwable $e) {
        // قبلاً در «هر» خطا دیتابیس پاک می‌شد (حتی database is locked!). حالا فقط خرابیِ واقعی قرنطینه می‌شود.
        if (preg_match('/malformed|not a database|disk image/i', $e->getMessage())) {
            db_quarantine($e->getMessage());
            return $pdo = db_open();
        }
        throw $e;
    }
}

function db_write(callable $fn): mixed {
    $maxRetries = 3; $attempt = 0;
    while ($attempt < $maxRetries) {
        try {
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (PDOException $e) {
            $pdo = db();
            if ($pdo->inTransaction()) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
            if (str_contains($e->getMessage(), 'database is locked') && $attempt < $maxRetries - 1) {
                usleep(100000 * ($attempt + 1)); $attempt++; continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException('Write transaction failed after retries');
}

function init_database(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        api_key TEXT UNIQUE NOT NULL,
        custom_domain TEXT,
        created_at INTEGER NOT NULL,
        created_by INTEGER,
        status TEXT NOT NULL DEFAULT 'active'
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_hash TEXT UNIQUE NOT NULL,
        key_encrypted TEXT NOT NULL,
        product_id INTEGER NOT NULL DEFAULT 1,
        reseller_id INTEGER,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        max_users INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL,
        created_by INTEGER,
        first_activated_at INTEGER,
        expires_at INTEGER,
        status TEXT NOT NULL DEFAULT 'unused',
        device_id TEXT,
        device_public_key TEXT,
        device_model TEXT,
        android_version TEXT,
        app_version TEXT,
        last_login INTEGER,
        last_ip TEXT,
        login_count INTEGER NOT NULL DEFAULT 0,
        note TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS resellers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id INTEGER NOT NULL,
        username TEXT,
        display_name TEXT,
        product_id INTEGER NOT NULL,
        panel_password_hash TEXT,
        quota_max_keys INTEGER DEFAULT 10,
        quota_max_users_per_key INTEGER DEFAULT 1,
        quota_max_duration INTEGER DEFAULT 2592000,
        used_keys INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_at INTEGER NOT NULL,
        created_by INTEGER,
        note TEXT,
        can_create_keys INTEGER NOT NULL DEFAULT 1,
        can_ban_keys INTEGER NOT NULL DEFAULT 1,
        can_renew_keys INTEGER NOT NULL DEFAULT 1,
        can_reset_devices INTEGER NOT NULL DEFAULT 1,
        can_edit_users INTEGER NOT NULL DEFAULT 0,
        can_view_all_keys INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    );");

    // Plans/Payments: نگه داشته شده برای سازگاری (دیگر استفاده نمی‌شود)
    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        stars_price INTEGER NOT NULL DEFAULT 100,
        max_keys INTEGER DEFAULT 10,
        max_users INTEGER DEFAULT 1,
        max_duration INTEGER DEFAULT 2592000,
        created_at INTEGER NOT NULL,
        updated_at INTEGER
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        plan_id INTEGER NOT NULL,
        stars_amount INTEGER NOT NULL,
        telegram_charge_id TEXT,
        provider_charge_id TEXT,
        payload TEXT,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at INTEGER NOT NULL,
        FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS license_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        license_id INTEGER NOT NULL,
        device_id TEXT NOT NULL,
        device_public_key TEXT,
        device_model TEXT,
        android_version TEXT,
        app_version TEXT,
        first_login INTEGER NOT NULL,
        last_login INTEGER NOT NULL,
        login_count INTEGER NOT NULL DEFAULT 0,
        last_ip TEXT,
        UNIQUE(license_id, device_id),
        FOREIGN KEY(license_id) REFERENCES licenses(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT UNIQUE NOT NULL,
        license_id INTEGER NOT NULL,
        device_id TEXT NOT NULL,
        access_token TEXT UNIQUE NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        last_heartbeat INTEGER,
        status TEXT NOT NULL DEFAULT 'active',
        ip TEXT,
        FOREIGN KEY(license_id) REFERENCES licenses(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT, license_id INTEGER, ip TEXT,
        status TEXT, message TEXT, created_at INTEGER NOT NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        level TEXT, message TEXT, context TEXT, created_at INTEGER NOT NULL
    );");

    // ⬇ used_nonces با index (anti-replay برای Charon API)
    $pdo->exec("CREATE TABLE IF NOT EXISTS used_nonces (
        nonce TEXT PRIMARY KEY,
        created_at INTEGER NOT NULL
    );");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nonces_created ON used_nonces(created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        ip TEXT NOT NULL,
        action TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(ip, action, window_start)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_states (
        chat_id INTEGER PRIMARY KEY,
        state TEXT, data TEXT, updated_at INTEGER
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_actions (
        id TEXT PRIMARY KEY,
        chat_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        payload TEXT NOT NULL,
        created_at INTEGER NOT NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_processed_updates (
        update_id INTEGER PRIMARY KEY,
        processed_at INTEGER
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reseller_id INTEGER NOT NULL,
        session_token TEXT UNIQUE NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        last_seen INTEGER,
        ip TEXT, user_agent TEXT,
        FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_login_tokens (
        token TEXT PRIMARY KEY,
        reseller_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        used_at INTEGER,
        FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS key_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        product_id INTEGER,
        duration_seconds INTEGER NOT NULL,
        max_users INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL,
        created_by INTEGER,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        admin_name TEXT,
        action TEXT NOT NULL,
        target_type TEXT,
        target_id INTEGER,
        details TEXT,
        created_at INTEGER NOT NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        message TEXT NOT NULL,
        sent_count INTEGER NOT NULL DEFAULT 0,
        failed_count INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        created_by INTEGER
    );");

    // ---------- Migrations ----------
    $licCols = array_column($pdo->query("PRAGMA table_info(licenses)")->fetchAll(), 'name');
    foreach (['product_id'=>'INTEGER NOT NULL DEFAULT 1','reseller_id'=>'INTEGER','max_users'=>'INTEGER NOT NULL DEFAULT 1','note'=>'TEXT'] as $col=>$def) {
        if (!in_array($col, $licCols, true)) $pdo->exec("ALTER TABLE licenses ADD COLUMN $col $def");
    }

    $resCols = array_column($pdo->query("PRAGMA table_info(resellers)")->fetchAll(), 'name');
    foreach (['panel_password_hash'=>'TEXT','can_create_keys'=>'INTEGER NOT NULL DEFAULT 1','can_ban_keys'=>'INTEGER NOT NULL DEFAULT 1','can_renew_keys'=>'INTEGER NOT NULL DEFAULT 1','can_reset_devices'=>'INTEGER NOT NULL DEFAULT 1','can_edit_users'=>'INTEGER NOT NULL DEFAULT 0','can_view_all_keys'=>'INTEGER NOT NULL DEFAULT 0','can_delete_keys'=>'INTEGER NOT NULL DEFAULT 1'] as $col=>$def) {
        if (!in_array($col, $resCols, true)) $pdo->exec("ALTER TABLE resellers ADD COLUMN $col $def");
    }

    $prodCols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('custom_domain', $prodCols, true)) $pdo->exec("ALTER TABLE products ADD COLUMN custom_domain TEXT");

    $sessCols = array_column($pdo->query("PRAGMA table_info(sessions)")->fetchAll(), 'name');
    if (!in_array('last_heartbeat', $sessCols, true)) $pdo->exec("ALTER TABLE sessions ADD COLUMN last_heartbeat INTEGER");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_res_tg_prod ON resellers(telegram_id, product_id)");

    // ---------- Indexes (سرعت لیست‌ها و آمار) ----------
    foreach ([
        'idx_lic_product'   => 'licenses(product_id)',
        'idx_lic_reseller'  => 'licenses(reseller_id)',
        'idx_lic_status'    => 'licenses(status)',
        'idx_lic_created'   => 'licenses(created_at)',
        'idx_sess_license'  => 'sessions(license_id, device_id)',
        'idx_sess_hb'       => 'sessions(status, last_heartbeat)',
        'idx_dev_license'   => 'license_devices(license_id)',
        'idx_apilog_time'   => 'api_logs(created_at)',
        'idx_log_time'      => 'logs(created_at)',
        'idx_audit_time'    => 'audit_log(created_at)',
        'idx_rl_window'     => 'rate_limits(window_start)',
        'idx_rsess_res'     => 'reseller_sessions(reseller_id)',
    ] as $name => $def) {
        $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $def");
    }


    if (!$pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn()) {
        $seed = $pdo->prepare("INSERT INTO plans(slug, name, stars_price, max_keys, max_users, max_duration, created_at) VALUES(?,?,?,?,?,?,?)");
        foreach ([['basic','Basic',100,10,1,2592000],['standard','Standard',300,50,3,7776000],['pro','Pro',800,200,10,0],['unlimited','Unlimited',2000,0,0,0]] as $p) {
            $seed->execute([$p[0],$p[1],$p[2],$p[3],$p[4],$p[5],time()]);
        }
    }

    if (!$pdo->query("SELECT COUNT(*) FROM products WHERE id=1")->fetchColumn()) {
        $apiKey = 'pk_default_' . bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO products(id, slug, name, api_key, created_at, status) VALUES(1, 'default', 'Default', ?, ?, 'active')")->execute([$apiKey, time()]);
    }

    $pdo->exec("INSERT OR IGNORE INTO settings(key, value) VALUES('panel_url', '')");
    $pdo->exec("INSERT OR IGNORE INTO settings(key, value) VALUES('api_url', '')");
}

// ============================================================
// Utility Functions
// ============================================================
function now(): int { return time(); }
function random_hex(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

function generate_license_key(string $slug = 'SPIDER'): string {
    $seg = fn() => strtoupper(bin2hex(random_bytes(2)));
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $slug));
    $prefix = substr($prefix, 0, 8) ?: 'SPIDER';
    return "{$prefix}-{$seg()}-{$seg()}-{$seg()}";
}

function hash_license_key(string $key): string {
    return hash_hmac('sha256', strtoupper(trim($key)), SECRET_SALT);
}

function encrypt_key(string $raw): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($raw, 'AES-256-CBC', hash('sha256', SECRET_SALT, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_key(string $b64): string {
    $data = base64_decode($b64);
    $iv   = substr($data, 0, 16);
    $enc  = substr($data, 16);
    return (string)openssl_decrypt($enc, 'AES-256-CBC', hash('sha256', SECRET_SALT, true), OPENSSL_RAW_DATA, $iv);
}

function mask_key(string $raw): string {
    $p = explode('-', $raw);
    return count($p) >= 4 ? "{$p[0]}-{$p[1]}-****-****" : substr($raw, 0, 8) . '...';
}

function format_duration(int $sec): string {
    if ($sec <= 0) return 'نامحدود';
    $d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
    $o = []; if ($d) $o[] = "$d روز"; if ($h) $o[] = "$h ساعت"; if ($m) $o[] = "$m دقیقه";
    return $o ? implode(' و ', $o) : "$sec ثانیه";
}

function parse_duration(string $s): ?int {
    $s = trim(strtolower($s));
    if ($s === '' || $s === '0' || $s === 'unlimited' || $s === 'نامحدود') return 0;
    if (preg_match('/^(\d+)\s*([smhd]?)$/', $s, $m)) {
        $n = (int)$m[1]; $u = $m[2] ?: 's';
        return $n * (['s'=>1,'m'=>60,'h'=>3600,'d'=>86400][$u]);
    }
    return null;
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function log_api(string $a, ?int $lid, string $ip, string $st, string $m = ''): void {
    try { db()->prepare("INSERT INTO api_logs(action, license_id, ip, status, message, created_at) VALUES(?,?,?,?,?,?)")->execute([$a,$lid,$ip,$st,$m,now()]); } catch (Throwable) {}
}

function log_msg(string $l, string $m, $c = null): void {
    try { db()->prepare("INSERT INTO logs(level, message, context, created_at) VALUES(?,?,?,?)")->execute([$l,$m,is_string($c)?$c:json_encode($c),now()]); } catch (Throwable) {}
}

function setting_get(string $k, ?string $d = null): ?string {
    $st = db()->prepare("SELECT value FROM settings WHERE key=?"); $st->execute([$k]);
    $r = $st->fetch(); return $r ? $r['value'] : $d;
}

function setting_set(string $k, string $v): void {
    db()->prepare("INSERT INTO settings(key, value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$k,$v]);
}

function compute_license_status(array $lic): string {
    if ($lic['status'] === 'banned') return 'banned';
    if (!$lic['first_activated_at']) return 'unused';
    if ((int)$lic['duration_seconds'] > 0 && $lic['expires_at'] && (int)$lic['expires_at'] <= now()) return 'expired';
    return 'active';
}

function verify_signature(string $pubB64, string $data, string $sigB64): bool {
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($pubB64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = @openssl_pkey_get_public($pem);
    if (!$key) return false;
    return openssl_verify($data, base64_decode($sigB64), $key, OPENSSL_ALGO_SHA256) === 1;
}

function check_and_store_nonce(string $nonce): bool {
    db()->prepare("DELETE FROM used_nonces WHERE created_at < ?")->execute([now() - NONCE_TTL]);
    $st = db()->prepare("INSERT OR IGNORE INTO used_nonces(nonce, created_at) VALUES(?,?)");
    $st->execute([$nonce, now()]);
    return $st->rowCount() > 0;
}

function rate_limit_ok(string $ip, string $action, int $limit = 0): bool {
    if ($limit <= 0) $limit = (int)RATE_LIMIT_PER_MIN;
    $w = intdiv(now(), 60) * 60;
    $st = db()->prepare("SELECT count FROM rate_limits WHERE ip=? AND action=? AND window_start=?");
    $st->execute([$ip, $action, $w]); $r = $st->fetch();
    if ($r && (int)$r['count'] >= $limit) return false;
    db()->prepare("INSERT INTO rate_limits(ip, action, window_start, count) VALUES(?,?,?,1) ON CONFLICT(ip, action, window_start) DO UPDATE SET count=count+1")->execute([$ip, $action, $w]);
    return true;
}

function create_session(int $lid, string $did, string $ip): array {
    $sid = 'sess_' . random_hex(16); $tok = 'tok_' . random_hex(32); $exp = now() + SESSION_DURATION;
    // یک دستگاه = یک نشست فعال (قبلاً با هر لاگین نشست‌ها روی هم جمع می‌شدند و آمار آنلاین بیشتر از واقعیت بود)
    db()->prepare("UPDATE sessions SET status='revoked' WHERE license_id=? AND device_id=? AND status='active'")->execute([$lid, $did]);
    db()->prepare("INSERT INTO sessions(session_id, license_id, device_id, access_token, created_at, expires_at, last_heartbeat, status, ip) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$sid,$lid,$did,$tok,now(),$exp,now(),'active',$ip]);
    return ['session_id'=>$sid, 'access_token'=>$tok, 'expires_at'=>$exp];
}

function pending_create(int $chatId, string $type, array $payload): string {
    $id = bin2hex(random_bytes(4));
    db()->prepare("INSERT INTO pending_actions(id, chat_id, type, payload, created_at) VALUES(?,?,?,?,?)")->execute([$id,$chatId,$type,json_encode($payload),now()]);
    return $id;
}

function pending_get(string $id, int $chatId): ?array {
    $st = db()->prepare("SELECT * FROM pending_actions WHERE id=? AND chat_id=?"); $st->execute([$id,$chatId]);
    $r = $st->fetch(); if (!$r) return null;
    if ((int)$r['created_at'] < now() - 600) { pending_delete($id); return null; }
    return ['type'=>$r['type'], 'payload'=>json_decode($r['payload'], true)];
}

function pending_delete(string $id): void { db()->prepare("DELETE FROM pending_actions WHERE id=?")->execute([$id]); }


// ============================================================
// Helpers (v12)
// ============================================================
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_https_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** آدرس پایه‌ی پروژه، مثل https://example.com/path (بدون / آخر) */
function base_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return rtrim((string)DEFAULT_API_URL !== '' ? preg_replace('#/api\.php.*$#', '', (string)DEFAULT_API_URL) : '', '/');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if (str_ends_with($dir, '/api')) $dir = substr($dir, 0, -4);
    return (is_https_request() ? 'https' : 'http') . '://' . $host . $dir;
}

/** رمز ادمین: هم متن ساده و هم هش bcrypt پشتیبانی می‌شود */
function admin_password_ok(string $given): bool {
    $stored = (string)ADMIN_PANEL_PASSWORD;
    if ($stored === '') return false;
    if (preg_match('/^\$2[aby]\$/', $stored)) return password_verify($given, $stored);
    return hash_equals($stored, $given);
}

/** تعداد دستگاه‌های «آنلاین» = نشست فعال با heartbeat در ONLINE_WINDOW ثانیه‌ی اخیر */
function active_sessions_count(?int $productId = null, ?int $resellerId = null): int {
    $sql = "SELECT COUNT(DISTINCT s.license_id || ':' || s.device_id) FROM sessions s";
    $w = ["s.status='active'", 's.expires_at>?', 's.last_heartbeat>?'];
    $p = [now(), now() - (int)ONLINE_WINDOW];
    if ($productId || $resellerId) {
        $sql .= ' JOIN licenses l ON l.id=s.license_id';
        if ($productId)  { $w[] = 'l.product_id=?';  $p[] = $productId; }
        if ($resellerId) { $w[] = 'l.reseller_id=?'; $p[] = $resellerId; }
    }
    $st = db()->prepare($sql . ' WHERE ' . implode(' AND ', $w));
    $st->execute($p);
    return (int)$st->fetchColumn();
}
