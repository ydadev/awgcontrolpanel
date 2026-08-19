<?php

class AdminRoutingController
{
    public function index(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.view');

        $selectedModuleId = isset($_GET['module_id']) ? (int) $_GET['module_id'] : null;

        View::render('routing/admin.twig', [
            'stats' => DynamicRoutingModuleService::dashboard(),
            'routing_modules' => DynamicRoutingModuleService::listForAdmin($selectedModuleId),
            'routing_servers' => DynamicRoutingModuleService::listServers(),
            'success_message' => trim((string) ($_GET['success'] ?? '')),
            'error_message' => trim((string) ($_GET['error'] ?? '')),
        ]);
    }

    public function createModule(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $this->requireCsrf();

        $user = Auth::user();
        try {
            $moduleId = DynamicRoutingModuleService::createModule(
                (int) ($_POST['server_id'] ?? 0),
                (int) $user['id']
            );
            $this->redirectSuccess($moduleId, 'Модуль для сервера создан. Перед включением настройте направления.');
        } catch (Throwable $e) {
            $this->redirectError($e->getMessage());
        }
    }

    public function saveModule(int $moduleId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.force_sync');
        $this->requireCsrf($moduleId);

        $user = Auth::user();
        try {
            $result = DynamicRoutingModuleService::saveSettings($moduleId, $_POST, (int) $user['id']);
            $message = !empty($result['enabled'])
                ? 'Модуль сохранен, установлен и применен.'
                : 'Модуль отключен. Специальные policy-маршруты сняты.';
            $this->redirectSuccess($moduleId, $message);
        } catch (Throwable $e) {
            $this->redirectError($e->getMessage(), $moduleId);
        }
    }

    public function applyModule(int $moduleId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.force_sync');
        $this->requireCsrf($moduleId);

        $user = Auth::user();
        try {
            DynamicRoutingModuleService::apply($moduleId, (int) $user['id']);
            $this->redirectSuccess($moduleId, 'Конфигурация модуля применена повторно.');
        } catch (Throwable $e) {
            $this->redirectError($e->getMessage(), $moduleId);
        }
    }

    public function createPath(int $moduleId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $this->requireCsrf($moduleId);

        $user = Auth::user();
        try {
            DynamicRoutingModuleService::createPath($moduleId, $_POST, (int) $user['id']);
            $this->redirectSuccess($moduleId, 'Направление создано. Добавьте правила и примените модуль.');
        } catch (Throwable $e) {
            $this->redirectError($e->getMessage(), $moduleId);
        }
    }

    public function savePath(int $pathId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.force_sync');
        $this->requireCsrf();

        $user = Auth::user();
        $moduleId = (int) ($_POST['module_id'] ?? 0);
        try {
            $result = DynamicRoutingModuleService::savePath($pathId, $_POST, (int) $user['id']);
            $this->redirectSuccess(
                (int) $result['module_id'],
                'Правила направления сохранены, весь модуль применен атомарно.'
            );
        } catch (Throwable $e) {
            $this->redirectError($e->getMessage(), $moduleId ?: null);
        }
    }

    private function requireCsrf(?int $moduleId = null): void
    {
        if (Csrf::validate($_POST['csrf_token'] ?? null)) {
            return;
        }
        $this->redirectError('Сессия формы истекла. Обновите страницу и повторите попытку.', $moduleId);
    }

    private function redirectSuccess(int $moduleId, string $message): void
    {
        redirect('/routing?module_id=' . $moduleId . '&success=' . urlencode($message) . '#routes');
    }

    private function redirectError(string $message, ?int $moduleId = null): void
    {
        $query = $moduleId ? 'module_id=' . $moduleId . '&' : '';
        redirect('/routing?' . $query . 'error=' . urlencode($message) . '#routes');
    }
}
