<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_admin();

$term = selected_term();
$classes = all_classes();
$classId = input_int('class') ?: (int) ($classes[0]['id'] ?? 0);

if (is_post() && $term && $classId) {
    verify_csrf();
    $action = input('action');
    $back = ['term' => $term['id'], 'class' => $classId];

    if ($action === 'add') {
        $name = trim((string) input('name'));
        $amount = naira_to_kobo(input('amount'));
        if ($name === '' || $amount <= 0) {
            flash('error', 'Enter a fee name and an amount above zero.');
        } else {
            try {
                db()->prepare('INSERT INTO fee_items (term_id, class_id, name, amount_kobo) VALUES (?,?,?,?)')->execute([$term['id'], $classId, $name, $amount]);
                flash('success', "$name added.");
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                flash('error', 'This class already has a fee with that name for the term.');
            }
        }
    }

    if ($action === 'update') {
        $st = db()->prepare('UPDATE fee_items SET name = ?, amount_kobo = ? WHERE id = ? AND term_id = ? AND class_id = ?');
        foreach ((array) ($_POST['items'] ?? []) as $id => $it) {
            $amount = naira_to_kobo($it['amount'] ?? '');
            $name = trim((string) ($it['name'] ?? ''));
            if ($name !== '' && $amount > 0) {
                $st->execute([$name, $amount, (int) $id, $term['id'], $classId]);
            }
        }
        flash('success', 'Fees updated.');
    }

    if ($action === 'delete') {
        db()->prepare('DELETE FROM fee_items WHERE id = ? AND term_id = ? AND class_id = ?')->execute([input_int('id'), $term['id'], $classId]);
        flash('success', 'Fee item removed.');
    }

    if ($action === 'copy_to_classes') {
        $targets = array_map('intval', (array) ($_POST['targets'] ?? []));
        $n = 0;
        $st = db()->prepare('INSERT INTO fee_items (term_id, class_id, name, amount_kobo) SELECT term_id, ?, name, amount_kobo FROM fee_items WHERE term_id = ? AND class_id = ?
                             ON DUPLICATE KEY UPDATE amount_kobo = VALUES(amount_kobo)');
        foreach ($targets as $t) {
            if ($t && $t !== $classId) {
                $st->execute([$t, $term['id'], $classId]);
                $n++;
            }
        }
        flash('success', "Fee items copied to $n classes.");
    }

    if ($action === 'copy_from_term') {
        $from = input_int('from_term');
        if ($from && $from !== (int) $term['id']) {
            $st = db()->prepare('INSERT IGNORE INTO fee_items (term_id, class_id, name, amount_kobo) SELECT ?, class_id, name, amount_kobo FROM fee_items WHERE term_id = ?');
            $st->execute([$term['id'], $from]);
            flash('success', $st->rowCount() . ' fee items copied from ' . term_label(find_term($from)) . ' for all classes.');
        }
    }
    redirect('portal/fees.php', $back);
}

$items = ($term && $classId) ? fee_items((int) $term['id'], $classId) : [];
$total = array_sum(array_map(fn($i) => (int) $i['amount_kobo'], $items));
$overview = [];
if ($term) {
    $st = db()->prepare('SELECT c.id, c.name, COALESCE(SUM(fi.amount_kobo), 0) AS total, COUNT(fi.id) AS n
                         FROM classes c LEFT JOIN fee_items fi ON fi.class_id = c.id AND fi.term_id = ?
                         GROUP BY c.id, c.name, c.sort_order ORDER BY c.sort_order, c.name');
    $st->execute([$term['id']]);
    $overview = $st->fetchAll();
}

portal_header('Fee Setup');
?>
<?php if (!$term || !$classes): ?>
  <div class="alert alert-warning">Create a term and at least one class first.</div>
<?php else: ?>
<div class="card">
  <form method="get" class="filters" style="margin-bottom:0">
    <div><label>Term</label><select name="term" data-autosubmit><?= term_options((int) $term['id']) ?></select></div>
    <div><label>Class</label><select name="class" data-autosubmit><?= class_options($classId, $classes) ?></select></div>
  </form>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Fees for <?= e(find_class($classId)['name']) ?> <small><?= e(term_label($term)) ?></small></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update">
      <div class="table-wrap"><table class="data">
        <thead><tr><th>Item</th><th class="num" style="width:160px">Amount (₦)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><input name="items[<?= $it['id'] ?>][name]" value="<?= e($it['name']) ?>"></td>
            <td><input name="items[<?= $it['id'] ?>][amount]" value="<?= e(number_format($it['amount_kobo'] / 100, 2, '.', '')) ?>" style="text-align:right" inputmode="decimal"></td>
            <td class="right"><button class="btn-link danger" type="submit" form="del<?= $it['id'] ?>">Remove</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="3" class="empty">No fees for this class and term yet.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($items): ?><tfoot><tr><td>Total</td><td class="num"><?= money($total) ?></td><td></td></tr></tfoot><?php endif; ?>
      </table></div>
      <?php if ($items): ?><p><button class="btn" type="submit"><i class="fa fa-save"></i> Save changes</button></p><?php endif; ?>
    </form>
    <?php foreach ($items as $it): ?>
      <form method="post" id="del<?= $it['id'] ?>" data-confirm="Remove <?= e($it['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $it['id'] ?>"></form>
    <?php endforeach; ?>
    <form method="post" class="filters" style="margin-top:16px;margin-bottom:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div style="flex:1"><label>New item</label><input name="name" placeholder="e.g. Tuition, PTA levy, Books" required></div>
      <div style="max-width:160px"><label>Amount (₦)</label><input name="amount" inputmode="decimal" required placeholder="45000"></div>
      <button class="btn btn-light" type="submit"><i class="fa fa-plus"></i> Add</button>
    </form>
  </div>

  <div>
    <div class="card">
      <h2>All classes this term</h2>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>Class</th><th class="num">Items</th><th class="num">Total per student</th></tr></thead>
        <tbody>
        <?php foreach ($overview as $o): ?>
          <tr><td><a href="<?= url('portal/fees.php', ['term' => $term['id'], 'class' => $o['id']]) ?>"><?= e($o['name']) ?></a></td><td class="num"><?= (int) $o['n'] ?></td><td class="num"><?= money((int) $o['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php if ($items && count($classes) > 1): ?>
    <div class="card">
      <h2>Copy these fees to other classes</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="copy_to_classes">
        <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:12px">
          <?php foreach ($classes as $c): if ((int) $c['id'] === $classId) continue; ?>
            <label class="check"><input type="checkbox" name="targets[]" value="<?= $c['id'] ?>"> <?= e($c['name']) ?></label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-light" type="submit"><i class="fa fa-copy"></i> Copy</button>
        <p class="hint">Items with the same name are overwritten with these amounts.</p>
      </form>
    </div>
    <?php endif; ?>
    <?php if (count(all_terms()) > 1): ?>
    <div class="card">
      <h2>Start from another term</h2>
      <form method="post" class="filters" style="margin-bottom:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="copy_from_term">
        <div><label>Copy all classes' fees from</label><select name="from_term"><?= term_options() ?></select></div>
        <button class="btn btn-light" type="submit">Copy</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php portal_footer(); ?>
