<?php

return static function (): void {
    Router::moduleGet('routing', '/routing', function (): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->index();
    });

    Router::modulePost('routing', '/routing/modules/create', function (): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->createModule();
    });

    Router::modulePost('routing', '/routing/modules/{module_id}/save-apply', function (array $params): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->saveModule((int) $params['module_id']);
    });

    Router::modulePost('routing', '/routing/modules/{module_id}/apply', function (array $params): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->applyModule((int) $params['module_id']);
    });

    Router::modulePost('routing', '/routing/modules/{module_id}/paths/create', function (array $params): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->createPath((int) $params['module_id']);
    });

    Router::modulePost('routing', '/routing/paths/{path_id}/save-apply', function (array $params): void {
        require_once __DIR__ . '/../../controllers/AdminRoutingController.php';
        (new AdminRoutingController())->savePath((int) $params['path_id']);
    });

    Router::moduleGet('routing', '/my/routes', function (): void {
        requireAuth();
        redirect('/dashboard');
    });

    Router::moduleGet('routing', '/api/routing/status', function (): void {
        require_once __DIR__ . '/../../controllers/RoutingApiController.php';
        (new RoutingApiController())->status();
    });
};
