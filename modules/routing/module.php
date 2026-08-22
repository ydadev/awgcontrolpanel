<?php

return [
    'id' => 'routing',
    'name' => 'Dynamic routing',
    'description' => 'Administrative routing UI, API and background routing jobs.',
    'required' => false,
    'default_enabled' => true,
    'dependencies' => ['core'],
    'routes' => 'routes.php',
    'owned_tables' => [
        'routing_modules',
        'routing_paths',
        'routing_path_servers',
        'routing_shared_routes',
        'routing_apply_outbox',
    ],
    'workers' => [
        'bin/routing_apply_shared.php',
        'bin/routing_outbox_dispatcher.php',
        'bin/routing_reconcile.php',
        'bin/routing_worker.php',
    ],
];
