<?php
/*
 * EduResult configuration.
 *
 * Copy this file to includes/config.php and fill in your own values.
 * config.php holds secrets (database password, Paystack secret key, SMTP password):
 * never commit it to Git or send it to anyone.
 */
return [
    // Full address of the site, without a trailing slash. Used for Paystack
    // callbacks and links in emails.
    'app_url' => 'http://localhost/eduresult',

    // Long random string used to sign receipt links. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'replace-with-a-long-random-string',

    // Show PHP errors on screen (true only on your own computer).
    'debug' => false,

    // Timezone for dates on receipts and report cards.
    'timezone' => 'Africa/Lagos',

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'eduresult2',
        'user'     => 'root',
        'password' => '',
    ],

    // Paystack keys: Dashboard -> Settings -> API Keys & Webhooks.
    // Use the sk_test_ key until you are ready to take real money.
    // Set the webhook URL in Paystack to  <app_url>/paystack_webhook.php
    'paystack' => [
        'secret_key' => 'sk_test_30fcebdf06aa8647805878e92c01a7f60a1daa1e',
        'base_url'   => 'https://api.paystack.co',
    ],

    // Email to parents.
    //  driver 'log'  : emails are written to storage/mail/ instead of sent (for testing)
    //  driver 'smtp' : real sending, e.g. Gmail (smtp.gmail.com, 587, tls, app password),
    //                  Zoho, Brevo, Mailgun or your web host's mail server.
    'mail' => [
        'driver'     => 'log',
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',        // 'tls', 'ssl' or ''
        'username'   => '',
        'password'   => '',
        'from_email' => 'no-reply@example.com',
        'from_name'  => 'EduResult',
    ],
];
