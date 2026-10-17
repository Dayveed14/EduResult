<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        flash('error', 'Your current password is not correct.');
    } elseif ($new !== $confirm) {
        flash('error', 'The new passwords do not match.');
    } elseif ($problem = password_problem($new)) {
        flash('error', $problem);
    } elseif (password_verify($new, $user['password_hash'])) {
        flash('error', 'Choose a password different from the current one.');
    } else {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('portal/index.php');
    }
    redirect('portal/account.php');
}

portal_header('My Account');
?>
<div class="card" style="max-width:520px">
  <h2>Change password</h2>
  <p class="muted mt0">Signed in as <strong><?= e($user['username']) ?></strong> (<?= e($user['role']) ?>).</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"><div class="hint">At least 8 characters with letters and numbers.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
    <button class="btn" type="submit"><i class="fa fa-lock"></i> Change password</button>
  </form>
</div>
<?php portal_footer(); ?>
