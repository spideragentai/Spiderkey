<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('API_SECRET') || strlen(API_SECRET) < 32) {
    http_response_code(500);
    die('ERR:server');
}

$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rate_limit_ok($ip, 'legacy_verify', 120)) { http_response_code(429); die('ERR:ratelimit'); }

$token = (string)($_POST['token'] ?? '');
$hwid  = trim((string)($_POST['hwid'] ?? ''));
$sig   = (string)($_POST['sig']   ?? '');

if (!preg_match('/^sk_[a-f0-9]{48}$/', $token)) die('ERR:invalid');
if (!preg_match('/^[A-F0-9]{16,64}$/i', $hwid)) die('ERR:invalid');

$payload = "token=$token&hwid=$hwid";
if (!hash_equals(hash_hmac('sha256', $payload, API_SECRET), $sig)) die('ERR:signature');

$st = db()->prepare("SELECT s.*, l.status AS lic_status, l.expires_at AS lic_exp FROM sessions s JOIN licenses l ON l.id = s.license_id WHERE s.access_token = ? AND s.device_id = ? AND s.status = 'active'");
$st->execute([$token, $hwid]);
$sess = $st->fetch();

if (!$sess)                                            die('ERR:session');
if ($sess['lic_status'] === 'banned')                  die('ERR:banned');
if ($sess['lic_exp'] && (int)$sess['lic_exp'] < now()) die('ERR:expired');
if ((int)$sess['expires_at'] < now())                  die('ERR:session');

db()->prepare("UPDATE sessions SET expires_at=?, last_heartbeat=? WHERE id=?")
    ->execute([now() + 3600, now(), (int)$sess['id']]);

echo 'OK';