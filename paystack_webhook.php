<?php
/*
 * Paystack webhook: Paystack calls this server-to-server when a charge succeeds,
 * so payments are confirmed even if the parent closes the browser before the redirect.
 * Set it in Paystack: Settings -> API Keys & Webhooks -> Webhook URL = <app_url>/paystack_webhook.php
 */
require __DIR__ . '/includes/bootstrap.php';

$body = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha512', $body, (string) config('paystack.secret_key'));

if ($signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true);
if (($event['event'] ?? '') === 'charge.success' && !empty($event['data']['reference'])) {
    // Re-verifies with the API and checks the amount before completing anything.
    settle_paystack_reference((string) $event['data']['reference']);
}
http_response_code(200);
echo 'ok';
