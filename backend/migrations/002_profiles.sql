-- 002: catalogue, profiles, portfolio, verification, availability
CREATE TABLE instruments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_instr_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE genres (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_genre_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  org_type ENUM('individual','church','event_planner','venue','business','organization') NOT NULL DEFAULT 'individual',
  city VARCHAR(80) NULL,
  area VARCHAR(80) NULL,
  bio VARCHAR(1000) NULL,
  photo_file_id BIGINT UNSIGNED NULL,
  rating_sum INT NOT NULL DEFAULT 0,
  rating_count INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE musician_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  stage_name VARCHAR(120) NOT NULL,
  bio VARCHAR(2000) NULL,
  city VARCHAR(80) NOT NULL,
  area VARCHAR(80) NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  travel_radius_km INT NOT NULL DEFAULT 30,
  years_experience INT NOT NULL DEFAULT 0,
  typical_rate_minor BIGINT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  equipment_provided VARCHAR(1000) NULL,
  equipment_required VARCHAR(1000) NULL,
  photo_file_id BIGINT UNSIGNED NULL,
  accepting_bookings TINYINT(1) NOT NULL DEFAULT 1,
  rating_sum INT NOT NULL DEFAULT 0,
  rating_count INT NOT NULL DEFAULT 0,
  completed_count INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  KEY idx_mp_city (city),
  CONSTRAINT fk_mp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE musician_instruments (
  musician_id BIGINT UNSIGNED NOT NULL,
  instrument_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (musician_id, instrument_id),
  KEY idx_mi_instr (instrument_id),
  CONSTRAINT fk_mi_m FOREIGN KEY (musician_id) REFERENCES musician_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_mi_i FOREIGN KEY (instrument_id) REFERENCES instruments(id)
) ENGINE=InnoDB;

CREATE TABLE musician_genres (
  musician_id BIGINT UNSIGNED NOT NULL,
  genre_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (musician_id, genre_id),
  CONSTRAINT fk_mg_m FOREIGN KEY (musician_id) REFERENCES musician_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_mg_g FOREIGN KEY (genre_id) REFERENCES genres(id)
) ENGINE=InnoDB;

CREATE TABLE portfolio_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  musician_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('photo','audio','video_link') NOT NULL,
  file_id BIGINT UNSIGNED NULL,
  url VARCHAR(500) NULL,
  title VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_pi_m (musician_id),
  CONSTRAINT fk_pi_m FOREIGN KEY (musician_id) REFERENCES musician_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE availability_blocks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  musician_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  note VARCHAR(120) NULL,
  KEY idx_ab_m (musician_id, starts_at),
  CONSTRAINT fk_ab_m FOREIGN KEY (musician_id) REFERENCES musician_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('email','phone','identity','portfolio') NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  document_file_id BIGINT UNSIGNED NULL,
  reviewer_id BIGINT UNSIGNED NULL,
  reviewer_note VARCHAR(500) NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ver_user (user_id, kind, status),
  CONSTRAINT fk_ver_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
