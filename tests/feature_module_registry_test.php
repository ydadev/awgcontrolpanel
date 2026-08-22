<?php

require_once __DIR__ . '/../inc/Modules/FeatureModuleRegistry.php';
require_once __DIR__ . '/../inc/Router.php';

function moduleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$modulesPath = __DIR__ . '/../modules';

FeatureModuleRegistry::boot($modulesPath, ['enabled' => '', 'disabled' => '']);
moduleAssert(FeatureModuleRegistry::isEnabled('core'), 'Core must be enabled by default');
moduleAssert(FeatureModuleRegistry::isEnabled('routing'), 'Routing must preserve its current default behavior');
moduleAssert(FeatureModuleRegistry::isEnabled('ldap'), 'LDAP must preserve its current default behavior');

FeatureModuleRegistry::resetForTests();
FeatureModuleRegistry::boot($modulesPath, ['enabled' => '', 'disabled' => 'routing']);
moduleAssert(FeatureModuleRegistry::isEnabled('core'), 'Disabling routing must not disable core');
moduleAssert(!FeatureModuleRegistry::isEnabled('routing'), 'Routing feature flag must disable routing');
moduleAssert(
    FeatureModuleRegistry::states()['routing']['has_routes'] === true,
    'Routing manifest must own its route registrar'
);
FeatureModuleRegistry::registerRoutes();
ob_start();
Router::dispatch('GET', '/api/routing/status');
$disabledApiResponse = json_decode((string) ob_get_clean(), true);
moduleAssert(http_response_code() === 404, 'Disabled module API route must return 404');
moduleAssert(($disabledApiResponse['error'] ?? '') === 'Not Found', 'Disabled module API route must use a neutral response');

FeatureModuleRegistry::resetForTests();
$unknownRejected = false;
try {
    FeatureModuleRegistry::boot($modulesPath, ['enabled' => '', 'disabled' => 'missing-module']);
} catch (RuntimeException $e) {
    $unknownRejected = true;
}
moduleAssert($unknownRejected, 'Unknown configured modules must fail fast');

FeatureModuleRegistry::resetForTests();
$coreRejected = false;
try {
    FeatureModuleRegistry::boot($modulesPath, ['enabled' => '', 'disabled' => 'core']);
} catch (RuntimeException $e) {
    $coreRejected = true;
}
moduleAssert($coreRejected, 'Required core module must not be disabled');

FeatureModuleRegistry::resetForTests();
FeatureModuleRegistry::boot($modulesPath, ['enabled' => '', 'disabled' => 'routing']);
$handlerCalled = false;
Router::moduleGet('routing', '/__routing_module_test', function () use (&$handlerCalled): void {
    $handlerCalled = true;
});
ob_start();
Router::dispatch('GET', '/__routing_module_test');
$disabledResponse = ob_get_clean();
moduleAssert(http_response_code() === 404, 'Disabled module route must return 404');
moduleAssert($disabledResponse === '404 Not Found', 'Disabled module route must not expose implementation details');
moduleAssert(!$handlerCalled, 'Disabled module handler must not execute');

echo "Feature module registry tests passed\n";
