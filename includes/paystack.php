<?php
/*
 * Paystack integration (https://paystack.com/docs/api/transaction/).
 *
 * Both result-checking tokens and school fees go through here. A payment row is
 * saved as "pending" BEFORE the customer is sent to Paystack; when Paystack
 * calls back (or sends the webhook) we look that row up by reference, ask
 * Paystack to verify it, and check the amount matches what we saved.
 * Nothing is taken from the browser session, so a payment cannot be credited
 * to the wrong student.
 */

class PaystackError extends RuntimeException {}

function paystack_request(string $method, string $path, ?array $body = null): array
{
    $secret = (string) config('paystack.secret_key');
    if ($secret === '' || str_contains($secret, 'xxxx')) {
        throw new PaystackError('Online payment is not set up yet (Paystack secret key missing in config.php).');
    }
    $ch = curl_init(rtrim((string) config('paystack.base_url', 'https://api.paystack.co'), '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new PaystackError('Could not reach Paystack: ' . $error);
    }
    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        throw new PaystackError('Unexpected reply from Paystack.');
    }
    return $data;
}

/** Start a payment; returns the Paystack checkout URL to send the customer to. */
function paystack_initialize(string $email, int $amountKobo, string $reference, array $metadata = []): string
{
    $data = paystack_request('POST', '/transaction/initialize', [
        'email'        => $email,
        'amount'       => $amountKobo,
        'currency'     => 'NGN',
        'reference'    => $reference,
        'callback_url' => absolute_url('paystack_callback.php'),
        'metadata'     => $metadata,
    ]);
    if (empty($data['status']) || empty($data['data']['authorization_url'])) {
        throw new PaystackError($data['message'] ?? 'Paystack could not start the payment.');
    }
    return $data['data']['authorization_url'];
}

function paystack_verify(string $reference): array
{
    $data = paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
    if (empty($data['status']) || !isset($data['data'])) {
        throw new PaystackError($data['message'] ?? 'Paystack could not verify the payment.');
    }
    return $data['data'];
}

function new_reference(string $prefix): string
{
    return $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
}

/**
 * Verify a Paystack reference and complete whatever it paid for.
 * Returns ['ok' => bool, 'type' => 'result'|'fee'|null, 'row' => array, 'message' => string].
 */
function settle_paystack_reference(string $reference): array
{
    $reference = trim($reference);
    $st = db()->prepare('SELECT * FROM result_checks WHERE reference = ?');
    $st->execute([$reference]);
    $row = $st->fetch();
    $type = $row ? 'result' : null;
    if (!$row) {
        $st = db()->prepare("SELECT * FROM fee_payments WHERE reference = ? AND method = 'paystack'");
        $st->execute([$reference]);
        $row = $st->fetch();
        $type = $row ? 'fee' : null;
    }
    if (!$row) {
        return ['ok' => false, 'type' => null, 'row' => [], 'message' => 'We could not find that payment.'];
    }

    $done = $type === 'result' ? $row['status'] === 'paid' : $row['status'] === 'success';
    if ($done) {
        return ['ok' => true, 'type' => $type, 'row' => $row, 'message' => 'Payment already confirmed.'];
    }

    try {
        $tx = paystack_verify($reference);
    } catch (PaystackError $e) {
        return ['ok' => false, 'type' => $type, 'row' => $row, 'message' => $e->getMessage()];
    }

    $paidOk = ($tx['status'] ?? '') === 'success'
        && (int) ($tx['amount'] ?? 0) === (int) $row['amount_kobo']
        && strtoupper($tx['currency'] ?? 'NGN') === 'NGN'
        && ($tx['reference'] ?? '') === $reference;

    if (!$paidOk) {
        if (in_array($tx['status'] ?? '', ['failed', 'abandoned', 'reversed'], true)) {
            $table = $type === 'result' ? 'result_checks' : 'fee_payments';
            db()->prepare("UPDATE $table SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([$row['id']]);
        } elseif (($tx['status'] ?? '') === 'success') {
            error_log("Paystack amount mismatch for $reference: got {$tx['amount']} expected {$row['amount_kobo']}");
            return ['ok' => false, 'type' => $type, 'row' => $row,
                    'message' => 'The amount paid does not match. Please contact the school with reference ' . $reference . '.'];
        }
        return ['ok' => false, 'type' => $type, 'row' => $row, 'message' => 'The payment was not completed.'];
    }

    $row = $type === 'result' ? complete_result_check((int) $row['id']) : complete_fee_payment((int) $row['id']);
    return ['ok' => true, 'type' => $type, 'row' => $row, 'message' => 'Payment confirmed.'];
}

// ---- Result-checking tokens ----------------------------------------------------------

function unique_token(): string
{
    $st = db()->prepare('SELECT 1 FROM result_checks WHERE token = ?');
    do {
        $token = random_code(12);
        $st->execute([$token]);
    } while ($st->fetchColumn());
    return $token;
}

/** Mark a result-check purchase paid, give it a token and email it. Safe to repeat. */
function complete_result_check(int $id): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM result_checks WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $rc = $st->fetch();
    $justPaid = false;
    if ($rc && $rc['status'] !== 'paid') {
        $pdo->prepare("UPDATE result_checks SET status = 'paid', token = ?, paid_at = NOW() WHERE id = ?")
            ->execute([unique_token(), $id]);
        $justPaid = true;
    }
    $pdo->commit();

    $st = $pdo->prepare('SELECT * FROM result_checks WHERE id = ?');
    $st->execute([$id]);
    $rc = $st->fetch() ?: [];
    if ($justPaid && $rc) {
        notify_result_token($rc);
    }
    return $rc;
}

function notify_result_token(array $rc): void
{
    $student = $rc['student_id'] ? find_student((int) $rc['student_id']) : null;
    $term = find_term((int) $rc['term_id']);
    if (!$student || !$term) {
        return;
    }
    $recipients = array_unique(array_filter([$rc['email'], $student['parent_email']], fn($m) => $m && valid_email($m)));
    $uses = (int) setting('token_max_uses', '5');
    $body = '<p>Thank you for your payment.</p>'
        . '<p>Your result token for <strong>' . e(student_name($student)) . '</strong> (' . e($student['reg_number']) . '), '
        . e(term_label($term)) . ' is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:3px;background:#f3f4f6;padding:12px;text-align:center">'
        . e($rc['token']) . '</p>'
        . '<p>Keep it safe. You can use it ' . $uses . ' times to view or download the report card at '
        . '<a href="' . e(absolute_url('result_form.php')) . '">' . e(absolute_url('result_form.php')) . '</a>.</p>';
    foreach ($recipients as $to) {
        $nid = queue_email($to, 'Your result token: ' . term_label($term), email_layout('Result token', $body), 'token', (int) $student['id']);
        send_queued([$nid]);
    }
}

/** Grant this browser access to a report card. */
function grant_report_access(int $studentId, int $termId): void
{
    $_SESSION['report_access'][$studentId . ':' . $termId] = time();
}

function has_report_access(int $studentId, int $termId): bool
{
    $t = $_SESSION['report_access'][$studentId . ':' . $termId] ?? 0;
    return $t > time() - 2 * 3600; // access lasts two hours
}
