<?php

return static function (): void {
    Router::moduleGet('ldap', '/settings/ldap', function (): void {
        requireAdmin();
        redirect('/settings#ldap');
    });

    Router::modulePost('ldap', '/settings/ldap/save', function (): void {
        requireAdmin();
        require_once __DIR__ . '/../../controllers/SettingsController.php';
        require_once __DIR__ . '/../../inc/LdapSync.php';
        (new SettingsController())->saveLdapSettings();
    });

    Router::modulePost('ldap', '/settings/ldap/test', function (): void {
        requireAdmin();
        require_once __DIR__ . '/../../controllers/SettingsController.php';
        require_once __DIR__ . '/../../inc/LdapSync.php';
        (new SettingsController())->testLdapConnection();
    });
};
