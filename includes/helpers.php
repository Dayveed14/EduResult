<?php
/*
 * Small helpers shared by every page.
 */

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Path part of app_url, e.g. "/eduresult" (empty when installed at the web root). */
function base_path(): string
{
    $path = parse_url((string) config('app_url', ''), PHP_URL_PATH) ?: '';
    return rtrim($path, '/');
}

/** Link to a page inside the app, e.g. url('portal/index.php'). */
function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/** Absolute link for emails and Paystack callbacks. */
function absolute_url(string $path = '', array $query = []): string
{
    $root = rtrim((string) config('app_url'), '/');
    $u = $root . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = '')
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

// ---- Flash messages ----------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF ----------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every POST handler. */
function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

// ---- Settings ------------------------------------------------------------------
function setting(string $name, $default = '')
{
    static $cache = null;
    if ($cache === null || $name === '__reload__') {
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
        if ($name === '__reload__') {
            return null;
        }
    }
    return array_key_exists($name, $cache) && $cache[$name] !== null ? $cache[$name] : $default;
}

function save_setting(string $name, string $value): void
{
    $st = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $st->execute([$name, $value]);
    setting('__reload__');
}

// ---- Terms ----------------------------------------------------------------------
function current_term(): ?array
{
    static $t = false;
    if ($t === false) {
        $t = db()->query('SELECT * FROM terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    }
    return $t;
}

function find_term(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM terms WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function all_terms(): array
{
    return db()->query('SELECT * FROM terms ORDER BY session DESC, term DESC')->fetchAll();
}

function term_name(int $term): string
{
    return [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'][$term] ?? 'Term ' . $term;
}

function term_label(?array $t): string
{
    return $t ? term_name((int) $t['term']) . ' ' . $t['session'] : '—';
}

// ---- Classes / students -----------------------------------------------------------
function all_classes(): array
{
    return db()->query('SELECT * FROM classes ORDER BY sort_order, name')->fetchAll();
}

function find_class(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function find_student(int $id): ?array
{
    $st = db()->prepare('SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function find_student_by_reg(string $reg): ?array
{
    $st = db()->prepare('SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE s.reg_number = ?');
    $st->execute([strtoupper(trim($reg))]);
    return $st->fetch() ?: null;
}

function student_name(array $s): string
{
    return trim($s['surname'] . ' ' . $s['first_name'] . ' ' . ($s['other_names'] ?? ''));
}

// ---- Formatting ---------------------------------------------------------------------
function money(int $kobo): string
{
    return '₦' . number_format($kobo / 100, 2);
}

/** Parse a naira amount typed by a person ("25,000" or "25000.50") into kobo. */
function naira_to_kobo($naira): int
{
    $clean = str_replace([',', '₦', ' '], '', (string) $naira);
    return is_numeric($clean) ? (int) round(((float) $clean) * 100) : 0;
}

function ordinal(int $n): string
{
    $suffix = 'th';
    if (!in_array($n % 100, [11, 12, 13], true)) {
        $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
    }
    return $n . $suffix;
}

function fmt_date(?string $date, string $format = 'j M Y'): string
{
    return $date ? date($format, strtotime($date)) : '—';
}

function fmt_score($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $f = (float) $v;
    return floor($f) == $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

/** Random code without look-alike characters (no 0/O, 1/I/L). */
function random_code(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Simple ?page= pagination: returns [limit, offset, page]. */
function paginate(int $perPage = 50): array
{
    $page = max(1, input_int('page', 1));
    return [$perPage, ($page - 1) * $perPage, $page];
}

/** "Bright Future Secondary School" -> "BFS" for the report-card crest. */
function school_initials(): string
{
    $words = preg_split('/\s+/', trim((string) setting('school_name', 'School')));
    $letters = '';
    foreach ($words as $w) {
        if ($w !== '' && ctype_alpha($w[0])) {
            $letters .= strtoupper($w[0]);
        }
    }
    return substr($letters, 0, 3) ?: 'S';
}
