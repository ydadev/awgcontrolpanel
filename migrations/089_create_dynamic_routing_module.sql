CREATE TABLE IF NOT EXISTS routing_policy_modules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  intercept_dns TINYINT(1) NOT NULL DEFAULT 1,
  dns_upstreams TEXT NOT NULL,
  dns_cache_size INT UNSIGNED NOT NULL DEFAULT 10000,
  set_timeout_seconds INT UNSIGNED NOT NULL DEFAULT 21600,
  apply_status ENUM('disabled','unknown','pending','applying','applied','failed') NOT NULL DEFAULT 'disabled',
  desired_hash CHAR(64) NULL,
  applied_hash CHAR(64) NULL,
  last_applied_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_policy_module_server (server_id),
  CONSTRAINT fk_routing_policy_module_server
    FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_policy_paths (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_id INT UNSIGNED NOT NULL,
  legacy_target_id INT UNSIGNED NULL,
  server_link_id INT UNSIGNED NULL,
  egress_server_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  transport_label VARCHAR(100) NOT NULL,
  interface_name VARCHAR(32) NOT NULL,
  peer_config_path VARCHAR(255) NULL,
  legacy_route_file_path VARCHAR(255) NULL,
  routing_table_id INT UNSIGNED NOT NULL,
  fwmark INT UNSIGNED NOT NULL,
  priority INT NOT NULL DEFAULT 100,
  tcp_mss INT UNSIGNED NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_policy_path_legacy (legacy_target_id),
  UNIQUE KEY uniq_routing_policy_path_table (module_id, routing_table_id),
  UNIQUE KEY uniq_routing_policy_path_mark (module_id, fwmark),
  KEY idx_routing_policy_path_module (module_id, enabled, priority),
  KEY idx_routing_policy_path_link (server_link_id),
  KEY idx_routing_policy_path_egress (egress_server_id),
  CONSTRAINT fk_routing_policy_path_module
    FOREIGN KEY (module_id) REFERENCES routing_policy_modules(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_policy_path_legacy
    FOREIGN KEY (legacy_target_id) REFERENCES routing_route_targets(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_policy_path_link
    FOREIGN KEY (server_link_id) REFERENCES routing_server_links(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_policy_path_egress
    FOREIGN KEY (egress_server_id) REFERENCES vpn_servers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_policy_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path_id INT UNSIGNED NOT NULL,
  match_type ENUM('domain','cidr') NOT NULL,
  match_value VARCHAR(255) NOT NULL,
  canonical_value VARCHAR(255) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_policy_rule (path_id, match_type, canonical_value),
  KEY idx_routing_policy_rule_path (path_id, match_type, enabled),
  CONSTRAINT fk_routing_policy_rule_path
    FOREIGN KEY (path_id) REFERENCES routing_policy_paths(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO routing_policy_modules
  (server_id, name, enabled, intercept_dns, dns_upstreams, apply_status)
SELECT DISTINCT
  ingress.server_id,
  CONCAT('Динамическая маршрутизация: ', source_server.name),
  0,
  1,
  '1.1.1.1, 8.8.8.8',
  'disabled'
FROM routing_route_targets target
JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
JOIN vpn_servers source_server ON source_server.id = ingress.server_id
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO routing_policy_paths
  (module_id, legacy_target_id, server_link_id, egress_server_id, name,
   transport_label, interface_name, peer_config_path, legacy_route_file_path,
   routing_table_id, fwmark, priority, tcp_mss, enabled)
SELECT
  module.id,
  target.id,
  target.server_link_id,
  COALESCE(link.egress_server_id, egress_server.id),
  target.name,
  target.transport_label,
  target.route_interface_name,
  CASE
    WHEN target.route_interface_name = 'awg-egress'
      THEN '/opt/amnezia/awg-egress/awg-egress.conf'
    ELSE CONCAT('/etc/wireguard/', target.route_interface_name, '.conf')
  END,
  target.route_file_path,
  200 + target.id,
  17408 + target.id,
  target.priority,
  CASE WHEN target.route_interface_name = 'awg-egress' THEN 1200 ELSE NULL END,
  target.enabled
FROM routing_route_targets target
JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
JOIN routing_policy_modules module ON module.server_id = ingress.server_id
LEFT JOIN routing_server_links link ON link.id = target.server_link_id
LEFT JOIN vpn_servers egress_server ON egress_server.name = target.egress_name
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  transport_label = VALUES(transport_label),
  interface_name = VALUES(interface_name),
  legacy_route_file_path = VALUES(legacy_route_file_path),
  priority = VALUES(priority);

INSERT INTO routing_policy_rules
  (path_id, match_type, match_value, canonical_value, enabled)
SELECT
  path.id,
  'cidr',
  entry.canonical_cidr,
  entry.canonical_cidr,
  1
FROM routing_policy_paths path
JOIN routing_route_targets target ON target.id = path.legacy_target_id
JOIN routing_ip_list_entries entry ON entry.ip_list_id = target.ip_list_id
ON DUPLICATE KEY UPDATE enabled = 1;
