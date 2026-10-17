<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

$term = selected_term();
$source = input('source') === 'offline' ? 'offline' : 'online';
$maxUses = (int) setting('token_max_uses', '5');

if (is_post() && $term) {
    verify_csrf();
    if (input('action') === 'generate') {
        $count = max(1, min(500, input_int('count')));
        $batch = [];
        $st = db()->prepare("INSERT INTO result_checks (term_id, source, token, status, paid_at) VALUES (?, 'offline', ?, 'paid', NOW())");
        db()->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $tok = unique_token();
            $st->execute([$term['id'], $tok]);
            $batch[] = (int) db()->lastInsertId();
        }
        db()->commit();
        $_SESSION['pin_batch'] = $batch;
        flash('success', "$count PINs generated for " . term_label($term) . '. Print them below and keep the sheet safe.');
        redirect('portal/tokens.php', ['term' => $term['id'], 'source' => 'offline', 'print' => 1]);
    }
    if (input('action') === 'verify') {
        $r = settle_paystack_reference((string) input('reference'));
        flash($r['ok'] ? 'success' : 'warning', $r['message']);
        redirect('portal/tokens.php', ['term' => $term['id']]);
    }
}

$rows = [];
if ($term) {
    $sql = 'SELECT rc.*, s.reg_number, s.surname, s.first_name, s.other_names FROM result_checks rc LEFT JOIN students s ON s.id = rc.student_id
            WHERE rc.term_id = ? AND rc.source = ?';
    $params = [$term['id'], $source];
    if (input('print') && !empty($_SESSION['pin_batch'])) {
        $ids = array_map('intval', $_SESSION['pin_batch']);
        $sql .= ' AND rc.id IN (' . implode(',', $ids) . ')';
    }
    $st = db()->prepare($sql . ' ORDER BY rc.id DESC LIMIT 2000');
    $st->execute($params);
    $rows = $st->fetchAll();

    if (input('export') === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="result_pins_' . preg_replace('/\W+/', '_', term_label($term)) . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['PIN', 'Term', 'Used by', 'Uses']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['token'], term_label($term), $r['reg_number'] ?? '', $r['uses'] . '/' . $maxUses]);
        }
        exit;
    }
}
$st = $term ? db()->prepare("SELECT source, status, COUNT(*) n, COALESCE(SUM(amount_kobo),0) amt FROM result_checks WHERE term_id = ? GROUP BY source, status") : null;
$summary = [];
if ($st) {
    $st->execute([$term['id']]);
    foreach ($st as $r) {
        $summary[$r['source']][$r['status']] = $r;
    }
}

portal_header('Result Tokens');
?>
<?php if (!$term): ?>
  <div class="alert alert-warning">Create a term first.</div>
<?php elseif (input('print') && $source === 'offline'): ?>
  <div class="card">
    <div class="card-head no-print">
      <h2>PIN sheet <small><?= e(term_label($term)) ?></small></h2>
      <div class="actions">
        <button class="btn" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
        <a class="btn btn-light" href="<?= url('portal/tokens.php', ['term' => $term['id'], 'source' => 'offline']) ?>">Done</a>
      </div>
    </div>
    <div class="pin-sheet">
      <?php foreach ($rows as $r): ?>
        <div class="pin-card">
          <div style="font-weight:700"><?= e(setting('school_name')) ?></div>
          <div class="muted" style="font-size:12px">Result PIN · <?= e(term_label($term)) ?></div>
          <div class="mono" style="margin:8px 0"><?= e(chunk_split($r['token'], 4, ' ')) ?></div>
          <div style="font-size:11px">Check at <?= e(absolute_url('result_form.php')) ?> · <?= $maxUses ?> uses</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
<div class="grid grid-3" style="margin-bottom:20px">
  <div class="stat"><div class="label">Bought online</div><div class="value"><?= (int) ($summary['online']['paid']['n'] ?? 0) ?></div><div class="sub"><?= money((int) ($summary['online']['paid']['amt'] ?? 0)) ?> received</div></div>
  <div class="stat"><div class="label">Abandoned / failed checkouts</div><div class="value"><?= (int) ($summary['online']['pending']['n'] ?? 0) + (int) ($summary['online']['failed']['n'] ?? 0) ?></div><div class="sub">started but not paid</div></div>
  <div class="stat"><div class="label">School PINs</div><div class="value"><?= (int) ($summary['offline']['paid']['n'] ?? 0) ?></div><div class="sub">printed to sell at the bursary</div></div>
</div>

<div class="card">
  <div class="card-head">
    <div class="actions">
      <a class="btn <?= $source === 'online' ? '' : 'btn-light' ?>" href="<?= url('portal/tokens.php', ['term' => $term['id']]) ?>">Online purchases</a>
      <a class="btn <?= $source === 'offline' ? '' : 'btn-light' ?>" href="<?= url('portal/tokens.php', ['term' => $term['id'], 'source' => 'offline']) ?>">School PINs</a>
    </div>
    <form method="get" class="filters" style="margin:0">
      <input type="hidden" name="source" value="<?= e($source) ?>">
      <div><label>Term</label><select name="term" data-autosubmit><?= term_options((int) $term['id']) ?></select></div>
    </form>
  </div>

  <?php if ($source === 'offline'): ?>
  <form method="post" class="filters">
    <?= csrf_field() ?><input type="hidden" name="action" value="generate">
    <div style="max-width:160px"><label>How many PINs?</label><input type="number" name="count" min="1" max="500" value="50"></div>
    <button class="btn btn-gold" type="submit"><i class="fa fa-ticket"></i> Generate PINs for <?= e(term_label($term)) ?></button>
    <a class="btn btn-light" href="<?= url('portal/tokens.php', ['term' => $term['id'], 'source' => 'offline', 'export' => 'csv']) ?>"><i class="fa fa-download"></i> Export CSV</a>
  </form>
  <p class="hint mt0">Sell printed PINs at the bursary for parents who prefer to pay cash. Each PIN works for one student (the first one it is used for), <?= $maxUses ?> times.</p>
  <?php endif; ?>

  <input placeholder="Filter…" data-filter="#tok" style="max-width:300px;margin-bottom:12px">
  <div class="table-wrap"><table class="data" id="tok">
    <thead><tr><th>Date</th><th>Token</th><th>Student</th><?php if ($source === 'online'): ?><th>Email</th><th class="num">Amount</th><?php endif; ?><th>Status</th><th class="c">Uses</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(fmt_date($r['paid_at'] ?? $r['created_at'], 'j M Y g:ia')) ?></td>
        <td class="mono"><?= e($r['token'] ?? '—') ?><?php if ($r['reference']): ?><br><small class="muted"><?= e($r['reference']) ?></small><?php endif; ?></td>
        <td><?= $r['student_id'] ? e(student_name($r)) . '<br><small class="muted mono">' . e($r['reg_number']) . '</small>' : '<span class="muted">unused</span>' ?></td>
        <?php if ($source === 'online'): ?>
          <td><?= e($r['email'] ?? '') ?></td>
          <td class="num"><?= money((int) $r['amount_kobo']) ?></td>
        <?php endif; ?>
        <td>
          <span class="badge <?= ['paid' => 'ok', 'pending' => 'warn', 'failed' => 'err'][$r['status']] ?>"><?= e($r['status']) ?></span>
          <?php if ($r['status'] === 'pending' && $r['reference']): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="reference" value="<?= e($r['reference']) ?>"><button class="btn-link" type="submit">check with Paystack</button></form>
          <?php endif; ?>
        </td>
        <td class="c"><?= (int) $r['uses'] ?>/<?= $maxUses ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
