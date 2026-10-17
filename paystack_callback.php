<?php
/*
 * Paystack sends the customer back here after checkout (?reference=...).
 * We verify the payment with Paystack's API before giving anything out.
 */
require __DIR__ . '/includes/bootstrap.php';

$reference = (string) ($_GET['reference'] ?? $_GET['trxref'] ?? '');
$result = $reference !== ''
    ? settle_paystack_reference($reference)
    : ['ok' => false, 'type' => null, 'row' => [], 'message' => 'No payment reference was received.'];

if ($result['ok'] && $result['type'] === 'result') {
    $rc = $result['row'];
    grant_report_access((int) $rc['student_id'], (int) $rc['term_id']);
    $_SESSION['new_token'] = $rc['token'];
    redirect('report_card.php', ['student' => $rc['student_id'], 'term' => $rc['term_id']]);
}

if ($result['ok'] && $result['type'] === 'fee') {
    flash('success', 'Payment received. Thank you! A receipt has been emailed to you.');
    header('Location: ' . receipt_url($result['row']['receipt_no']));
    exit;
}

public_header('Payment');
?>
<div class="panel">
  <h1>Payment not completed</h1>
  <p class="lead"><?= e($result['message']) ?></p>
  <?php if ($reference !== ''): ?>
    <p class="center muted">Reference: <strong><?= e($reference) ?></strong><br>If money left your account, contact the school with this reference.</p>
  <?php endif; ?>
  <p class="center">
    <a class="btn" href="<?= url($result['type'] === 'fee' ? 'pay_fees.php' : 'result_form.php') ?>">Try again</a>
  </p>
</div>
<?php public_footer(); ?>
