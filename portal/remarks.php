<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();
$admin = $user['role'] === 'admin';

$classes = managed_classes($user);
if (!$classes && $user['role'] === 'admin') {
    portal_header(basename($_SERVER['SCRIPT_NAME']) === 'remarks.php' ? 'Remarks' : 'Broadsheet');
    echo '<div class="alert alert-warning">Add classes first on <a href="' . url('portal/classes.php') . '">Classes &amp; Subjects</a>.</div>';
    portal_footer();
    exit;
}
if (!$classes) {
    http_response_code(403);
    exit('Only administrators and class teachers can write remarks.');
}
$term = selected_term();
$classId = input_int('class') ?: (int) $classes[0]['id'];
if (!can_manage_class($user, $classId)) {
    http_response_code(403);
    exit('You are not the class teacher of this class.');
}
$class = find_class($classId);
$locked = $term && $term['results_published_at'] && !$admin;
$res = $term ? class_results((int) $term['id'], $classId) : null;

if (is_post() && $term) {
    verify_csrf();
    if ($locked) {
        flash('error', 'Results are published, so remarks are locked.');
        redirect('portal/remarks.php', ['class' => $classId, 'term' => $term['id']]);
    }
    $st = db()->prepare('SELECT * FROM term_reports WHERE term_id = ? AND student_id = ?');
    $up = db()->prepare('INSERT INTO term_reports (student_id, term_id, teacher_remark, principal_remark, days_present) VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE teacher_remark = VALUES(teacher_remark), principal_remark = VALUES(principal_remark), days_present = VALUES(days_present)');
    $autofill = $admin && input('action') === 'autofill';
    db()->beginTransaction();
    foreach ($res['students'] as $sid => $r) {
        $st->execute([$term['id'], $sid]);
        $cur = $st->fetch() ?: ['teacher_remark' => null, 'principal_remark' => null, 'days_present' => null];
        $in = $_POST['r'][$sid] ?? null;
        if ($autofill) {
            $teacher = $cur['teacher_remark'];
            $principal = $cur['principal_remark'] ?: ($r['average'] !== null ? suggested_principal_remark((float) $r['average']) : null);
            $days = $cur['days_present'];
        } elseif (is_array($in)) {
            $teacher = trim((string) ($in['teacher'] ?? '')) ?: null;
            $principal = $admin ? (trim((string) ($in['principal'] ?? '')) ?: null) : $cur['principal_remark'];
            $days = ($in['days'] ?? '') === '' ? null : max(0, (int) $in['days']);
        } else {
            continue;
        }
        $up->execute([$sid, $term['id'], $teacher ? mb_substr($teacher, 0, 255) : null, $principal ? mb_substr($principal, 0, 255) : null, $days]);
    }
    db()->commit();
    flash('success', $autofill ? 'Empty principal remarks filled from each student\'s average. Review and edit as needed.' : 'Remarks saved.');
    redirect('portal/remarks.php', ['class' => $classId, 'term' => $term['id']]);
}

$saved = [];
if ($term) {
    $st = db()->prepare('SELECT * FROM term_reports WHERE term_id = ?');
    $st->execute([$term['id']]);
    foreach ($st as $r) {
        $saved[(int) $r['student_id']] = $r;
    }
}

portal_header('Remarks');
?>
<div class="card">
  <form method="get" class="filters" style="margin-bottom:0">
    <div><label>Class</label><select name="class" data-autosubmit><?= class_options($classId, $classes) ?></select></div>
    <div><label>Term</label><select name="term" data-autosubmit><?= term_options($term ? (int) $term['id'] : null) ?></select></div>
  </form>
</div>

<?php if ($term && $res): ?>
<div class="card">
  <div class="card-head">
    <h2><?= e($class['name']) ?> remarks <small><?= e(term_label($term)) ?></small></h2>
    <?php if ($admin): ?>
    <form method="post" data-confirm="Fill empty principal remarks with a suggestion based on each student's average?">
      <?= csrf_field() ?><input type="hidden" name="action" value="autofill">
      <button class="btn btn-light btn-sm" type="submit"><i class="fa fa-magic"></i> Suggest principal remarks</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($locked): ?><div class="alert alert-warning">Results are published, so remarks are read-only.</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Student</th><th class="c">Avg</th><th class="c">Pos.</th>
        <th class="c" style="width:90px">Days present<?= $term['days_school_opened'] ? ' / ' . (int) $term['days_school_opened'] : '' ?></th>
        <th>Class teacher's remark</th><th>Principal's remark</th></tr></thead>
      <tbody>
      <?php foreach ($res['students'] as $sid => $r): $sv = $saved[$sid] ?? []; ?>
        <tr>
          <td><?= e(student_name($r['student'])) ?><br><small class="muted mono"><?= e($r['student']['reg_number']) ?></small></td>
          <td class="c"><?= $r['average'] !== null ? number_format($r['average'], 1) : '–' ?></td>
          <td class="c"><?= $r['position'] ? ordinal($r['position']) : '–' ?></td>
          <td class="c"><input type="number" min="0" name="r[<?= $sid ?>][days]" value="<?= e($sv['days_present'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><input name="r[<?= $sid ?>][teacher]" maxlength="255" value="<?= e($sv['teacher_remark'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><input name="r[<?= $sid ?>][principal]" maxlength="255" value="<?= e($sv['principal_remark'] ?? '') ?>" <?= !$admin || $locked ? 'disabled title="Only administrators edit the principal\'s remark"' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$res['students']): ?><tr><td colspan="6" class="empty">No students in this class for this term.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php if (!$locked && $res['students']): ?><p><button class="btn" type="submit"><i class="fa fa-save"></i> Save remarks</button></p><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
