-- Policy routing capability flag.
-- Note: 073 is already used by user_server_access in this fork.

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'protocols'
    AND COLUMN_NAME = 'supports_policy_routing'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE protocols ADD COLUMN supports_policy_routing TINYINT(1) NOT NULL DEFAULT 0 AFTER show_text_content',
  'SELECT "protocols.supports_policy_routing already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_protocols'
    AND COLUMN_NAME = 'routing_interface_name'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE server_protocols ADD COLUMN routing_interface_name VARCHAR(32) NULL AFTER config_data',
  'SELECT "server_protocols.routing_interface_name already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_protocols'
    AND COLUMN_NAME = 'routing_enabled'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE server_protocols ADD COLUMN routing_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER routing_interface_name',
  'SELECT "server_protocols.routing_enabled already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE protocols
SET supports_policy_routing = 1
WHERE slug IN ('wireguard-standard', 'amnezia-wg-advanced', 'awg2');
