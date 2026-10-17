<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'send') {
        $r = send_queued(null, 50);
        flash($r['failed'] ? 'warning' : 'success', "{$r['sent']} sent, {$r['failed']} failed.");
    }

    if ($action === 'retry') {
        db()->exec("UPDATE notifications SET status = 'queued', attempts = 0 WHERE status = 'failed'");
        $r = send_queued(null, 50);
        flash($r['failed'] ? 'warning' : 'success', "Retried: {$r['sent']} sent, {$r['failed']} failed.");
    }

    if ($action === 'broadcast') {
        $subject = trim((string) input('subject'));
        $message = trim((string) input('message'));
        $classId = input_int('class') ?: null;
        if ($subject === '' || $message === '') {
            flash('error', 'Write a subject and a message.');
        } else {
            $sql = "SELECT * FROM students WHERE status = 'active' AND parent_email IS NOT NULL AND parent_email <> ''";
            $params = [];
            if ($classId) { $sql .= ' AND class_id = ?'; $params[] = $classId; }
            $st = db()->prepare($sql);
            $st->execute($params);
            $seen = [];
            $n = 0;
            $body = '<p>' . nl2br(e($message)) . '</p>';
            foreach ($st as $s) {
                $to = strtolower($s['parent_email']);
                if (isset($seen[$to]) || !valid_email($to)) {
                    continue; // one email per parent even with several children
                }
                $seen[$to] = true;
                queue_email($to, $subject, email_layout($subject, '<p>Dear ' . e($s['parent_name'] ?: 'Parent/Guardian') . ',</p>' . $body), 'broadcast', (int) $s['id']);
                $n++;
            }
            $r = send_queued(null, 25);
            flash('success', "Message queued for $n parents; {$r['sent']} sent so far." . ($n > $r['sent'] ? ' Press "Send queued now" to continue, or let the cron job send the rest.' : ''));
        }
    }
    redirect('portal/notifications.php');
}

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) n FROM notifications GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$status = in_array(input('status'), ['queued', 'sent', 'failed'], true) ? input('status') : null;
$sql = 'SELECT n.id, n.type, n.recipient, n.subject, n.status, n.attempts, n.last_error, n.created_at, n.sent_at FROM notifications n';
$st = $status ? db()->prepare($sql . ' WHERE status = ? ORDER BY id DESC LIMIT 300') : db()->prepare($sql . ' ORDER BY id DESC LIMIT 300');
$st->execute($status ? [$status] : []);
$rows = $st->fetchAll();

portal_header('Notifications');
?>
<?php if (config('mail.driver') === 'log'): ?>
  <div class="alert alert-info"><strong>Test mode:</strong> emails are saved to the <span class="mono">storage/mail</span> folder instead of being sent. Set <span class="mono">mail.driver</span> to <span class="mono">smtp</span> in includes/config.php to send real email.</div>
<?php endif; ?>

<div class="grid grid-3" style="margin-bottom:20px">
  <div class="stat"><div class="label">Waiting to send</div><div class="value"><?= $counts['queued'] ?? 0 ?></div>
    <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="send"><button class="btn btn-sm" <?= empty($counts['queued']) ? 'disabled' : '' ?>><i class="fa fa-paper-plane"></i> Send queued now</button></form></div>
  <div class="stat"><div class="label">Sent</div><div class="value"><?= $counts['sent'] ?? 0 ?></div></div>
  <div class="stat"><div class="label">Failed</div><div class="value" style="color:<?= !empty($counts['failed']) ? '#b91c1c' : 'inherit' ?>"><?= $counts['failed'] ?? 0 ?></div>
    <?php if (!empty($counts['failed'])): ?><form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="btn btn-light btn-sm">Retry failed</button></form><?php endif; ?></div>
</div>

<div class="card">
  <h2>Send a message to parents</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="broadcast">
    <div class="form-row">
      <div><label>To parents of</label><select name="class"><option value="">All active students</option><?= class_options() ?></select></div>
      <div style="grid-column:span 2"><label>Subject</label><input name="subject" required maxlength="150" placeholder="e.g. Mid-term break"></div>
    </div>
    <div class="field"><label>Message</label><textarea name="message" required placeholder="Write your message…"></textarea></div>
    <button class="btn btn-gold" type="submit" data-confirm-send><i class="fa fa-envelope"></i> Send</button>
    <span class="hint">Parents with more than one child get one copy. Results-published, receipt, token and fee-reminder emails are sent automatically.</span>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h2>Recent emails</h2>
    <div class="actions">
      <?php foreach (['' => 'All', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'] as $k => $label): ?>
        <a class="btn btn-sm <?= (string) $status === $k ? '' : 'btn-light' ?>" href="<?= url('portal/notifications.php', $k ? ['status' => $k] : []) ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Created</th><th>Type</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $n): ?>
      <tr>
        <td><?= e(fmt_date($n['created_at'], 'j M g:ia')) ?></td>
        <td><span class="badge"><?= e($n['type']) ?></span></td>
        <td><?= e($n['recipient']) ?></td>
        <td><?= e($n['subject']) ?></td>
        <td><span class="badge <?= ['sent' => 'ok', 'queued' => 'warn', 'failed' => 'err'][$n['status']] ?>"><?= e($n['status']) ?></span>
          <?= $n['last_error'] ? '<br><small style="color:#b91c1c">' . e($n['last_error']) . '</small>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">No emails yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php portal_footer(); ?>
