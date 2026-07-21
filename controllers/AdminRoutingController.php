<?php

class AdminRoutingController
{
    public function index(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.view');
        $groups = RoutingGroupRepository::listGroups();
        $memberIds = RoutingGroupRepository::listMemberIdsByGroup();
        foreach ($groups as &$group) {
            $group['member_user_ids'] = $memberIds[(int) $group['id']] ?? [];
        }
        unset($group);

        View::render('routing/admin.twig', [
            'stats' => RoutingRepository::dashboard(),
            'protocols' => RoutingRepository::listServersWithProtocols(),
            'ingresses' => RoutingRepository::listIngresses(),
            'links' => RoutingRepository::listLinks(),
            'ip_lists' => RoutingRepository::listIpLists(),
            'servers' => VpnServer::listAll(),
            'routing_groups' => $groups,
            'routing_users' => RoutingGroupRepository::listUsersWithGroups(),
            'group_permissions' => RoutingGroupRepository::listGroupPermissions(),
        ]);
    }

    public function saveIngress(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $user = Auth::user();

        try {
            $serverId = (int) ($_POST['server_id'] ?? 0);
            $serverProtocolId = (int) ($_POST['server_protocol_id'] ?? 0);
            $poolCidr = trim((string) ($_POST['pool_cidr'] ?? ''));
            $interfaceName = trim((string) ($_POST['interface_name'] ?? ''));

            if ($serverId <= 0 || $serverProtocolId <= 0 || $poolCidr === '' || $interfaceName === '') {
                throw new InvalidArgumentException('Server, protocol, pool and interface are required');
            }

            IpPoolManager::assertPoolAvailable($poolCidr);
            RoutingRepository::ensureIngress($serverId, $serverProtocolId, $poolCidr, $interfaceName, (int) $user['id']);
            redirect('/routing?success=' . urlencode('Routing ingress saved'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function createLink(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $user = Auth::user();

        try {
            ServerLinkManager::create($_POST, (int) $user['id']);
            redirect('/routing?success=' . urlencode('Server link created'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function createIpList(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_ip_lists');
        $user = Auth::user();

        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('List name is required');
            }
            $entries = preg_split('/\R+/', (string) ($_POST['entries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $normalizedEntries = [];
            foreach ($entries as $entry) {
                $normalizedEntries[] = RoutingValidator::normalizeIpv4Cidr(trim($entry))['canonical_cidr'];
            }

            $id = RoutingRepository::createIpList($name, (string) ($_POST['scope'] ?? 'admin'), null, (int) $user['id']);
            foreach ($normalizedEntries as $entry) {
                RoutingRepository::addIpListEntry($id, $entry);
            }
            redirect('/routing?success=' . urlencode('IP list created'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function createGroup(): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $user = Auth::user();

        try {
            RoutingGroupRepository::createGroup(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (int) $user['id']
            );
            redirect('/routing?success=' . urlencode('Routing group created'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function saveGroupMembers(int $groupId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $user = Auth::user();

        try {
            $userIds = $_POST['user_ids'] ?? [];
            if (!is_array($userIds)) {
                $userIds = [];
            }
            RoutingGroupRepository::replaceMembers($groupId, $userIds, (int) $user['id']);
            redirect('/routing?success=' . urlencode('Routing group members saved'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function saveGroupPermission(int $groupId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.manage_links');
        $user = Auth::user();

        try {
            RoutingGroupRepository::setGroupLinkPermission(
                $groupId,
                (int) ($_POST['server_link_id'] ?? 0),
                (string) ($_POST['permission'] ?? 'allow'),
                (int) ($_POST['max_routes'] ?? 100),
                (int) ($_POST['minimum_prefix_length'] ?? 8),
                !empty($_POST['allow_default_route']),
                (int) $user['id']
            );
            redirect('/routing?success=' . urlencode('Routing group permission saved'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }

    public function createRevision(int $serverId): void
    {
        requireAdmin();
        RoutingPermissionService::requirePermission('routing.force_sync');

        try {
            $revisionId = RoutingDeliveryService::createRevision($serverId);
            redirect('/routing?success=' . urlencode('Routing revision #' . $revisionId . ' created'));
        } catch (Throwable $e) {
            redirect('/routing?error=' . urlencode($e->getMessage()));
        }
    }
}
