<?php
/*
 * Term report card. Open to:
 *  - a parent/student who unlocked it with a token in this browser (result_form.php), once results are published
 *  - admins, and the class teacher of the student's class (preview, even before publishing)
 */
require __DIR__ . '/includes/bootstrap.php';

$studentId = input_int('student');
$termId = input_int('term');
$data = report_card_data($studentId, $termId);
if (!$data) {
    http_response_code(404);
    exit('Report card not found.');
}

$user = current_user();
$isStaff = $user && can_manage_class($user, (int) $data['class']['id']);
$published = (bool) $data['term']['results_published_at'];

if (!$isStaff) {
    if (!has_report_access($studentId, $termId) || !$published) {
        flash('error', 'Please enter your registration number and token to view this result.');
        redirect('result_form.php');
    }
}

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);

$s = $data['student'];
$t = $data['term'];
$lim = $data['limits'];
$overall = $data['average'] !== null ? grade_for((float) $data['average']) : null;
$school = setting('school_name');
$remarks = $data['remarks'];
$daysOpened = $t['days_school_opened'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report Card · <?= e(student_name($s)) ?> · <?= e(term_label($t)) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/fontawesome.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/report.css') ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
</head>
<body>
<div class="toolbar no-print">
  <a href="<?= $isStaff ? url('portal/broadsheet.php', ['class' => $data['class']['id'], 'term' => $t['id']]) : url('result_form.php') ?>"><i class="fa fa-arrow-left"></i> Back</a>
  <div>
    <button onclick="window.print()"><i class="fa fa-print"></i> Print</button>
    <button id="pdfBtn" onclick="downloadPDF()"><i class="fa fa-download"></i> Download PDF</button>
  </div>
</div>

<?php if ($newToken): ?>
<div class="notice no-print">
  <strong>Payment successful.</strong> Your result token is <span class="tok"><?= e($newToken) ?></span>.
  It has been emailed to you. Use it with the registration number to open this report again.
</div>
<?php endif; ?>
<?php if ($isStaff && !$published): ?>
<div class="notice warn no-print"><strong>Preview.</strong> Results for this term are not published yet, so parents cannot see this report.</div>
<?php endif; ?>

<div class="sheet" id="reportCard">
  <div class="watermark"><?= e($school) ?></div>
  <header class="rc-head">
    <div class="crest"><?= e(school_initials()) ?></div>
    <div class="school">
      <h1><?= e($school) ?></h1>
      <?php if (setting('school_motto')): ?><div class="motto"><?= e(setting('school_motto')) ?></div><?php endif; ?>
      <div class="contact"><?= e(implode(' · ', array_filter([setting('school_address'), setting('school_phone'), setting('school_email')]))) ?></div>
    </div>
  </header>

  <div class="rc-title">Student's Terminal Report — <?= e(term_label($t)) ?></div>

  <div class="info">
    <div><span>Name</span><?= e(student_name($s)) ?></div>
    <div><span>Reg. No.</span><?= e($s['reg_number']) ?></div>
    <div><span>Class</span><?= e($data['class']['name']) ?></div>
    <div><span>Session</span><?= e($t['session']) ?></div>
    <div><span>Term</span><?= e(term_name((int) $t['term'])) ?></div>
    <div><span>Gender</span><?= e(['M' => 'Male', 'F' => 'Female'][$s['gender']] ?? '—') ?></div>
  </div>

  <table class="marks">
    <thead>
      <tr>
        <th>#</th><th class="l">Subject</th>
        <th>CA1<small><?= fmt_score($lim['ca1']) ?></small></th>
        <th>CA2<small><?= fmt_score($lim['ca2']) ?></small></th>
        <th>Exam<small><?= fmt_score($lim['exam']) ?></small></th>
        <th>Total<small>100</small></th>
        <th>Grade</th><th>Pos.</th><th>Class Avg</th><th>High</th><th>Low</th><th class="l">Remark</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($data['rows'] as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="l"><?= e($r['subject']) ?></td>
        <td><?= fmt_score($r['ca1']) ?></td>
        <td><?= fmt_score($r['ca2']) ?></td>
        <td><?= fmt_score($r['exam']) ?></td>
        <td class="b"><?= fmt_score($r['total']) ?></td>
        <td class="b grade-<?= e(substr($r['grade'], 0, 1)) ?>"><?= e($r['grade']) ?></td>
        <td><?= $r['position'] ? ordinal($r['position']) : '' ?></td>
        <td><?= fmt_score($r['class_average']) ?></td>
        <td><?= fmt_score($r['highest']) ?></td>
        <td><?= fmt_score($r['lowest']) ?></td>
        <td class="l"><?= e($r['remark']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$data['rows']): ?>
      <tr><td colspan="12">No scores have been recorded for this term.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <div class="summary">
    <div><strong><?= fmt_score($data['total']) ?></strong><span>Total score (<?= $data['count'] ?> subjects)</span></div>
    <div><strong><?= $data['average'] !== null ? number_format((float) $data['average'], 2) : '—' ?></strong><span>Average</span></div>
    <div><strong><?= $overall ? e($overall['grade']) : '—' ?></strong><span>Overall grade</span></div>
    <div><strong><?= $data['position'] ? ordinal($data['position']) : '—' ?></strong><span>Position out of <?= (int) $data['out_of'] ?></span></div>
    <?php if ($daysOpened): ?>
    <div><strong><?= isset($remarks['days_present']) && $remarks['days_present'] !== null ? (int) $remarks['days_present'] : '—' ?>/<?= (int) $daysOpened ?></strong><span>Days present</span></div>
    <?php endif; ?>
  </div>

  <div class="remarks">
    <div class="remark">
      <h4>Class teacher's remark<?= $data['class_teacher'] ? ' — ' . e($data['class_teacher']) : '' ?></h4>
      <p><?= e($remarks['teacher_remark'] ?? '') ?: '&nbsp;' ?></p>
    </div>
    <div class="remark">
      <h4>Principal's remark<?= setting('principal_name') ? ' — ' . e(setting('principal_name')) : '' ?></h4>
      <p><?= e($remarks['principal_remark'] ?? '') ?: '&nbsp;' ?></p>
    </div>
  </div>

  <div class="foot">
    <div><?php if ($t['next_term_begins']): ?>Next term begins: <strong><?= e(fmt_date($t['next_term_begins'], 'l, j F Y')) ?></strong><?php endif; ?></div>
    <div>Printed <?= date('j M Y') ?></div>
  </div>

  <div class="key">
    Grading: <?php foreach (grade_scale() as $i => [$min, $g, $rem]): ?><?= $i ? ' · ' : '' ?><?= e($g) ?> <?= $min ?>+<?php endforeach; ?>
  </div>
</div>

<script>
function downloadPDF() {
  var btn = document.getElementById('pdfBtn');
  if (!window.jspdf || !window.html2canvas) { window.print(); return; }
  btn.disabled = true; btn.textContent = 'Preparing…';
  html2canvas(document.getElementById('reportCard'), { scale: 2, useCORS: true, scrollY: -window.scrollY }).then(function (canvas) {
    var pdf = new window.jspdf.jsPDF('p', 'mm', 'a4');
    var w = pdf.internal.pageSize.getWidth(), h = pdf.internal.pageSize.getHeight();
    var imgH = canvas.height * w / canvas.width;
    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, w, Math.min(imgH, h));
    pdf.save(<?= json_encode(preg_replace('/[^A-Za-z0-9]+/', '_', $s['reg_number'] . '_' . $s['surname'] . '_' . term_label($t)) . '.pdf') ?>);
  }).catch(function () { alert('Could not create the PDF. Use Print instead.'); })
    .finally(function () { btn.disabled = false; btn.innerHTML = '<i class="fa fa-download"></i> Download PDF'; });
}
</script>
</body>
</html>
