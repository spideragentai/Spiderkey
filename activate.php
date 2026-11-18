<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../key.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('API_SECRET') || strlen(API_SECRET) < 32) {
    http_response_code(500);
    die('ERR:server');
}

$key   = trim((string)($_POST['key']   ?? ''));
$hwid  = trim((string)($_POST['hwid']  ?? ''));
$ts    = (int)   ($_POST['ts']         ?? 0);
$nonce = (string)($_POST['nonce']      ?? '');
$sig   = (string)($_POST['sig']        ?? '');
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!rate_limit_ok($ip, 'legacy_activate', 30)) { http_response_code(429); die('ERR:ratelimit'); }

if (!preg_match('/^[A-Z0-9\-]{8,64}$/i', $key))  die('ERR:invalid');
if (!preg_match('/^[A-F0-9]{16,64}$/i', $hwid))  die('ERR:invalid');
if (!preg_match('/^[a-f0-9]{8,32}$/', $nonce))   die('ERR:invalid');
if (abs(time() - $ts) > 300)                     die('ERR:clock');

$payload = "key=$key&hwid=$hwid&ts=$ts&nonce=$nonce";
$expect  = hash_hmac('sha256', $payload, API_SECRET);
if (!hash_equals($expect, $sig)) die('ERR:signature');

// Anti-replay
$st = db()->prepare("SELECT 1 FROM used_nonces WHERE nonce=?");
$st->execute([$nonce]);
if ($st->fetch()) die('ERR:replay');

try {
    db()->prepare("INSERT INTO used_nonces(nonce, created_at) VALUES(?,?)")->execute([$nonce, now()]);
} catch (Throwable $e) { die('ERR:replay'); }
db()->prepare("DELETE FROM used_nonces WHERE created_at < ?")->execute([now() - 600]);

$lic = key_get_by_raw($key);
if (!$lic) {
    log_api('legacy_activate', null, $ip, 'invalid', 'key=' . substr($key, 0, 6) . '…');
    if (!rate_limit_ok($ip, 'legacy_invalid', 10)) { http_response_code(429); die('ERR:ratelimit'); }
    die('ERR:invalid');
}
if ($lic['status'] === 'banned')          die('ERR:banned');
if ($lic['first_activated_at']
    && (int)$lic['duration_seconds'] > 0
    && $lic['expires_at']
    && (int)$lic['expires_at'] < now())   die('ERR:expired');

$existing = get_license_device((int)$lic['id'], $hwid);
$devCount = count_license_devices((int)$lic['id']);
if (!$existing) {
    if ($devCount >= (int)$lic['max_users']) die('ERR:maxdevices');
    bind_device((int)$lic['id'], $hwid, '', 'android', '', '', $ip);
}

if (!$lic['first_activated_at']) {
    $expires = (int)$lic['duration_seconds'] > 0 ? now() + (int)$lic['duration_seconds'] : null;
    db()->prepare("UPDATE licenses SET first_activated_at=?, expires_at=?, status='active', last_login=?, last_ip=?, login_count=login_count+1 WHERE id=?")
        ->execute([now(), $expires, now(), $ip, (int)$lic['id']]);
}

db()->prepare("UPDATE sessions SET status='revoked' WHERE license_id=? AND device_id=? AND status='active'")->execute([(int)$lic['id'], $hwid]);
$token = 'sk_' . bin2hex(random_bytes(24));
$expires = now() + 3600;
db()->prepare("INSERT INTO sessions(session_id, access_token, license_id, device_id, status, created_at, expires_at, last_heartbeat, ip) VALUES(?,?,?,?,?,?,?,?,?)")
    ->execute([$token, $token, (int)$lic['id'], $hwid, 'active', now(), $expires, now(), $ip]);

$respSig = hash_hmac('sha256', $token, API_SECRET);
log_api('legacy_activate', (int)$lic['id'], $ip, 'ok');
echo "OK:$token:$respSig";