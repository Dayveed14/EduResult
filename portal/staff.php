<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

function temp_password(): string
{
    return random_code(4) . '-' . random_code(4);
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = input_int('id');

    if ($action === 'save') {
        $username = strtolower(trim((string) input('username')));
        $fullName = trim((string) input('full_name'));
        $email = trim((string) input('email')) ?: null;
        $phone = trim((string) input('phone')) ?: null;
        $role = input('role') === 'admin' ? 'admin' : 'teacher';
        $active = input('is_active', '1') === '1' ? 1 : 0;

        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username) || $fullName === '') {
            flash('error', 'Enter a full name and a username of 3–50 letters, numbers, dots or dashes.');
        } elseif ($email && !valid_email($email)) {
            flash('error', 'That email address is not valid.');
        } elseif ($id === (int) $me['id'] && ($role !== 'admin' || !$active)) {
            flash('error', 'You cannot remove your own admin access or deactivate yourself.');
        } else {
            try {
                if ($id) {
                    db()->prepare('UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, is_active=? WHERE id=?')
                        ->execute([$username, $fullName, $email, $phone, $role, $active, $id]);
                    flash('success', 'Staff member updated.');
                } else {
                    $pw = temp_password();
                    db()->prepare('INSERT INTO users (username, full_name, email, phone, password_hash, role, must_change_password) VALUES (?,?,?,?,?,?,1)')
                        ->execute([$username, $fullName, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), $role]);
                    flash('success', "Account created. Give $fullName these sign-in details: username \"$username\", temporary password \"$pw\". They will be asked to choose a new password.");
                }
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                flash('error', 'That username is already taken.');
            }
        }
        redirect('portal/staff.php');
    }

    if ($action === 'reset' && $id) {
        $pw = temp_password();
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        $st = db()->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$id]);
        flash('success', 'New temporary password for "' . $st->fetchColumn() . "\": $pw");
        redirect('portal/staff.php');
    }
}

$users = db()->query("SELECT u.*,
    (SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') FROM classes WHERE class_teacher_id = u.id) AS class_teacher_of,
    (SELECT COUNT(*) FROM class_subjects WHERE teacher_id = u.id) AS subject_count
    FROM users u ORDER BY u.role, u.full_name")->fetchAll();
$editing = input_int('edit') ? (array_values(array_filter($users, fn($u) => (int) $u['id'] === input_int('edit')))[0] ?? null) : null;

portal_header('Staff');
?>
<div class="card">
  <h2><?= $editing ? 'Edit ' . e($editing['full_name']) : 'Add a staff member' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>Full name *</label><input name="full_name" required value="<?= e($editing['full_name'] ?? '') ?>"></div>
      <div><label>Username *</label><input name="username" required value="<?= e($editing['username'] ?? '') ?>"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= e($editing['email'] ?? '') ?>"></div>
      <div><label>Phone</label><input name="phone" value="<?= e($editing['phone'] ?? '') ?>"></div>
      <div><label>Role</label><select name="role"><?= options(['teacher' => 'Teacher', 'admin' => 'Administrator'], $editing['role'] ?? 'teacher') ?></select></div>
      <?php if ($editing): ?><div><label>Status</label><select name="is_active"><?= options(['1' => 'Active', '0' => 'Deactivated'], $editing['is_active']) ?></select></div><?php endif; ?>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><i class="fa fa-save"></i> <?= $editing ? 'Save' : 'Create account' ?></button>
      <?php if ($editing): ?><a class="btn btn-light" href="<?= url('portal/staff.php') ?>">Cancel</a><?php endif; ?>
    </div>
    <?php if (!$editing): ?><p class="hint">A temporary password is generated and shown once. Teachers enter scores for the subjects you assign them on Classes &amp; Subjects.</p><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>All staff</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Class teacher of</th><th class="num">Subjects</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['full_name']) ?><?= !(int) $u['is_active'] ? ' <span class="badge err">inactive</span>' : '' ?><br><small class="muted"><?= e($u['email'] ?? '') ?></small></td>
        <td class="mono"><?= e($u['username']) ?></td>
        <td><span class="badge <?= $u['role'] === 'admin' ? 'blue' : '' ?>"><?= e($u['role']) ?></span></td>
        <td><?= e($u['class_teacher_of'] ?? '—') ?></td>
        <td class="num"><?= (int) $u['subject_count'] ?></td>
        <td><?= e(fmt_date($u['last_login_at'], 'j M Y g:ia')) ?></td>
        <td class="right">
          <div class="actions" style="justify-content:flex-end">
            <a class="btn btn-light btn-sm" href="<?= url('portal/staff.php', ['edit' => $u['id']]) ?>">Edit</a>
            <form method="post" class="inline-form" data-confirm="Reset this password? The old one will stop working.">
              <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-light btn-sm" type="submit">Reset password</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php portal_footer(); ?>
