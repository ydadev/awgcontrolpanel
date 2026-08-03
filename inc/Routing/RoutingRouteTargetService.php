<?php

class RoutingRouteTargetService
{
    private const MAX_ENTRIES = 5000;
    private const SUCCESS_MARKER = '__AWG_ROUTE_APPLY_OK__';

    public static function listForAdmin(): array
    {
        $stmt = DB::conn()->query('
            SELECT
                target.*,
                routing_group.name AS group_name,
                source_server.name AS ingress_server_name,
                source_server.host AS ingress_server_host,
                route_list.name AS ip_list_name,
                route_list.description AS ip_list_description,
                (
                    SELECT COUNT(*)
                    FROM routing_ip_list_entries count_entries
                    WHERE count_entries.ip_list_id = route_list.id
                ) AS entry_count
            FROM routing_route_targets target
            JOIN routing_user_groups routing_group ON routing_group.id = target.group_id
            JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
            JOIN vpn_servers source_server ON source_server.id = ingress.server_id
            JOIN routing_ip_lists route_list ON route_list.id = target.ip_list_id
            WHERE target.enabled = 1
            ORDER BY target.priority, target.id
        ');
        $targets = $stmt->fetchAll();

        $entryStmt = DB::conn()->prepare(
            'SELECT canonical_cidr
             FROM routing_ip_list_entries
             WHERE ip_list_id = ?
             ORDER BY id'
        );
        foreach ($targets as &$target) {
            $entryStmt->execute([(int) $target['ip_list_id']]);
            $entries = $entryStmt->fetchAll(PDO::FETCH_COLUMN);
            $target['entries'] = $entries;
            $target['entries_text'] = implode("\n", $entries);
            $target['edit_hash'] = self::hashCidrs($entries);
            $target['is_synchronized'] = $target['apply_status'] === 'applied'
                && !empty($target['applied_hash'])
                && hash_equals((string) $target['applied_hash'], $target['edit_hash']);
        }
        unset($target);

        return $targets;
    }

    public static function parseEntries(string $input): array
    {
        $parts = preg_split('/[\r\n,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        $entries = [];
        foreach ($parts as $part) {
            $part = preg_replace('/(?:\s+#.*$)|(?:^\s*#.*$)/', '', trim((string) $part));
            if ($part === '') {
                continue;
            }
            $normalized = RoutingValidator::normalizeIpv4Cidr($part);
            if ($normalized['prefix_length'] === 0) {
                throw new InvalidArgumentException(
                    'Маршрут 0.0.0.0/0 нельзя добавлять в общий список: он изменит маршрут управления самим сервером'
                );
            }
            $entries[$normalized['canonical_cidr']] = $normalized;
        }

        if (!$entries) {
            throw new InvalidArgumentException('Список маршрутов не может быть пустым');
        }
        if (count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('В одном списке допускается не более ' . self::MAX_ENTRIES . ' маршрутов');
        }

        return array_values($entries);
    }

    public static function replaceAndApply(
        int $targetId,
        string $input,
        ?string $expectedHash,
        int $actorUserId
    ): array {
        $entries = self::parseEntries($input);
        $cidrs = array_column($entries, 'canonical_cidr');
        $desiredHash = self::hashCidrs($cidrs);
        $pdo = DB::conn();

        $pdo->beginTransaction();
        try {
            $targetLock = $pdo->query(
                'SELECT id
                 FROM routing_route_targets
                 WHERE enabled = 1
                 ORDER BY id
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($targetId, array_map('intval', $targetLock), true)) {
                throw new InvalidArgumentException('Маршрутный список не найден');
            }

            $target = self::findTarget($targetId);
            $currentHash = self::hashForList((int) $target['ip_list_id']);
            if ($expectedHash && !hash_equals($currentHash, $expectedHash)) {
                throw new RuntimeException('Список уже был изменён в другой сессии. Обновите страницу.');
            }

            self::assertNoExactDuplicates($targetId, $cidrs);

            $pdo->prepare('DELETE FROM routing_ip_list_entries WHERE ip_list_id = ?')
                ->execute([(int) $target['ip_list_id']]);
            $insert = $pdo->prepare(
                'INSERT INTO routing_ip_list_entries
                 (ip_list_id, family, network, prefix_length, canonical_cidr)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($entries as $entry) {
                $insert->execute([
                    (int) $target['ip_list_id'],
                    4,
                    $entry['network'],
                    $entry['prefix_length'],
                    $entry['canonical_cidr'],
                ]);
            }
            $pdo->prepare(
                'UPDATE routing_route_targets
                 SET desired_hash = ?, apply_status = "pending", last_error = NULL
                 WHERE id = ?'
            )->execute([$desiredHash, $targetId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        RoutingAuditService::log(
            $actorUserId,
            'routing.shared_list.saved',
            'routing_route_target',
            $targetId,
            (int) $target['server_id'],
            null,
            ['entry_count' => count($cidrs), 'desired_hash' => $desiredHash]
        );

        return self::apply($targetId, $actorUserId);
    }

    public static function apply(int $targetId, int $actorUserId): array
    {
        $pdo = DB::conn();
        $lockName = 'routing_route_target_' . $targetId;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lockStmt->execute([$lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Другой процесс уже применяет этот список');
        }

        try {
            $target = self::findTarget($targetId);
            $cidrs = self::entriesForList((int) $target['ip_list_id']);
            if (!$cidrs) {
                throw new RuntimeException('Список маршрутов не может быть пустым');
            }
            $desiredHash = self::hashCidrs($cidrs);

            $pdo->prepare(
                'UPDATE routing_route_targets
                 SET desired_hash = ?, apply_status = "applying", last_error = NULL
                 WHERE id = ?'
            )->execute([$desiredHash, $targetId]);

            $server = new VpnServer((int) $target['server_id']);
            $output = match ($target['apply_strategy']) {
                'linux_route_file' => self::applyLinuxRouteFile($server, $target, $cidrs, $desiredHash),
                'wireguard_config' => self::applyWireGuardConfig($server, $target, $cidrs, $desiredHash),
                default => throw new RuntimeException('Unknown route apply strategy'),
            };

            if (!str_contains($output, self::SUCCESS_MARKER)) {
                throw new RuntimeException(self::cleanRemoteError($output));
            }

            $pdo->prepare(
                'UPDATE routing_route_targets
                 SET applied_hash = ?, apply_status = "applied",
                     last_applied_at = NOW(), last_error = NULL
                 WHERE id = ?'
            )->execute([$desiredHash, $targetId]);

            self::recordAppliedRevision((int) $target['server_id'], $target, $cidrs, $desiredHash);
            RoutingAuditService::log(
                $actorUserId,
                'routing.shared_list.applied',
                'routing_route_target',
                $targetId,
                (int) $target['server_id'],
                null,
                ['entry_count' => count($cidrs), 'applied_hash' => $desiredHash]
            );

            return [
                'target_id' => $targetId,
                'entry_count' => count($cidrs),
                'hash' => $desiredHash,
            ];
        } catch (Throwable $e) {
            $pdo->prepare(
                'UPDATE routing_route_targets
                 SET apply_status = "failed", last_error = ?
                 WHERE id = ?'
            )->execute([substr($e->getMessage(), 0, 4000), $targetId]);
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public static function hashForList(int $listId): string
    {
        return self::hashCidrs(self::entriesForList($listId));
    }

    private static function findTarget(int $targetId): array
    {
        $stmt = DB::conn()->prepare('
            SELECT
                target.*,
                ingress.server_id,
                source_server.name AS ingress_server_name
            FROM routing_route_targets target
            JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
            JOIN vpn_servers source_server ON source_server.id = ingress.server_id
            WHERE target.id = ? AND target.enabled = 1
            LIMIT 1
        ');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new InvalidArgumentException('Маршрутный список не найден');
        }
        self::assertSafeTargetMetadata($target);
        return $target;
    }

    private static function entriesForList(int $listId): array
    {
        $stmt = DB::conn()->prepare(
            'SELECT canonical_cidr
             FROM routing_ip_list_entries
             WHERE ip_list_id = ?
             ORDER BY id'
        );
        $stmt->execute([$listId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function assertNoExactDuplicates(int $targetId, array $cidrs): void
    {
        if (!$cidrs) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($cidrs), '?'));
        $sql = '
            SELECT target.name, entry.canonical_cidr
            FROM routing_route_targets target
            JOIN routing_ip_list_entries entry ON entry.ip_list_id = target.ip_list_id
            WHERE target.id <> ?
              AND target.enabled = 1
              AND entry.canonical_cidr IN (' . $placeholders . ')
            LIMIT 1
        ';
        $stmt = DB::conn()->prepare($sql);
        $stmt->execute(array_merge([$targetId], $cidrs));
        $duplicate = $stmt->fetch();
        if ($duplicate) {
            throw new InvalidArgumentException(
                'Маршрут ' . $duplicate['canonical_cidr']
                . ' уже закреплён за направлением ' . $duplicate['name']
            );
        }
    }

    private static function applyLinuxRouteFile(
        VpnServer $server,
        array $target,
        array $cidrs,
        string $desiredHash
    ): string {
        $interface = escapeshellarg($target['route_interface_name']);
        $routeFile = escapeshellarg($target['route_file_path']);
        $payload = escapeshellarg(base64_encode(implode("\n", $cidrs) . "\n"));
        $hash = escapeshellarg($desiredHash);
        $successMarker = self::SUCCESS_MARKER;

        $command = <<<SH
set -eu
interface={$interface}
route_file={$routeFile}
expected_hash={$hash}
test -d /opt/amnezia/awg-egress
ip link show "\$interface" >/dev/null
install -d -m 700 "\$(dirname "\$route_file")"
tmp_file=\$(mktemp "\${route_file}.new.XXXXXX")
old_file=\$(mktemp /tmp/awg-route-old.XXXXXX)
trap 'rm -f "\$tmp_file" "\$old_file"' EXIT
printf %s {$payload} | base64 -d | sed '/^[[:space:]]*\$/d' | sort -u > "\$tmp_file"
if [ -f "\$route_file" ]; then
  sort -u "\$route_file" > "\$old_file"
else
  : > "\$old_file"
fi
sed -e 's|^|route replace |' -e "s|\$| dev \$interface|" "\$tmp_file" | ip -batch -
comm -23 "\$old_file" "\$tmp_file" |
  sed -e 's|^|route delete |' -e "s|\$| dev \$interface|" |
  ip -force -batch - >/dev/null 2>&1 || true
install -m 600 "\$tmp_file" "\$route_file"
actual_hash=\$(sha256sum "\$route_file" | awk '{print \$1}')
[ "\$actual_hash" = "\$expected_hash" ]
echo {$successMarker}
SH;

        return $server->executeCommand($command, true);
    }

    private static function applyWireGuardConfig(
        VpnServer $server,
        array $target,
        array $cidrs,
        string $desiredHash
    ): string {
        $interfaceName = (string) $target['route_interface_name'];
        $interface = escapeshellarg($interfaceName);
        $routeFile = escapeshellarg($target['route_file_path']);
        $payload = escapeshellarg(base64_encode(implode("\n", $cidrs) . "\n"));
        $hash = escapeshellarg($desiredHash);
        $configPath = escapeshellarg('/etc/wireguard/' . $interfaceName . '.conf');
        $hookPathValue = '/opt/awgcontrolpanel/routes/' . $interfaceName . '-hook.sh';
        $hookPath = escapeshellarg($hookPathValue);
        $hookContent = self::wireGuardHookScript($target['route_file_path']);
        $hookPayload = escapeshellarg(base64_encode($hookContent));
        $successMarker = self::SUCCESS_MARKER;

        $command = <<<SH
set -eu
interface={$interface}
route_file={$routeFile}
config_file={$configPath}
hook_file={$hookPath}
expected_hash={$hash}
ip link show "\$interface" >/dev/null
test -f "\$config_file"
install -d -m 700 "\$(dirname "\$route_file")"
tmp_file=\$(mktemp "\${route_file}.new.XXXXXX")
old_file=\$(mktemp /tmp/awg-office-old.XXXXXX)
config_tmp=\$(mktemp /tmp/awg-office-config.XXXXXX)
config_clean=\$(mktemp /tmp/awg-office-clean.XXXXXX)
hook_tmp=\$(mktemp /tmp/awg-office-hook.XXXXXX)
trap 'rm -f "\$tmp_file" "\$old_file" "\$config_tmp" "\$config_clean" "\$hook_tmp"' EXIT
printf %s {$payload} | base64 -d | sed '/^[[:space:]]*\$/d' | sort -u > "\$tmp_file"
printf %s {$hookPayload} | base64 -d > "\$hook_tmp"
if [ -f "\$route_file" ]; then
  sort -u "\$route_file" > "\$old_file"
else
  awk -F= '/^[[:space:]]*AllowedIPs[[:space:]]*=/{print \$2; exit}' "\$config_file" |
    tr ',' '\\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*\$//' | sed '/^\$/d' | sort -u > "\$old_file"
fi
peer=\$(wg show "\$interface" peers | head -n 1)
[ -n "\$peer" ]
[ "\$(wg show "\$interface" peers | wc -l)" -eq 1 ]
allowed_ips=\$(paste -sd, "\$tmp_file")
sed -E '/^[[:space:]]*Post(Up|Down)[[:space:]]*=/d' "\$config_file" > "\$config_clean"
sed -E "0,/^[[:space:]]*AllowedIPs[[:space:]]*=/{s|^[[:space:]]*AllowedIPs[[:space:]]*=.*\$|AllowedIPs = \$allowed_ips|}" \
  "\$config_clean" |
  awk -v up="PostUp = {$hookPathValue} up %i" -v down="PostDown = {$hookPathValue} down %i" '
    BEGIN { inserted = 0 }
    /^\\[Peer\\]/ && !inserted { print up; print down; print ""; inserted = 1 }
    { print }
  ' > "\$config_tmp"
grep -Fqx "AllowedIPs = \$allowed_ips" "\$config_tmp"
sed -e 's|^|route replace |' -e "s|\$| dev \$interface|" "\$tmp_file" | ip -batch -
for source in 10.8.2.0/24 172.17.0.0/16; do
  while IFS= read -r cidr; do
    [ -n "\$cidr" ] || continue
    iptables -t nat -C POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE 2>/dev/null ||
      iptables -t nat -A POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE
  done < "\$tmp_file"
done
wg set "\$interface" peer "\$peer" allowed-ips "\$allowed_ips"
comm -23 "\$old_file" "\$tmp_file" | while IFS= read -r cidr; do
  [ -n "\$cidr" ] || continue
  ip route delete "\$cidr" dev "\$interface" 2>/dev/null || true
  for source in 10.8.2.0/24 172.17.0.0/16; do
    while iptables -t nat -C POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE 2>/dev/null; do
      iptables -t nat -D POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE
    done
  done
done
install -m 700 "\$hook_tmp" "\$hook_file"
install -m 600 "\$tmp_file" "\$route_file"
install -m 600 "\$config_tmp" "\$config_file"
actual_hash=\$(sha256sum "\$route_file" | awk '{print \$1}')
[ "\$actual_hash" = "\$expected_hash" ]
echo {$successMarker}
SH;

        return $server->executeCommand($command, true);
    }

    private static function wireGuardHookScript(string $routeFile): string
    {
        $safeRouteFile = escapeshellarg($routeFile);
        return <<<SH
#!/bin/sh
set -u
mode=\${1:-up}
interface=\${2:-office1}
route_file={$safeRouteFile}
[ -f "\$route_file" ] || exit 0
while IFS= read -r cidr; do
  [ -n "\$cidr" ] || continue
  if [ "\$mode" = "up" ]; then
    ip route replace "\$cidr" dev "\$interface"
    for source in 10.8.2.0/24 172.17.0.0/16; do
      iptables -t nat -C POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE 2>/dev/null ||
        iptables -t nat -A POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE
    done
  else
    ip route delete "\$cidr" dev "\$interface" 2>/dev/null || true
    for source in 10.8.2.0/24 172.17.0.0/16; do
      while iptables -t nat -C POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE 2>/dev/null; do
        iptables -t nat -D POSTROUTING -s "\$source" -d "\$cidr" -o "\$interface" -j MASQUERADE
      done
    done
  fi
done < "\$route_file"
SH;
    }

    private static function recordAppliedRevision(
        int $serverId,
        array $target,
        array $cidrs,
        string $hash
    ): void {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $aggregateStmt = $pdo->prepare(
                'SELECT target.target_key, target.desired_hash, target.applied_hash,
                        target.apply_status
                 FROM routing_route_targets target
                 JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
                 WHERE ingress.server_id = ? AND target.enabled = 1
                 ORDER BY target.target_key
                 FOR UPDATE'
            );
            $aggregateStmt->execute([$serverId]);
            $aggregateTargets = $aggregateStmt->fetchAll();
            $desiredState = [];
            $appliedState = [];
            $allApplied = true;
            foreach ($aggregateTargets as $aggregateTarget) {
                $key = (string) $aggregateTarget['target_key'];
                $desiredState[$key] = $aggregateTarget['desired_hash'];
                $appliedState[$key] = $aggregateTarget['applied_hash'];
                if (
                    $aggregateTarget['apply_status'] !== 'applied'
                    || empty($aggregateTarget['desired_hash'])
                    || empty($aggregateTarget['applied_hash'])
                    || !hash_equals(
                        (string) $aggregateTarget['desired_hash'],
                        (string) $aggregateTarget['applied_hash']
                    )
                ) {
                    $allApplied = false;
                }
            }
            $desiredAggregateHash = hash(
                'sha256',
                json_encode($desiredState, JSON_UNESCAPED_SLASHES)
            );
            $appliedAggregateHash = hash(
                'sha256',
                json_encode($appliedState, JSON_UNESCAPED_SLASHES)
            );
            $agentStatus = $allApplied ? 'healthy' : 'degraded';

            $versionStmt = $pdo->prepare(
                'SELECT GREATEST(desired_version, applied_version)
                 FROM routing_server_state
                 WHERE server_id = ?
                 FOR UPDATE'
            );
            $versionStmt->execute([$serverId]);
            $currentVersion = $versionStmt->fetchColumn();
            $version = $currentVersion === false ? 1 : ((int) $currentVersion + 1);

            if ($currentVersion === false) {
                $state = $pdo->prepare(
                    'INSERT INTO routing_server_state
                     (server_id, desired_version, applied_version, desired_hash, applied_hash,
                      agent_status, last_reconcile_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $state->execute([
                    $serverId,
                    $version,
                    $version,
                    $desiredAggregateHash,
                    $appliedAggregateHash,
                    $agentStatus,
                ]);
            } else {
                $state = $pdo->prepare(
                    'UPDATE routing_server_state
                     SET desired_version = ?, applied_version = ?,
                         desired_hash = ?, applied_hash = ?,
                         agent_status = ?, last_reconcile_at = NOW(),
                         last_error = NULL
                     WHERE server_id = ?'
                );
                $state->execute([
                    $version,
                    $version,
                    $desiredAggregateHash,
                    $appliedAggregateHash,
                    $agentStatus,
                    $serverId,
                ]);
            }
            $configuration = [
                'format_version' => 2,
                'route_target_id' => (int) $target['id'],
                'target_key' => $target['target_key'],
                'interface' => $target['route_interface_name'],
                'entry_count' => count($cidrs),
                'hash' => $hash,
            ];
            $revision = $pdo->prepare(
                'INSERT INTO routing_config_revisions
                 (server_id, version, configuration_hash, configuration_json, status, applied_at)
                 VALUES (?, ?, ?, ?, "applied", NOW())'
            );
            $revision->execute([
                $serverId,
                $version,
                $hash,
                json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertSafeTargetMetadata(array $target): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,32}$/', (string) $target['route_interface_name'])) {
            throw new RuntimeException('Unsafe route interface metadata');
        }
        $path = (string) $target['route_file_path'];
        if (
            !str_starts_with($path, '/opt/')
            || str_contains($path, '..')
            || !preg_match('#^/[a-zA-Z0-9_./-]+$#', $path)
        ) {
            throw new RuntimeException('Unsafe route file metadata');
        }
    }

    private static function hashCidrs(array $cidrs): string
    {
        $normalized = array_values(array_unique(array_map('strval', $cidrs)));
        sort($normalized, SORT_STRING);
        return hash('sha256', implode("\n", $normalized) . "\n");
    }

    private static function cleanRemoteError(string $output): string
    {
        $output = trim(preg_replace('/\s+/', ' ', $output));
        if ($output === '') {
            return 'Сервер не подтвердил применение маршрутов';
        }
        return 'Не удалось применить маршруты: ' . substr($output, 0, 500);
    }
}
