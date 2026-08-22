#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/Modules/FeatureModuleRegistry.php';

Config::load(__DIR__ . '/../.env');
FeatureModuleRegistry::boot(__DIR__ . '/../modules');

foreach (FeatureModuleRegistry::states() as $module) {
    printf(
        "%s enabled=%s required=%s dependencies=%s\n",
        $module['id'],
        $module['enabled'] ? 'yes' : 'no',
        $module['required'] ? 'yes' : 'no',
        $module['dependencies'] === [] ? '-' : implode(',', $module['dependencies'])
    );
}
