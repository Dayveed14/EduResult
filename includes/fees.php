<?php
/*
 * School fees: bills, balances, receipts.
 */

/** Fee items a class owes for a term. */
function fee_items(int $termId, int $classId): array
{
    $st = db()->prepare('SELECT * FROM fee_items WHERE term_id = ? AND class_id = ? ORDER BY id');
    $st->execute([$termId, $classId]);
    return $st->fetchAll();
}

/** Bill, payments and balance for one student in one term. */
function fee_statement(array $student, int $termId): array
{
    $classId = class_for_term((int) $student['id'], $termId) ?? (int) $student['class_id'];
    $items = fee_items($termId, $classId);
    $billed = array_sum(array_map(fn($i) => (int) $i['amount_kobo'], $items));

    $st = db()->prepare("SELECT * FROM fee_payments WHERE student_id = ? AND term_id = ? AND status = 'success' ORDER BY paid_at");
    $st->execute([$student['id'], $termId]);
    $payments = $st->fetchAll();
    $paid = array_sum(array_map(fn($p) => (int) $p['amount_kobo'], $payments));

    return [
        'items'    => $items,
        'billed'   => $billed,
        'payments' => $payments,
        'paid'     => $paid,
        'balance'  => max(0, $billed - $paid),
    ];
}

/** True when the school blocks results for students who still owe this term's fees. */
function results_blocked_for_fees(array $student, int $termId): bool
{
    if (setting('block_results_if_owing', '0') !== '1') {
        return false;
    }
    $s = fee_statement($student, $termId);
    return $s['billed'] > 0 && $s['balance'] > 0;
}

/**
 * Every active student in a class (or the whole school) with what they owe for a term.
 * Returns rows sorted by class then name: student fields + billed, paid, balance.
 */
function fee_ledger(int $termId, ?int $classId = null): array
{
    $sql = "SELECT s.*, c.name AS class_name,
                   COALESCE((SELECT SUM(fi.amount_kobo) FROM fee_items fi
                             WHERE fi.term_id = ? AND fi.class_id = s.class_id), 0) AS billed,
                   COALESCE((SELECT SUM(fp.amount_kobo) FROM fee_payments fp
                             WHERE fp.term_id = ? AND fp.student_id = s.id AND fp.status = 'success'), 0) AS paid
            FROM students s JOIN classes c ON c.id = s.class_id
            WHERE s.status = 'active'";
    $params = [$termId, $termId];
    if ($classId) {
        $sql .= ' AND s.class_id = ?';
        $params[] = $classId;
    }
    $sql .= ' ORDER BY c.sort_order, c.name, s.surname, s.first_name';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['billed'] = (int) $r['billed'];
        $r['paid'] = (int) $r['paid'];
        $r['balance'] = max(0, $r['billed'] - $r['paid']);
    }
    return $rows;
}

function make_receipt_no(int $paymentId): string
{
    return 'RCT' . date('y') . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
}

/** Signature that lets a parent open their receipt link without logging in. */
function receipt_key(string $receiptNo): string
{
    return substr(hash_hmac('sha256', 'receipt:' . $receiptNo, (string) config('app_secret')), 0, 24);
}

function receipt_url(string $receiptNo, bool $absolute = false): string
{
    $q = ['no' => $receiptNo, 'key' => receipt_key($receiptNo)];
    return $absolute ? absolute_url('receipt.php', $q) : url('receipt.php', $q);
}

/**
 * Mark a fee payment successful, give it a receipt number and email the parent.
 * Safe to call more than once for the same payment.
 */
function complete_fee_payment(int $paymentId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM fee_payments WHERE id = ? FOR UPDATE');
    $st->execute([$paymentId]);
    $p = $st->fetch();
    if ($p && $p['status'] !== 'success') {
        $receipt = make_receipt_no((int) $p['id']);
        $pdo->prepare("UPDATE fee_payments SET status = 'success', receipt_no = ?, paid_at = COALESCE(paid_at, NOW()) WHERE id = ?")
            ->execute([$receipt, $p['id']]);
        $pdo->commit();
        $st = $pdo->prepare('SELECT * FROM fee_payments WHERE id = ?');
        $st->execute([$paymentId]);
        $p = $st->fetch();
        notify_fee_receipt($p);
        return $p;
    }
    $pdo->commit();
    return $p ?: [];
}

function notify_fee_receipt(array $payment): void
{
    $student = find_student((int) $payment['student_id']);
    if (!$student) {
        return;
    }
    $term = find_term((int) $payment['term_id']);
    $statement = fee_statement($student, (int) $payment['term_id']);
    $recipients = array_unique(array_filter([
        $payment['payer_email'] ?? null,
        $student['parent_email'] ?? null,
    ], 'valid_email'));
    if (!$recipients) {
        return;
    }
    $body = '<p>Dear Parent/Guardian,</p>'
        . '<p>We have received a school fees payment of <strong>' . e(money((int) $payment['amount_kobo'])) . '</strong> for <strong>'
        . e(student_name($student)) . '</strong> (' . e($student['reg_number']) . '), ' . e(term_label($term)) . '.</p>'
        . '<table cellpadding="6" style="border-collapse:collapse">'
        . '<tr><td>Receipt number</td><td><strong>' . e($payment['receipt_no']) . '</strong></td></tr>'
        . '<tr><td>Total billed</td><td>' . e(money($statement['billed'])) . '</td></tr>'
        . '<tr><td>Total paid</td><td>' . e(money($statement['paid'])) . '</td></tr>'
        . '<tr><td>Outstanding balance</td><td><strong>' . e(money($statement['balance'])) . '</strong></td></tr>'
        . '</table>'
        . '<p><a href="' . e(receipt_url($payment['receipt_no'], true)) . '">View or print your receipt</a></p>';
    foreach ($recipients as $to) {
        $id = queue_email($to, 'Payment receipt ' . $payment['receipt_no'], email_layout('Payment received', $body), 'receipt', (int) $student['id']);
        send_queued([$id]);
    }
}
