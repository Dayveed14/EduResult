<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();
$term = current_term();
$admin = $user['role'] === 'admin';

$assignments = score_assignments($user);
$progress = $term ? score_progress((int) $term['id'], $assignments) : [];
$myClasses = $admin ? [] : managed_classes($user);

if ($admin) {
    $counts = db()->query("SELECT
        (SELECT COUNT(*) FROM students WHERE status = 'active') AS students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND is_active = 1) AS teachers,
        (SELECT COUNT(*) FROM classes) AS classes,
        (SELECT COUNT(*) FROM subjects) AS subjects,
        (SELECT COUNT(*) FROM notifications WHERE status = 'queued') AS queued,
        (SELECT COUNT(*) FROM notifications WHERE status = 'failed') AS failed")->fetch();

    $fees = ['billed' => 0, 'paid' => 0, 'balance' => 0, 'debtors' => 0];
    $tokens = ['count' => 0, 'amount' => 0];
    if ($term) {
        foreach (fee_ledger((int) $term['id']) as $r) {
            $fees['billed'] += $r['billed'];
            $fees['paid'] += $r['paid'];
            $fees['balance'] += $r['balance'];
            $fees['debtors'] += $r['balance'] > 0 ? 1 : 0;
        }
        $st = db()->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(amount_kobo),0) AS amount FROM result_checks WHERE term_id = ? AND status = 'paid' AND source = 'online'");
        $st->execute([$term['id']]);
        $tokens = $st->fetch();
    }

    $setup = [];
    if (!$term) $setup[] = ['terms.php', 'Create the current term'];
    if (!$counts['classes']) $setup[] = ['classes.php', 'Add your classes'];
    if (!$counts['subjects']) $setup[] = ['classes.php', 'Add subjects and assign them to classes'];
    if (!$counts['teachers']) $setup[] = ['staff.php', 'Create teacher accounts'];
    if (!$counts['students']) $setup[] = ['students.php', 'Add or import students'];
    if (!setting('school_phone') && !setting('school_email')) $setup[] = ['settings.php', 'Fill in the school details'];
}

portal_header('Dashboard');
?>
<?php if ($admin && $setup): ?>
<div class="card" style="border-color:#fcd34d;background:#fffbeb">
  <h2><i class="fa fa-flag-checkered"></i> Getting started</h2>
  <ol style="margin:0;padding-left:20px">
    <?php foreach ($setup as [$link, $label]): ?><li><a href="<?= url('portal/' . $link) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<?php if ($admin): ?>
<div class="grid grid-4" style="margin-bottom:20px">
  <div class="stat"><div class="label">Active students</div><div class="value"><?= (int) $counts['students'] ?></div><div class="sub"><?= (int) $counts['classes'] ?> classes</div></div>
  <div class="stat"><div class="label">Teachers</div><div class="value"><?= (int) $counts['teachers'] ?></div><div class="sub"><?= (int) $counts['subjects'] ?> subjects</div></div>
  <div class="stat"><div class="label">Fees collected</div><div class="value"><?= money($fees['paid']) ?></div>
    <div class="sub"><?= $fees['billed'] ? round($fees['paid'] / $fees['billed'] * 100) . '% of ' . money($fees['billed']) : 'No fees set for this term' ?></div></div>
  <div class="stat"><div class="label">Outstanding fees</div><div class="value"><?= money($fees['balance']) ?></div><div class="sub"><a href="<?= url('portal/payments.php', ['view' => 'debtors']) ?>"><?= (int) $fees['debtors'] ?> students owing</a></div></div>
</div>
<div class="grid grid-3" style="margin-bottom:20px">
  <div class="stat"><div class="label">Result tokens sold online</div><div class="value"><?= (int) $tokens['count'] ?></div><div class="sub"><?= money((int) $tokens['amount']) ?> this term</div></div>
  <div class="stat"><div class="label">Results</div><div class="value" style="font-size:18px;margin-top:10px">
    <?php if (!$term): ?>—<?php elseif ($term['results_published_at']): ?><span class="badge ok">Published</span><?php else: ?><span class="badge warn">Not published</span><?php endif; ?></div>
    <div class="sub"><a href="<?= url('portal/terms.php') ?>">Manage terms</a></div></div>
  <div class="stat"><div class="label">Emails waiting</div><div class="value"><?= (int) $counts['queued'] ?></div>
    <div class="sub"><?= (int) $counts['failed'] ? '<span style="color:#b91c1c">' . (int) $counts['failed'] . ' failed</span> · ' : '' ?><a href="<?= url('portal/notifications.php') ?>">Open notifications</a></div></div>
</div>
<?php endif; ?>

<?php if (!$admin && $myClasses): ?>
<div class="card">
  <h2>My class<?= count($myClasses) > 1 ? 'es' : '' ?> (class teacher)</h2>
  <div class="actions">
  <?php foreach ($myClasses as $c): ?>
    <a class="btn btn-light" href="<?= url('portal/broadsheet.php', ['class' => $c['id']]) ?>"><i class="fa fa-table"></i> <?= e($c['name']) ?> broadsheet</a>
    <a class="btn btn-light" href="<?= url('portal/remarks.php', ['class' => $c['id']]) ?>"><i class="fa fa-comment-o"></i> <?= e($c['name']) ?> remarks</a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>Score entry progress <small><?= e(term_label($term)) ?></small></h2>
    <a class="btn btn-sm" href="<?= url('portal/scores.php') ?>"><i class="fa fa-pencil"></i> Enter scores</a>
  </div>
  <?php if (!$term): ?>
    <p class="muted">There is no current term. <?= $admin ? '<a href="' . url('portal/terms.php') . '">Create one</a>.' : 'Ask the administrator to set one.' ?></p>
  <?php elseif (!$progress): ?>
    <p class="muted"><?= $admin ? 'No subjects are assigned to classes yet.' : 'You have not been assigned any subjects yet. Ask the administrator.' ?></p>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Class</th><th>Subject</th><?php if ($admin): ?><th>Teacher</th><?php endif; ?><th>Progress</th><th class="num">Scored</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($progress as $p): $pct = $p['enrolled'] ? min(100, round($p['scored'] / $p['enrolled'] * 100)) : 0; ?>
      <tr>
        <td><?= e($p['class_name']) ?></td>
        <td><?= e($p['subject_name']) ?></td>
        <?php if ($admin): ?><td><?= $p['teacher_name'] ? e($p['teacher_name']) : '<span class="badge warn">Unassigned</span>' ?></td><?php endif; ?>
        <td style="width:30%"><div class="progress"><span style="width:<?= $pct ?>%"></span></div></td>
        <td class="num"><?= (int) $p['scored'] ?>/<?= (int) $p['enrolled'] ?></td>
        <td class="right"><a href="<?= url('portal/scores.php', ['class' => $p['class_id'], 'subject' => $p['subject_id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php portal_footer(); ?>
