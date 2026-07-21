CREATE TABLE IF NOT EXISTS user_server_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    server_id INT UNSIGNED NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 1,
    can_create_clients TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_server_access (user_id, server_id),
    KEY idx_user_server_access_user (user_id),
    KEY idx_user_server_access_server (server_id),
    CONSTRAINT fk_user_server_access_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_server_access_server
        FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE
);
