<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = input_int('id');

    if ($action === 'save') {
        $session = trim((string) input('session'));
        $termNo = input_int('term');
        if (!preg_match('#^(\d{4})/(\d{4})$#', $session, $m) || (int) $m[2] !== (int) $m[1] + 1 || $termNo < 1 || $termNo > 3) {
            flash('error', 'Session must look like 2025/2026 and the term must be 1, 2 or 3.');
            redirect('portal/terms.php', $id ? ['edit' => $id] : []);
        }
        $fields = [
            $session, $termNo,
            input('starts_on') ?: null, input('ends_on') ?: null, input('next_term_begins') ?: null,
            input_int('days_school_opened') ?: null,
        ];
        try {
            if ($id) {
                db()->prepare('UPDATE terms SET session=?, term=?, starts_on=?, ends_on=?, next_term_begins=?, days_school_opened=? WHERE id=?')
                    ->execute(array_merge($fields, [$id]));
            } else {
                db()->prepare('INSERT INTO terms (session, term, starts_on, ends_on, next_term_begins, days_school_opened) VALUES (?,?,?,?,?,?)')
                    ->execute($fields);
                $id = (int) db()->lastInsertId();
            }
            if (input('make_current') || !current_term()) {
                db()->exec('UPDATE terms SET is_current = 0');
                db()->prepare('UPDATE terms SET is_current = 1 WHERE id = ?')->execute([$id]);
            }
            flash('success', 'Term saved.');
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            flash('error', 'That term already exists.');
        }
        redirect('portal/terms.php');
    }

    if ($action === 'make_current' && $id) {
        db()->exec('UPDATE terms SET is_current = 0');
        db()->prepare('UPDATE terms SET is_current = 1 WHERE id = ?')->execute([$id]);
        flash('success', term_label(find_term($id)) . ' is now the current term. Score entry and fees default to it.');
        redirect('portal/terms.php');
    }

    if ($action === 'publish' && ($term = find_term($id))) {
        db()->prepare('UPDATE terms SET results_published_at = NOW() WHERE id = ?')->execute([$id]);
        $msg = 'Results for ' . term_label($term) . ' are published. Parents can now check them.';
        if (input('notify')) {
            $queued = queue_results_published($term);
            $sent = send_queued(null, 25);
            $msg .= " $queued parent emails queued, {$sent['sent']} sent so far";
            $msg .= $queued > $sent['sent'] ? ' (the rest send from the Notifications page or the cron job).' : '.';
        }
        flash('success', $msg);
        redirect('portal/terms.php');
    }

    if ($action === 'unpublish' && $id) {
        db()->prepare('UPDATE terms SET results_published_at = NULL WHERE id = ?')->execute([$id]);
        flash('success', 'Results hidden from parents again. Teachers can edit scores.');
        redirect('portal/terms.php');
    }
}

$terms = db()->query('SELECT t.*,
    (SELECT COUNT(DISTINCT student_id) FROM scores s WHERE s.term_id = t.id) AS students_scored
    FROM terms t ORDER BY session DESC, term DESC')->fetchAll();
$editing = input_int('edit') ? find_term(input_int('edit')) : null;

// Suggest the next term to create.
$suggest = ['session' => (date('n') >= 9 ? date('Y') : date('Y') - 1) . '/' . (date('n') >= 9 ? date('Y') + 1 : date('Y')), 'term' => 1];
if ($terms) {
    $last = $terms[0];
    $suggest = (int) $last['term'] < 3
        ? ['session' => $last['session'], 'term' => (int) $last['term'] + 1]
        : ['session' => ((int) substr($last['session'], 0, 4) + 1) . '/' . ((int) substr($last['session'], 0, 4) + 2), 'term' => 1];
}
$f = $editing ?: $suggest;

portal_header('Terms');
?>
<div class="card">
  <h2><?= $editing ? 'Edit ' . e(term_label($editing)) : 'Add a term' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>Session *</label><input name="session" required pattern="\d{4}/\d{4}" value="<?= e($f['session']) ?>" placeholder="2025/2026"></div>
      <div><label>Term *</label><select name="term"><?= options([1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'], $f['term']) ?></select></div>
      <div><label>Starts</label><input type="date" name="starts_on" value="<?= e($f['starts_on'] ?? '') ?>"></div>
      <div><label>Ends</label><input type="date" name="ends_on" value="<?= e($f['ends_on'] ?? '') ?>"></div>
      <div><label>Next term begins</label><input type="date" name="next_term_begins" value="<?= e($f['next_term_begins'] ?? '') ?>"></div>
      <div><label>Days school opened</label><input type="number" min="0" name="days_school_opened" value="<?= e($f['days_school_opened'] ?? '') ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="make_current" value="1" <?= !$editing ? 'checked' : '' ?>> Make this the current term</label>
    <p class="hint">"Next term begins" and "days school opened" are printed on report cards.</p>
    <div class="actions">
      <button class="btn" type="submit"><i class="fa fa-save"></i> Save term</button>
      <?php if ($editing): ?><a class="btn btn-light" href="<?= url('portal/terms.php') ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>All terms</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Term</th><th>Dates</th><th class="num">Students with scores</th><th>Results</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($terms as $t): ?>
      <tr>
        <td><strong><?= e(term_label($t)) ?></strong> <?= (int) $t['is_current'] ? '<span class="badge blue">current</span>' : '' ?></td>
        <td><?= e(fmt_date($t['starts_on'])) ?> – <?= e(fmt_date($t['ends_on'])) ?></td>
        <td class="num"><?= (int) $t['students_scored'] ?></td>
        <td><?= $t['results_published_at'] ? '<span class="badge ok">Published ' . e(fmt_date($t['results_published_at'])) . '</span>' : '<span class="badge warn">Not published</span>' ?></td>
        <td class="right">
          <div class="actions" style="justify-content:flex-end">
            <a class="btn btn-light btn-sm" href="<?= url('portal/terms.php', ['edit' => $t['id']]) ?>">Edit</a>
            <?php if (!(int) $t['is_current']): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="make_current"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-light btn-sm">Make current</button></form>
            <?php endif; ?>
            <?php if ($t['results_published_at']): ?>
              <form method="post" class="inline-form" data-confirm="Hide these results from parents?"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-light btn-sm">Unpublish</button></form>
            <?php else: ?>
              <form method="post" class="inline-form" data-confirm="Publish results? Parents will be able to check them and teachers can no longer change scores.">
                <?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                <label class="check" style="display:inline-flex;font-size:12px;margin-right:4px"><input type="checkbox" name="notify" value="1" checked> email parents</label>
                <button class="btn btn-ok btn-sm"><i class="fa fa-bullhorn"></i> Publish results</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$terms): ?><tr><td colspan="5" class="empty">No terms yet. Add the current term above.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php portal_footer(); ?>
