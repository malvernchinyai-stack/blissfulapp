-- 001: users, auth, staff, files, settings, audit
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(32) NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  is_client TINYINT(1) NOT NULL DEFAULT 1,
  is_musician TINYINT(1) NOT NULL DEFAULT 0,
  active_role ENUM('client','musician') NOT NULL DEFAULT 'client',
  email_verified_at DATETIME NULL,
  phone_verified_at DATETIME NULL,
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Harare',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  family_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  kind ENUM('access','refresh') NOT NULL,
  device_name VARCHAR(100) NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_tokens_user (user_id),
  KEY idx_tokens_family (family_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verification_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('password_reset','email_verify') NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_vc_user (user_id, purpose),
  CONSTRAINT fk_vc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  role ENUM('superadmin','moderator','support','finance') NOT NULL,
  totp_secret_enc VARCHAR(255) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_totp_step BIGINT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_sessions (
  id CHAR(64) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  csrf_token CHAR(64) NOT NULL,
  totp_passed TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_as_user (user_id),
  CONSTRAINT fk_as_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('avatar','portfolio','verification') NOT NULL,
  visibility ENUM('public','private') NOT NULL,
  storage_name VARCHAR(100) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  retain_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_files_storage (storage_name),
  KEY idx_files_owner (owner_id),
  CONSTRAINT fk_files_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  name VARCHAR(64) PRIMARY KEY,
  value_json TEXT NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id BIGINT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  meta_json TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket VARCHAR(190) NOT NULL,
  window_start BIGINT NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
  user_id BIGINT UNSIGNED NOT NULL,
  idem_key VARCHAR(80) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  status_code INT NULL,
  response_body MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, idem_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_locks (
  name VARCHAR(64) PRIMARY KEY,
  locked_until DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
