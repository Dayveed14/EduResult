# EduResult — School Results, Fees & Parent Portal

A school portal for Nigerian secondary schools, written in plain PHP and MySQL so it runs on XAMPP or any cheap shared host.

**For parents and students**
- **Check results**: enter the registration number and a token. Tokens are bought online through Paystack or come as printed PINs sold by the school. The report card shows CA1, CA2 and exam scores, the grade, subject position, class average and high/low per subject, overall average and position, days present, and the class teacher's and principal's remarks. It can be printed or downloaded as a PDF.
- **Pay school fees**: see the term's bill, pay all of it or part of it through Paystack, and get a receipt by email.

**For staff** (`/portal`)
- **Admins** manage students (including CSV import and promotion), classes, subjects, subject-teacher assignments, staff accounts, terms, fee setup, payments, debtors, result tokens, notifications and settings.
- **Subject teachers** enter scores for their own subjects, either in a spreadsheet-style grid or by CSV upload.
- **Class teachers** see their class broadsheet, write remarks and preview report cards.
- **Publishing**: when an admin publishes a term's results, parents can see them, teachers can no longer edit scores, and parents get an email.

**Parent emails**: results published, token purchased, payment receipt, fee reminders and broadcast messages.

---

## 1. Install on XAMPP (Windows)

1. Copy the `eduresult` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open <http://localhost/phpmyadmin> and create a database called `eduresult` (collation `utf8mb4_unicode_ci`). Then use **Import** to load:
   - `database/schema.sql` (required)
   - `database/demo_data.sql` (optional: a sample school with 3 classes, 12 students, scores, fees and payments)
4. Copy `includes/config.sample.php` to `includes/config.php` and fill it in. For a default XAMPP install, the database user is `root` with an empty password, and `app_url` is `http://localhost/eduresult`.
5. Open <http://localhost/eduresult>.

**First sign-in:** username `admin`, password `ChangeMe123!`. You will be asked to choose a new password straight away.

**Demo data logins:** teachers `mrs.adeyemi`, `mr.okoro` and `ms.bello`, password `Teacher123`. The demo result token is `DEMO2025TERM` for registration number `BFS/2025/001`.

Requirements: PHP 8.0 or newer with the `pdo_mysql`, `curl` and `mbstring` extensions, and MySQL 5.7+ or MariaDB 10.4+. XAMPP 8.x has all of these.

## 2. Set up the school (admin)

Work through these in order. The dashboard shows the same checklist until everything is done.

1. **Settings**: school name, address, phone, email, principal's name, the price of a result token, how many times one token can be used, and the score weighting (CA1/CA2/Exam, default 20/20/60).
2. **Terms**: add the current session and term (for example 2025/2026, First Term), with the next-term date and the number of days the school opened.
3. **Staff**: create the teacher accounts. Each one gets a temporary password that is shown once.
4. **Classes & Subjects**: add the classes and subjects, pick a class teacher for each class, then for each class tick the subjects it offers and choose who teaches each one.
5. **Students**: add students one at a time, or use **Import CSV** (download the template first). Each student's parent email is where results, receipts and reminders are sent.
6. **Fee Setup**: enter the fee items for each class and term (tuition, PTA levy, and so on). You can copy them to other classes or from an earlier term.

At the end of the term:
- Teachers enter scores.
- Class teachers add remarks and days present.
- The admin uses **Suggest principal remarks** on the Remarks page, then goes to **Terms** and clicks **Publish results**.

## 3. Paystack

1. Create an account at <https://paystack.com>. Copy the **test** secret key from Settings → API Keys & Webhooks into `config.php` (`paystack.secret_key`).
2. Set the **Webhook URL** to `https://your-domain/eduresult/paystack_webhook.php`. With the webhook, a payment is still confirmed if the parent closes the browser before being sent back to the site.
3. Make a few test payments with Paystack's test cards. When everything works, switch to the **live** secret key.

How a payment is protected: a *pending* payment row is saved before the parent goes to Paystack. When Paystack sends them back, the site looks up that row, asks Paystack to verify the payment, and checks the amount and currency before issuing a token or receipt. Nothing comes from the browser session, so a payment can't be credited to the wrong student, and replaying the callback does nothing.

## 4. Email

- `mail.driver = 'log'` (the default) saves every email as an HTML file in `storage/mail/` without sending anything. Use this for testing.
- `mail.driver = 'smtp'` sends real email. With Gmail, use `smtp.gmail.com`, port 587, `tls`, and an **app password**. Your host's mail server, Zoho, Brevo or Mailgun also work.
- **Settings → Send test email** checks the configuration.

Large batches, such as the results-published email to every parent, go into a queue. The first 25 are sent immediately. The rest are sent with **Notifications → Send queued now**, or automatically every few minutes if you schedule the cron job:

```
*/5 * * * *  php /path/to/eduresult/cron/send_notifications.php
```

On Windows, use Task Scheduler to run `C:\xampp\php\php.exe C:\xampp\htdocs\eduresult\cron\send_notifications.php`.

## 5. Going live checklist

- [ ] Set `debug` to `false` and give `app_secret` a long random value: `php -r "echo bin2hex(random_bytes(32));"`
- [ ] Set `app_url` to the real `https://` address. HTTPS is required for card payments in production.
- [ ] Use a dedicated MySQL user with a strong password, not `root`.
- [ ] Switch to the live Paystack key and set the webhook URL.
- [ ] Switch `mail.driver` to `smtp` and send a test email.
- [ ] Schedule the cron job.
- [ ] Do **not** import `demo_data.sql` on the live database.
- [ ] Make sure the `.htaccess` files are honoured (Apache `AllowOverride All`). They block web access to `includes/`, `lib/`, `storage/`, `cron/` and `database/`. On Nginx, add equivalent `deny` rules.

## 6. Folder layout

```
index.php, result_form.php, report_card.php      public: home, result checker, report card
pay_fees.php, receipt.php                        public: fee payment and receipts
paystack_callback.php, paystack_webhook.php      Paystack return URL and webhook
login.php, logout.php                            staff sign-in
portal/                                          staff portal (one file per page)
includes/                                        config, database, auth, results engine, fees, Paystack, email
lib/PHPMailer/                                   PHPMailer 6.9 (LGPL), bundled so Composer isn't needed
cron/send_notifications.php                      sends queued emails, tidies abandoned checkouts
database/schema.sql, database/demo_data.sql      database
assets/                                          CSS, JS, Font Awesome 4 and the background video
storage/mail/                                    emails saved in log mode
```

To change the grading scale, edit `includes/grading.php`. It defaults to WAEC A1–F9.

## 7. What changed from the original project

**Bugs fixed**
1. **Admin APIs had no login check.** `admin/students_api.php`, `results_api.php` and `dashboard_stats.php` were not used by any page and have been removed. Every portal page now requires sign-in and checks the user's role.
2. **The Paystack secret key was hard-coded** in two files. It now lives only in `includes/config.php`, which is git-ignored.
3. **Passwords were unsalted SHA-1.** They now use bcrypt (`password_hash`), the default password must be changed on first sign-in, and logins are throttled after 5 failures.
4. **Payment verification trusted the session.** It now uses the saved pending row plus Paystack's verification, with amount and currency checks. It is idempotent and has a webhook backup. The broken redirect to the missing `payment_form.php` is gone.
5. **The report card showed every result ever recorded.** It now shows only the chosen term and class, with real positions, remarks and student name in place of the hard-coded "BYEKWASO RONALD", "4th" and "Fairly good".
6. **Redirects pointed to missing `.html` pages** and `Result_form.php` had the wrong capitalisation. All links now point to real pages.
7. **The database had no constraints.** There are now foreign keys and unique keys (reg number, token, payment reference, one score per student/subject/term), and money is stored as whole kobo integers.

**Also fixed:**
- CSRF tokens on every form.
- All output is escaped.
- Students added by the admin can now pay. The old flow rejected anyone already in `students`.
- A token only works for its own student and is limited to N uses.
- Term values are consistent. The old code mixed `first`, `FIRST` and `1`.

**Removed** (unused template code): Bootstrap, jQuery and jQuery UI, Owl Carousel, Lightbox, Isotope, Slick, Flexslider, Magnific video popup, the countdown script, 14 unused images, the Flaticon/Slick/Flexslider/EOT/SVG fonts, the duplicate `admin/assets` folder, `admin/result_form.php`, and the dashboard's fake charts, calendar, "error rate" and settings widgets. The Rio de Janeiro map and the contact form that sent nowhere were also removed from the home page.
