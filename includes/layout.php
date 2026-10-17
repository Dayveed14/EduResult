<?php
/*
 * Page chrome: public pages (parents and students) and the staff portal.
 */

function render_flashes(): string
{
    $html = '';
    foreach (take_flashes() as $f) {
        $type = in_array($f['type'], ['success', 'error', 'info', 'warning'], true) ? $f['type'] : 'info';
        $html .= '<div class="alert alert-' . $type . '">' . e($f['message']) . '</div>';
    }
    return $html;
}

// ---- Public pages -----------------------------------------------------------------

function public_header(string $title, bool $hero = false): void
{
    $school = setting('school_name', 'EduResult');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e($school) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/fontawesome.css') ?>">
  <link rel="stylesheet" href="<?= url('assets/css/public.css') ?>">
</head>
<body class="<?= $hero ? 'has-hero' : 'plain' ?>">
<header class="site-header">
  <a class="brand" href="<?= url('index.php') ?>"><em>EDU</em> RESULT</a>
  <button class="menu-toggle" aria-label="Menu" onclick="document.body.classList.toggle('menu-open')"><i class="fa fa-bars"></i></button>
  <nav class="site-nav">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('result_form.php') ?>">Check Result</a>
    <a href="<?= url('pay_fees.php') ?>">Pay Fees</a>
    <a href="<?= url('login.php') ?>">Staff Login</a>
  </nav>
</header>
<?php if (!$hero): ?>
<div class="video-bg"><video autoplay muted loop playsinline poster="<?= url('assets/images/video-bg.jpg') ?>"><source src="<?= url('assets/images/course-video.mp4') ?>" type="video/mp4"></video></div>
<main class="page-wrap">
<?php endif;
}

function public_footer(bool $hero = false): void
{
    if (!$hero) {
        echo '</main>';
    }
    ?>
<footer class="site-footer">
  <p><i class="fa fa-copyright"></i> <?= date('Y') ?> <?= e(setting('school_name', 'EduResult')) ?>. All rights reserved.</p>
</footer>
</body>
</html>
<?php
}

// ---- Staff portal --------------------------------------------------------------------

function portal_nav(array $user): array
{
    $isClassTeacher = $user['role'] === 'admin' || managed_classes($user);
    $items = [['index.php', 'dashboard', 'Dashboard']];
    if ($user['role'] === 'admin') {
        $items[] = ['students.php', 'users', 'Students'];
        $items[] = ['classes.php', 'sitemap', 'Classes & Subjects'];
        $items[] = ['staff.php', 'user', 'Staff'];
        $items[] = ['terms.php', 'calendar', 'Terms'];
    }
    $items[] = ['scores.php', 'pencil-square-o', $user['role'] === 'admin' ? 'Scores' : 'Enter Scores'];
    if ($isClassTeacher) {
        $items[] = ['broadsheet.php', 'table', 'Broadsheet'];
        $items[] = ['remarks.php', 'comment-o', 'Remarks'];
    }
    if ($user['role'] === 'admin') {
        $items[] = ['fees.php', 'money', 'Fee Setup'];
        $items[] = ['payments.php', 'credit-card', 'Payments'];
        $items[] = ['tokens.php', 'ticket', 'Result Tokens'];
        $items[] = ['notifications.php', 'envelope', 'Notifications'];
        $items[] = ['settings.php', 'cog', 'Settings'];
    }
    $items[] = ['account.php', 'lock', 'My Account'];
    return $items;
}

function portal_header(string $title): void
{
    $user = current_user();
    $current = basename($_SERVER['SCRIPT_NAME']);
    $term = current_term();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · Staff Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/fontawesome.css') ?>">
  <link rel="stylesheet" href="<?= url('assets/css/portal.css') ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <a href="<?= url('portal/index.php') ?>"><em>EDU</em> RESULT</a>
      <small><?= e(setting('school_name')) ?></small>
    </div>
    <nav>
      <?php foreach ($user ? portal_nav($user) : [] as [$file, $icon, $label]): ?>
        <a href="<?= url('portal/' . $file) ?>" class="<?= $current === $file ? 'active' : '' ?>"><i class="fa fa-<?= $icon ?> fa-fw"></i> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="logout" href="<?= url('logout.php') ?>"><i class="fa fa-sign-out fa-fw"></i> Sign out</a>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="nav-toggle" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')"><i class="fa fa-bars"></i></button>
      <h1><?= e($title) ?></h1>
      <div class="topbar-meta">
        <span class="pill"><i class="fa fa-calendar-o"></i> <?= $term ? e(term_label($term)) : 'No current term' ?></span>
        <?php if ($user): ?><span class="who"><?= e($user['full_name']) ?> · <?= e(ucfirst($user['role'])) ?></span><?php endif; ?>
      </div>
    </header>
    <div class="content">
      <?= render_flashes() ?>
<?php
}

function portal_footer(): void
{
    ?>
    </div>
  </div>
</div>
<script src="<?= url('assets/js/portal.js') ?>"></script>
</body>
</html>
<?php
}

/** <option> tags for a select. $options: value => label */
function options(array $options, $selected = null): string
{
    $html = '';
    foreach ($options as $value => $label) {
        $sel = (string) $value === (string) $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

function term_options(?int $selected = null, bool $publishedOnly = false): string
{
    $opts = [];
    foreach (all_terms() as $t) {
        if ($publishedOnly && !$t['results_published_at']) {
            continue;
        }
        $opts[$t['id']] = term_label($t) . ((int) $t['is_current'] ? ' (current)' : '');
    }
    return options($opts, $selected);
}

function class_options(?int $selected = null, ?array $classes = null): string
{
    $opts = [];
    foreach ($classes ?? all_classes() as $c) {
        $opts[$c['id']] = $c['name'];
    }
    return options($opts, $selected);
}

/** Term chosen in the page's ?term= or the current term. */
function selected_term(): ?array
{
    $id = input_int('term');
    return $id ? find_term($id) : current_term();
}
