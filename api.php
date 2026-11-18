<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';

// ============================================================
// CORS + Preflight
// ============================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if ($origin === 'null') $origin = '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Requested-With, Authorization');
header('Access-Control-Expose-Headers: X-RateLimit-Remaining, Retry-After');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function parse_request_body(): array {
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $raw = file_get_contents('php://input');

    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
        return $_POST;
    }
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    if (!empty($_POST)) return $_POST;
    return $_GET;
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$input = parse_request_body();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);

// ============================================================
// Public Endpoints
// ============================================================
if ($action === 'ping') {
    json_response([
        'success' => true,
        'pong' => true,
        'time' => now(),
        'version' => SKY_VERSION,
        'server_time' => date('c'),
    ]);
}

if ($action === 'docs') {
    json_response([
        'success' => true,
        'version' => SKY_VERSION,
        'endpoints' => [
            'activate' => 'POST — initial activation',
            'login'    => 'POST — same as activate',
            'validate' => 'POST — session validation',
            'heartbeat'=> 'POST — periodic check',
            'status'   => 'POST — license info',
            'logout'   => 'POST — logout',
            'device_info' => 'POST — device info',
            'reset_request' => 'POST — request device reset',
        ],
        'auth' => 'X-API-Key header OR ?product=slug OR dedicated domain',
    ]);
}

// ============================================================
// Product resolution
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response([
        'success' => false,
        'error'   => 'METHOD_NOT_ALLOWED',
        'message' => 'Use POST for API actions, GET for ?action=ping / ?action=docs',
    ], 405);
}

$product = null;

if (isset($_GET['product'])) {
    $p = product_by_slug((string)$_GET['product']);
    if ($p && $p['status'] === 'active') $product = $p;
}

if (!$product) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    if ($apiKey) $product = product_by_api_key($apiKey);
}

if (!$product && !empty($_SERVER['PATH_INFO'])) {
    $path = trim($_SERVER['PATH_INFO'], '/');
    $parts = explode('/', $path);
    $slug = $parts[0] ?? '';
    if ($slug) {
        $p = product_by_slug($slug);
        if ($p && $p['status'] === 'active') $product = $p;
    }
}

if (!$product) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host) $product = product_by_host($host);
}

if (!$product) {
    log_api($action, null, $ip, 'no_product', $ua);
    json_response([
        'success' => false,
        'error'   => 'PRODUCT_NOT_IDENTIFIED',
        'message' => 'Use /{slug}/api.php, X-API-Key header, or ?product=slug',
    ], 401);
}

$productId   = (int)$product['id'];
$productSlug = $product['slug'];

if (!rate_limit_ok($ip, $action ?: 'unknown')) {
    header('Retry-After: 60');
    log_api($action, null, $ip, 'ratelimit', $ua);
    json_response(['success' => false, 'error' => 'RATE_LIMIT', 'message' => 'Too many requests. Retry in 60s.'], 429);
}

try {
    match ($action) {
        'activate'      => api_activate($input, $ip, $productId, $productSlug),
        'login'         => api_activate($input, $ip, $productId, $productSlug),
        'validate'      => api_validate($input, $ip, $productId),
        'heartbeat'     => api_heartbeat($input, $ip, $productId),
        'status'        => api_status($input, $ip, $productId),
        'logout'        => api_logout($input, $ip),
        'device_info'   => api_device_info($input, $ip, $productId),
        'reset_request' => api_reset_request($input, $ip, $productId),
        default         => json_response(['success'=>false,'error'=>'UNKNOWN_ACTION','message'=>"Unknown action: $action"], 404),
    };
} catch (Throwable $e) {
    log_msg('error', 'API', ['action'=>$action, 'err'=>$e->getMessage(), 'ip'=>$ip]);
    json_response(['success' => false, 'error' => 'SERVER_ERROR', 'message' => 'Internal error'], 500);
}

function auth_request(array $input, string $action, string $ip, int $productId): array {
    $token     = (string)($input['access_token'] ?? '');
    $deviceId  = (string)($input['device_id']    ?? '');
    $timestamp = (int)   ($input['timestamp']    ?? 0);
    $nonce     = (string)($input['nonce']        ?? '');
    $signature = (string)($input['signature']    ?? '');

    if (!$token || !$deviceId || !$timestamp || !$nonce || !$signature) {
        json_response(['success'=>false,'error'=>'MISSING_PARAMS','message'=>'access_token, device_id, timestamp, nonce, signature required'], 400);
    }
    if (abs(now() - $timestamp) > TIMESTAMP_WINDOW) {
        json_response(['success'=>false,'error'=>'TIMESTAMP_INVALID','message'=>'Timestamp outside window (±5min)'], 400);
    }
    if (!check_and_store_nonce($nonce)) {
        log_api($action, null, $ip, 'nonce', 'replay');
        json_response(['success'=>false,'error'=>'NONCE_REUSED','message'=>'Nonce already used'], 400);
    }

    $st = db()->prepare("SELECT s.* FROM sessions s JOIN licenses l ON l.id=s.license_id
        WHERE s.access_token=? AND l.product_id=?");
    $st->execute([$token, $productId]);
    $sess = $st->fetch();
    if (!$sess || $sess['status'] !== 'active') {
        json_response(['success'=>false,'error'=>'INVALID_SESSION','message'=>'Session invalid or revoked'], 401);
    }
    if ((int)$sess['expires_at'] < now()) {
        db()->prepare("UPDATE sessions SET status='expired' WHERE id=?")->execute([$sess['id']]);
        json_response(['success'=>false,'error'=>'SESSION_EXPIRED','message'=>'Session expired. Re-login required.'], 401);
    }
    if ($sess['device_id'] !== $deviceId) {
        json_response(['success'=>false,'error'=>'DEVICE_MISMATCH','message'=>'Session belongs to another device'], 401);
    }

    $lic = key_get((int)$sess['license_id']);
    if (!$lic || (int)$lic['product_id'] !== $productId) {
        json_response(['success'=>false,'error'=>'INVALID_LICENSE','message'=>'License not found'], 404);
    }

    $dev = get_license_device((int)$sess['license_id'], $deviceId);
    if (!$dev) json_response(['success'=>false,'error'=>'DEVICE_NOT_BOUND','message'=>'Device unbound by admin'], 403);
    if (empty($dev['device_public_key'])) json_response(['success'=>false,'error'=>'NO_PUBKEY'], 401);

    $signed = $deviceId . '|' . $timestamp . '|' . $nonce . '|' . $action;
    if (!verify_signature($dev['device_public_key'], $signed, $signature)) {
        log_api($action, (int)$lic['id'], $ip, 'badsig');
        json_response(['success'=>false,'error'=>'BAD_SIGNATURE','message'=>'Invalid signature'], 401);
    }

    db()->prepare("UPDATE sessions SET last_heartbeat=? WHERE id=?")->execute([now(), $sess['id']]);
    return ['session'=>$sess, 'license'=>$lic, 'ip'=>$ip];
}

function api_activate(array $in, string $ip, int $productId, string $productSlug): void {
    $key     = trim((string)($in['license_key'] ?? ''));
    $device  = trim((string)($in['device_id'] ?? ''));
    $pubkey  = trim((string)($in['public_key'] ?? ''));
    $model   = trim((string)($in['device_model'] ?? ''));
    $android = trim((string)($in['android_version'] ?? ''));
    $appver  = trim((string)($in['app_version'] ?? ''));

    if (!$key || !$device || !$pubkey) {
        json_response(['success'=>false,'error'=>'MISSING_PARAMS','message'=>'license_key, device_id, public_key required'], 400);
    }

    // حدس‌زدن کلید: حداکثر ۱۰ کلید نامعتبر در دقیقه برای هر IP
    $lic = key_get_by_raw($key, $productSlug);
    if (!$lic) {
        log_api('activate', null, $ip, 'invalid', 'key=' . substr($key, 0, 6) . '…');
        if (!rate_limit_ok($ip, 'activate_invalid', 10)) {
            header('Retry-After: 60');
            json_response(['success'=>false,'error'=>'RATE_LIMIT','message'=>'Too many invalid attempts. Retry in 60s.'], 429);
        }
        json_response(['success'=>false,'error'=>'INVALID_KEY','message'=>'Invalid license key for this product'], 404);
    }
    if ((int)$lic['product_id'] !== $productId) {
        json_response(['success'=>false,'error'=>'PRODUCT_MISMATCH','message'=>'Key belongs to another product'], 403);
    }
    if ($lic['status'] === 'banned') {
        json_response(['success'=>false,'error'=>'BANNED_KEY','message'=>'License suspended'], 403);
    }
    if ($lic['first_activated_at'] && (int)$lic['duration_seconds'] > 0 && $lic['expires_at'] && (int)$lic['expires_at'] <= now()) {
        json_response(['success'=>false,'error'=>'EXPIRED_KEY','message'=>'License expired'], 403);
    }

    $maxUsers = max(1, (int)$lic['max_users']);
    $existingDevice = get_license_device((int)$lic['id'], $device);
    $currentCount = count_license_devices((int)$lic['id']);

    if ($existingDevice) {
        if (!empty($existingDevice['device_public_key']) && $existingDevice['device_public_key'] !== $pubkey) {
            json_response(['success'=>false,'error'=>'DEVICE_MISMATCH','message'=>'Device identity changed'], 403);
        }
    } else {
        if ($currentCount >= $maxUsers) {
            json_response([
                'success'=>false,'error'=>'USERS_EXCEEDED',
                'message'=>"Limit reached ({$currentCount}/{$maxUsers})",
                'current_users'=>$currentCount,'max_users'=>$maxUsers,
            ], 403);
        }
    }

    if (!$lic['first_activated_at']) {
        $expires = (int)$lic['duration_seconds'] > 0 ? now() + (int)$lic['duration_seconds'] : null;
        db()->prepare("UPDATE licenses SET first_activated_at=?, expires_at=?, status='active' WHERE id=?")
            ->execute([now(), $expires, $lic['id']]);
    }

    bind_device((int)$lic['id'], $device, $pubkey, $model, $android, $appver, $ip);
    db()->prepare("UPDATE licenses SET last_login=?, last_ip=?, login_count=login_count+1 WHERE id=?")
        ->execute([now(), $ip, $lic['id']]);

    $sess = create_session((int)$lic['id'], $device, $ip);
    $lic = key_get((int)$lic['id']);
    $remaining = $lic['expires_at'] ? max(0, (int)$lic['expires_at'] - now()) : -1;

    log_api('activate', (int)$lic['id'], $ip, 'ok');
    json_response([
        'success' => true,
        'status' => 'active',
        'message' => 'Login successful',
        'license' => mask_key(decrypt_key($lic['key_encrypted'])),
        'product' => $productSlug,
        'expires_at' => $lic['expires_at'] ? (int)$lic['expires_at'] : 0,
        'remaining_seconds' => $remaining,
        'device_bound' => true,
        'session_valid' => true,
        'session_id' => $sess['session_id'],
        'access_token' => $sess['access_token'],
        'session_expires_at' => $sess['expires_at'],
        'current_users' => count_license_devices((int)$lic['id']),
        'max_users' => $maxUsers,
    ]);
}

function api_validate(array $in, string $ip, int $productId): void {
    $auth = auth_request($in, 'validate', $ip, $productId);
    $lic = $auth['license'];
    $st = compute_license_status($lic);
    $rem = $lic['expires_at'] ? max(0, (int)$lic['expires_at'] - now()) : -1;
    if (in_array($st, ['banned','expired','unused'], true)) {
        $map = ['banned'=>['BANNED_KEY','Suspended'],'expired'=>['EXPIRED_KEY','Expired'],'unused'=>['INACTIVE_KEY','Not active']];
        json_response(['success'=>false,'error'=>$map[$st][0],'message'=>$map[$st][1],'status'=>$st,'remaining_seconds'=>$rem], 403);
    }
    json_response(['success'=>true,'status'=>'active','expires_at'=>(int)$lic['expires_at'],'remaining_seconds'=>$rem,'session_valid'=>true]);
}

function api_heartbeat(array $in, string $ip, int $productId): void {
    $auth = auth_request($in, 'heartbeat', $ip, $productId);
    $lic = $auth['license'];
    $st = compute_license_status($lic);
    if ($st === 'banned')  json_response(['success'=>false,'error'=>'BANNED_KEY','message'=>'Suspended'], 403);
    if ($st === 'expired') json_response(['success'=>false,'error'=>'EXPIRED_KEY','message'=>'Expired'], 403);
    $rem = $lic['expires_at'] ? max(0, (int)$lic['expires_at'] - now()) : -1;
    json_response(['success'=>true,'status'=>'active','remaining_seconds'=>$rem,'expires_at'=>$lic['expires_at']?(int)$lic['expires_at']:0,'session_valid'=>true]);
}

function api_status(array $in, string $ip, int $productId): void {
    $auth = auth_request($in, 'status', $ip, $productId);
    $lic = $auth['license'];
    $dev = get_license_device((int)$lic['id'], (string)$auth['session']['device_id']) ?: [];
    $rem = $lic['expires_at'] ? max(0, (int)$lic['expires_at'] - now()) : -1;
    json_response([
        'success'=>true,
        'status'=>compute_license_status($lic),
        'license'=>mask_key(decrypt_key($lic['key_encrypted'])),
        'duration_seconds'=>(int)$lic['duration_seconds'],
        'remaining_seconds'=>$rem,
        'expires_at'=>$lic['expires_at']?(int)$lic['expires_at']:0,
        'first_activated_at'=>$lic['first_activated_at']?(int)$lic['first_activated_at']:0,
        'device_bound'=>!empty($dev),
        'device_model'=>$dev['device_model'] ?? null,
        'current_users'=>count_license_devices((int)$lic['id']),
        'max_users'=>max(1, (int)$lic['max_users']),
        'login_count'=>(int)$lic['login_count'],
        'last_login'=>$lic['last_login']?(int)$lic['last_login']:0,
    ]);
}

function api_logout(array $in, string $ip): void {
    $token = (string)($in['access_token'] ?? '');
    if ($token) db()->prepare("UPDATE sessions SET status='revoked' WHERE access_token=?")->execute([$token]);
    json_response(['success'=>true,'message'=>'Logged out']);
}

function api_device_info(array $in, string $ip, int $productId): void {
    $auth = auth_request($in, 'device_info', $ip, $productId);
    $lic = $auth['license'];
    $dev = get_license_device((int)$lic['id'], (string)$auth['session']['device_id']) ?: [];
    json_response([
        'success'=>true,'device_id'=>$dev['device_id'] ?? null,'device_model'=>$dev['device_model'] ?? null,
        'android_version'=>$dev['android_version'] ?? null,'app_version'=>$dev['app_version'] ?? null,
        'first_seen'=>(int)($dev['first_login'] ?? 0),'last_login'=>(int)($dev['last_login'] ?? 0),
        'login_count'=>(int)($dev['login_count'] ?? 0),
    ]);
}

function api_reset_request(array $in, string $ip, int $productId): void {
    $auth = auth_request($in, 'reset_request', $ip, $productId);
    $lic = $auth['license'];
    $reason = substr((string)($in['reason'] ?? ''), 0, 500);
    log_msg('info', 'reset_request', ['license_id'=>$lic['id'],'product_id'=>$productId,'device_id'=>$auth['session']['device_id'],'ip'=>$ip,'reason'=>$reason]);
    if (BOT_TOKEN !== '' && rate_limit_ok($ip, 'reset_notify', 3)) {
        require_once __DIR__ . '/tg.php';
        tg_notify_admins("🔄 <b>درخواست ریست دستگاه</b>\n\n🔑 <code>" . h(mask_key(decrypt_key($lic['key_encrypted']))) . "</code> (#{$lic['id']})\n📦 " . h($lic['product_name'] ?? '') . "\n📝 " . h($reason ?: '—'),
            [[btn('👁 مشاهده کلید', 'view:' . (int)$lic['id'], 'primary')]]);
    }
    json_response(['success'=>true,'message'=>'Reset request submitted']);
}