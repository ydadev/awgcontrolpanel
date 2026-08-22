<?php

return [
    'id' => 'ldap',
    'name' => 'LDAP authentication',
    'description' => 'LDAP login, settings UI, connection test and synchronization job.',
    'required' => false,
    'default_enabled' => true,
    'dependencies' => ['core'],
    'routes' => 'routes.php',
    'owned_tables' => ['ldap_configs', 'ldap_group_mappings'],
    'workers' => ['bin/sync_ldap_users.php'],
];
