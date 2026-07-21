CREATE TABLE IF NOT EXISTS routing_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  scope ENUM('ingress','user','client','global') NOT NULL DEFAULT 'global',
  created_by INT UNSIGNED NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_routing_profiles_scope (scope),
  CONSTRAINT fk_routing_profiles_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id INT UNSIGNED NOT NULL,
  target_type ENUM('cidr','ip_list') NOT NULL DEFAULT 'cidr',
  destination_cidr VARCHAR(64) NULL,
  ip_list_id INT UNSIGNED NULL,
  action ENUM('direct','egress','block') NOT NULL DEFAULT 'direct',
  server_link_id INT UNSIGNED NULL,
  priority INT NOT NULL DEFAULT 1000,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_routing_rules_profile (profile_id),
  KEY idx_routing_rules_link (server_link_id),
  KEY idx_routing_rules_ip_list (ip_list_id),
  CONSTRAINT fk_routing_rules_profile FOREIGN KEY (profile_id) REFERENCES routing_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_rules_ip_list FOREIGN KEY (ip_list_id) REFERENCES routing_ip_lists(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_rules_link FOREIGN KEY (server_link_id) REFERENCES routing_server_links(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_rules_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_profile_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id INT UNSIGNED NOT NULL,
  subject_type ENUM('ingress','user','client') NOT NULL,
  ingress_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  priority INT NOT NULL DEFAULT 1000,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_routing_assignment_profile (profile_id),
  KEY idx_routing_assignment_subject (subject_type, ingress_id, user_id, client_id),
  CONSTRAINT fk_routing_assignment_profile FOREIGN KEY (profile_id) REFERENCES routing_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_assignment_ingress FOREIGN KEY (ingress_id) REFERENCES routing_ingresses(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_assignment_client FOREIGN KEY (client_id) REFERENCES vpn_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
