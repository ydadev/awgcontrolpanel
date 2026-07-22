CREATE TABLE IF NOT EXISTS login_rate_limits (
  scope VARCHAR(20) NOT NULL,
  identifier_hash CHAR(64) NOT NULL,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope, identifier_hash),
  INDEX idx_login_rate_limits_updated (updated_at),
  INDEX idx_login_rate_limits_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
