<?php

class AdminRoutingController
{
    public function index(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.view');

        RoutingGroupRepository::ensureDefaultGroup();
        RoutingGroupRepository::assignAllUsersToDefault();

        View::render('routing/admin.twig', [
            'stats' => RoutingRepository::dashboard(),
            'route_targets' => RoutingRouteTargetService::listForAdmin(),
            'routing_groups' => RoutingGroupRepository::listGroups(),
            'routing_users' => RoutingGroupRepository::listUsersWithGroups(),
            'success_message' => trim((string) ($_GET['success'] ?? '')),
            'error_message' => trim((string) ($_GET['error'] ?? '')),
        ]);
    }

    public function saveAndApplyTarget(int $targetId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.force_sync');

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            redirect('/routing?error=' . urlencode('Сессия формы истекла. Обновите страницу и повторите попытку.') . '#routes');
        }

        $user = Auth::user();
        try {
            $result = RoutingRouteTargetService::replaceAndApply(
                $targetId,
                (string) ($_POST['entries'] ?? ''),
                trim((string) ($_POST['expected_hash'] ?? '')) ?: null,
                (int) $user['id']
            );

            $message = sprintf(
                'Список сохранен и применен: %d маршрутов.',
                (int) $result['entry_count']
            );
            redirect('/routing?success=' . urlencode($message) . '#routes');
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()) . '#routes');
        }
    }

    public function syncDefaultGroup(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            redirect('/routing?error=' . urlencode('Сессия формы истекла. Обновите страницу и повторите попытку.') . '#groups');
        }

        try {
            $count = RoutingGroupRepository::assignAllUsersToDefault();
            redirect('/routing?success=' . urlencode("Группа default синхронизирована: {$count} пользователей.") . '#groups');
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()) . '#groups');
        }
    }
}
