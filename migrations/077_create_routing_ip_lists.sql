CREATE TABLE IF NOT EXISTS routing_ip_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  scope ENUM('system','admin','user') NOT NULL DEFAULT 'admin',
  description TEXT NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_routing_ip_lists_owner (owner_user_id),
  KEY idx_routing_ip_lists_scope (scope),
  CONSTRAINT fk_routing_ip_lists_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_ip_lists_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_ip_list_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_list_id INT UNSIGNED NOT NULL,
  family TINYINT UNSIGNED NOT NULL DEFAULT 4,
  network VARBINARY(16) NOT NULL,
  prefix_length TINYINT UNSIGNED NOT NULL,
  canonical_cidr VARCHAR(64) NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_ip_list_entry (ip_list_id, family, network, prefix_length),
  KEY idx_routing_ip_list_entry_cidr (family, network, prefix_length),
  CONSTRAINT fk_routing_ip_list_entry_list FOREIGN KEY (ip_list_id) REFERENCES routing_ip_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
