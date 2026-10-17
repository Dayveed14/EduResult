<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('portal/index.php');
}

$error = null;
if (is_post()) {
    verify_csrf();
    $result = attempt_login((string) input('username'), (string) ($_POST['password'] ?? ''));
    if (is_array($result)) {
        redirect('portal/index.php');
    }
    $error = $result;
}

public_header('Staff Login');
?>
<?= render_flashes() ?>
<div class="panel">
  <h1>Staff Login</h1>
  <p class="lead">For school administrators and teachers.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" required autofocus value="<?= e(input('username')) ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>
    </div>
    <button class="btn btn-block" type="submit"><i class="fa fa-sign-in"></i> Sign in</button>
  </form>
  <p class="center muted" style="font-size:13px;margin-top:18px">Forgot your password? Ask the school administrator to reset it.</p>
</div>
<?php public_footer(); ?>
