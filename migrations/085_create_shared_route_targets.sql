CREATE TABLE IF NOT EXISTS routing_route_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_key VARCHAR(100) NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  ingress_id INT UNSIGNED NOT NULL,
  server_link_id INT UNSIGNED NULL,
  ip_list_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  egress_name VARCHAR(255) NOT NULL,
  transport_label VARCHAR(100) NOT NULL,
  route_interface_name VARCHAR(32) NOT NULL,
  apply_strategy ENUM('linux_route_file','wireguard_config') NOT NULL,
  route_file_path VARCHAR(255) NOT NULL,
  priority INT NOT NULL DEFAULT 100,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  desired_hash CHAR(64) NULL,
  applied_hash CHAR(64) NULL,
  apply_status ENUM('unknown','pending','applying','applied','failed') NOT NULL DEFAULT 'unknown',
  last_applied_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routing_route_target_key (target_key),
  UNIQUE KEY uniq_routing_route_target_group_name (group_id, name),
  KEY idx_routing_route_target_group (group_id),
  KEY idx_routing_route_target_ingress (ingress_id),
  KEY idx_routing_route_target_link (server_link_id),
  KEY idx_routing_route_target_list (ip_list_id),
  CONSTRAINT fk_routing_route_target_group
    FOREIGN KEY (group_id) REFERENCES routing_user_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_route_target_ingress
    FOREIGN KEY (ingress_id) REFERENCES routing_ingresses(id) ON DELETE CASCADE,
  CONSTRAINT fk_routing_route_target_link
    FOREIGN KEY (server_link_id) REFERENCES routing_server_links(id) ON DELETE SET NULL,
  CONSTRAINT fk_routing_route_target_list
    FOREIGN KEY (ip_list_id) REFERENCES routing_ip_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS migrate_shared_route_targets;
DELIMITER $$
CREATE PROCEDURE migrate_shared_route_targets()
BEGIN
  DECLARE migration_done INT DEFAULT 0;
  DECLARE default_group_id INT UNSIGNED DEFAULT NULL;
  DECLARE kazan_ingress_id INT UNSIGNED DEFAULT NULL;
  DECLARE vienna_link_id INT UNSIGNED DEFAULT NULL;
  DECLARE vienna_list_id INT UNSIGNED DEFAULT NULL;
  DECLARE office_list_id INT UNSIGNED DEFAULT NULL;

  SELECT COUNT(*) INTO migration_done
  FROM settings
  WHERE user_id IS NULL
    AND namespace = 'routing'
    AND `key` = 'shared_route_targets_migrated';

  IF migration_done = 0 THEN
    DELETE FROM routing_profile_assignments;
    DELETE FROM routing_rules;
    DELETE FROM routing_profiles;
    DELETE FROM routing_user_link_permissions;
    DELETE FROM routing_group_link_permissions;
    DELETE FROM routing_user_group_members;
    DELETE FROM routing_user_groups;
    DELETE FROM routing_ip_lists WHERE scope = 'user';

    INSERT INTO routing_user_groups (name, description, enabled, created_by)
    VALUES (
      'default',
      'Общая группа маршрутизации. В неё автоматически входят все пользователи.',
      1,
      NULL
    );
    SET default_group_id = LAST_INSERT_ID();

    INSERT IGNORE INTO routing_user_group_members (group_id, user_id)
    SELECT default_group_id, id FROM users;

    SELECT ri.id INTO kazan_ingress_id
    FROM routing_ingresses ri
    JOIN vpn_servers s ON s.id = ri.server_id
    WHERE s.name = 'kazan1'
    ORDER BY ri.id
    LIMIT 1;

    SELECT l.id INTO vienna_link_id
    FROM routing_server_links l
    JOIN routing_ingresses ri ON ri.id = l.ingress_id
    JOIN vpn_servers source_server ON source_server.id = ri.server_id
    JOIN vpn_servers egress_server ON egress_server.id = l.egress_server_id
    WHERE source_server.name = 'kazan1'
      AND egress_server.name = 'vienna2'
    ORDER BY l.id
    LIMIT 1;

    SELECT id INTO vienna_list_id
    FROM routing_ip_lists
    WHERE name IN ('vamin-vienna-destinations', 'default-vienna2-routes')
    ORDER BY FIELD(name, 'default-vienna2-routes', 'vamin-vienna-destinations'), id
    LIMIT 1;

    IF vienna_list_id IS NOT NULL THEN
      UPDATE routing_ip_lists
      SET name = 'default-vienna2-routes',
          description = 'Общие назначения, которые выходят из kazan1 через vienna2',
          scope = 'admin',
          owner_user_id = NULL,
          is_locked = 1,
          enabled = 1
      WHERE id = vienna_list_id;
    END IF;

    IF kazan_ingress_id IS NOT NULL AND vienna_list_id IS NOT NULL THEN
      INSERT INTO routing_route_targets
        (target_key, group_id, ingress_id, server_link_id, ip_list_id, name,
         egress_name, transport_label, route_interface_name, apply_strategy,
         route_file_path, priority, enabled)
      VALUES
        ('kazan1-vienna2', default_group_id, kazan_ingress_id, vienna_link_id,
         vienna_list_id, 'kazan1 → vienna2', 'vienna2', 'AmneziaWG 2.0 / UDP 443',
         'awg-egress', 'linux_route_file', '/opt/amnezia/awg-egress/routes.txt',
         100, 1);
    END IF;

    INSERT INTO routing_ip_lists
      (owner_user_id, name, scope, description, is_locked, enabled, created_by)
    VALUES
      (NULL, 'default-office1-routes', 'admin',
       'Общие назначения, которые выходят из kazan1 через office1',
       1, 1, NULL);
    SET office_list_id = LAST_INSERT_ID();

    IF kazan_ingress_id IS NOT NULL THEN
      INSERT INTO routing_route_targets
        (target_key, group_id, ingress_id, server_link_id, ip_list_id, name,
         egress_name, transport_label, route_interface_name, apply_strategy,
         route_file_path, priority, enabled)
      VALUES
        ('kazan1-office1', default_group_id, kazan_ingress_id, NULL,
         office_list_id, 'kazan1 → office1', 'office1', 'WireGuard / UDP 51835',
         'office1', 'wireguard_config',
         '/opt/awgcontrolpanel/routes/office1.txt', 50, 1);
    END IF;

    UPDATE routing_config_revisions
    SET status = 'superseded',
        error_message = 'Superseded by shared route target migration'
    WHERE status IN ('pending', 'delivering');

    UPDATE routing_delivery_jobs
    SET status = 'superseded',
        last_error = 'Superseded by shared route target migration'
    WHERE status IN ('pending', 'running', 'retry');

    UPDATE routing_outbox
    SET status = 'processed',
        processed_at = NOW()
    WHERE status IN ('pending', 'queued');

    UPDATE routing_server_state
    SET desired_version = applied_version,
        desired_hash = applied_hash,
        last_error = NULL;

    INSERT INTO settings (user_id, namespace, `key`, value)
    VALUES (NULL, 'routing', 'shared_route_targets_migrated', CAST('true' AS JSON));
  END IF;
END$$
DELIMITER ;

CALL migrate_shared_route_targets();
DROP PROCEDURE IF EXISTS migrate_shared_route_targets;

DROP TRIGGER IF EXISTS users_assign_default_routing_group;
CREATE TRIGGER users_assign_default_routing_group
AFTER INSERT ON users
FOR EACH ROW
  INSERT IGNORE INTO routing_user_group_members (group_id, user_id)
  SELECT id, NEW.id
  FROM routing_user_groups
  WHERE name = 'default' AND enabled = 1
  LIMIT 1;
