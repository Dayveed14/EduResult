<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    verify_csrf();
    if (input('action') === 'test_email') {
        $to = (string) input('to');
        $id = queue_email($to, 'Test email from ' . setting('school_name'), email_layout('It works', '<p>This is a test email from the school portal.</p>'), 'test');
        $r = send_queued([$id]);
        $st = db()->prepare('SELECT last_error FROM notifications WHERE id = ?');
        $st->execute([$id]);
        flash($r['sent'] ? 'success' : 'error', $r['sent'] ? "Test email sent to $to." . (config('mail.driver') === 'log' ? ' (Test mode: saved in storage/mail.)' : '') : 'Sending failed: ' . $st->fetchColumn());
        redirect('portal/settings.php');
    }

    $ca1 = (float) input('ca1_max');
    $ca2 = (float) input('ca2_max');
    $exam = (float) input('exam_max');
    if (abs($ca1 + $ca2 + $exam - 100) > 0.001 || $ca1 < 0 || $ca2 < 0 || $exam <= 0) {
        flash('error', 'CA1, CA2 and Exam maximums must add up to 100.');
        redirect('portal/settings.php');
    }
    $email = trim((string) input('school_email'));
    if ($email !== '' && !valid_email($email)) {
        flash('error', 'The school email is not valid.');
        redirect('portal/settings.php');
    }
    foreach (['school_name', 'school_motto', 'school_address', 'school_phone', 'school_email', 'principal_name'] as $k) {
        save_setting($k, mb_substr(trim((string) input($k)), 0, 200));
    }
    save_setting('result_check_fee', (string) max(0, input_int('result_check_fee')));
    save_setting('token_max_uses', (string) max(1, min(50, input_int('token_max_uses', 5))));
    save_setting('block_results_if_owing', input('block_results_if_owing') ? '1' : '0');
    save_setting('ca1_max', (string) $ca1);
    save_setting('ca2_max', (string) $ca2);
    save_setting('exam_max', (string) $exam);
    flash('success', 'Settings saved.');
    redirect('portal/settings.php');
}

$hasScores = (int) db()->query('SELECT COUNT(*) FROM scores')->fetchColumn() > 0;
portal_header('Settings');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card">
      <h2>School details</h2>
      <p class="hint mt0">Shown on report cards, receipts, emails and the home page.</p>
      <div class="field"><label>School name</label><input name="school_name" required value="<?= e(setting('school_name')) ?>"></div>
      <div class="field"><label>Motto</label><input name="school_motto" value="<?= e(setting('school_motto')) ?>"></div>
      <div class="field"><label>Address</label><input name="school_address" value="<?= e(setting('school_address')) ?>"></div>
      <div class="form-row">
        <div><label>Phone</label><input name="school_phone" value="<?= e(setting('school_phone')) ?>"></div>
        <div><label>Email</label><input type="email" name="school_email" value="<?= e(setting('school_email')) ?>"></div>
      </div>
      <div class="field"><label>Principal's name</label><input name="principal_name" value="<?= e(setting('principal_name')) ?>"></div>
    </div>

    <div>
      <div class="card">
        <h2>Result checking</h2>
        <div class="form-row">
          <div><label>Price of a result token (₦)</label><input type="number" min="0" name="result_check_fee" value="<?= e(setting('result_check_fee', '1000')) ?>"><div class="hint">0 = free.</div></div>
          <div><label>Uses per token</label><input type="number" min="1" max="50" name="token_max_uses" value="<?= e(setting('token_max_uses', '5')) ?>"></div>
        </div>
        <label class="check"><input type="checkbox" name="block_results_if_owing" value="1" <?= setting('block_results_if_owing') === '1' ? 'checked' : '' ?>> Hold results for students who have not paid the term's fees in full</label>
      </div>
      <div class="card">
        <h2>Score weighting</h2>
        <div class="form-row">
          <div><label>CA1 max</label><input type="number" step="0.5" min="0" name="ca1_max" value="<?= e(setting('ca1_max', '20')) ?>"></div>
          <div><label>CA2 max</label><input type="number" step="0.5" min="0" name="ca2_max" value="<?= e(setting('ca2_max', '20')) ?>"></div>
          <div><label>Exam max</label><input type="number" step="0.5" min="1" name="exam_max" value="<?= e(setting('exam_max', '60')) ?>"></div>
        </div>
        <p class="hint mt0">Must add up to 100. <?= $hasScores ? '<strong>Scores already exist:</strong> lowering a maximum does not change marks already entered.' : '' ?>
          Grades use the WAEC A1–F9 scale (edit <span class="mono">includes/grading.php</span> to change it).</p>
      </div>
    </div>
  </div>
  <button class="btn" type="submit"><i class="fa fa-save"></i> Save settings</button>
</form>

<div class="card" style="margin-top:20px;max-width:620px">
  <h2>Email</h2>
  <p class="muted mt0">Sending method: <strong><?= config('mail.driver') === 'smtp' ? 'SMTP (' . e(config('mail.host')) . ')' : 'Test mode (saved to storage/mail)' ?></strong>. Change it in <span class="mono">includes/config.php</span>.</p>
  <form method="post" class="filters" style="margin-bottom:0">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_email">
    <div style="flex:1"><label>Send a test email to</label><input type="email" name="to" required value="<?= e($user['email'] ?? '') ?>"></div>
    <button class="btn btn-light" type="submit"><i class="fa fa-paper-plane"></i> Send test</button>
  </form>
</div>
<?php portal_footer(); ?>
