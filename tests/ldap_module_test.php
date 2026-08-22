<?php

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/Modules/FeatureModuleRegistry.php';
require_once __DIR__ . '/../inc/Router.php';

function ldapModuleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$indexSource = file_get_contents($root . '/public/index.php');
$authSource = file_get_contents($root . '/inc/Auth.php');
$settingsSource = file_get_contents($root . '/controllers/SettingsController.php');
$syncSource = file_get_contents($root . '/bin/sync_ldap_users.php');
$settingsTemplate = file_get_contents($root . '/templates/settings.twig');

ldapModuleAssert(strpos($indexSource, "Router::get('/settings/ldap'") === false, 'LDAP route remains in the monolithic index');
ldapModuleAssert(strpos($authSource, "FeatureModuleRegistry::isEnabled('ldap')") !== false, 'Authentication does not honor the LDAP module state');
ldapModuleAssert(strpos($settingsSource, 'if ($ldapModuleEnabled)') !== false, 'Disabled settings page can still query LDAP tables');
ldapModuleAssert(strpos($syncSource, "FeatureModuleRegistry::isEnabled('ldap')") !== false, 'LDAP sync job does not honor the module state');
ldapModuleAssert(substr_count($settingsTemplate, 'modules.ldap.enabled') >= 3, 'LDAP settings surfaces are not fully guarded');

FeatureModuleRegistry::boot($root . '/modules', ['enabled' => '', 'disabled' => 'ldap']);
ldapModuleAssert(!FeatureModuleRegistry::isEnabled('ldap'), 'LDAP module did not disable');
ldapModuleAssert(FeatureModuleRegistry::isEnabled('core'), 'Disabling LDAP affected core');
ldapModuleAssert(FeatureModuleRegistry::states()['ldap']['owned_tables'] === ['ldap_configs', 'ldap_group_mappings'], 'LDAP table ownership is not declared');

FeatureModuleRegistry::registerRoutes();
ob_start();
Router::dispatch('GET', '/settings/ldap');
$body = ob_get_clean();
ldapModuleAssert(http_response_code() === 404, 'Disabled LDAP HTML route must return 404');
ldapModuleAssert($body === '404 Not Found', 'Disabled LDAP route must use a neutral response');

echo "LDAP module tests passed\n";
