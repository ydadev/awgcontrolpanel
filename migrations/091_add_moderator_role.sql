ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'moderator', 'user') NOT NULL DEFAULT 'user';

INSERT INTO user_roles (name, display_name, description, permissions) VALUES
('moderator', 'Moderator', 'Can manage regular users without server configuration or routing access', JSON_ARRAY('users.view', 'users.create', 'users.edit', 'users.delete'))
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    description = VALUES(description),
    permissions = VALUES(permissions);

INSERT INTO translations (`locale`, `category`, `key_name`, `translation`) VALUES
('en', 'users', 'role_moderator', 'Moderator'),
('ru', 'users', 'role_moderator', 'Модератор'),
('es', 'users', 'role_moderator', 'Moderador'),
('de', 'users', 'role_moderator', 'Moderator'),
('fr', 'users', 'role_moderator', 'Moderateur'),
('zh', 'users', 'role_moderator', 'Moderator')
ON DUPLICATE KEY UPDATE `translation` = VALUES(`translation`);
