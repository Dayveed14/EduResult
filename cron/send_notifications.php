<?php
// Sends queued emails. Run every few minutes from cron (Linux hosting / cPanel):
//   */5 * * * *  php /path/to/eduresult/cron/send_notifications.php
// On Windows/XAMPP use Task Scheduler:
//   C:\xampp\php\php.exe C:\xampp\htdocs\eduresult\cron\send_notifications.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require dirname(__DIR__) . '/includes/bootstrap.php';

$total = ['sent' => 0, 'failed' => 0];
for ($i = 0; $i < 10; $i++) {          // up to 10 batches of 25 per run
    $r = send_queued(null, 25);
    $total['sent'] += $r['sent'];
    $total['failed'] += $r['failed'];
    if ($r['sent'] + $r['failed'] === 0) {
        break;
    }
}
// Abandoned Paystack checkouts older than a day are marked failed to keep lists tidy.
db()->exec("UPDATE result_checks SET status = 'failed' WHERE status = 'pending' AND created_at < NOW() - INTERVAL 1 DAY");
db()->exec("UPDATE fee_payments SET status = 'failed' WHERE status = 'pending' AND method = 'paystack' AND created_at < NOW() - INTERVAL 1 DAY");
db()->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');

echo date('c') . " sent={$total['sent']} failed={$total['failed']}\n";
