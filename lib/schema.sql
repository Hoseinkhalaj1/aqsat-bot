CREATE TABLE IF NOT EXISTS users (
  id BIGINT PRIMARY KEY,
  name VARCHAR(100) NOT NULL DEFAULT '',
  username VARCHAR(64) NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 0,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  remind_hour TINYINT NOT NULL DEFAULT 9,
  remind_days VARCHAR(32) NOT NULL DEFAULT '3,1,0',
  overdue_daily TINYINT(1) NOT NULL DEFAULT 1,
  monthly_summary TINYINT(1) NOT NULL DEFAULT 1,
  last_remind_date DATE NULL,
  last_monthly VARCHAR(7) NULL,
  state VARCHAR(40) NULL,
  state_data TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  name VARCHAR(60) NOT NULL,
  emoji VARCHAR(16) NOT NULL DEFAULT '📁',
  budget BIGINT NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obligations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  type ENUM('loan','recurring','once') NOT NULL,
  title VARCHAR(120) NOT NULL,
  category_id INT NULL,
  creditor VARCHAR(120) NULL,
  note TEXT NULL,
  period_unit ENUM('month','week','year') NULL,
  period_n INT NOT NULL DEFAULT 1,
  anchor_day TINYINT NULL,
  amount BIGINT NOT NULL DEFAULT 0,
  shared_user_id BIGINT NULL,
  owner_share TINYINT NOT NULL DEFAULT 100,
  status ENUM('active','done','stopped') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  INDEX (user_id), INDEX (shared_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obligation_id INT NOT NULL,
  seq INT NOT NULL,
  due_date DATE NOT NULL,
  amount BIGINT NOT NULL,
  paid_amount BIGINT NOT NULL DEFAULT 0,
  status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  INDEX (obligation_id), INDEX (due_date),
  CONSTRAINT fk_dues_ob FOREIGN KEY (obligation_id) REFERENCES obligations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  due_id INT NOT NULL,
  payer_id BIGINT NOT NULL,
  amount BIGINT NOT NULL,
  paid_at DATETIME NOT NULL,
  INDEX (due_id), INDEX (paid_at),
  CONSTRAINT fk_pay_due FOREIGN KEY (due_id) REFERENCES dues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_log (
  due_id INT NOT NULL,
  user_id BIGINT NOT NULL,
  sent_date DATE NOT NULL,
  PRIMARY KEY (due_id, user_id, sent_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_alerts (
  user_id BIGINT NOT NULL,
  category_id INT NOT NULL,
  ym VARCHAR(7) NOT NULL,
  PRIMARY KEY (user_id, category_id, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(40) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
