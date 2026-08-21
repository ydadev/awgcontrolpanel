ALTER TABLE vpn_clients
    ADD COLUMN allowed_ips_mode VARCHAR(32) NOT NULL DEFAULT 'full' AFTER protocol_id,
    MODIFY COLUMN config MEDIUMTEXT NULL COMMENT 'Full VPN config file';
