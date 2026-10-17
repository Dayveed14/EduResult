<?php
/*
 * Printable fee receipt. Opened from the signed link in the receipt email,
 * or by staff from the Payments page.
 */
require __DIR__ . '/includes/bootstrap.php';

$no = (string) input('no');
$key = (string) input('key');
$user = is_admin() ? current_user() : null;

if (!$user && !hash_equals(receipt_key($no), $key)) {
    http_response_code(403);
    exit('This receipt link is not valid.');
}

$st = db()->prepare("SELECT * FROM fee_payments WHERE receipt_no = ? AND status = 'success'");
$st->execute([$no]);
$payment = $st->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Receipt not found.');
}
$student = find_student((int) $payment['student_id']);
$term = find_term((int) $payment['term_id']);
$statement = fee_statement($student, (int) $term['id']);
$methods = ['paystack' => 'Online (Paystack)', 'cash' => 'Cash', 'transfer' => 'Bank transfer', 'pos' => 'POS'];
$school = setting('school_name');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($no) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/fontawesome.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/report.css') ?>">
</head>
<body>
<div class="toolbar no-print">
  <a href="<?= $user ? url('portal/payments.php') : url('pay_fees.php', ['reg' => $student['reg_number'], 'term' => $term['id']]) ?>"><i class="fa fa-arrow-left"></i> Back</a>
  <button onclick="window.print()"><i class="fa fa-print"></i> Print</button>
</div>
<?php foreach (take_flashes() as $f): ?><div class="notice no-print"><?= e($f['message']) ?></div><?php endforeach; ?>
<div class="sheet receipt">
  <header class="rc-head">
    <div class="crest"><?= e(school_initials()) ?></div>
    <div class="school">
      <h1><?= e($school) ?></h1>
      <div class="contact"><?= e(implode(' · ', array_filter([setting('school_address'), setting('school_phone'), setting('school_email')]))) ?></div>
    </div>
  </header>
  <div class="rc-title">Official Receipt — School Fees</div>
  <div class="info">
    <div><span>Receipt No.</span><?= e($payment['receipt_no']) ?></div>
    <div><span>Date</span><?= e(fmt_date($payment['paid_at'], 'j M Y, g:ia')) ?></div>
    <div><span>Method</span><?= e($methods[$payment['method']] ?? $payment['method']) ?></div>
    <div><span>Student</span><?= e(student_name($student)) ?></div>
    <div><span>Reg. No.</span><?= e($student['reg_number']) ?></div>
    <div><span>Class / Term</span><?= e($student['class_name']) ?> · <?= e(term_label($term)) ?></div>
  </div>

  <table class="lines">
    <tr><th>Description</th><th class="num">Amount</th></tr>
    <tr><td>School fees payment<?= $payment['note'] ? ' — ' . e($payment['note']) : '' ?><br><small style="color:#6b7280">Ref: <?= e($payment['reference']) ?></small></td>
        <td class="num"><strong><?= money((int) $payment['amount_kobo']) ?></strong></td></tr>
  </table>

  <table class="lines">
    <tr><th colspan="2">Term statement</th></tr>
    <?php foreach ($statement['items'] as $item): ?>
      <tr><td><?= e($item['name']) ?></td><td class="num"><?= money((int) $item['amount_kobo']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><strong>Total billed</strong></td><td class="num"><strong><?= money($statement['billed']) ?></strong></td></tr>
    <tr><td>Total paid to date</td><td class="num"><?= money($statement['paid']) ?></td></tr>
    <tr><td><strong>Balance outstanding</strong></td><td class="num"><strong><?= money($statement['balance']) ?></strong></td></tr>
  </table>

  <div class="foot" style="align-items:center;margin-top:24px">
    <span class="paid-stamp">PAID</span>
    <div>Thank you.</div>
  </div>
</div>
</body>
</html>
