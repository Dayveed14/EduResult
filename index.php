<?php
require __DIR__ . '/includes/bootstrap.php';

$fee = (int) setting('result_check_fee', '0');
public_header('Home', true);
?>
<section class="hero">
  <video autoplay muted loop playsinline poster="<?= url('assets/images/video-bg.jpg') ?>"><source src="<?= url('assets/images/course-video.mp4') ?>" type="video/mp4"></video>
  <div class="hero-inner">
    <h6><?= e(setting('school_name')) ?></h6>
    <h2><em>Results</em> &amp; school fees, online</h2>
    <p>Check your child's report card, pay school fees and get receipts from any phone or computer. No queues, no lost paperwork.</p>
    <div class="hero-actions">
      <a class="btn" href="<?= url('result_form.php') ?>"><i class="fa fa-graduation-cap"></i> Check Result</a>
      <a class="btn" href="<?= url('pay_fees.php') ?>"><i class="fa fa-credit-card"></i> Pay School Fees</a>
      <a class="btn btn-outline" href="<?= url('login.php') ?>"><i class="fa fa-lock"></i> Staff Login</a>
    </div>
  </div>
</section>

<section class="band">
  <h3 class="section-title">What you can do here</h3>
  <div class="grid-3">
    <div class="feature">
      <i class="fa fa-file-text-o"></i>
      <h4>Report cards</h4>
      <p>Full term report with CA and exam scores, grades, subject positions, class position and teachers' remarks. Download it as a PDF.</p>
    </div>
    <div class="feature">
      <i class="fa fa-credit-card"></i>
      <h4>School fees</h4>
      <p>See exactly what is owed, pay in full or in parts by card, bank transfer or USSD, and get an instant receipt by email.</p>
    </div>
    <div class="feature">
      <i class="fa fa-envelope-o"></i>
      <h4>Parent updates</h4>
      <p>Parents get an email when results are published, when a payment is received, and reminders before fees are due.</p>
    </div>
  </div>
</section>

<section class="band alt">
  <h3 class="section-title">How to check a result</h3>
  <div class="grid-3">
    <div class="step"><div class="num">1</div><h4>Open Check Result</h4><p>Enter the student's registration number and choose the term.</p></div>
    <div class="step"><div class="num">2</div><h4>Get a token</h4><p><?= $fee > 0 ? 'Pay ₦' . number_format($fee) . ' online, or use a PIN bought from the school.' : 'Use the token or PIN given to you by the school.' ?> The token is emailed to you.</p></div>
    <div class="step"><div class="num">3</div><h4>View &amp; download</h4><p>The report card opens immediately. Use the same token again later to re-check.</p></div>
  </div>
</section>

<?php $contacts = array_filter(['map-marker' => setting('school_address'), 'phone' => setting('school_phone'), 'envelope' => setting('school_email')]); ?>
<?php if ($contacts): ?>
<section class="band">
  <h3 class="section-title">Contact the school</h3>
  <div class="contact-list">
    <?php foreach ($contacts as $icon => $value): ?>
      <div><i class="fa fa-<?= $icon ?>"></i><span><?= e($value) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php public_footer(true); ?>
