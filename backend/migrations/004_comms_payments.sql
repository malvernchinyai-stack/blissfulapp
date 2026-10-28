-- 004: chat, safety, notifications, payments
CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_msg_b (booking_id, id),
  CONSTRAINT fk_msg_b FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_reads (
  booking_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (booking_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE user_blocks (
  blocker_id BIGINT UNSIGNED NOT NULL,
  blocked_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (blocker_id, blocked_id)
) ENGINE=InnoDB;

CREATE TABLE reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('user','request','offer','message','review') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  reason ENUM('spam','abuse','fraud','inappropriate','safety','other') NOT NULL,
  details VARCHAR(2000) NULL,
  status ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open',
  handled_by BIGINT UNSIGNED NULL,
  handled_at DATETIME NULL,
  handler_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rep_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NOT NULL,
  data_json TEXT NULL,
  dedupe_key VARCHAR(150) NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key),
  KEY idx_notif_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('push','email') NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  last_error VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_outbox (notification_id, channel),
  KEY idx_outbox_due (status, next_attempt_at),
  CONSTRAINT fk_ob_n FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_prefs (
  user_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(30) NOT NULL,
  push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, category)
) ENGINE=InnoDB;

CREATE TABLE device_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(400) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_device_token (token(255)),
  KEY idx_dt_user (user_id),
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  payer_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,
  mode ENUM('sandbox','live') NOT NULL,
  reference VARCHAR(64) NOT NULL,
  provider_ref VARCHAR(100) NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  refunded_minor BIGINT NOT NULL DEFAULT 0,
  redirect_url VARCHAR(500) NULL,
  poll_url VARCHAR(500) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_pay_ref (reference),
  UNIQUE KEY uq_pay_idem (idempotency_key),
  KEY idx_pay_b (booking_id),
  CONSTRAINT fk_pay_b FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT NOT NULL,
  reason VARCHAR(300) NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  method VARCHAR(40) NOT NULL DEFAULT 'manual',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_refund_idem (idempotency_key),
  CONSTRAINT fk_ref_pay FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payouts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  musician_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  method VARCHAR(40) NULL,
  external_ref VARCHAR(100) NULL,
  paid_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_payout_booking (booking_id),
  CONSTRAINT fk_po_b FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  event_key CHAR(64) NOT NULL,
  payload TEXT NOT NULL,
  signature_valid TINYINT(1) NOT NULL,
  processed_at DATETIME NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY uq_wh_event (provider, event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
