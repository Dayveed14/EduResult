<?php
/*
 * Staff authentication and permissions.
 */

const LOGIN_MAX_ATTEMPTS = 5;     // failed attempts allowed ...
const LOGIN_WINDOW_MINUTES = 15;  // ... within this many minutes, per IP address

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $st->execute([(int) $_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
            if (!$user) {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Every portal page starts with this. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    // Force a new password on first sign-in, except on the page that changes it.
    if ((int) $u['must_change_password'] === 1 && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') {
        flash('info', 'Please set a new password before you continue.');
        redirect('portal/account.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Only administrators can open this page.');
    }
    return $u;
}

function login_locked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)');
    $st->execute([client_ip(), LOGIN_WINDOW_MINUTES]);
    return (int) $st->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

/** Returns the user on success, or an error message string. */
function attempt_login(string $username, string $password)
{
    if (login_locked()) {
        return 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    }
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())')
            ->execute([client_ip(), substr($username, 0, 50)]);
        return 'Username or password is incorrect.';
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db()->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([client_ip()]);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return $user;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain letters and numbers.';
    }
    return null;
}

// ---- Teacher permissions ----------------------------------------------------------

/** Class-subject pairs this user may enter scores for. */
function score_assignments(array $user): array
{
    $sql = 'SELECT cs.class_id, cs.subject_id, c.name AS class_name, sb.name AS subject_name,
                   u.full_name AS teacher_name
            FROM class_subjects cs
            JOIN classes c   ON c.id = cs.class_id
            JOIN subjects sb ON sb.id = cs.subject_id
            LEFT JOIN users u ON u.id = cs.teacher_id';
    if ($user['role'] === 'admin') {
        return db()->query($sql . ' ORDER BY c.sort_order, c.name, sb.name')->fetchAll();
    }
    $st = db()->prepare($sql . ' WHERE cs.teacher_id = ? ORDER BY c.sort_order, c.name, sb.name');
    $st->execute([$user['id']]);
    return $st->fetchAll();
}

function can_enter_scores(array $user, int $classId, int $subjectId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    $st = db()->prepare('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ? AND teacher_id = ?');
    $st->execute([$classId, $subjectId, $user['id']]);
    return (bool) $st->fetchColumn();
}

/** Classes whose broadsheet, remarks and report cards this user may see. */
function managed_classes(array $user): array
{
    if ($user['role'] === 'admin') {
        return all_classes();
    }
    $st = db()->prepare('SELECT * FROM classes WHERE class_teacher_id = ? ORDER BY sort_order, name');
    $st->execute([$user['id']]);
    return $st->fetchAll();
}

function can_manage_class(array $user, int $classId): bool
{
    foreach (managed_classes($user) as $c) {
        if ((int) $c['id'] === $classId) {
            return true;
        }
    }
    return false;
}
