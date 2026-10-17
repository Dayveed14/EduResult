<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$classes = managed_classes($user);
if (!$classes && $user['role'] === 'admin') {
    portal_header(basename($_SERVER['SCRIPT_NAME']) === 'remarks.php' ? 'Remarks' : 'Broadsheet');
    echo '<div class="alert alert-warning">Add classes first on <a href="' . url('portal/classes.php') . '">Classes &amp; Subjects</a>.</div>';
    portal_footer();
    exit;
}
if (!$classes) {
    http_response_code(403);
    exit('Only administrators and class teachers can open broadsheets.');
}
$term = selected_term();
$classId = input_int('class') ?: (int) $classes[0]['id'];
if (!can_manage_class($user, $classId)) {
    http_response_code(403);
    exit('You are not the class teacher of this class.');
}
$class = find_class($classId);
$res = $term ? class_results((int) $term['id'], $classId) : null;

// Rank order for display; unranked (no scores) at the bottom.
$rows = $res ? array_values($res['students']) : [];
usort($rows, function ($a, $b) {
    $pa = $a['position'] ?? PHP_INT_MAX;
    $pb = $b['position'] ?? PHP_INT_MAX;
    return $pa <=> $pb ?: strcmp($a['student']['surname'], $b['student']['surname']);
});

if ($res && input('export') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="broadsheet_' . preg_replace('/\W+/', '_', $class['name'] . '_' . term_label($term)) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['Position', 'Reg No', 'Name'], array_values($res['subjects']), ['Total', 'Subjects', 'Average']));
    foreach ($rows as $r) {
        $line = [$r['position'] ? ordinal($r['position']) : '', $r['student']['reg_number'], student_name($r['student'])];
        foreach ($res['subjects'] as $sid => $name) {
            $line[] = isset($r['scores'][$sid]) ? fmt_score($r['scores'][$sid]['total']) : '';
        }
        $line[] = fmt_score($r['total']);
        $line[] = $r['count'];
        $line[] = $r['average'] !== null ? number_format($r['average'], 2) : '';
        fputcsv($out, $line);
    }
    exit;
}

$codes = [];
foreach (db()->query("SELECT id, code FROM subjects WHERE code IS NOT NULL AND code <> ''") as $c) {
    $codes[(int) $c['id']] = $c['code'];
}

portal_header('Broadsheet');
?>
<div class="card no-print">
  <form method="get" class="filters" style="margin-bottom:0">
    <div><label>Class</label><select name="class" data-autosubmit><?= class_options($classId, $classes) ?></select></div>
    <div><label>Term</label><select name="term" data-autosubmit><?= term_options($term ? (int) $term['id'] : null) ?></select></div>
    <?php if ($res): ?>
      <a class="btn btn-light" href="<?= url('portal/broadsheet.php', ['class' => $classId, 'term' => $term['id'], 'export' => 'csv']) ?>"><i class="fa fa-file-excel-o"></i> Export CSV</a>
      <button type="button" class="btn btn-light" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
      <a class="btn btn-light" href="<?= url('portal/remarks.php', ['class' => $classId, 'term' => $term['id']]) ?>"><i class="fa fa-comment-o"></i> Remarks</a>
    <?php endif; ?>
  </form>
</div>

<?php if (!$term): ?>
  <div class="alert alert-warning">There is no current term yet.</div>
<?php else: ?>
<div class="card">
  <h2><?= e($class['name']) ?> broadsheet <small><?= e(term_label($term)) ?> · <?= (int) $res['out_of'] ?> students ranked</small></h2>
  <?php if (!$res['subjects']): ?>
    <p class="muted">No subjects are set up for this class yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="data broadsheet">
    <thead>
      <tr>
        <th>Pos.</th><th class="name">Student</th>
        <?php foreach ($res['subjects'] as $sid => $name): ?><th title="<?= e($name) ?>"><?= e($codes[$sid] ?? mb_strimwidth($name, 0, 12, '…')) ?></th><?php endforeach; ?>
        <th>Total</th><th>Avg</th><th>Grade</th><th class="no-print"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $s = $r['student']; ?>
      <tr>
        <td><strong><?= $r['position'] ? ordinal($r['position']) : '—' ?></strong></td>
        <td class="name"><?= e(student_name($s)) ?><br><small class="muted mono"><?= e($s['reg_number']) ?></small></td>
        <?php foreach ($res['subjects'] as $sid => $name): $sc = $r['scores'][$sid] ?? null; ?>
          <td><?= $sc ? fmt_score($sc['total']) . '<br><small class="muted">' . e($sc['grade']) . '</small>' : '<span class="muted">–</span>' ?></td>
        <?php endforeach; ?>
        <td><strong><?= fmt_score($r['total']) ?></strong></td>
        <td><?= $r['average'] !== null ? number_format($r['average'], 2) : '' ?></td>
        <td><?= $r['average'] !== null ? e(grade_for($r['average'])['grade']) : '' ?></td>
        <td class="no-print"><?php if ($r['count']): ?><a href="<?= url('report_card.php', ['student' => $s['id'], 'term' => $term['id']]) ?>">Report</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($res['subjects']) + 6 ?>" class="empty">No students or scores yet.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($res['subject_stats']): ?>
    <tfoot>
      <tr>
        <td></td><td class="name">Class average</td>
        <?php foreach ($res['subjects'] as $sid => $name): ?><td><?= isset($res['subject_stats'][$sid]) ? fmt_score($res['subject_stats'][$sid]['average']) : '' ?></td><?php endforeach; ?>
        <td colspan="4"></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
