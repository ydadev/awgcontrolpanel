CREATE TABLE IF NOT EXISTS routing_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(128) NOT NULL,
  subject_type VARCHAR(64) NULL,
  subject_id BIGINT UNSIGNED NULL,
  server_id INT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_routing_audit_actor (actor_user_id),
  KEY idx_routing_audit_action (action),
  KEY idx_routing_audit_server (server_id),
  KEY idx_routing_audit_subject (subject_type, subject_id),
  CONSTRAINT fk_routing_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_audit_server FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
