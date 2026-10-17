<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

const CSV_COLUMNS = ['reg_number', 'surname', 'first_name', 'other_names', 'gender', 'class', 'parent_name', 'parent_email', 'parent_phone'];

// ---- CSV template download ----------------------------------------------------------
if (input('download') === 'template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="students_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, CSV_COLUMNS);
    fputcsv($out, ['ERS/2025/001', 'Okafor', 'Chidera', 'Grace', 'F', all_classes()[0]['name'] ?? 'JSS1A', 'Mr. Okafor', 'parent@example.com', '08030000000']);
    exit;
}

/** Validate and normalise a student record from a form or CSV row. Returns [data, error]. */
function clean_student(array $in, array $classesByName): array
{
    $d = [
        'reg_number'   => strtoupper(trim((string) ($in['reg_number'] ?? ''))),
        'surname'      => trim((string) ($in['surname'] ?? '')),
        'first_name'   => trim((string) ($in['first_name'] ?? '')),
        'other_names'  => trim((string) ($in['other_names'] ?? '')) ?: null,
        'gender'       => strtoupper(substr(trim((string) ($in['gender'] ?? '')), 0, 1)) ?: null,
        'parent_name'  => trim((string) ($in['parent_name'] ?? '')) ?: null,
        'parent_email' => strtolower(trim((string) ($in['parent_email'] ?? ''))) ?: null,
        'parent_phone' => trim((string) ($in['parent_phone'] ?? '')) ?: null,
        'class_id'     => null,
    ];
    if (isset($in['class_id'])) {
        $d['class_id'] = (int) $in['class_id'] ?: null;
    } elseif (isset($in['class'])) {
        $d['class_id'] = $classesByName[strtolower(trim((string) $in['class']))] ?? null;
    }
    if ($d['reg_number'] === '' || !preg_match('#^[A-Z0-9/\-_.]{2,30}$#', $d['reg_number'])) {
        return [null, 'Registration number is missing or has invalid characters.'];
    }
    if ($d['surname'] === '' || $d['first_name'] === '') {
        return [null, 'Surname and first name are required.'];
    }
    if (!$d['class_id']) {
        return [null, 'Class not found' . (isset($in['class']) ? ' ("' . $in['class'] . '")' : '') . '.'];
    }
    if ($d['gender'] !== null && !in_array($d['gender'], ['M', 'F'], true)) {
        return [null, 'Gender must be M or F.'];
    }
    if ($d['parent_email'] !== null && !valid_email($d['parent_email'])) {
        return [null, 'Parent email is not valid.'];
    }
    return [$d, null];
}

$classes = all_classes();
$classesByName = [];
foreach ($classes as $c) {
    $classesByName[strtolower($c['name'])] = (int) $c['id'];
}

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'save') {
        [$d, $err] = clean_student($_POST, $classesByName);
        $id = input_int('id');
        if ($err) {
            flash('error', $err);
        } else {
            $dup = db()->prepare('SELECT id FROM students WHERE reg_number = ? AND id <> ?');
            $dup->execute([$d['reg_number'], $id]);
            if ($dup->fetchColumn()) {
                flash('error', 'Another student already has registration number ' . $d['reg_number'] . '.');
            } else {
                $status = in_array(input('status'), ['active', 'graduated', 'withdrawn'], true) ? input('status') : 'active';
                $dob = input('date_of_birth') ?: null;
                if ($id) {
                    db()->prepare('UPDATE students SET reg_number=?, surname=?, first_name=?, other_names=?, gender=?, date_of_birth=?, class_id=?, parent_name=?, parent_email=?, parent_phone=?, status=? WHERE id=?')
                        ->execute([$d['reg_number'], $d['surname'], $d['first_name'], $d['other_names'], $d['gender'], $dob, $d['class_id'], $d['parent_name'], $d['parent_email'], $d['parent_phone'], $status, $id]);
                    flash('success', 'Student updated.');
                } else {
                    db()->prepare('INSERT INTO students (reg_number, surname, first_name, other_names, gender, date_of_birth, class_id, parent_name, parent_email, parent_phone, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$d['reg_number'], $d['surname'], $d['first_name'], $d['other_names'], $d['gender'], $dob, $d['class_id'], $d['parent_name'], $d['parent_email'], $d['parent_phone'], $status]);
                    flash('success', 'Student added.');
                }
                redirect('portal/students.php', ['class' => $d['class_id']]);
            }
        }
        redirect('portal/students.php', $id ? ['edit' => $id] : ['new' => 1]);
    }

    if ($action === 'delete') {
        $id = input_int('id');
        $st = db()->prepare('SELECT (SELECT COUNT(*) FROM scores WHERE student_id = ?) + (SELECT COUNT(*) FROM fee_payments WHERE student_id = ? AND status = \'success\')');
        $st->execute([$id, $id]);
        if ((int) $st->fetchColumn() > 0) {
            flash('error', 'This student has scores or payments, so the record cannot be deleted. Edit the student and set the status to Withdrawn instead.');
        } else {
            db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
            flash('success', 'Student deleted.');
        }
        redirect('portal/students.php');
    }

    if ($action === 'bulk') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $ids = array_values(array_filter($ids));
        if (!$ids) {
            flash('error', 'Tick at least one student first.');
        } else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if (input('bulk') === 'move' && ($to = input_int('to_class')) && find_class($to)) {
                db()->prepare("UPDATE students SET class_id = ? WHERE id IN ($in)")->execute(array_merge([$to], $ids));
                flash('success', count($ids) . ' students moved to ' . find_class($to)['name'] . '.');
            } elseif (in_array(input('bulk'), ['graduated', 'withdrawn', 'active'], true)) {
                db()->prepare("UPDATE students SET status = ? WHERE id IN ($in)")->execute(array_merge([input('bulk')], $ids));
                flash('success', count($ids) . ' students marked ' . input('bulk') . '.');
            } else {
                flash('error', 'Choose what to do with the selected students.');
            }
        }
        redirect('portal/students.php', array_filter(['class' => input_int('class') ?: null]));
    }

    if ($action === 'import') {
        $file = $_FILES['csv']['tmp_name'] ?? '';
        if (!$file || !is_uploaded_file($file)) {
            flash('error', 'Choose a CSV file to import.');
            redirect('portal/students.php', ['import' => 1]);
        }
        $fh = fopen($file, 'r');
        $header = fgetcsv($fh) ?: [];
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(['reg_number', 'surname', 'first_name', 'class'], $header);
        if ($missing) {
            flash('error', 'The CSV is missing these columns: ' . implode(', ', $missing) . '. Download the template to see the format.');
            redirect('portal/students.php', ['import' => 1]);
        }
        $added = $updated = 0;
        $problems = [];
        $line = 1;
        $find = db()->prepare('SELECT id FROM students WHERE reg_number = ?');
        $ins = db()->prepare('INSERT INTO students (reg_number, surname, first_name, other_names, gender, class_id, parent_name, parent_email, parent_phone) VALUES (?,?,?,?,?,?,?,?,?)');
        $upd = db()->prepare('UPDATE students SET surname=?, first_name=?, other_names=?, gender=?, class_id=?, parent_name=?, parent_email=?, parent_phone=? WHERE id=?');
        db()->beginTransaction();
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $assoc = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            [$d, $err] = clean_student($assoc, $classesByName);
            if ($err) {
                $problems[] = "Line $line: $err";
                continue;
            }
            $find->execute([$d['reg_number']]);
            if ($existing = $find->fetchColumn()) {
                $upd->execute([$d['surname'], $d['first_name'], $d['other_names'], $d['gender'], $d['class_id'], $d['parent_name'], $d['parent_email'], $d['parent_phone'], $existing]);
                $updated++;
            } else {
                $ins->execute([$d['reg_number'], $d['surname'], $d['first_name'], $d['other_names'], $d['gender'], $d['class_id'], $d['parent_name'], $d['parent_email'], $d['parent_phone']]);
                $added++;
            }
        }
        db()->commit();
        fclose($fh);
        flash('success', "Import finished: $added added, $updated updated" . ($problems ? ', ' . count($problems) . ' skipped.' : '.'));
        foreach (array_slice($problems, 0, 15) as $p) {
            flash('warning', $p);
        }
        redirect('portal/students.php');
    }
}

// ---- List ---------------------------------------------------------------------------
$classFilter = input_int('class');
$status = input('status', 'active');
$q = (string) input('q');
$sql = 'SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE 1=1';
$params = [];
if ($classFilter) { $sql .= ' AND s.class_id = ?'; $params[] = $classFilter; }
if ($status !== 'all') { $sql .= ' AND s.status = ?'; $params[] = $status; }
if ($q !== '') {
    $sql .= ' AND (s.reg_number LIKE ? OR s.surname LIKE ? OR s.first_name LIKE ? OR s.parent_email LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY c.sort_order, c.name, s.surname, s.first_name LIMIT 1000';
$st = db()->prepare($sql);
$st->execute($params);
$students = $st->fetchAll();

$editing = null;
if (input_int('edit')) {
    $editing = find_student(input_int('edit'));
} elseif (input('new')) {
    $editing = ['id' => 0, 'class_id' => $classFilter, 'status' => 'active'];
}

portal_header('Students');
?>
<?php if (!$classes): ?>
  <div class="alert alert-warning">Add classes first on <a href="<?= url('portal/classes.php') ?>">Classes &amp; Subjects</a>, then come back to add students.</div>
<?php endif; ?>

<?php if ($editing): ?>
<div class="card">
  <h2><?= $editing['id'] ? 'Edit student' : 'Add a student' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <div class="form-row">
      <div><label>Registration number *</label><input name="reg_number" required value="<?= e($editing['reg_number'] ?? '') ?>"></div>
      <div><label>Surname *</label><input name="surname" required value="<?= e($editing['surname'] ?? '') ?>"></div>
      <div><label>First name *</label><input name="first_name" required value="<?= e($editing['first_name'] ?? '') ?>"></div>
      <div><label>Other names</label><input name="other_names" value="<?= e($editing['other_names'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div><label>Class *</label><select name="class_id" required><option value="">Choose…</option><?= class_options((int) ($editing['class_id'] ?? 0), $classes) ?></select></div>
      <div><label>Gender</label><select name="gender"><?= options(['' => '—', 'M' => 'Male', 'F' => 'Female'], $editing['gender'] ?? '') ?></select></div>
      <div><label>Date of birth</label><input type="date" name="date_of_birth" value="<?= e($editing['date_of_birth'] ?? '') ?>"></div>
      <div><label>Status</label><select name="status"><?= options(['active' => 'Active', 'graduated' => 'Graduated', 'withdrawn' => 'Withdrawn'], $editing['status'] ?? 'active') ?></select></div>
    </div>
    <div class="form-row">
      <div><label>Parent / guardian name</label><input name="parent_name" value="<?= e($editing['parent_name'] ?? '') ?>"></div>
      <div><label>Parent email</label><input type="email" name="parent_email" value="<?= e($editing['parent_email'] ?? '') ?>"><div class="hint">Results, receipts and reminders go here.</div></div>
      <div><label>Parent phone</label><input name="parent_phone" value="<?= e($editing['parent_phone'] ?? '') ?>"></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><i class="fa fa-save"></i> Save</button>
      <a class="btn btn-light" href="<?= url('portal/students.php') ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (input('import')): ?>
<div class="card">
  <h2>Import students from a spreadsheet</h2>
  <p class="muted mt0">Save your Excel sheet as <strong>CSV</strong> with these column headings in the first row:
    <span class="mono" style="font-size:12px"><?= e(implode(', ', CSV_COLUMNS)) ?></span>.
    The class must match a class name exactly. Rows whose registration number already exists are updated.</p>
  <form method="post" enctype="multipart/form-data" class="filters">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div><input type="file" name="csv" accept=".csv,text/csv" required></div>
    <button class="btn" type="submit"><i class="fa fa-upload"></i> Import</button>
    <a class="btn btn-light" href="<?= url('portal/students.php', ['download' => 'template']) ?>"><i class="fa fa-download"></i> Download template</a>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2><?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></h2>
    <div class="actions">
      <a class="btn" href="<?= url('portal/students.php', ['new' => 1, 'class' => $classFilter ?: null]) ?>"><i class="fa fa-plus"></i> Add student</a>
      <a class="btn btn-light" href="<?= url('portal/students.php', ['import' => 1]) ?>"><i class="fa fa-upload"></i> Import CSV</a>
    </div>
  </div>
  <form method="get" class="filters">
    <div><label>Class</label><select name="class" data-autosubmit><option value="">All classes</option><?= class_options($classFilter, $classes) ?></select></div>
    <div><label>Status</label><select name="status" data-autosubmit><?= options(['active' => 'Active', 'graduated' => 'Graduated', 'withdrawn' => 'Withdrawn', 'all' => 'All'], $status) ?></select></div>
    <div style="flex:1"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Name, reg. number or parent email"></div>
    <button class="btn btn-light" type="submit">Search</button>
  </form>

  <form method="post" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk">
    <input type="hidden" name="class" value="<?= $classFilter ?>">
    <div class="table-wrap"><table class="data" id="studentTable">
      <thead><tr><th><input type="checkbox" data-check-all=".row-check" aria-label="Select all"></th><th>Reg. No.</th><th>Name</th><th>Class</th><th>Gender</th><th>Parent</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
          <td class="mono"><?= e($s['reg_number']) ?></td>
          <td><?= e(student_name($s)) ?></td>
          <td><?= e($s['class_name']) ?></td>
          <td><?= e($s['gender'] ?? '') ?></td>
          <td><?= e($s['parent_name'] ?? '') ?><br><small class="muted"><?= e($s['parent_email'] ?? '') ?> <?= e($s['parent_phone'] ?? '') ?></small></td>
          <td><span class="badge <?= $s['status'] === 'active' ? 'ok' : '' ?>"><?= e($s['status']) ?></span></td>
          <td class="right"><a href="<?= url('portal/students.php', ['edit' => $s['id']]) ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$students): ?><tr><td colspan="8" class="empty">No students match.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php if ($students): ?>
    <div class="filters" style="margin-top:14px;margin-bottom:0">
      <div><label>With selected students</label>
        <select name="bulk">
          <option value="">Choose…</option>
          <option value="move">Move / promote to class…</option>
          <option value="graduated">Mark as graduated</option>
          <option value="withdrawn">Mark as withdrawn</option>
          <option value="active">Mark as active</option>
        </select></div>
      <div><label>Target class (for move)</label><select name="to_class"><option value="">—</option><?= class_options(null, $classes) ?></select></div>
      <button class="btn btn-light" type="submit" data-confirm-bulk>Apply</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($editing && $editing['id']): ?>
<form method="post" data-confirm="Delete this student permanently?" class="no-print">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
  <button class="btn btn-danger btn-sm" type="submit"><i class="fa fa-trash"></i> Delete <?= e(student_name($editing)) ?></button>
</form>
<?php endif; ?>
<?php portal_footer(); ?>
