CREATE TABLE IF NOT EXISTS routing_user_link_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  server_link_id INT UNSIGNED NOT NULL,
  permission ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  max_routes INT UNSIGNED NOT NULL DEFAULT 100,
  minimum_prefix_length TINYINT UNSIGNED NOT NULL DEFAULT 8,
  allow_default_route TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_user_link_permission (user_id, server_link_id),
  CONSTRAINT fk_routing_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_permission_link FOREIGN KEY (server_link_id) REFERENCES routing_server_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_server_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  desired_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  applied_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  desired_hash CHAR(64) NULL,
  applied_hash CHAR(64) NULL,
  agent_status ENUM('unknown','healthy','degraded','down','error') NOT NULL DEFAULT 'unknown',
  last_reconcile_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_server_state (server_id),
  CONSTRAINT fk_routing_server_state_server FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_config_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  configuration_hash CHAR(64) NOT NULL,
  configuration_json LONGTEXT NOT NULL,
  status ENUM('pending','delivering','applied','superseded','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  applied_at TIMESTAMP NULL,
  UNIQUE KEY uniq_routing_revision_version (server_id, version),
  KEY idx_routing_revision_status (status),
  CONSTRAINT fk_routing_revision_server FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  urgency ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  payload JSON NULL,
  status ENUM('pending','queued','processed','failed') NOT NULL DEFAULT 'pending',
  available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  KEY idx_routing_outbox_status (status, available_at),
  KEY idx_routing_outbox_server (server_id),
  CONSTRAINT fk_routing_outbox_server FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_delivery_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  revision_id BIGINT UNSIGNED NULL,
  status ENUM('pending','running','applied','retry','failed','superseded') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at TIMESTAMP NULL,
  locked_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_routing_delivery_status (status, next_attempt_at),
  KEY idx_routing_delivery_server (server_id),
  CONSTRAINT fk_routing_delivery_server FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_delivery_revision FOREIGN KEY (revision_id) REFERENCES routing_config_revisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
