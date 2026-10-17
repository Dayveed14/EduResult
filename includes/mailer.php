<?php
/*
 * Email to parents. Messages go into the `notifications` table first, then are
 * sent in batches (by the portal's Notifications page or by cron/send_notifications.php).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once APP_ROOT . '/lib/PHPMailer/Exception.php';
require_once APP_ROOT . '/lib/PHPMailer/PHPMailer.php';
require_once APP_ROOT . '/lib/PHPMailer/SMTP.php';

function queue_email(string $to, string $subject, string $html, string $type, ?int $studentId = null): int
{
    $st = db()->prepare('INSERT INTO notifications (student_id, type, recipient, subject, body_html) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$studentId, $type, $to, $subject, $html]);
    return (int) db()->lastInsertId();
}

/** Wrap a message body in the school's letterhead. */
function email_layout(string $title, string $bodyHtml): string
{
    $school = e(setting('school_name', 'Our School'));
    $contact = array_filter([setting('school_address'), setting('school_phone'), setting('school_email')]);
    return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
        . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden">'
        . '<tr><td style="background:#172238;color:#ffffff;padding:20px 24px;font-size:20px;font-weight:bold">'
        . $school . '</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.6">'
        . '<h2 style="margin:0 0 12px;font-size:18px;color:#172238">' . e($title) . '</h2>'
        . $bodyHtml
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f9fafb;color:#6b7280;font-size:12px">'
        . e(implode(' · ', $contact)) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Send queued messages. Pass ids to send specific ones now, or null to send the
 * next $limit queued messages. Returns ['sent' => n, 'failed' => n].
 */
function send_queued(?array $ids = null, int $limit = 25): array
{
    if ($ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return ['sent' => 0, 'failed' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT * FROM notifications WHERE status <> 'sent' AND id IN ($in)");
        $st->execute($ids);
    } else {
        $st = db()->prepare("SELECT * FROM notifications WHERE status = 'queued' OR (status = 'failed' AND attempts < 3) ORDER BY id LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
    }
    $sent = 0;
    $failed = 0;
    foreach ($st->fetchAll() as $n) {
        $error = deliver_email($n['recipient'], $n['subject'], $n['body_html']);
        if ($error === null) {
            db()->prepare("UPDATE notifications SET status = 'sent', attempts = attempts + 1, sent_at = NOW(), last_error = NULL WHERE id = ?")
                ->execute([$n['id']]);
            $sent++;
        } else {
            db()->prepare("UPDATE notifications SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?")
                ->execute([substr($error, 0, 255), $n['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/** Returns null on success, or an error message. */
function deliver_email(string $to, string $subject, string $html): ?string
{
    if (!valid_email($to)) {
        return 'Invalid email address';
    }
    $driver = config('mail.driver', 'log');

    if ($driver === 'log') {
        $dir = APP_ROOT . '/storage/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html';
        $meta = '<!-- To: ' . e($to) . ' | Subject: ' . e($subject) . " -->\n";
        return file_put_contents($file, $meta . $html) === false ? 'Could not write to storage/mail' : null;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string) config('mail.host');
        $mail->Port = (int) config('mail.port', 587);
        $mail->SMTPAuth = config('mail.username') !== '';
        $mail->Username = (string) config('mail.username');
        $mail->Password = (string) config('mail.password');
        $enc = config('mail.encryption', 'tls');
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) config('mail.from_email'), (string) config('mail.from_name', setting('school_name')));
        if (valid_email((string) setting('school_email'))) {
            $mail->addReplyTo((string) setting('school_email'), (string) setting('school_name'));
        }
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html))));
        $mail->send();
        return null;
    } catch (MailException $e) {
        return $mail->ErrorInfo ?: $e->getMessage();
    }
}

// ---- Ready-made messages -------------------------------------------------------------

/** Tell every parent in a term's classes that results are out. Returns how many were queued. */
function queue_results_published(array $term): int
{
    $st = db()->prepare(
        "SELECT DISTINCT st.* FROM students st
         JOIN scores sc ON sc.student_id = st.id AND sc.term_id = ?
         WHERE st.parent_email IS NOT NULL AND st.parent_email <> ''"
    );
    $st->execute([$term['id']]);
    $count = 0;
    $fee = (int) setting('result_check_fee', '0');
    foreach ($st as $s) {
        if (!valid_email($s['parent_email'])) {
            continue;
        }
        $body = '<p>Dear ' . e($s['parent_name'] ?: 'Parent/Guardian') . ',</p>'
            . '<p>The <strong>' . e(term_label($term)) . '</strong> results are now available for <strong>'
            . e(student_name($s)) . '</strong> (' . e($s['reg_number']) . ').</p>'
            . '<p>Check the result online with the registration number'
            . ($fee > 0 ? ' and a result token (₦' . number_format($fee) . ', paid online)' : '')
            . ':</p>'
            . '<p><a href="' . e(absolute_url('result_form.php')) . '" style="display:inline-block;background:#f5a425;color:#fff;padding:10px 18px;border-radius:5px;text-decoration:none">Check result</a></p>';
        if ($term['next_term_begins']) {
            $body .= '<p>Next term begins on <strong>' . e(fmt_date($term['next_term_begins'], 'l, j F Y')) . '</strong>.</p>';
        }
        queue_email($s['parent_email'], term_label($term) . ' results are out', email_layout('Results published', $body), 'results', (int) $s['id']);
        $count++;
    }
    return $count;
}

/** Fee reminders to parents of students with a balance. Returns how many were queued. */
function queue_fee_reminders(array $term, ?int $classId = null): int
{
    $count = 0;
    foreach (fee_ledger((int) $term['id'], $classId) as $r) {
        if ($r['balance'] <= 0 || !valid_email((string) $r['parent_email'])) {
            continue;
        }
        $body = '<p>Dear ' . e($r['parent_name'] ?: 'Parent/Guardian') . ',</p>'
            . '<p>This is a friendly reminder that <strong>' . e(student_name($r)) . '</strong> (' . e($r['reg_number']) . ', '
            . e($r['class_name']) . ') has an outstanding school fees balance of <strong>' . e(money($r['balance']))
            . '</strong> for ' . e(term_label($term)) . '.</p>'
            . '<p>You can pay securely online with your card, bank transfer or USSD:</p>'
            . '<p><a href="' . e(absolute_url('pay_fees.php', ['reg' => $r['reg_number']])) . '" style="display:inline-block;background:#f5a425;color:#fff;padding:10px 18px;border-radius:5px;text-decoration:none">Pay school fees</a></p>'
            . '<p>If you have already paid, please ignore this message.</p>';
        queue_email($r['parent_email'], 'School fees reminder: ' . student_name($r), email_layout('School fees reminder', $body), 'reminder', (int) $r['id']);
        $count++;
    }
    return $count;
}
