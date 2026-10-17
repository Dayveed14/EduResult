<?php
/*
 * Loaded at the top of every page: configuration, session, database, helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/includes/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('EduResult is not configured yet. Copy includes/config.sample.php to includes/config.php and fill it in.');
}
$GLOBALS['CONFIG'] = require $configFile;

function config(string $key, $default = null)
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

date_default_timezone_set(config('timezone', 'Africa/Lagos'));

if (config('debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// ---- Session ---------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_starts_with((string) config('app_url'), 'https://');
    session_name('EDURESULT');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Database --------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host', '127.0.0.1'),
            (int) config('db.port', 3306),
            config('db.name', 'eduresult2')
        );
        try {
            $pdo = new PDO($dsn, config('db.user'), config('db.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit(config('debug') ? 'Database connection failed: ' . $e->getMessage()
                                 : 'The portal is temporarily unavailable. Please try again shortly.');
        }
    }
    return $pdo;
}

require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/grading.php';
require_once APP_ROOT . '/includes/results.php';
require_once APP_ROOT . '/includes/fees.php';
require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/paystack.php';
require_once APP_ROOT . '/includes/layout.php';
