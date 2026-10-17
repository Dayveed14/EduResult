<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$term = selected_term();
$assignments = score_assignments($user);
$classId = input_int('class');
$subjectId = input_int('subject');
$limits = score_limits();

$allowed = $classId && $subjectId && can_enter_scores($user, $classId, $subjectId);
$locked = $term && $term['results_published_at'] && $user['role'] !== 'admin';

/** Students for the grid: everyone active in the class now, plus anyone already scored here. */
function grid_students(int $termId, int $classId, int $subjectId): array
{
    $st = db()->prepare(
        "SELECT st.*, sc.ca1, sc.ca2, sc.exam, sc.total
         FROM students st
         LEFT JOIN scores sc ON sc.student_id = st.id AND sc.term_id = ? AND sc.subject_id = ?
         WHERE (st.class_id = ? AND st.status = 'active')
            OR st.id IN (SELECT student_id FROM scores WHERE term_id = ? AND class_id = ? AND subject_id = ?)
         ORDER BY st.surname, st.first_name"
    );
    $st->execute([$termId, $subjectId, $classId, $termId, $classId, $subjectId]);
    return $st->fetchAll();
}

/** Parse one mark: '' => null, otherwise a number within 0..max. Returns [value, ok]. */
function parse_mark($raw, float $max): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [null, true];
    }
    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $max) {
        return [null, false];
    }
    return [round((float) $raw, 2), true];
}

function save_mark_row(int $studentId, int $termId, int $classId, int $subjectId, $ca1, $ca2, $exam, int $userId): void
{
    if ($ca1 === null && $ca2 === null && $exam === null) {
        db()->prepare('DELETE FROM scores WHERE student_id = ? AND term_id = ? AND subject_id = ?')->execute([$studentId, $termId, $subjectId]);
        return;
    }
    db()->prepare('INSERT INTO scores (student_id, term_id, class_id, subject_id, ca1, ca2, exam, entered_by) VALUES (?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), ca1 = VALUES(ca1), ca2 = VALUES(ca2), exam = VALUES(exam), entered_by = VALUES(entered_by)')
        ->execute([$studentId, $termId, $classId, $subjectId, $ca1, $ca2, $exam, $userId]);
}

if (is_post()) {
    verify_csrf();
    if (!$term || !$allowed) {
        http_response_code(403);
        exit('You are not assigned to this class and subject.');
    }
    if ($locked) {
        flash('error', 'Results for this term are published, so scores are locked. Ask the administrator to unpublish if a correction is needed.');
        redirect('portal/scores.php', ['term' => $term['id'], 'class' => $classId, 'subject' => $subjectId]);
    }
    $valid = array_column(grid_students((int) $term['id'], $classId, $subjectId), 'id', 'id');
    $bad = 0;
    $saved = 0;

    if (input('action') === 'upload') {
        // CSV columns: reg_number, ca1, ca2, exam  (header row required)
        $file = $_FILES['csv']['tmp_name'] ?? '';
        if ($file && is_uploaded_file($file)) {
            $fh = fopen($file, 'r');
            $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh) ?: []);
            $idx = array_flip($header);
            if (!isset($idx['reg_number'])) {
                flash('error', 'The CSV needs a reg_number column, plus ca1, ca2 and exam.');
            } else {
                db()->beginTransaction();
                while (($row = fgetcsv($fh)) !== false) {
                    $student = find_student_by_reg((string) ($row[$idx['reg_number']] ?? ''));
                    if (!$student || !isset($valid[$student['id']])) { $bad++; continue; }
                    [$ca1, $ok1] = parse_mark($row[$idx['ca1'] ?? -1] ?? '', $limits['ca1']);
                    [$ca2, $ok2] = parse_mark($row[$idx['ca2'] ?? -1] ?? '', $limits['ca2']);
                    [$exam, $ok3] = parse_mark($row[$idx['exam'] ?? -1] ?? '', $limits['exam']);
                    if (!$ok1 || !$ok2 || !$ok3) { $bad++; continue; }
                    save_mark_row((int) $student['id'], (int) $term['id'], $classId, $subjectId, $ca1, $ca2, $exam, (int) $user['id']);
                    $saved++;
                }
                db()->commit();
            }
            fclose($fh);
        }
    } else {
        db()->beginTransaction();
        foreach ((array) ($_POST['m'] ?? []) as $sid => $marks) {
            $sid = (int) $sid;
            if (!isset($valid[$sid])) { continue; }
            [$ca1, $ok1] = parse_mark($marks['ca1'] ?? '', $limits['ca1']);
            [$ca2, $ok2] = parse_mark($marks['ca2'] ?? '', $limits['ca2']);
            [$exam, $ok3] = parse_mark($marks['exam'] ?? '', $limits['exam']);
            if (!$ok1 || !$ok2 || !$ok3) { $bad++; continue; }
            save_mark_row($sid, (int) $term['id'], $classId, $subjectId, $ca1, $ca2, $exam, (int) $user['id']);
            $saved++;
        }
        db()->commit();
    }
    flash($bad ? 'warning' : 'success', "Saved $saved rows." . ($bad ? " $bad rows were skipped because a mark was above the maximum, not a number, or the student is not in this class." : ''));
    redirect('portal/scores.php', ['term' => $term['id'], 'class' => $classId, 'subject' => $subjectId]);
}

$rows = ($term && $allowed) ? grid_students((int) $term['id'], $classId, $subjectId) : [];
$className = $allowed ? find_class($classId)['name'] : '';
$subjectName = '';
foreach ($assignments as $a) {
    if ((int) $a['class_id'] === $classId && (int) $a['subject_id'] === $subjectId) {
        $subjectName = $a['subject_name'];
    }
}

portal_header('Enter Scores');
?>
<div class="card">
  <form method="get" class="filters" style="margin-bottom:0">
    <div><label>Term</label><select name="term"><?= term_options($term ? (int) $term['id'] : null) ?></select></div>
    <div style="min-width:260px"><label>Class and subject</label>
      <select name="cs" onchange="var p=this.value.split('-');this.form.class.value=p[0];this.form.subject.value=p[1];this.form.submit()">
        <option value="">Choose…</option>
        <?php foreach ($assignments as $a): $v = $a['class_id'] . '-' . $a['subject_id']; ?>
          <option value="<?= $v ?>" <?= $v === "$classId-$subjectId" ? 'selected' : '' ?>><?= e($a['class_name'] . ' — ' . $a['subject_name']) ?><?= $user['role'] === 'admin' && $a['teacher_name'] ? ' (' . e($a['teacher_name']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select></div>
    <input type="hidden" name="class" value="<?= $classId ?: '' ?>"><input type="hidden" name="subject" value="<?= $subjectId ?: '' ?>">
    <button class="btn btn-light" type="submit">Open</button>
  </form>
  <?php if (!$assignments): ?><p class="muted">You have no subjects assigned yet. The administrator assigns subject teachers on Classes &amp; Subjects.</p><?php endif; ?>
</div>

<?php if (!$term): ?>
  <div class="alert alert-warning">There is no current term yet.</div>
<?php elseif ($classId && !$allowed): ?>
  <div class="alert alert-error">You are not assigned to teach this subject in this class.</div>
<?php elseif ($allowed): ?>
<div class="card">
  <div class="card-head">
    <h2><?= e($className) ?> · <?= e($subjectName) ?> <small><?= e(term_label($term)) ?></small></h2>
    <span class="muted">CA1 out of <?= fmt_score($limits['ca1']) ?> · CA2 out of <?= fmt_score($limits['ca2']) ?> · Exam out of <?= fmt_score($limits['exam']) ?></span>
  </div>
  <?php if ($locked): ?><div class="alert alert-warning">Results for this term are published, so these scores are read-only.</div><?php endif; ?>
  <?php if (!$rows): ?>
    <p class="muted">There are no active students in <?= e($className) ?>.</p>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="table-wrap"><table class="data scores" data-scale='<?= e(json_encode(array_map(fn($g) => [$g[0], $g[1]], grade_scale()))) ?>'>
      <thead><tr><th>#</th><th>Student</th><th>Reg. No.</th><th class="c">CA1</th><th class="c">CA2</th><th class="c">Exam</th><th class="c">Total</th><th class="c">Grade</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e(student_name($r)) ?></td>
          <td class="mono"><?= e($r['reg_number']) ?></td>
          <?php foreach (['ca1', 'ca2', 'exam'] as $k): ?>
            <td class="c"><input name="m[<?= $r['id'] ?>][<?= $k ?>]" value="<?= e(fmt_score($r[$k])) ?>" data-max="<?= $limits[$k] ?>" inputmode="decimal" autocomplete="off" <?= $locked ? 'disabled' : '' ?>></td>
          <?php endforeach; ?>
          <td class="c total"></td>
          <td class="c grade"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$locked): ?>
    <p class="actions" style="margin-top:14px">
      <button class="btn" type="submit"><i class="fa fa-save"></i> Save scores</button>
      <span class="muted">Leave a row empty if the student did not take the subject. Press Enter to move down a column.</span>
    </p>
    <?php endif; ?>
  </form>
  <?php if (!$locked): ?>
  <details class="panel-toggle" style="margin-top:10px">
    <summary>Upload scores from a spreadsheet instead</summary>
    <form method="post" enctype="multipart/form-data" class="filters">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div><input type="file" name="csv" accept=".csv,text/csv" required></div>
      <button class="btn btn-light" type="submit"><i class="fa fa-upload"></i> Upload CSV</button>
    </form>
    <p class="hint">Save your sheet as CSV with the headings <span class="mono">reg_number, ca1, ca2, exam</span> in the first row.</p>
  </details>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
