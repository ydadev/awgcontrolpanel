<?php

class UserRoutingController
{
    public function index(): void
    {
        RoutingPermissionService::requirePermission('routing.manage_own_routes');
        $user = Auth::user();
        $group = RoutingGroupRepository::getUserGroup((int) $user['id']);

        View::render('routing/user.twig', [
            'ip_lists' => $group ? [] : RoutingRepository::listIpLists((int) $user['id'], false),
            'links' => $group ? RoutingGroupRepository::listAllowedLinksForGroup((int) $group['id']) : $this->allowedLinks((int) $user['id']),
            'routing_group' => $group,
        ]);
    }

    public function createIpList(): void
    {
        RoutingPermissionService::requirePermission('routing.manage_own_routes');
        $user = Auth::user();

        try {
            $group = RoutingGroupRepository::getUserGroup((int) $user['id']);
            if ($group) {
                throw new InvalidArgumentException('Individual route lists are disabled while your account is assigned to routing group "' . $group['name'] . '"');
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('List name is required');
            }

            $entries = preg_split('/\R+/', (string) ($_POST['entries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $normalizedEntries = [];
            foreach ($entries as $entry) {
                $normalizedEntries[] = RoutingValidator::validateUserDestination(trim($entry))['canonical_cidr'];
            }

            $id = RoutingRepository::createIpList($name, 'user', (int) $user['id'], (int) $user['id']);
            foreach ($normalizedEntries as $entry) {
                RoutingRepository::addIpListEntry($id, $entry);
            }
            redirect('/my/routes?success=' . urlencode('Route list created'));
        } catch (Throwable $e) {
            redirect('/my/routes?error=' . urlencode($e->getMessage()));
        }
    }

    private function allowedLinks(int $userId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT l.*, si.name AS ingress_server_name, se.name AS egress_server_name, p.max_routes, p.minimum_prefix_length, p.allow_default_route
            FROM routing_user_link_permissions p
            JOIN routing_server_links l ON l.id = p.server_link_id
            JOIN routing_ingresses i ON i.id = l.ingress_id
            JOIN vpn_servers si ON si.id = i.server_id
            JOIN vpn_servers se ON se.id = l.egress_server_id
            WHERE p.user_id = ? AND p.permission = "allow" AND l.enabled = 1 AND l.user_access_mode = "user_allowed"
            ORDER BY si.name, se.name
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
