-- EduResult school portal — database schema
-- MySQL 5.7+ / MariaDB 10.4+  (tested on MariaDB 10.4 as shipped with XAMPP 8.2)
--
-- Import this file once into an empty database, e.g. in phpMyAdmin:
--   create a database called `eduresult`, open it, Import -> schema.sql
-- Then (optionally) import demo_data.sql to get sample classes, students and scores.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications, fee_payments, fee_items, result_checks, term_reports,
  scores, class_subjects, students, subjects, classes, terms, login_attempts, users, settings;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Staff accounts (admins and teachers)
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username              VARCHAR(50)  NOT NULL,
  full_name             VARCHAR(120) NOT NULL,
  email                 VARCHAR(150) NULL,
  phone                 VARCHAR(30)  NULL,
  password_hash         VARCHAR(255) NOT NULL,
  role                  ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at         DATETIME     NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45) NOT NULL,
  username     VARCHAR(50) NOT NULL,
  attempted_at DATETIME    NOT NULL,
  KEY ix_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- School settings (name, address, fees for checking results, score weights ...)
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
  name  VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Academic terms, e.g. session 2025/2026, term 1
-- ---------------------------------------------------------------------------
CREATE TABLE terms (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session               VARCHAR(9)   NOT NULL,          -- "2025/2026"
  term                  TINYINT UNSIGNED NOT NULL,        -- 1, 2 or 3
  starts_on             DATE         NULL,
  ends_on               DATE         NULL,
  next_term_begins      DATE         NULL,
  days_school_opened    SMALLINT UNSIGNED NULL,
  is_current            TINYINT(1)   NOT NULL DEFAULT 0,
  results_published_at  DATETIME     NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_terms_session_term (session, term),
  CONSTRAINT ck_terms_term CHECK (term BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Classes (JSS1A, SS2 Science ...) and subjects
-- ---------------------------------------------------------------------------
CREATE TABLE classes (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(50)  NOT NULL,
  sort_order       SMALLINT     NOT NULL DEFAULT 0,
  class_teacher_id INT UNSIGNED NULL,
  UNIQUE KEY uq_classes_name (name),
  CONSTRAINT fk_classes_teacher FOREIGN KEY (class_teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(100) NOT NULL,
  code  VARCHAR(12)  NULL,
  UNIQUE KEY uq_subjects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which subjects a class offers, and who teaches each one
CREATE TABLE class_subjects (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id    INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  teacher_id  INT UNSIGNED NULL,
  UNIQUE KEY uq_class_subject (class_id, subject_id),
  CONSTRAINT fk_cs_class   FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
  CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Students (one row per student; class_id is the class they are in now)
-- ---------------------------------------------------------------------------
CREATE TABLE students (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reg_number     VARCHAR(30)  NOT NULL,
  surname        VARCHAR(60)  NOT NULL,
  first_name     VARCHAR(60)  NOT NULL,
  other_names    VARCHAR(60)  NULL,
  gender         ENUM('M','F') NULL,
  date_of_birth  DATE         NULL,
  class_id       INT UNSIGNED NOT NULL,
  parent_name    VARCHAR(120) NULL,
  parent_email   VARCHAR(150) NULL,
  parent_phone   VARCHAR(30)  NULL,
  status         ENUM('active','graduated','withdrawn') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_reg (reg_number),
  KEY ix_students_class (class_id, status),
  CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Scores: one row per student, term and subject.  class_id records the class
-- the student was in when the score was entered, so old report cards stay right
-- after promotion.
-- ---------------------------------------------------------------------------
CREATE TABLE scores (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  term_id     INT UNSIGNED NOT NULL,
  class_id    INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  ca1         DECIMAL(5,2) NULL,
  ca2         DECIMAL(5,2) NULL,
  exam        DECIMAL(5,2) NULL,
  total       DECIMAL(5,2) AS (COALESCE(ca1,0) + COALESCE(ca2,0) + COALESCE(exam,0)) STORED,
  entered_by  INT UNSIGNED NULL,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scores (student_id, term_id, subject_id),
  KEY ix_scores_class (term_id, class_id, subject_id),
  CONSTRAINT fk_scores_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_scores_term    FOREIGN KEY (term_id)    REFERENCES terms(id)    ON DELETE CASCADE,
  CONSTRAINT fk_scores_class   FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_scores_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_scores_user    FOREIGN KEY (entered_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class teacher / principal remarks and attendance count per student per term
CREATE TABLE term_reports (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id        INT UNSIGNED NOT NULL,
  term_id           INT UNSIGNED NOT NULL,
  teacher_remark    VARCHAR(255) NULL,
  principal_remark  VARCHAR(255) NULL,
  days_present      SMALLINT UNSIGNED NULL,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_term_reports (student_id, term_id),
  CONSTRAINT fk_tr_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_term    FOREIGN KEY (term_id)    REFERENCES terms(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Result-checking tokens.  Bought online through Paystack (source = online) or
-- generated by the school as printable PINs to sell offline (source = offline,
-- student_id is filled in the first time the PIN is used).
-- ---------------------------------------------------------------------------
CREATE TABLE result_checks (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    INT UNSIGNED NULL,
  term_id       INT UNSIGNED NOT NULL,
  source        ENUM('online','offline') NOT NULL DEFAULT 'online',
  email         VARCHAR(150) NULL,
  amount_kobo   INT UNSIGNED NOT NULL DEFAULT 0,
  reference     VARCHAR(64)  NULL,
  token         VARCHAR(16)  NULL,
  status        ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  uses          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  paid_at       DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rc_reference (reference),
  UNIQUE KEY uq_rc_token (token),
  CONSTRAINT fk_rc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_term    FOREIGN KEY (term_id)    REFERENCES terms(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- School fees: what each class owes per term, and payments against it.
-- Amounts are whole kobo (₦1 = 100 kobo) so there is no rounding.
-- ---------------------------------------------------------------------------
CREATE TABLE fee_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term_id      INT UNSIGNED NOT NULL,
  class_id     INT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL,
  amount_kobo  INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_fee_items (term_id, class_id, name),
  CONSTRAINT fk_fi_term  FOREIGN KEY (term_id)  REFERENCES terms(id)   ON DELETE CASCADE,
  CONSTRAINT fk_fi_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_payments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id   INT UNSIGNED NOT NULL,
  term_id      INT UNSIGNED NOT NULL,
  amount_kobo  INT UNSIGNED NOT NULL,
  method       ENUM('paystack','cash','transfer','pos') NOT NULL,
  reference    VARCHAR(64)  NOT NULL,
  receipt_no   VARCHAR(20)  NULL,
  status       ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  payer_email  VARCHAR(150) NULL,
  note         VARCHAR(255) NULL,
  recorded_by  INT UNSIGNED NULL,
  paid_at      DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fp_reference (reference),
  UNIQUE KEY uq_fp_receipt (receipt_no),
  KEY ix_fp_student_term (student_id, term_id, status),
  CONSTRAINT fk_fp_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_term    FOREIGN KEY (term_id)     REFERENCES terms(id)    ON DELETE CASCADE,
  CONSTRAINT fk_fp_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Outgoing emails to parents (queued, then sent by the portal or cron)
-- ---------------------------------------------------------------------------
CREATE TABLE notifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NULL,
  type            VARCHAR(30)  NOT NULL,       -- results, receipt, token, reminder, broadcast
  recipient       VARCHAR(150) NOT NULL,
  subject         VARCHAR(200) NOT NULL,
  body_html       MEDIUMTEXT   NOT NULL,
  status          ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME     NULL,
  KEY ix_notifications_status (status, id),
  CONSTRAINT fk_notif_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Defaults
-- ---------------------------------------------------------------------------
INSERT INTO settings (name, value) VALUES
  ('school_name',            'EduResult International School'),
  ('school_motto',           'Knowledge, Character, Service'),
  ('school_address',         'FCT, Abuja'),
  ('school_phone',           ''),
  ('school_email',           ''),
  ('principal_name',         ''),
  ('result_check_fee',       '1000'),   -- naira per token
  ('token_max_uses',         '5'),      -- how many times one token can open a report card
  ('block_results_if_owing', '0'),      -- 1 = students with unpaid fees cannot check results
  ('ca1_max',                '20'),
  ('ca2_max',                '20'),
  ('exam_max',               '60');

-- First admin login:  username  admin   password  ChangeMe123!
-- (You are asked to set a new password the first time you sign in.)
INSERT INTO users (username, full_name, password_hash, role, must_change_password) VALUES
  ('admin', 'School Administrator', '$2y$10$oe247VvEjhjb0eKRGwKZX.JwHLPRaUhIBvbTKjLz36wsKvNddMTYm', 'admin', 1);
