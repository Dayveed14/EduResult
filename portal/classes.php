<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

$teachers = db()->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$teacherOpts = ['' => '— none —'];
foreach ($teachers as $t) {
    $teacherOpts[$t['id']] = $t['full_name'];
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    try {
        switch ($action) {
            case 'add_class':
                $name = strtoupper(trim((string) input('name')));
                if ($name === '') { flash('error', 'Enter a class name.'); break; }
                db()->prepare('INSERT INTO classes (name, sort_order, class_teacher_id) VALUES (?, ?, ?)')
                    ->execute([$name, input_int('sort_order'), input_int('class_teacher_id') ?: null]);
                flash('success', "Class $name added.");
                break;

            case 'update_classes':
                $st = db()->prepare('UPDATE classes SET name = ?, sort_order = ?, class_teacher_id = ? WHERE id = ?');
                foreach ((array) ($_POST['classes'] ?? []) as $id => $c) {
                    $name = strtoupper(trim((string) ($c['name'] ?? '')));
                    if ($name !== '') {
                        $st->execute([$name, (int) ($c['sort_order'] ?? 0), ((int) ($c['teacher'] ?? 0)) ?: null, (int) $id]);
                    }
                }
                flash('success', 'Classes saved.');
                break;

            case 'delete_class':
                $id = input_int('id');
                $n = db()->prepare('SELECT (SELECT COUNT(*) FROM students WHERE class_id = ?) + (SELECT COUNT(*) FROM scores WHERE class_id = ?)');
                $n->execute([$id, $id]);
                if ((int) $n->fetchColumn() > 0) {
                    flash('error', 'This class has students or scores. Move the students to another class first.');
                } else {
                    db()->prepare('DELETE FROM classes WHERE id = ?')->execute([$id]);
                    flash('success', 'Class deleted.');
                }
                break;

            case 'add_subject':
                $name = trim((string) input('name'));
                if ($name === '') { flash('error', 'Enter a subject name.'); break; }
                db()->prepare('INSERT INTO subjects (name, code) VALUES (?, ?)')->execute([$name, strtoupper(trim((string) input('code'))) ?: null]);
                flash('success', "Subject $name added.");
                break;

            case 'update_subjects':
                $st = db()->prepare('UPDATE subjects SET name = ?, code = ? WHERE id = ?');
                foreach ((array) ($_POST['subjects'] ?? []) as $id => $s) {
                    $name = trim((string) ($s['name'] ?? ''));
                    if ($name !== '') {
                        $st->execute([$name, strtoupper(trim((string) ($s['code'] ?? ''))) ?: null, (int) $id]);
                    }
                }
                flash('success', 'Subjects saved.');
                break;

            case 'delete_subject':
                $id = input_int('id');
                $n = db()->prepare('SELECT COUNT(*) FROM scores WHERE subject_id = ?');
                $n->execute([$id]);
                if ((int) $n->fetchColumn() > 0) {
                    flash('error', 'Scores have been recorded for this subject, so it cannot be deleted.');
                } else {
                    db()->prepare('DELETE FROM subjects WHERE id = ?')->execute([$id]);
                    flash('success', 'Subject deleted.');
                }
                break;

            case 'save_offerings':
                $classId = input_int('class_id');
                $offered = (array) ($_POST['offered'] ?? []);
                $teacherFor = (array) ($_POST['teacher'] ?? []);
                db()->beginTransaction();
                $keep = [];
                $up = db()->prepare('INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)');
                foreach ($offered as $subjectId => $on) {
                    $subjectId = (int) $subjectId;
                    $up->execute([$classId, $subjectId, ((int) ($teacherFor[$subjectId] ?? 0)) ?: null]);
                    $keep[] = $subjectId;
                }
                if ($keep) {
                    $in = implode(',', array_fill(0, count($keep), '?'));
                    db()->prepare("DELETE FROM class_subjects WHERE class_id = ? AND subject_id NOT IN ($in)")->execute(array_merge([$classId], $keep));
                } else {
                    db()->prepare('DELETE FROM class_subjects WHERE class_id = ?')->execute([$classId]);
                }
                db()->commit();
                flash('success', 'Subjects and teachers saved for ' . (find_class($classId)['name'] ?? 'class') . '.');
                redirect('portal/classes.php', ['class' => $classId]);

            case 'copy_offerings':
                $to = input_int('class_id');
                $from = input_int('from_class');
                if ($from && $to && $from !== $to) {
                    db()->prepare('INSERT IGNORE INTO class_subjects (class_id, subject_id) SELECT ?, subject_id FROM class_subjects WHERE class_id = ?')
                        ->execute([$to, $from]);
                    flash('success', 'Subjects copied. Now choose a teacher for each one.');
                }
                redirect('portal/classes.php', ['class' => $to]);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That name is already in use.');
        } else {
            throw $e;
        }
    }
    redirect('portal/classes.php', array_filter(['class' => input_int('class') ?: null]));
}

$classes = db()->query('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = \'active\') AS students,
                               (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS subjects
                        FROM classes c ORDER BY c.sort_order, c.name')->fetchAll();
$subjects = db()->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
$selectedClass = input_int('class') ? find_class(input_int('class')) : ($classes[0] ?? null);
$offerings = [];
if ($selectedClass) {
    $st = db()->prepare('SELECT subject_id, teacher_id FROM class_subjects WHERE class_id = ?');
    $st->execute([$selectedClass['id']]);
    foreach ($st as $r) {
        $offerings[(int) $r['subject_id']] = $r['teacher_id'];
    }
}

portal_header('Classes & Subjects');
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Classes</h2>
    <?php if ($classes): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_classes">
      <div class="table-wrap"><table class="data">
        <thead><tr><th>Name</th><th style="width:70px">Order</th><th>Class teacher</th><th class="num">Students</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
          <tr>
            <td><input name="classes[<?= $c['id'] ?>][name]" value="<?= e($c['name']) ?>"></td>
            <td><input type="number" name="classes[<?= $c['id'] ?>][sort_order]" value="<?= (int) $c['sort_order'] ?>"></td>
            <td><select name="classes[<?= $c['id'] ?>][teacher]"><?= options($teacherOpts, $c['class_teacher_id']) ?></select></td>
            <td class="num"><?= (int) $c['students'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p><button class="btn" type="submit"><i class="fa fa-save"></i> Save classes</button></p>
    </form>
    <?php endif; ?>
    <form method="post" class="filters" style="margin-bottom:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_class">
      <div><label>New class</label><input name="name" placeholder="e.g. JSS1A" required></div>
      <div style="min-width:80px;max-width:90px"><label>Order</label><input type="number" name="sort_order" value="<?= count($classes) + 1 ?>"></div>
      <div><label>Class teacher</label><select name="class_teacher_id"><?= options($teacherOpts) ?></select></div>
      <button class="btn btn-light" type="submit"><i class="fa fa-plus"></i> Add</button>
    </form>
    <p class="hint">The class teacher sees the class broadsheet, writes remarks and can preview report cards. "Order" sorts classes in lists (JSS1 before SS3).</p>
  </div>

  <div class="card">
    <h2>Subjects</h2>
    <?php if ($subjects): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_subjects">
      <div class="table-wrap" style="max-height:420px;overflow-y:auto"><table class="data">
        <thead><tr><th>Name</th><th style="width:110px">Code</th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
          <tr>
            <td><input name="subjects[<?= $s['id'] ?>][name]" value="<?= e($s['name']) ?>"></td>
            <td><input name="subjects[<?= $s['id'] ?>][code]" value="<?= e($s['code'] ?? '') ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p><button class="btn" type="submit"><i class="fa fa-save"></i> Save subjects</button></p>
    </form>
    <?php endif; ?>
    <form method="post" class="filters" style="margin-bottom:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_subject">
      <div style="flex:1"><label>New subject</label><input name="name" placeholder="e.g. Mathematics" required></div>
      <div style="max-width:110px;min-width:90px"><label>Code</label><input name="code" placeholder="MTH"></div>
      <button class="btn btn-light" type="submit"><i class="fa fa-plus"></i> Add</button>
    </form>
  </div>
</div>

<?php if ($selectedClass && $subjects): ?>
<div class="card">
  <div class="card-head">
    <h2>Subjects offered by <?= e($selectedClass['name']) ?> and who teaches them</h2>
    <form method="get"><select name="class" data-autosubmit><?= class_options((int) $selectedClass['id']) ?></select></form>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_offerings"><input type="hidden" name="class_id" value="<?= (int) $selectedClass['id'] ?>">
    <div class="table-wrap"><table class="data">
      <thead><tr><th style="width:60px" class="c">Offered</th><th>Subject</th><th>Subject teacher</th></tr></thead>
      <tbody>
      <?php foreach ($subjects as $s): $on = array_key_exists((int) $s['id'], $offerings); ?>
        <tr>
          <td class="c"><input type="checkbox" name="offered[<?= $s['id'] ?>]" value="1" <?= $on ? 'checked' : '' ?>></td>
          <td><?= e($s['name']) ?></td>
          <td><select name="teacher[<?= $s['id'] ?>]"><?= options($teacherOpts, $offerings[(int) $s['id']] ?? '') ?></select></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p><button class="btn" type="submit"><i class="fa fa-save"></i> Save for <?= e($selectedClass['name']) ?></button></p>
  </form>
  <?php if (count($classes) > 1): ?>
  <form method="post" class="filters" style="margin-bottom:0">
    <?= csrf_field() ?><input type="hidden" name="action" value="copy_offerings"><input type="hidden" name="class_id" value="<?= (int) $selectedClass['id'] ?>">
    <div><label>Copy the subject list from</label><select name="from_class"><?= class_options(null, array_filter($classes, fn($c) => (int) $c['id'] !== (int) $selectedClass['id'])) ?></select></div>
    <button class="btn btn-light" type="submit"><i class="fa fa-copy"></i> Copy</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($classes || $subjects): ?>
<details class="card panel-toggle">
  <summary>Delete a class or subject</summary>
  <div class="grid grid-2">
    <form method="post" class="filters" data-confirm="Delete this class?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_class">
      <div><label>Class</label><select name="id"><?= class_options(null, $classes) ?></select></div>
      <button class="btn btn-danger" type="submit">Delete class</button>
    </form>
    <form method="post" class="filters" data-confirm="Delete this subject?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_subject">
      <div><label>Subject</label><select name="id"><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-danger" type="submit">Delete subject</button>
    </form>
  </div>
  <p class="hint">A class can only be deleted when it has no students or scores; a subject only when it has no scores.</p>
</details>
<?php endif; ?>
<?php portal_footer(); ?>
