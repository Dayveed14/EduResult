<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

$term = selected_term();
$view = in_array(input('view'), ['list', 'debtors', 'record'], true) ? input('view') : 'list';
$classId = input_int('class') ?: null;
$methods = ['paystack' => 'Paystack', 'cash' => 'Cash', 'transfer' => 'Bank transfer', 'pos' => 'POS'];

if (is_post() && $term) {
    verify_csrf();
    $action = input('action');

    if ($action === 'record') {
        $student = find_student_by_reg((string) input('reg_number'));
        $amount = naira_to_kobo(input('amount'));
        $method = input('method');
        $paidAt = input('paid_at') ?: date('Y-m-d');
        if (!$student) {
            flash('error', 'No student has that registration number.');
        } elseif ($amount <= 0) {
            flash('error', 'Enter the amount received.');
        } elseif (!in_array($method, ['cash', 'transfer', 'pos'], true)) {
            flash('error', 'Choose how the money was paid.');
        } else {
            db()->prepare("INSERT INTO fee_payments (student_id, term_id, amount_kobo, method, reference, status, payer_email, note, recorded_by, paid_at) VALUES (?,?,?,?,?, 'pending', ?, ?, ?, ?)")
                ->execute([$student['id'], $term['id'], $amount, $method, new_reference('OFF'), $student['parent_email'], trim((string) input('note')) ?: null, $user['id'], $paidAt . ' ' . date('H:i:s')]);
            $p = complete_fee_payment((int) db()->lastInsertId());
            flash('success', 'Payment of ' . money($amount) . ' recorded for ' . student_name($student) . '. Receipt ' . $p['receipt_no'] . ($student['parent_email'] ? ' emailed to the parent.' : '.'));
            redirect('portal/payments.php', ['term' => $term['id'], 'view' => 'record', 'last' => $p['receipt_no']]);
        }
        redirect('portal/payments.php', ['term' => $term['id'], 'view' => 'record']);
    }

    if ($action === 'void') {
        $st = db()->prepare("UPDATE fee_payments SET status = 'failed', note = CONCAT('VOIDED by ', ?, ' ', NOW(), IF(note IS NULL, '', CONCAT(' — ', note)))
                             WHERE id = ? AND method <> 'paystack' AND status = 'success'");
        $st->execute([$user['username'], input_int('id')]);
        flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Payment voided. Its receipt is no longer valid.' : 'Only offline payments can be voided here. Refund Paystack payments from the Paystack dashboard.');
        redirect('portal/payments.php', ['term' => $term['id']]);
    }

    if ($action === 'verify') {
        $r = settle_paystack_reference((string) input('reference'));
        flash($r['ok'] ? 'success' : 'warning', $r['message']);
        redirect('portal/payments.php', ['term' => $term['id'], 'status' => 'pending']);
    }

    if ($action === 'remind') {
        $n = queue_fee_reminders($term, $classId);
        $sent = send_queued(null, 25);
        flash('success', "$n reminder emails queued, {$sent['sent']} sent so far." . ($n > $sent['sent'] ? ' The rest send from Notifications or the cron job.' : ''));
        redirect('portal/payments.php', array_filter(['term' => $term['id'], 'view' => 'debtors', 'class' => $classId]));
    }
}

// ---- Data for the view ----------------------------------------------------------------
$payments = [];
$ledger = [];
if ($term && $view === 'list') {
    $sql = "SELECT fp.*, s.reg_number, s.surname, s.first_name, s.other_names, c.name AS class_name, u.full_name AS recorded_by_name
            FROM fee_payments fp JOIN students s ON s.id = fp.student_id JOIN classes c ON c.id = s.class_id
            LEFT JOIN users u ON u.id = fp.recorded_by
            WHERE fp.term_id = ?";
    $params = [$term['id']];
    $status = input('status', 'success');
    if ($status !== 'all') { $sql .= ' AND fp.status = ?'; $params[] = $status; }
    if ($classId) { $sql .= ' AND s.class_id = ?'; $params[] = $classId; }
    if (isset($methods[input('method')])) { $sql .= ' AND fp.method = ?'; $params[] = input('method'); }
    $sql .= ' ORDER BY COALESCE(fp.paid_at, fp.created_at) DESC LIMIT 1000';
    $st = db()->prepare($sql);
    $st->execute($params);
    $payments = $st->fetchAll();
}
if ($term && $view === 'debtors') {
    $ledger = array_values(array_filter(fee_ledger((int) $term['id'], $classId), fn($r) => $r['balance'] > 0));
    if (input('export') === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="fee_debtors_' . preg_replace('/\W+/', '_', term_label($term)) . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reg No', 'Name', 'Class', 'Parent', 'Phone', 'Email', 'Billed', 'Paid', 'Balance']);
        foreach ($ledger as $r) {
            fputcsv($out, [$r['reg_number'], student_name($r), $r['class_name'], $r['parent_name'], $r['parent_phone'], $r['parent_email'],
                $r['billed'] / 100, $r['paid'] / 100, $r['balance'] / 100]);
        }
        exit;
    }
}

portal_header('Payments');
$tabs = ['list' => 'Payments received', 'debtors' => 'Students owing', 'record' => 'Record offline payment'];
?>
<div class="card">
  <div class="filters" style="margin-bottom:0;justify-content:space-between">
    <div class="actions">
      <?php foreach ($tabs as $k => $label): ?>
        <a class="btn <?= $view === $k ? '' : 'btn-light' ?>" href="<?= url('portal/payments.php', array_filter(['view' => $k, 'term' => $term['id'] ?? null])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="filters" style="margin:0">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <div><label>Term</label><select name="term" data-autosubmit><?= term_options($term ? (int) $term['id'] : null) ?></select></div>
      <?php if ($view !== 'record'): ?>
      <div><label>Class</label><select name="class" data-autosubmit><option value="">All classes</option><?= class_options($classId) ?></select></div>
      <?php endif; ?>
      <?php if ($view === 'list'): ?>
      <div><label>Method</label><select name="method" data-autosubmit><option value="">All</option><?= options($methods, input('method')) ?></select></div>
      <div><label>Status</label><select name="status" data-autosubmit><?= options(['success' => 'Successful', 'pending' => 'Pending', 'failed' => 'Failed / voided', 'all' => 'All'], input('status', 'success')) ?></select></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$term): ?>
  <div class="alert alert-warning">Create a term first.</div>

<?php elseif ($view === 'record'): ?>
<div class="card" style="max-width:720px">
  <h2>Record a cash, transfer or POS payment <small><?= e(term_label($term)) ?></small></h2>
  <?php if (input('last')): ?><p><a class="btn btn-light" target="_blank" href="<?= e(receipt_url((string) input('last'))) ?>"><i class="fa fa-print"></i> Print receipt <?= e(input('last')) ?></a></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="record">
    <div class="form-row">
      <div><label>Registration number *</label><input name="reg_number" required autocomplete="off"></div>
      <div><label>Amount (₦) *</label><input name="amount" required inputmode="decimal"></div>
      <div><label>Method *</label><select name="method"><?= options(['cash' => 'Cash', 'transfer' => 'Bank transfer', 'pos' => 'POS']) ?></select></div>
      <div><label>Date received</label><input type="date" name="paid_at" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="field"><label>Note</label><input name="note" maxlength="200" placeholder="e.g. teller number or who brought it"></div>
    <button class="btn" type="submit"><i class="fa fa-check"></i> Record payment &amp; issue receipt</button>
  </form>
</div>

<?php elseif ($view === 'debtors'): ?>
<div class="card">
  <div class="card-head">
    <h2><?= count($ledger) ?> students owing <small>total <?= money(array_sum(array_column($ledger, 'balance'))) ?></small></h2>
    <div class="actions">
      <a class="btn btn-light" href="<?= url('portal/payments.php', array_filter(['view' => 'debtors', 'term' => $term['id'], 'class' => $classId, 'export' => 'csv'])) ?>"><i class="fa fa-file-excel-o"></i> Export CSV</a>
      <?php if ($ledger): ?>
      <form method="post" class="inline-form" data-confirm="Email a fees reminder to the parents of these <?= count($ledger) ?> students?">
        <?= csrf_field() ?><input type="hidden" name="action" value="remind">
        <button class="btn btn-gold" type="submit"><i class="fa fa-envelope"></i> Email reminders</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <input placeholder="Filter…" data-filter="#debtors" style="max-width:300px;margin-bottom:12px">
  <div class="table-wrap"><table class="data" id="debtors">
    <thead><tr><th>Reg. No.</th><th>Student</th><th>Class</th><th>Parent</th><th class="num">Billed</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $r): ?>
      <tr>
        <td class="mono"><?= e($r['reg_number']) ?></td>
        <td><?= e(student_name($r)) ?></td>
        <td><?= e($r['class_name']) ?></td>
        <td><?= e($r['parent_name'] ?? '') ?><br><small class="muted"><?= e($r['parent_phone'] ?? '') ?> <?= $r['parent_email'] ? '' : '<span class="badge warn">no email</span>' ?></small></td>
        <td class="num"><?= money($r['billed']) ?></td>
        <td class="num"><?= money($r['paid']) ?></td>
        <td class="num"><strong><?= money($r['balance']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger): ?><tr><td colspan="7" class="empty">Nobody owes anything for this term. 🎉</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php else: ?>
<div class="card">
  <h2><?= count($payments) ?> payments <small>total <?= money(array_sum(array_map(fn($p) => (int) $p['amount_kobo'], $payments))) ?></small></h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Date</th><th>Receipt</th><th>Student</th><th>Class</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= e(fmt_date($p['paid_at'] ?? $p['created_at'], 'j M Y g:ia')) ?></td>
        <td class="mono"><?= $p['receipt_no'] && $p['status'] === 'success' ? '<a target="_blank" href="' . e(receipt_url($p['receipt_no'])) . '">' . e($p['receipt_no']) . '</a>' : '<small class="muted">' . e($p['reference']) . '</small>' ?></td>
        <td><?= e(student_name($p)) ?><br><small class="muted mono"><?= e($p['reg_number']) ?></small></td>
        <td><?= e($p['class_name']) ?></td>
        <td><?= e($methods[$p['method']] ?? $p['method']) ?><?= $p['recorded_by_name'] ? '<br><small class="muted">by ' . e($p['recorded_by_name']) . '</small>' : '' ?></td>
        <td class="num"><?= money((int) $p['amount_kobo']) ?></td>
        <td><span class="badge <?= ['success' => 'ok', 'pending' => 'warn', 'failed' => 'err'][$p['status']] ?>"><?= e($p['status']) ?></span><?= $p['note'] ? '<br><small class="muted">' . e($p['note']) . '</small>' : '' ?></td>
        <td class="right">
          <?php if ($p['status'] === 'pending' && $p['method'] === 'paystack'): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="reference" value="<?= e($p['reference']) ?>"><button class="btn-link" type="submit">Check with Paystack</button></form>
          <?php endif; ?>
          <?php if ($p['status'] === 'success' && $p['method'] !== 'paystack'): ?>
          <form method="post" class="inline-form" data-confirm="Void this payment? Use this only for a mistake.">
            <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn-link danger" type="submit">Void</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="8" class="empty">No payments match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
