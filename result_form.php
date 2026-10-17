<?php
/*
 * Result checker: open a report card with a token, or buy a token through Paystack.
 */
require __DIR__ . '/includes/bootstrap.php';

$fee = (int) setting('result_check_fee', '0');
$maxUses = max(1, (int) setting('token_max_uses', '5'));
$tab = input('tab', 'check') === 'buy' ? 'buy' : 'check';
$errors = [];

function published_term(int $id): ?array
{
    $t = find_term($id);
    return ($t && $t['results_published_at']) ? $t : null;
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $student = find_student_by_reg((string) input('reg_number'));

    // ---- Use an existing token / PIN ------------------------------------------
    if ($action === 'check') {
        $tab = 'check';
        $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) input('token')));
        $st = db()->prepare("SELECT * FROM result_checks WHERE token = ? AND status = 'paid'");
        $st->execute([$token]);
        $rc = $st->fetch();

        if (!$student || !$rc) {
            $errors[] = 'The registration number or token is not correct.';
        } elseif ($rc['student_id'] !== null && (int) $rc['student_id'] !== (int) $student['id']) {
            $errors[] = 'This token was issued for a different student.';
        } elseif ((int) $rc['uses'] >= $maxUses) {
            $errors[] = "This token has been used $maxUses times and has expired. Please get a new one.";
        } elseif (!($term = published_term((int) $rc['term_id']))) {
            $errors[] = 'Results for this term have not been published yet.';
        } elseif (results_blocked_for_fees($student, (int) $term['id'])) {
            $errors[] = 'This result is on hold because school fees for the term are not fully paid.';
        } else {
            // A school-issued PIN is locked to the first student who uses it.
            $upd = db()->prepare('UPDATE result_checks SET uses = uses + 1, student_id = COALESCE(student_id, ?) WHERE id = ? AND uses < ? AND (student_id IS NULL OR student_id = ?)');
            $upd->execute([$student['id'], $rc['id'], $maxUses, $student['id']]);
            if ($upd->rowCount() === 1) {
                grant_report_access((int) $student['id'], (int) $term['id']);
                redirect('report_card.php', ['student' => $student['id'], 'term' => $term['id']]);
            }
            $errors[] = 'This token can no longer be used.';
        }
    }

    // ---- Buy a token ------------------------------------------------------------
    if ($action === 'buy') {
        $tab = 'buy';
        $email = strtolower((string) input('email'));
        $term = published_term(input_int('term'));

        if (!$student) {
            $errors[] = 'We could not find a student with that registration number.';
        } elseif (!$term) {
            $errors[] = 'Choose a term whose results have been published.';
        } elseif (!valid_email($email)) {
            $errors[] = 'Enter a valid email address. Your token will be sent there.';
        } elseif (results_blocked_for_fees($student, (int) $term['id'])) {
            $errors[] = 'This result is on hold because school fees for the term are not fully paid. You can pay fees online first.';
        } else {
            // Already bought one with uses left? Re-send it to the original addresses instead of charging again.
            $st = db()->prepare("SELECT * FROM result_checks WHERE student_id = ? AND term_id = ? AND status = 'paid' AND uses < ? ORDER BY id DESC LIMIT 1");
            $st->execute([$student['id'], $term['id'], $maxUses]);
            if ($existing = $st->fetch()) {
                notify_result_token($existing);
                flash('info', 'A token was already bought for this student and term. We have sent it again to the email address used when it was bought and to the parent email on file.');
                redirect('result_form.php');
            }

            $reference = new_reference('RES');
            db()->prepare("INSERT INTO result_checks (student_id, term_id, source, email, amount_kobo, reference, status) VALUES (?, ?, 'online', ?, ?, ?, 'pending')")
                ->execute([$student['id'], $term['id'], $email, $fee * 100, $reference]);
            $id = (int) db()->lastInsertId();

            if ($fee <= 0) { // the school does not charge for results
                complete_result_check($id);
                grant_report_access((int) $student['id'], (int) $term['id']);
                redirect('report_card.php', ['student' => $student['id'], 'term' => $term['id']]);
            }
            try {
                $checkout = paystack_initialize($email, $fee * 100, $reference, [
                    'purpose'    => 'result_check',
                    'reg_number' => $student['reg_number'],
                    'term'       => term_label($term),
                ]);
                header('Location: ' . $checkout);
                exit;
            } catch (PaystackError $e) {
                db()->prepare("UPDATE result_checks SET status = 'failed' WHERE id = ?")->execute([$id]);
                error_log('Paystack init failed: ' . $e->getMessage());
                $errors[] = 'We could not start the payment right now. Please try again in a few minutes.';
            }
        }
    }
}

$publishedTerms = array_filter(all_terms(), fn($t) => $t['results_published_at']);
public_header('Check Result');
?>
<?= render_flashes() ?>
<div class="panel">
  <h1>Result Checker</h1>
  <p class="lead">Enter the registration number and your token. No token yet? Buy one online<?= $fee > 0 ? ' for ₦' . number_format($fee) : '' ?>.</p>

  <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="tabs" role="tablist">
    <button type="button" data-tab="check" class="<?= $tab === 'check' ? 'active' : '' ?>">I have a token / PIN</button>
    <button type="button" data-tab="buy" class="<?= $tab === 'buy' ? 'active' : '' ?>">Buy a token</button>
  </div>

  <form method="post" class="tab-pane <?= $tab === 'check' ? 'active' : '' ?>" id="pane-check">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="check">
    <div class="field">
      <label for="reg1">Registration number</label>
      <input id="reg1" name="reg_number" required autocomplete="off" value="<?= e(input('reg_number')) ?>" placeholder="e.g. ERS/2024/001">
    </div>
    <div class="field">
      <label for="token">Token or PIN</label>
      <input id="token" name="token" required autocomplete="off" placeholder="12-character token" style="text-transform:uppercase;letter-spacing:2px">
      <div class="hint">Each token can open the report card <?= $maxUses ?> times.</div>
    </div>
    <button class="btn btn-block" type="submit"><i class="fa fa-search"></i> View result</button>
  </form>

  <form method="post" class="tab-pane <?= $tab === 'buy' ? 'active' : '' ?>" id="pane-buy">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="buy">
    <input type="hidden" name="tab" value="buy">
    <?php if (!$publishedTerms): ?>
      <div class="alert alert-info">No results have been published yet. Please check back later.</div>
    <?php else: ?>
    <div class="field">
      <label for="reg2">Registration number</label>
      <input id="reg2" name="reg_number" required autocomplete="off" value="<?= e(input('reg_number')) ?>">
    </div>
    <div class="field">
      <label for="term">Term</label>
      <select id="term" name="term" required><?= term_options(input_int('term') ?: null, true) ?></select>
    </div>
    <div class="field">
      <label for="email">Your email</label>
      <input id="email" name="email" type="email" required value="<?= e(input('email')) ?>" placeholder="We'll email the token here">
    </div>
    <button class="btn btn-block" type="submit"><i class="fa fa-lock"></i> <?= $fee > 0 ? 'Pay ₦' . number_format($fee) . ' with Paystack' : 'Get token' ?></button>
    <?php endif; ?>
  </form>
</div>
<script>
document.querySelectorAll('.tabs button').forEach(function (b) {
  b.addEventListener('click', function () {
    document.querySelectorAll('.tabs button').forEach(function (x) { x.classList.toggle('active', x === b); });
    document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.toggle('active', p.id === 'pane-' + b.dataset.tab); });
  });
});
</script>
<?php public_footer(); ?>
