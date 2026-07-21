CREATE TABLE IF NOT EXISTS routing_group_link_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  server_link_id INT UNSIGNED NOT NULL,
  permission ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  max_routes INT UNSIGNED NOT NULL DEFAULT 100,
  minimum_prefix_length TINYINT UNSIGNED NOT NULL DEFAULT 8,
  allow_default_route TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_group_link_permission (group_id, server_link_id),
  KEY idx_routing_group_permission_link (server_link_id),
  CONSTRAINT fk_routing_group_permission_group FOREIGN KEY (group_id) REFERENCES routing_user_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_group_permission_link FOREIGN KEY (server_link_id) REFERENCES routing_server_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE routing_profiles
  MODIFY COLUMN scope ENUM('ingress','user','group','client','global') NOT NULL DEFAULT 'global';

ALTER TABLE routing_profile_assignments
  MODIFY COLUMN subject_type ENUM('ingress','user','group','client') NOT NULL;

SET @routing_assignment_group_col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routing_profile_assignments'
    AND COLUMN_NAME = 'group_id'
);

SET @routing_assignment_group_col_sql := IF(
  @routing_assignment_group_col_exists = 0,
  'ALTER TABLE routing_profile_assignments ADD COLUMN group_id INT UNSIGNED NULL AFTER user_id',
  'SELECT 1'
);
PREPARE routing_assignment_group_col_stmt FROM @routing_assignment_group_col_sql;
EXECUTE routing_assignment_group_col_stmt;
DEALLOCATE PREPARE routing_assignment_group_col_stmt;

SET @routing_assignment_group_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routing_profile_assignments'
    AND INDEX_NAME = 'idx_routing_assignment_group'
);

SET @routing_assignment_group_idx_sql := IF(
  @routing_assignment_group_idx_exists = 0,
  'ALTER TABLE routing_profile_assignments ADD INDEX idx_routing_assignment_group (group_id)',
  'SELECT 1'
);
PREPARE routing_assignment_group_idx_stmt FROM @routing_assignment_group_idx_sql;
EXECUTE routing_assignment_group_idx_stmt;
DEALLOCATE PREPARE routing_assignment_group_idx_stmt;

SET @routing_assignment_group_fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routing_profile_assignments'
    AND CONSTRAINT_NAME = 'fk_routing_assignment_group'
);

SET @routing_assignment_group_fk_sql := IF(
  @routing_assignment_group_fk_exists = 0,
  'ALTER TABLE routing_profile_assignments ADD CONSTRAINT fk_routing_assignment_group FOREIGN KEY (group_id) REFERENCES routing_user_groups(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE routing_assignment_group_fk_stmt FROM @routing_assignment_group_fk_sql;
EXECUTE routing_assignment_group_fk_stmt;
DEALLOCATE PREPARE routing_assignment_group_fk_stmt;
