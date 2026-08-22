<?php

function settingsProjectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$controller = file_get_contents(__DIR__ . '/../controllers/SettingsController.php');
$template = file_get_contents(__DIR__ . '/../templates/settings.twig');
$protocolController = file_get_contents(__DIR__ . '/../controllers/ProtocolManagementController.php');
$protocolForm = file_get_contents(__DIR__ . '/../templates/settings/protocol_form.twig');

settingsProjectionAssert(strpos($controller, 'openrouter_key_configured') !== false, 'Settings controller does not expose a boolean key state');
settingsProjectionAssert(strpos($controller, "SELECT api_key FROM api_keys") === false, 'Settings page still reads the full OpenRouter key');
settingsProjectionAssert(strpos($template, 'value="{{ openrouter_key }}"') === false, 'Settings template still renders the OpenRouter key');
settingsProjectionAssert(strpos($template, 'name="api_key" autocomplete="new-password"') !== false, 'API key replacement input is not write-only');
settingsProjectionAssert(strpos($template, 'value="{{ config.bind_password }}"') === false, 'LDAP bind password is rendered into HTML');
settingsProjectionAssert(strpos($controller, 'unset($config[\'bind_password\'])') !== false, 'LDAP bind password is not removed from view data');
settingsProjectionAssert(strpos($template, "{% if user.role == 'admin' %}\n    <!-- API Tab -->") !== false, 'API settings tab is not admin-only');
settingsProjectionAssert(strpos($controller, "SecretStore::encrypt(\$bindPasswordInput, 'ldap:bind_password')") !== false, 'LDAP password writes are not encrypted');
settingsProjectionAssert(strpos($protocolController, 'SELECT api_key FROM api_keys') === false, 'Protocol editor still reads the full OpenRouter key');
settingsProjectionAssert(strpos($protocolController, 'openrouter_key_configured') !== false, 'Protocol editor does not use a boolean OpenRouter key state');
settingsProjectionAssert(strpos($protocolForm, 'openrouter_key_configured') !== false, 'Protocol form does not consume the boolean key state');

echo "Settings secret projection tests passed\n";
