<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/key.php';

// Cron: */10 * * * * php /home/USER/public_html/cleanup.php
// فقط از CLI (cron) یا با توکن مخفی از وب قابل اجراست: cleanup.php?token=WEBHOOK_SECRET
if (PHP_SAPI !== 'cli') {
    $t = (string)($_GET['token'] ?? '');
    if (WEBHOOK_SECRET === '' || !hash_equals((string)WEBHOOK_SECRET, $t)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $r = maintenance_run();
    log_msg('info', 'cleanup', $r);
    echo "✅ Cleanup done\n";
    foreach ($r as $k => $v) echo " - $k: $v\n";
} catch (Throwable $e) {
    log_msg('error', 'cleanup_fail', ['err' => $e->getMessage()]);
    echo '❌ Error: ' . $e->getMessage() . "\n";
}
