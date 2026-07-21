<?php

class RoutingApiController
{
    public function status(): void
    {
        header('Content-Type: application/json');
        $user = authenticateRequest();
        if (!$user || !Auth::can('routing.view', $user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        echo json_encode([
            'success' => true,
            'stats' => RoutingRepository::dashboard(),
        ]);
    }

    public function buildRevision(array $params): void
    {
        header('Content-Type: application/json');
        $user = authenticateRequest();
        if (!$user || !Auth::can('routing.force_sync', $user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        try {
            $revisionId = RoutingDeliveryService::createRevision((int) $params['server_id']);
            echo json_encode(['success' => true, 'revision_id' => $revisionId]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
