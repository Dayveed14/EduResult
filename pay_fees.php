<?php
/*
 * Parents look up a student's school fees for a term and pay all or part online.
 */
require __DIR__ . '/includes/bootstrap.php';

const MIN_PART_PAYMENT_KOBO = 100000; // ₦1,000 smallest part payment

$reg = (string) input('reg');
$term = input_int('term') ? find_term(input_int('term')) : current_term();
$student = $reg !== '' ? find_student_by_reg($reg) : null;
$statement = ($student && $term) ? fee_statement($student, (int) $term['id']) : null;
$errors = [];

if ($reg !== '' && !$student) {
    $errors[] = 'We could not find a student with that registration number.';
}

if (is_post() && input('action') === 'pay') {
    verify_csrf();
    $amount = naira_to_kobo(input('amount'));
    $email = strtolower((string) input('email'));
    if (!$student || !$statement) {
        $errors[] = 'Look up the student first.';
    } elseif ($statement['balance'] <= 0) {
        $errors[] = 'There is nothing to pay for this term.';
    } elseif ($amount <= 0 || $amount > $statement['balance']) {
        $errors[] = 'Enter an amount between ₦1 and the balance of ' . money($statement['balance']) . '.';
    } elseif ($amount < min(MIN_PART_PAYMENT_KOBO, $statement['balance'])) {
        $errors[] = 'The smallest part payment is ' . money(MIN_PART_PAYMENT_KOBO) . '.';
    } elseif (!valid_email($email)) {
        $errors[] = 'Enter a valid email address for the receipt.';
    } else {
        $reference = new_reference('FEE');
        db()->prepare("INSERT INTO fee_payments (student_id, term_id, amount_kobo, method, reference, status, payer_email) VALUES (?, ?, ?, 'paystack', ?, 'pending', ?)")
            ->execute([$student['id'], $term['id'], $amount, $reference, $email]);
        $paymentId = (int) db()->lastInsertId();
        try {
            $checkout = paystack_initialize($email, $amount, $reference, [
                'purpose'    => 'school_fees',
                'reg_number' => $student['reg_number'],
                'term'       => term_label($term),
            ]);
            header('Location: ' . $checkout);
            exit;
        } catch (PaystackError $e) {
            db()->prepare("UPDATE fee_payments SET status = 'failed' WHERE id = ?")->execute([$paymentId]);
            error_log('Paystack init failed: ' . $e->getMessage());
            $errors[] = 'We could not start the payment right now. Please try again in a few minutes.';
        }
    }
}

/** "Adaeze O." — enough for a parent to confirm, not a full name for strangers. */
function short_name(array $s): string
{
    return $s['first_name'] . ' ' . mb_substr($s['surname'], 0, 1) . '.';
}

public_header('Pay School Fees');
?>
<?= render_flashes() ?>
<div class="panel">
  <h1>Pay School Fees</h1>
  <p class="lead">Pay by card, bank transfer or USSD through Paystack. You can pay the full balance or part of it.</p>
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

  <form method="get">
    <div class="field">
      <label for="reg">Registration number</label>
      <input id="reg" name="reg" required value="<?= e($reg) ?>" autocomplete="off">
    </div>
    <div class="field">
      <label for="term">Term</label>
      <select id="term" name="term"><?= term_options($term ? (int) $term['id'] : null) ?></select>
    </div>
    <button class="btn btn-block" type="submit"><i class="fa fa-search"></i> Show fees</button>
  </form>

  <?php if ($student && $statement): ?>
    <hr style="border:0;border-top:1px solid rgba(255,255,255,.15);margin:26px 0">
    <h1 style="font-size:19px"><?= e(short_name($student)) ?> · <?= e($student['class_name']) ?></h1>
    <p class="lead"><?= e(term_label($term)) ?></p>

    <?php if (!$statement['items']): ?>
      <div class="alert alert-info">The school has not published fees for this class and term yet.</div>
    <?php else: ?>
      <table class="summary">
        <?php foreach ($statement['items'] as $item): ?>
          <tr><td><?= e($item['name']) ?></td><td class="num"><?= money((int) $item['amount_kobo']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td><strong>Total</strong></td><td class="num"><strong><?= money($statement['billed']) ?></strong></td></tr>
        <tr><td>Paid so far</td><td class="num"><?= money($statement['paid']) ?></td></tr>
        <tr class="total"><td>Balance</td><td class="num"><?= money($statement['balance']) ?></td></tr>
      </table>

      <?php if ($statement['payments']): ?>
        <p class="muted" style="font-size:13px">Receipts:
          <?php foreach ($statement['payments'] as $i => $p): ?><?= $i ? ', ' : '' ?><a href="<?= e(receipt_url($p['receipt_no'])) ?>"><?= e($p['receipt_no']) ?></a><?php endforeach; ?>
        </p>
      <?php endif; ?>

      <?php if ($statement['balance'] > 0): ?>
      <form method="post" action="<?= e(url('pay_fees.php', ['reg' => $student['reg_number'], 'term' => $term['id']])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay">
        <div class="field">
          <label for="amount">Amount to pay (₦)</label>
          <input id="amount" name="amount" inputmode="decimal" required value="<?= e(number_format($statement['balance'] / 100, 2, '.', '')) ?>">
          <div class="hint">Change this to make a part payment (at least <?= money(min(MIN_PART_PAYMENT_KOBO, $statement['balance'])) ?>).</div>
        </div>
        <div class="field">
          <label for="email">Email for the receipt</label>
          <input id="email" name="email" type="email" required value="<?= e(input('email', (string) $student['parent_email'])) ?>">
        </div>
        <button class="btn btn-block" type="submit"><i class="fa fa-lock"></i> Pay with Paystack</button>
      </form>
      <?php else: ?>
        <div class="alert alert-success">All fees for this term are fully paid. Thank you!</div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php public_footer(); ?>
