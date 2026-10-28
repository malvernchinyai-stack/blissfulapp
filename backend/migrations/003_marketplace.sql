-- 003: requests, positions, offers, bookings, reschedules, disputes, reviews
CREATE TABLE requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  description VARCHAR(3000) NULL,
  starts_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Harare',
  duration_minutes INT NOT NULL,
  setup_minutes INT NOT NULL DEFAULT 0,
  rehearsal_notes VARCHAR(1000) NULL,
  city VARCHAR(80) NOT NULL,
  area VARCHAR(80) NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  venue_name VARCHAR(150) NULL,
  venue_address_private VARCHAR(300) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  equipment_available VARCHAR(1000) NULL,
  travel_notes VARCHAR(1000) NULL,
  offer_deadline DATETIME NOT NULL,
  status ENUM('draft','open','filled','closed','expired','cancelled') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_req_client (client_id, status),
  KEY idx_req_open (status, starts_at),
  KEY idx_req_deadline (status, offer_deadline),
  CONSTRAINT fk_req_client FOREIGN KEY (client_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_positions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  instrument_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  budget_minor BIGINT NOT NULL,
  filled_count INT NOT NULL DEFAULT 0,
  status ENUM('open','filled','cancelled') NOT NULL DEFAULT 'open',
  notes VARCHAR(500) NULL,
  KEY idx_pos_req (request_id),
  KEY idx_pos_instr (instrument_id, status),
  CONSTRAINT fk_pos_req FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_instr FOREIGN KEY (instrument_id) REFERENCES instruments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NOT NULL,
  musician_id BIGINT UNSIGNED NOT NULL,
  price_minor BIGINT NOT NULL,
  extras_minor BIGINT NOT NULL DEFAULT 0,
  extras_note VARCHAR(300) NULL,
  currency CHAR(3) NOT NULL,
  included_services VARCHAR(1000) NULL,
  message VARCHAR(1000) NULL,
  counter_price_minor BIGINT NULL,
  counter_note VARCHAR(300) NULL,
  status ENUM('submitted','withdrawn','rejected','expired','accepted') NOT NULL DEFAULT 'submitted',
  version INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_offer_pos_musician (position_id, musician_id),
  KEY idx_off_req (request_id, status),
  KEY idx_off_musician (musician_id, status),
  CONSTRAINT fk_off_req FOREIGN KEY (request_id) REFERENCES requests(id),
  CONSTRAINT fk_off_pos FOREIGN KEY (position_id) REFERENCES request_positions(id),
  CONSTRAINT fk_off_m FOREIGN KEY (musician_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE offer_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('created','edited','withdrawn','counter_proposed','counter_accepted','counter_declined','accepted','rejected','expired') NOT NULL,
  price_minor BIGINT NOT NULL,
  extras_minor BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(1000) NULL,
  version INT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_or_offer (offer_id),
  CONSTRAINT fk_or_offer FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NOT NULL,
  offer_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  musician_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  setup_minutes INT NOT NULL DEFAULT 0,
  timezone VARCHAR(64) NOT NULL,
  price_minor BIGINT NOT NULL,
  extras_minor BIGINT NOT NULL,
  fee_minor BIGINT NOT NULL,
  total_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  fee_bps INT NOT NULL,
  terms_json TEXT NOT NULL,
  status ENUM('confirmed','in_progress','completed','cancelled','disputed') NOT NULL DEFAULT 'confirmed',
  cancelled_by BIGINT UNSIGNED NULL,
  cancel_reason VARCHAR(500) NULL,
  cancellation_charge_minor BIGINT NULL,
  client_done_at DATETIME NULL,
  musician_done_at DATETIME NULL,
  completed_at DATETIME NULL,
  dispute_window_ends_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  version INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_booking_offer (offer_id),
  KEY idx_bk_client (client_id, status, starts_at),
  KEY idx_bk_musician (musician_id, status, starts_at),
  KEY idx_bk_status (status, starts_at),
  CONSTRAINT fk_bk_req FOREIGN KEY (request_id) REFERENCES requests(id),
  CONSTRAINT fk_bk_offer FOREIGN KEY (offer_id) REFERENCES offers(id),
  CONSTRAINT fk_bk_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_bk_m FOREIGN KEY (musician_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  event VARCHAR(40) NOT NULL,
  detail_json TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_be_b (booking_id),
  CONSTRAINT fk_be_b FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reschedule_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  proposed_by BIGINT UNSIGNED NOT NULL,
  new_starts_at DATETIME NOT NULL,
  new_duration_minutes INT NOT NULL,
  note VARCHAR(500) NULL,
  status ENUM('pending','accepted','declined','withdrawn','superseded') NOT NULL DEFAULT 'pending',
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rp_b (booking_id, status),
  CONSTRAINT fk_rp_b FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disputes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  opened_by BIGINT UNSIGNED NOT NULL,
  reason ENUM('no_show','late','quality','payment','conduct','other') NOT NULL,
  details VARCHAR(3000) NOT NULL,
  previous_status VARCHAR(20) NOT NULL,
  status ENUM('open','under_review','resolved') NOT NULL DEFAULT 'open',
  resolution VARCHAR(2000) NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_dispute_booking (booking_id),
  CONSTRAINT fk_d_b FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  subject_role ENUM('client','musician') NOT NULL,
  rating TINYINT NOT NULL,
  comment VARCHAR(1500) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_review (booking_id, author_id),
  KEY idx_rev_subject (subject_id, subject_role),
  CONSTRAINT fk_rev_b FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
