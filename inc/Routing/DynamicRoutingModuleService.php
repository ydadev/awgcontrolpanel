<?php

class DynamicRoutingModuleService
{
    private const SUCCESS_MARKER = '__AWG_DYNAMIC_ROUTING_OK__';
    private const REMOTE_BASE = '/opt/awgcontrolpanel/dynamic-routing';

    public static function dashboard(): array
    {
        $pdo = DB::conn();
        return [
            'modules' => (int) $pdo->query('SELECT COUNT(*) FROM routing_policy_modules')->fetchColumn(),
            'enabled_modules' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_modules WHERE enabled = 1'
            )->fetchColumn(),
            'applied_modules' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_modules WHERE apply_status = "applied"'
            )->fetchColumn(),
            'failed_modules' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_modules WHERE apply_status = "failed"'
            )->fetchColumn(),
            'paths' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_paths WHERE enabled = 1'
            )->fetchColumn(),
            'domain_rules' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_rules WHERE enabled = 1 AND match_type = "domain"'
            )->fetchColumn(),
            'cidr_rules' => (int) $pdo->query(
                'SELECT COUNT(*) FROM routing_policy_rules WHERE enabled = 1 AND match_type = "cidr"'
            )->fetchColumn(),
        ];
    }

    public static function listForAdmin(?int $selectedModuleId = null): array
    {
        $stmt = DB::conn()->query(
            'SELECT module.*, server.name AS server_name, server.host AS server_host
             FROM routing_policy_modules module
             JOIN vpn_servers server ON server.id = module.server_id
             ORDER BY server.name, module.id'
        );
        $modules = $stmt->fetchAll();
        foreach ($modules as &$module) {
            $module['is_selected'] = $selectedModuleId !== null
                ? (int) $module['id'] === $selectedModuleId
                : $module === reset($modules);
            $module['paths'] = self::pathsForModule((int) $module['id']);
            $module['edit_hash'] = self::configurationHashForModule((int) $module['id']);
            $module['is_synchronized'] = $module['apply_status'] === 'applied'
                && !empty($module['applied_hash'])
                && hash_equals((string) $module['applied_hash'], $module['edit_hash']);
        }
        unset($module);
        return $modules;
    }

    public static function listServers(): array
    {
        return DB::conn()->query(
            'SELECT id, name, host FROM vpn_servers ORDER BY name, id'
        )->fetchAll();
    }

    public static function createModule(int $serverId, int $actorUserId): int
    {
        $serverStmt = DB::conn()->prepare('SELECT name FROM vpn_servers WHERE id = ? LIMIT 1');
        $serverStmt->execute([$serverId]);
        $serverName = trim((string) $serverStmt->fetchColumn());
        if ($serverName === '') {
            throw new InvalidArgumentException('Сервер не найден');
        }

        $stmt = DB::conn()->prepare(
            'INSERT INTO routing_policy_modules
             (server_id, name, enabled, intercept_dns, dns_upstreams, apply_status)
             VALUES (?, ?, 0, 1, "1.1.1.1, 8.8.8.8", "disabled")
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$serverId, 'Динамическая маршрутизация: ' . $serverName]);
        $id = (int) DB::conn()->lastInsertId();
        RoutingAuditService::log(
            $actorUserId,
            'routing.dynamic_module.created',
            'routing_policy_module',
            $id,
            $serverId,
            null,
            []
        );
        return $id;
    }

    public static function saveSettings(int $moduleId, array $input, int $actorUserId): array
    {
        $module = self::findModule($moduleId);
        $upstreams = DynamicRoutingCompiler::parseDnsUpstreams((string) ($input['dns_upstreams'] ?? ''));
        $cacheSize = max(0, min(100000, (int) ($input['dns_cache_size'] ?? 10000)));
        $timeout = max(300, min(604800, (int) ($input['set_timeout_seconds'] ?? 21600)));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $intercept = !empty($input['intercept_dns']) ? 1 : 0;

        $stmt = DB::conn()->prepare(
            'UPDATE routing_policy_modules
             SET enabled = ?, intercept_dns = ?, dns_upstreams = ?, dns_cache_size = ?,
                 set_timeout_seconds = ?, apply_status = "pending", last_error = NULL
             WHERE id = ?'
        );
        $stmt->execute([
            $enabled,
            $intercept,
            implode(', ', $upstreams),
            $cacheSize,
            $timeout,
            $moduleId,
        ]);

        RoutingAuditService::log(
            $actorUserId,
            'routing.dynamic_module.settings_saved',
            'routing_policy_module',
            $moduleId,
            (int) $module['server_id'],
            null,
            [
                'enabled' => (bool) $enabled,
                'intercept_dns' => (bool) $intercept,
                'dns_upstreams' => $upstreams,
                'dns_cache_size' => $cacheSize,
                'set_timeout_seconds' => $timeout,
            ]
        );

        return self::apply($moduleId, $actorUserId);
    }

    public static function savePath(int $pathId, array $input, int $actorUserId): array
    {
        $path = self::findPath($pathId);
        $domainRulesText = self::normalizeRulesText((string) ($input['domains'] ?? ''));
        $cidrRulesText = self::normalizeRulesText((string) ($input['cidrs'] ?? ''));
        $domains = DynamicRoutingCompiler::parseDomainEntries($domainRulesText);
        $cidrs = DynamicRoutingCompiler::parseCidrEntries($cidrRulesText);
        $priority = max(1, min(10000, (int) ($input['priority'] ?? 100)));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $tcpMss = trim((string) ($input['tcp_mss'] ?? ''));
        $tcpMss = $tcpMss === '' ? null : max(536, min(8960, (int) $tcpMss));
        $expectedHash = trim((string) ($input['expected_hash'] ?? ''));

        $currentHash = self::pathHash($pathId);
        if ($expectedHash !== '' && !hash_equals($currentHash, $expectedHash)) {
            throw new RuntimeException('Правила направления уже изменены в другой сессии. Обновите страницу.');
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE routing_policy_paths
                 SET priority = ?, tcp_mss = ?, enabled = ?,
                     domain_rules_text = ?, cidr_rules_text = ?
                 WHERE id = ?'
            )->execute([$priority, $tcpMss, $enabled, $domainRulesText, $cidrRulesText, $pathId]);
            $pdo->prepare('DELETE FROM routing_policy_rules WHERE path_id = ?')->execute([$pathId]);
            $insert = $pdo->prepare(
                'INSERT INTO routing_policy_rules
                 (path_id, match_type, match_value, canonical_value, enabled)
                 VALUES (?, ?, ?, ?, 1)'
            );
            foreach ($domains as $domain) {
                $insert->execute([$pathId, 'domain', $domain, $domain]);
            }
            foreach ($cidrs as $cidr) {
                $insert->execute([$pathId, 'cidr', $cidr, $cidr]);
            }
            $pdo->prepare(
                'UPDATE routing_policy_modules
                 SET apply_status = "pending", last_error = NULL WHERE id = ?'
            )->execute([(int) $path['module_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        RoutingAuditService::log(
            $actorUserId,
            'routing.dynamic_path.saved',
            'routing_policy_path',
            $pathId,
            (int) $path['server_id'],
            null,
            [
                'domains' => count($domains),
                'cidrs' => count($cidrs),
                'priority' => $priority,
                'enabled' => (bool) $enabled,
            ]
        );

        return self::apply((int) $path['module_id'], $actorUserId);
    }

    public static function createPath(int $moduleId, array $input, int $actorUserId): int
    {
        $module = self::findModule($moduleId);
        $name = trim((string) ($input['name'] ?? ''));
        $interface = trim((string) ($input['interface_name'] ?? ''));
        $transport = trim((string) ($input['transport_label'] ?? '')) ?: 'Существующий туннель';
        $configPath = trim((string) ($input['peer_config_path'] ?? '')) ?: null;
        $egressServerId = !empty($input['egress_server_id']) ? (int) $input['egress_server_id'] : null;
        $priority = max(1, min(10000, (int) ($input['priority'] ?? 100)));
        $tcpMssValue = trim((string) ($input['tcp_mss'] ?? ''));
        $tcpMss = $tcpMssValue === '' ? null : max(536, min(8960, (int) $tcpMssValue));

        if ($name === '') {
            throw new InvalidArgumentException('Укажите название направления');
        }
        if (!DynamicRoutingCompiler::isSafeInterfaceName($interface)) {
            throw new InvalidArgumentException('Недопустимое имя интерфейса');
        }
        if ($configPath !== null && !self::isSafeRemotePath($configPath)) {
            throw new InvalidArgumentException('Конфигурация peer должна находиться в /etc/wireguard или /opt/amnezia');
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM routing_policy_paths WHERE module_id = ? FOR UPDATE');
            $lock->execute([$moduleId]);
            $existing = $lock->fetchAll(PDO::FETCH_COLUMN);
            $offset = $existing ? max(array_map('intval', $existing)) + 1 : 1;
            $tableId = 1000 + $offset;
            $fwmark = 0x5000 + $offset;

            $stmt = $pdo->prepare(
                'INSERT INTO routing_policy_paths
                 (module_id, egress_server_id, name, transport_label, interface_name,
                  peer_config_path, routing_table_id, fwmark, priority, tcp_mss, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $moduleId,
                $egressServerId,
                $name,
                $transport,
                $interface,
                $configPath,
                $tableId,
                $fwmark,
                $priority,
                $tcpMss,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE routing_policy_modules SET apply_status = "pending" WHERE id = ?'
            )->execute([$moduleId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        RoutingAuditService::log(
            $actorUserId,
            'routing.dynamic_path.created',
            'routing_policy_path',
            $id,
            (int) $module['server_id'],
            null,
            ['name' => $name, 'interface' => $interface, 'egress_server_id' => $egressServerId]
        );
        return $id;
    }

    public static function apply(int $moduleId, int $actorUserId): array
    {
        $pdo = DB::conn();
        $lockName = 'routing_dynamic_module_' . $moduleId;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 60)');
        $lockStmt->execute([$lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Другой процесс уже применяет этот модуль');
        }

        try {
            $module = self::loadCompiledModule($moduleId);
            $hash = DynamicRoutingCompiler::configurationHash($module);
            $pdo->prepare(
                'UPDATE routing_policy_modules
                 SET desired_hash = ?, apply_status = "applying", last_error = NULL WHERE id = ?'
            )->execute([$hash, $moduleId]);

            $server = new VpnServer((int) $module['server_id']);
            if (!empty($module['enabled'])) {
                if (!$module['paths']) {
                    throw new RuntimeException('Добавьте хотя бы одно направление перед включением модуля');
                }
                $output = self::applyEnabled($server, $module);
            } else {
                $output = self::applyDisabled($server, $module);
            }
            if (!str_contains($output, self::SUCCESS_MARKER)) {
                throw new RuntimeException(self::cleanRemoteError($output));
            }

            $status = !empty($module['enabled']) ? 'applied' : 'disabled';
            $pdo->prepare(
                'UPDATE routing_policy_modules
                 SET applied_hash = ?, apply_status = ?, last_applied_at = NOW(), last_error = NULL
                 WHERE id = ?'
            )->execute([$hash, $status, $moduleId]);

            RoutingAuditService::log(
                $actorUserId,
                'routing.dynamic_module.applied',
                'routing_policy_module',
                $moduleId,
                (int) $module['server_id'],
                null,
                ['enabled' => !empty($module['enabled']), 'hash' => $hash]
            );

            return [
                'module_id' => $moduleId,
                'enabled' => !empty($module['enabled']),
                'hash' => $hash,
                'path_count' => count($module['paths']),
            ];
        } catch (Throwable $e) {
            $pdo->prepare(
                'UPDATE routing_policy_modules SET apply_status = "failed", last_error = ? WHERE id = ?'
            )->execute([substr($e->getMessage(), 0, 4000), $moduleId]);
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public static function configurationHashForModule(int $moduleId): string
    {
        return DynamicRoutingCompiler::configurationHash(self::loadCompiledModule($moduleId));
    }

    private static function applyEnabled(VpnServer $server, array $module): string
    {
        $nft = DynamicRoutingCompiler::compileNft($module);
        $dnsmasqNftSet = DynamicRoutingCompiler::compileDnsmasq($module, 'nftset');
        $dnsmasqIpSet = DynamicRoutingCompiler::compileDnsmasq($module, 'ipset');
        $state = self::compilePathState($module);
        $refresh = self::compileRefreshScript($module);
        $service = self::systemdService();
        $timer = self::systemdTimer();
        $base = self::REMOTE_BASE;
        $ipsetState = '';
        foreach ($module['paths'] as $path) {
            if (!empty($path['enabled'])) {
                $ipsetState .= DynamicRoutingCompiler::ipsetName((int) $path['id']) . "\n";
            }
        }

        $files = [
            $base . '/active.nft' => [$nft, '600'],
            $base . '/dnsmasq-nftset.conf' => [$dnsmasqNftSet, '600'],
            $base . '/dnsmasq-ipset.conf' => [$dnsmasqIpSet, '600'],
            $base . '/paths.state' => [$state, '600'],
            $base . '/ipsets.state' => [$ipsetState, '600'],
            $base . '/refresh.sh' => [$refresh, '700'],
            '/etc/systemd/system/awg-dynamic-routing.service' => [$service, '644'],
            '/etc/systemd/system/awg-dynamic-routing.timer' => [$timer, '644'],
        ];
        foreach ($module['paths'] as $path) {
            if (empty($path['enabled'])) {
                continue;
            }
            $files[$base . '/path-' . (int) $path['id'] . '.cidrs'] = [
                implode("\n", $path['cidrs'] ?? []) . "\n",
                '600',
            ];
        }

        $writeFiles = '';
        foreach ($files as $path => [$content, $mode]) {
            $writeFiles .= self::remoteWrite($path, $content, $mode);
        }

        $listenInterfaces = array_values(array_unique(array_merge(
            $module['ingress_interfaces'],
            ['docker0']
        )));
        $listenWords = implode(' ', array_filter($listenInterfaces, [DynamicRoutingCompiler::class, 'isSafeInterfaceName']));
        $transition = self::compilePeerTransition($module);
        $success = self::SUCCESS_MARKER;

        $command = <<<SH
set -eu
base={$base}
install -d -m 700 "\$base" "\$base/backups"
stamp=\$(date -u +%Y%m%d-%H%M%S)
backup="\$base/backups/\$stamp"
install -d -m 700 "\$backup"

if ! command -v dnsmasq >/dev/null 2>&1; then
  touch "\$base/dnsmasq-installed-by-module"
  export DEBIAN_FRONTEND=noninteractive
  policy_created=0
  if [ ! -e /usr/sbin/policy-rc.d ]; then
    printf '%s\n' '#!/bin/sh' 'exit 101' > /usr/sbin/policy-rc.d
    chmod 755 /usr/sbin/policy-rc.d
    policy_created=1
  fi
  apt_status=0
  apt-get update -qq
  apt-get install -y -qq dnsmasq || apt_status=\$?
  if [ "\$policy_created" -eq 1 ]; then rm -f /usr/sbin/policy-rc.d; fi
  [ "\$apt_status" -eq 0 ]
fi

if nft list table inet awg_policy >/dev/null 2>&1; then
  nft list table inet awg_policy > "\$backup/previous.nft"
fi
if [ -f "\$base/paths.state" ]; then
  cp -a "\$base/paths.state" "\$backup/previous-paths.state"
  while read -r pref mark table interface; do
    [ -n "\${pref:-}" ] || continue
    ip rule del pref "\$pref" fwmark "\$mark/0xffffffff" lookup "\$table" 2>/dev/null || true
    ip route flush table "\$table" 2>/dev/null || true
  done < "\$base/paths.state"
fi
if [ -f /etc/dnsmasq.d/90-awg-policy.conf ]; then
  cp -a /etc/dnsmasq.d/90-awg-policy.conf "\$backup/dnsmasq.conf"
fi
if [ -f /etc/dnsmasq.d/91-awg-policy-listen.conf ]; then
  cp -a /etc/dnsmasq.d/91-awg-policy-listen.conf "\$backup/dnsmasq-listen.conf"
fi

{$writeFiles}

listen_tmp=\$(mktemp)
trap 'rm -f "\$listen_tmp"' EXIT
printf '%s\n' '# Managed by AWG Control Panel dynamic routing module.' 'bind-dynamic' > "\$listen_tmp"
for interface in {$listenWords}; do
  if ip link show "\$interface" >/dev/null 2>&1; then
    printf 'interface=%s\n' "\$interface" >> "\$listen_tmp"
  fi
done
grep -q '^interface=' "\$listen_tmp"
install -m 600 "\$listen_tmp" /etc/dnsmasq.d/91-awg-policy-listen.conf
if dnsmasq --version | grep -Eq '(^|[[:space:]])nftset([[:space:]]|$)'; then
  dns_backend=nftset
  install -m 600 "\$base/dnsmasq-nftset.conf" /etc/dnsmasq.d/90-awg-policy.conf
elif dnsmasq --version | grep -Eq '(^|[[:space:]])ipset([[:space:]]|$)'; then
  dns_backend=ipset
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ipset
  install -m 600 "\$base/dnsmasq-ipset.conf" /etc/dnsmasq.d/90-awg-policy.conf
else
  echo 'dnsmasq must support nftset or ipset' >&2
  exit 1
fi
printf '%s\n' "\$dns_backend" > "\$base/dns-backend"

dnsmasq --test

vpn_show_peers() {
  control_interface="\$1"
  if wg show "\$control_interface" peers >/dev/null 2>&1; then
    wg show "\$control_interface" peers
    return
  fi
  for control_container in \$(docker ps --format '{{.Names}}' 2>/dev/null); do
    if docker exec "\$control_container" sh -c '
      interface=\$1
      if command -v awg >/dev/null 2>&1 && awg show "\$interface" peers >/dev/null 2>&1; then
        exec awg show "\$interface" peers
      fi
      if command -v wg >/dev/null 2>&1 && wg show "\$interface" peers >/dev/null 2>&1; then
        exec wg show "\$interface" peers
      fi
      exit 1
    ' sh "\$control_interface"; then
      return
    fi
  done
  return 1
}

vpn_set_allowed() {
  control_interface="\$1"
  control_peer="\$2"
  control_allowed="\$3"
  if wg show "\$control_interface" peers >/dev/null 2>&1; then
    wg set "\$control_interface" peer "\$control_peer" allowed-ips "\$control_allowed"
    return
  fi
  for control_container in \$(docker ps --format '{{.Names}}' 2>/dev/null); do
    if docker exec "\$control_container" sh -c '
      interface=\$1; peer=\$2; allowed=\$3
      if command -v awg >/dev/null 2>&1 && awg show "\$interface" peers >/dev/null 2>&1; then
        exec awg set "\$interface" peer "\$peer" allowed-ips "\$allowed"
      fi
      if command -v wg >/dev/null 2>&1 && wg show "\$interface" peers >/dev/null 2>&1; then
        exec wg set "\$interface" peer "\$peer" allowed-ips "\$allowed"
      fi
      exit 1
    ' sh "\$control_interface" "\$control_peer" "\$control_allowed"; then
      return
    fi
  done
  return 1
}

transitioned=0
rollback() {
  set +e
  rm -f "\$listen_tmp"
  systemctl stop awg-dynamic-routing.timer awg-dynamic-routing.service 2>/dev/null || true
  if nft list table inet awg_policy >/dev/null 2>&1; then nft delete table inet awg_policy; fi
  if [ -f "\$backup/previous.nft" ]; then nft -f "\$backup/previous.nft"; fi
  if [ "\$transitioned" -eq 1 ]; then
    {$transition['rollback']}
  fi
  if [ -f "\$backup/dnsmasq.conf" ]; then
    cp -a "\$backup/dnsmasq.conf" /etc/dnsmasq.d/90-awg-policy.conf
  else
    rm -f /etc/dnsmasq.d/90-awg-policy.conf
  fi
  if [ -f "\$backup/dnsmasq-listen.conf" ]; then
    cp -a "\$backup/dnsmasq-listen.conf" /etc/dnsmasq.d/91-awg-policy-listen.conf
  else
    rm -f /etc/dnsmasq.d/91-awg-policy-listen.conf
  fi
  systemctl restart dnsmasq 2>/dev/null || true
}
trap rollback EXIT HUP INT TERM

if nft list table inet awg_policy >/dev/null 2>&1; then nft delete table inet awg_policy; fi
nft -c -f "\$base/active.nft"
nft -f "\$base/active.nft"
if [ ! -f "\$base/peer-transition-complete" ]; then
  transitioned=1
  {$transition['apply']}
fi

systemctl daemon-reload
systemctl enable awg-dynamic-routing.service awg-dynamic-routing.timer dnsmasq >/dev/null
systemctl restart dnsmasq
systemctl restart awg-dynamic-routing.service
if [ "\$(cat "\$base/dns-backend")" = ipset ]; then
  while IFS= read -r dynamic_set; do
    [ -n "\$dynamic_set" ] || continue
    ipset flush "\$dynamic_set"
  done < "\$base/ipsets.state"
fi
systemctl restart awg-dynamic-routing.timer
systemctl is-active --quiet dnsmasq
systemctl is-active --quiet awg-dynamic-routing.timer
nft list table inet awg_policy >/dev/null

touch "\$base/peer-transition-complete"
trap - EXIT HUP INT TERM
rm -f "\$listen_tmp"
echo {$success}
SH;

        return $server->executeCommand($command, true);
    }

    private static function applyDisabled(VpnServer $server, array $module): string
    {
        $base = self::REMOTE_BASE;
        $success = self::SUCCESS_MARKER;
        $command = <<<SH
set -eu
base={$base}
systemctl disable --now awg-dynamic-routing.timer awg-dynamic-routing.service 2>/dev/null || true
if [ -f "\$base/paths.state" ]; then
  while read -r pref mark table interface; do
    [ -n "\${pref:-}" ] || continue
    ip rule del pref "\$pref" fwmark "\$mark/0xffffffff" lookup "\$table" 2>/dev/null || true
    ip route flush table "\$table" 2>/dev/null || true
  done < "\$base/paths.state"
fi
if nft list table inet awg_policy >/dev/null 2>&1; then nft delete table inet awg_policy; fi
while iptables -t mangle -D PREROUTING -j AWG_POLICY_DYNAMIC 2>/dev/null; do :; done
iptables -t mangle -F AWG_POLICY_DYNAMIC 2>/dev/null || true
iptables -t mangle -X AWG_POLICY_DYNAMIC 2>/dev/null || true
ipset destroy awg_policy_sources 2>/dev/null || true
for dynamic_set in \$(ipset list -name 2>/dev/null | grep -E '^awg_p[0-9]+_dynamic4$' || true); do
  ipset destroy "\$dynamic_set" 2>/dev/null || true
done
iptables -D INPUT -j AWG_POLICY_DNS_IN 2>/dev/null || true
iptables -F AWG_POLICY_DNS_IN 2>/dev/null || true
iptables -X AWG_POLICY_DNS_IN 2>/dev/null || true
for container in \$(docker ps --format '{{.Names}}' 2>/dev/null || true); do
  docker exec "\$container" sh -c '
    iptables -t nat -D PREROUTING -j AWG_POLICY_DNS 2>/dev/null || true
    iptables -t nat -F AWG_POLICY_DNS 2>/dev/null || true
    iptables -t nat -X AWG_POLICY_DNS 2>/dev/null || true
    while iptables -D FORWARD -j AWG_POLICY_DNS_GUARD 2>/dev/null; do :; done
    iptables -F AWG_POLICY_DNS_GUARD 2>/dev/null || true
    iptables -X AWG_POLICY_DNS_GUARD 2>/dev/null || true
  ' 2>/dev/null || true
done
while iptables -D FORWARD -j AWG_POLICY_DNS_GUARD 2>/dev/null; do :; done
iptables -F AWG_POLICY_DNS_GUARD 2>/dev/null || true
iptables -X AWG_POLICY_DNS_GUARD 2>/dev/null || true
rm -f /etc/dnsmasq.d/90-awg-policy.conf /etc/dnsmasq.d/91-awg-policy-listen.conf
if [ -f "\$base/dnsmasq-installed-by-module" ]; then
  systemctl disable --now dnsmasq 2>/dev/null || true
else
  systemctl restart dnsmasq 2>/dev/null || true
fi
echo {$success}
SH;
        return $server->executeCommand($command, true);
    }

    private static function compileRefreshScript(array $module): string
    {
        $base = self::REMOTE_BASE;
        $interfaces = array_values(array_unique(array_filter(
            $module['ingress_interfaces'],
            [DynamicRoutingCompiler::class, 'isSafeInterfaceName']
        )));
        $interfaceWords = implode(' ', $interfaces);
        $dnsInterceptionEnabled = !empty($module['intercept_dns']);
        $dnsGuardUpstreamLines = '';
        if ($dnsInterceptionEnabled) {
            foreach (DynamicRoutingCompiler::parseDnsUpstreams((string) ($module['dns_upstreams'] ?? '')) as $upstream) {
                $upstreamHost = explode('#', $upstream, 2)[0];
                foreach ([853, 443] as $port) {
                    $dnsGuardUpstreamLines .= '        iptables -A AWG_POLICY_DNS_GUARD -i "$interface" -d '
                        . $upstreamHost . ' -p tcp -m tcp --dport ' . $port
                        . ' -j REJECT --reject-with tcp-reset' . "\n";
                    $dnsGuardUpstreamLines .= '        iptables -A AWG_POLICY_DNS_GUARD -i "$interface" -d '
                        . $upstreamHost . ' -p udp -m udp --dport ' . $port . ' -j REJECT' . "\n";
                }
                $dnsGuardUpstreamLines .= '        iptables -A AWG_POLICY_DNS_GUARD -i "$interface" -d '
                    . $upstreamHost . ' -p udp -m multiport --dports 784,8853 -j REJECT' . "\n";
            }
        }
        $dnsGuardEnabled = $dnsInterceptionEnabled ? '1' : '0';
        $hostRoutes = self::hostUpstreamRoutes($module);
        $hostRouteLines = '';
        foreach ($hostRoutes as $host => $interface) {
            $hostRouteLines .= 'ip route replace ' . escapeshellarg($host . '/32')
                . ' dev ' . escapeshellarg($interface) . "\n";
        }

        $sources = array_values(array_unique(array_merge(
            $module['source_cidrs'] ?? [],
            ['172.17.0.0/16']
        )));
        $paths = array_values(array_filter(
            $module['paths'] ?? [],
            static fn(array $path): bool => !empty($path['enabled'])
        ));
        usort($paths, static fn(array $left, array $right): int =>
            [(int) $left['priority'], (int) $left['id']]
            <=> [(int) $right['priority'], (int) $right['id']]
        );
        $timeout = max(300, min(604800, (int) ($module['set_timeout_seconds'] ?? 21600)));
        $sourceSetLines = '';
        foreach ($sources as $source) {
            $sourceSetLines .= 'ipset add awg_policy_sources ' . escapeshellarg($source) . " -exist\n";
        }
        $protectedLines = '';
        foreach (DynamicRoutingCompiler::dynamicProtectedCidrs() as $cidr) {
            $protectedLines .= 'iptables -t mangle -A AWG_POLICY_DYNAMIC -d '
                . escapeshellarg($cidr) . " -j RETURN\n";
        }
        $ipsetCreateLines = '';
        $ipsetRuleLines = '';
        $ipsetNames = [];
        foreach ($paths as $path) {
            $name = DynamicRoutingCompiler::ipsetName((int) $path['id']);
            $ipsetNames[] = $name;
            $ipsetCreateLines .= 'ensure_dynamic_set ' . escapeshellarg($name) . ' ' . $timeout . "\n";
            $ipsetRuleLines .= 'iptables -t mangle -A AWG_POLICY_DYNAMIC -m set --match-set '
                . escapeshellarg($name) . ' dst -j MARK --set-xmark '
                . sprintf('0x%x', (int) $path['fwmark']) . "/0xffffffff\n";
            $ipsetRuleLines .= 'iptables -t mangle -A AWG_POLICY_DYNAMIC -m mark ! --mark 0/0xffffffff -j RETURN' . "\n";
        }
        $ipsetNameWords = implode(' ', $ipsetNames);

        return <<<SH
#!/bin/sh
set -eu
base={$base}
[ -f "\$base/active.nft" ]
[ -f "\$base/paths.state" ]
nft list table inet awg_policy >/dev/null 2>&1 || nft -f "\$base/active.nft"

while read -r pref mark table interface; do
  [ -n "\${pref:-}" ] || continue
  ip link show "\$interface" >/dev/null
  ip rule del pref "\$pref" fwmark "\$mark/0xffffffff" lookup "\$table" 2>/dev/null || true
  ip rule add pref "\$pref" fwmark "\$mark/0xffffffff" lookup "\$table"
  ip route replace default dev "\$interface" table "\$table"
done < "\$base/paths.state"

{$hostRouteLines}
cleanup_ipset_backend() {
  while iptables -t mangle -D PREROUTING -j AWG_POLICY_DYNAMIC 2>/dev/null; do :; done
  iptables -t mangle -F AWG_POLICY_DYNAMIC 2>/dev/null || true
  iptables -t mangle -X AWG_POLICY_DYNAMIC 2>/dev/null || true
  ipset destroy awg_policy_sources 2>/dev/null || true
  for dynamic_set in \$(ipset list -name 2>/dev/null | grep -E '^awg_p[0-9]+_dynamic4$' || true); do
    ipset destroy "\$dynamic_set" 2>/dev/null || true
  done
}

ensure_dynamic_set() {
  set_name=\$1
  set_timeout=\$2
  if ipset create "\$set_name" hash:ip family inet timeout "\$set_timeout" maxelem 65536 -exist 2>/dev/null; then
    return
  fi
  while iptables -t mangle -D PREROUTING -j AWG_POLICY_DYNAMIC 2>/dev/null; do :; done
  iptables -t mangle -F AWG_POLICY_DYNAMIC 2>/dev/null || true
  iptables -t mangle -X AWG_POLICY_DYNAMIC 2>/dev/null || true
  ipset destroy "\$set_name" 2>/dev/null || true
  ipset create "\$set_name" hash:ip family inet timeout "\$set_timeout" maxelem 65536
}

dns_backend=\$(cat "\$base/dns-backend" 2>/dev/null || printf nftset)
if [ "\$dns_backend" = ipset ]; then
  ipset create awg_policy_sources hash:net family inet -exist
  ipset flush awg_policy_sources
  {$sourceSetLines}
  {$ipsetCreateLines}
  for dynamic_set in \$(ipset list -name 2>/dev/null | grep -E '^awg_p[0-9]+_dynamic4$' || true); do
    case " {$ipsetNameWords} " in
      *" \$dynamic_set "*) ;;
      *) ipset destroy "\$dynamic_set" 2>/dev/null || true ;;
    esac
  done
  iptables -t mangle -N AWG_POLICY_DYNAMIC 2>/dev/null || true
  iptables -t mangle -F AWG_POLICY_DYNAMIC
  iptables -t mangle -A AWG_POLICY_DYNAMIC -m set ! --match-set awg_policy_sources src -j RETURN
  iptables -t mangle -A AWG_POLICY_DYNAMIC -m mark ! --mark 0/0xffffffff -j RETURN
  {$protectedLines}
  {$ipsetRuleLines}
  iptables -t mangle -C PREROUTING -j AWG_POLICY_DYNAMIC 2>/dev/null || \
    iptables -t mangle -I PREROUTING 1 -j AWG_POLICY_DYNAMIC
else
  cleanup_ipset_backend
fi

iptables -N AWG_POLICY_DNS_IN 2>/dev/null || true
iptables -F AWG_POLICY_DNS_IN
for interface in {$interfaceWords} docker0; do
  ip link show "\$interface" >/dev/null 2>&1 || continue
  iptables -A AWG_POLICY_DNS_IN -i "\$interface" -p udp --dport 53 -j ACCEPT
  iptables -A AWG_POLICY_DNS_IN -i "\$interface" -p tcp --dport 53 -j ACCEPT
done
iptables -C INPUT -j AWG_POLICY_DNS_IN 2>/dev/null || iptables -I INPUT 1 -j AWG_POLICY_DNS_IN

iptables -N AWG_POLICY_DNS_GUARD 2>/dev/null || true
iptables -F AWG_POLICY_DNS_GUARD
dns_guard_found=0
if [ {$dnsGuardEnabled} -eq 1 ]; then
  for interface in {$interfaceWords}; do
    ip link show "\$interface" >/dev/null 2>&1 || continue
{$dnsGuardUpstreamLines}    dns_guard_found=1
  done
fi
while iptables -D FORWARD -j AWG_POLICY_DNS_GUARD 2>/dev/null; do :; done
if [ "\$dns_guard_found" -eq 1 ]; then
  iptables -I FORWARD 1 -j AWG_POLICY_DNS_GUARD
else
  iptables -F AWG_POLICY_DNS_GUARD 2>/dev/null || true
  iptables -X AWG_POLICY_DNS_GUARD 2>/dev/null || true
fi

for container in \$(docker ps --format '{{.Names}}' 2>/dev/null || true); do
  docker exec "\$container" sh -c '
      set -u
      while iptables -D FORWARD -j AWG_POLICY_DNS_GUARD 2>/dev/null; do :; done
      gateway=\$(ip -4 route show default | awk "NR == 1 { print \\$3 }")
      [ -n "\$gateway" ] || exit 0
      iptables -t nat -N AWG_POLICY_DNS 2>/dev/null || true
      iptables -t nat -F AWG_POLICY_DNS
      iptables -N AWG_POLICY_DNS_GUARD 2>/dev/null || true
      iptables -F AWG_POLICY_DNS_GUARD
      found=0
      for interface in "\$@"; do
        ip link show "\$interface" >/dev/null 2>&1 || continue
        iptables -t nat -A AWG_POLICY_DNS -i "\$interface" -p udp --dport 53 -j DNAT --to-destination "\$gateway:53"
        iptables -t nat -A AWG_POLICY_DNS -i "\$interface" -p tcp --dport 53 -j DNAT --to-destination "\$gateway:53"
        if [ {$dnsGuardEnabled} -eq 1 ]; then
{$dnsGuardUpstreamLines}        fi
        found=1
      done
      if [ "\$found" -eq 1 ]; then
        iptables -t nat -C PREROUTING -j AWG_POLICY_DNS 2>/dev/null || iptables -t nat -I PREROUTING 1 -j AWG_POLICY_DNS
        if [ {$dnsGuardEnabled} -eq 1 ]; then
          iptables -I FORWARD 1 -j AWG_POLICY_DNS_GUARD
        else
          iptables -F AWG_POLICY_DNS_GUARD 2>/dev/null || true
          iptables -X AWG_POLICY_DNS_GUARD 2>/dev/null || true
        fi
      else
        iptables -t nat -D PREROUTING -j AWG_POLICY_DNS 2>/dev/null || true
        iptables -F AWG_POLICY_DNS_GUARD 2>/dev/null || true
        iptables -X AWG_POLICY_DNS_GUARD 2>/dev/null || true
      fi
    ' sh {$interfaceWords}
done
SH;
    }

    private static function compilePeerTransition(array $module): array
    {
        $apply = '';
        $rollback = '';
        foreach ($module['paths'] as $path) {
            if (empty($path['enabled'])) {
                continue;
            }
            $id = (int) $path['id'];
            $interface = escapeshellarg($path['interface_name']);
            $config = trim((string) ($path['peer_config_path'] ?? ''));
            $routeFile = trim((string) ($path['legacy_route_file_path'] ?? ''));
            $cidrFile = escapeshellarg(self::REMOTE_BASE . '/path-' . $id . '.cidrs');
            $backupConfig = '"$backup/path-' . $id . '.conf"';
            $backupRoutes = '"$backup/path-' . $id . '.routes"';

            $apply .= 'ip link show ' . $interface . " >/dev/null\n";
            if ($config !== '') {
                if (!self::isSafeRemotePath($config)) {
                    throw new RuntimeException('Unsafe peer config path');
                }
                $safeConfig = escapeshellarg($config);
                $apply .= 'test -f ' . $safeConfig . "\n";
                $apply .= 'cp -a ' . $safeConfig . ' ' . $backupConfig . "\n";
                $apply .= 'config_tmp=$(mktemp)' . "\n";
                $apply .= "awk '\n"
                    . "  BEGIN { section = \"\"; table_done = 0; allowed_done = 0 }\n"
                    . "  /^\\[Interface\\][[:space:]]*\$/ { section = \"interface\"; print; next }\n"
                    . "  /^\\[Peer\\][[:space:]]*\$/ {\n"
                    . "    if (section == \"interface\" && !table_done) print \"Table = off\"\n"
                    . "    section = \"peer\"; print; next\n"
                    . "  }\n"
                    . "  section == \"interface\" && /^[[:space:]]*Table[[:space:]]*=/ {\n"
                    . "    if (!table_done) print \"Table = off\"\n"
                    . "    table_done = 1; next\n"
                    . "  }\n"
                    . "  section == \"peer\" && !allowed_done && /^[[:space:]]*AllowedIPs[[:space:]]*=/ {\n"
                    . "    print \"AllowedIPs = 0.0.0.0/0\"; allowed_done = 1; next\n"
                    . "  }\n"
                    . "  { print }\n"
                    . "  END { if (!allowed_done) exit 3 }\n"
                    . "' " . $safeConfig . ' > "$config_tmp"' . "\n";
                $apply .= 'chmod --reference=' . $safeConfig . ' "$config_tmp"' . "\n";
                $apply .= 'chown --reference=' . $safeConfig . ' "$config_tmp"' . "\n";
                $apply .= 'mv "$config_tmp" ' . $safeConfig . "\n";
                $apply .= 'peer=$(vpn_show_peers ' . $interface . ' | head -n 1)' . "\n";
                $apply .= '[ -n "$peer" ]' . "\n";
                $apply .= '[ "$(vpn_show_peers ' . $interface . ' | wc -l)" -eq 1 ]' . "\n";
                $apply .= 'vpn_set_allowed ' . $interface . ' "$peer" 0.0.0.0/0' . "\n";

                $rollback .= 'if [ -f ' . $backupConfig . ' ]; then' . "\n";
                $rollback .= '  cp -a ' . $backupConfig . ' ' . $safeConfig . "\n";
                $rollback .= '  old_allowed=$(awk -F= \'/^[[:space:]]*AllowedIPs[[:space:]]*=/{print $2; exit}\' '
                    . $safeConfig . ' | tr -d "[:space:]")' . "\n";
                $rollback .= '  peer=$(vpn_show_peers ' . $interface . ' | head -n 1)' . "\n";
                $rollback .= '  [ -z "$peer" ] || [ -z "$old_allowed" ] || vpn_set_allowed ' . $interface
                    . ' "$peer" "$old_allowed"' . "\n";
                $rollback .= "fi\n";
            }
            if ($routeFile !== '') {
                if (!self::isSafeRemotePath($routeFile)) {
                    throw new RuntimeException('Unsafe legacy route file path');
                }
                $safeRouteFile = escapeshellarg($routeFile);
                $apply .= 'if [ -f ' . $safeRouteFile . ' ]; then cp -a ' . $safeRouteFile . ' '
                    . $backupRoutes . '; : > ' . $safeRouteFile . "; fi\n";
                $rollback .= 'if [ -f ' . $backupRoutes . ' ]; then cp -a ' . $backupRoutes . ' '
                    . $safeRouteFile . "; fi\n";
            }
            $apply .= 'while IFS= read -r cidr; do' . "\n";
            $apply .= '  [ -n "$cidr" ] || continue' . "\n";
            $apply .= '  ip route delete "$cidr" dev ' . $interface . " 2>/dev/null || true\n";
            $apply .= 'done < ' . $cidrFile . "\n";
            $rollback .= 'while IFS= read -r cidr; do' . "\n";
            $rollback .= '  [ -n "$cidr" ] || continue' . "\n";
            $rollback .= '  ip route replace "$cidr" dev ' . $interface . " 2>/dev/null || true\n";
            $rollback .= 'done < ' . $cidrFile . "\n";
        }
        return ['apply' => $apply, 'rollback' => $rollback];
    }

    private static function compilePathState(array $module): string
    {
        $lines = [];
        $index = 0;
        foreach ($module['paths'] as $path) {
            if (empty($path['enabled'])) {
                continue;
            }
            $lines[] = implode(' ', [
                12000 + $index,
                sprintf('0x%x', (int) $path['fwmark']),
                (int) $path['routing_table_id'],
                $path['interface_name'],
            ]);
            $index++;
        }
        return implode("\n", $lines) . "\n";
    }

    private static function systemdService(): string
    {
        return <<<'UNIT'
[Unit]
Description=AWG Control Panel dynamic routing
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/opt/awgcontrolpanel/dynamic-routing/refresh.sh

[Install]
WantedBy=multi-user.target
UNIT;
    }

    private static function systemdTimer(): string
    {
        return <<<'UNIT'
[Unit]
Description=Refresh AWG Control Panel dynamic routing

[Timer]
OnBootSec=45s
OnUnitActiveSec=60s
AccuracySec=10s
Unit=awg-dynamic-routing.service

[Install]
WantedBy=timers.target
UNIT;
    }

    private static function remoteWrite(string $path, string $content, string $mode): string
    {
        return 'install -d -m 700 ' . escapeshellarg(dirname($path)) . "\n"
            . 'printf %s ' . escapeshellarg(base64_encode($content))
            . ' | base64 -d > ' . escapeshellarg($path) . "\n"
            . 'chmod ' . escapeshellarg($mode) . ' ' . escapeshellarg($path) . "\n";
    }

    private static function loadCompiledModule(int $moduleId): array
    {
        $module = self::findModule($moduleId);
        $module['paths'] = self::pathsForModule($moduleId);
        [$sourceCidrs, $interfaces] = self::sourceNetworkMetadata((int) $module['server_id']);
        $module['source_cidrs'] = $sourceCidrs;
        $module['ingress_interfaces'] = $interfaces;
        $module['host_ingress_interfaces'] = $interfaces;
        return $module;
    }

    private static function pathsForModule(int $moduleId): array
    {
        $stmt = DB::conn()->prepare(
            'SELECT path.*, module.server_id,
                    egress.name AS egress_server_name, egress.host AS egress_server_host
             FROM routing_policy_paths path
             JOIN routing_policy_modules module ON module.id = path.module_id
             LEFT JOIN vpn_servers egress ON egress.id = path.egress_server_id
             WHERE path.module_id = ?
             ORDER BY path.priority, path.id'
        );
        $stmt->execute([$moduleId]);
        $paths = $stmt->fetchAll();
        $ruleStmt = DB::conn()->prepare(
            'SELECT match_type, canonical_value
             FROM routing_policy_rules
             WHERE path_id = ? AND enabled = 1
             ORDER BY match_type, id'
        );
        foreach ($paths as &$path) {
            $ruleStmt->execute([(int) $path['id']]);
            $path['domains'] = [];
            $path['cidrs'] = [];
            foreach ($ruleStmt->fetchAll() as $rule) {
                $key = $rule['match_type'] === 'domain' ? 'domains' : 'cidrs';
                $path[$key][] = $rule['canonical_value'];
            }
            $path['domains_text'] = $path['domain_rules_text'] !== null
                ? (string) $path['domain_rules_text']
                : implode("\n", $path['domains']);
            $path['cidrs_text'] = $path['cidr_rules_text'] !== null
                ? (string) $path['cidr_rules_text']
                : implode("\n", $path['cidrs']);
            $path['edit_hash'] = self::pathHashFromData($path);
        }
        unset($path);
        return $paths;
    }

    private static function findModule(int $moduleId): array
    {
        $stmt = DB::conn()->prepare(
            'SELECT module.*, server.name AS server_name, server.host AS server_host
             FROM routing_policy_modules module
             JOIN vpn_servers server ON server.id = module.server_id
             WHERE module.id = ? LIMIT 1'
        );
        $stmt->execute([$moduleId]);
        $module = $stmt->fetch();
        if (!$module) {
            throw new InvalidArgumentException('Модуль маршрутизации не найден');
        }
        return $module;
    }

    private static function findPath(int $pathId): array
    {
        $stmt = DB::conn()->prepare(
            'SELECT path.*, module.server_id
             FROM routing_policy_paths path
             JOIN routing_policy_modules module ON module.id = path.module_id
             WHERE path.id = ? LIMIT 1'
        );
        $stmt->execute([$pathId]);
        $path = $stmt->fetch();
        if (!$path) {
            throw new InvalidArgumentException('Направление не найдено');
        }
        return $path;
    }

    private static function pathHash(int $pathId): string
    {
        $path = self::findPath($pathId);
        $paths = self::pathsForModule((int) $path['module_id']);
        foreach ($paths as $candidate) {
            if ((int) $candidate['id'] === $pathId) {
                return $candidate['edit_hash'];
            }
        }
        throw new InvalidArgumentException('Направление не найдено');
    }

    private static function pathHashFromData(array $path): string
    {
        return hash('sha256', json_encode([
            'enabled' => !empty($path['enabled']),
            'priority' => (int) $path['priority'],
            'tcp_mss' => $path['tcp_mss'] !== null ? (int) $path['tcp_mss'] : null,
            'domains' => array_values($path['domains'] ?? []),
            'cidrs' => array_values($path['cidrs'] ?? []),
            'domain_rules_text' => (string) ($path['domains_text'] ?? $path['domain_rules_text'] ?? ''),
            'cidr_rules_text' => (string) ($path['cidrs_text'] ?? $path['cidr_rules_text'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeRulesText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private static function sourceNetworkMetadata(int $serverId): array
    {
        $subnets = ['172.17.0.0/16'];
        $interfaces = [];
        $serverStmt = DB::conn()->prepare('SELECT vpn_subnet FROM vpn_servers WHERE id = ? LIMIT 1');
        $serverStmt->execute([$serverId]);
        $serverSubnet = trim((string) $serverStmt->fetchColumn());
        if ($serverSubnet !== '') {
            $subnets[] = $serverSubnet;
        }

        $stmt = DB::conn()->prepare(
            'SELECT sp.config_data, sp.routing_interface_name, p.slug, p.definition,
                    ingress.interface_name AS ingress_interface
             FROM server_protocols sp
             JOIN protocols p ON p.id = sp.protocol_id
             LEFT JOIN routing_ingresses ingress ON ingress.server_protocol_id = sp.id
             WHERE sp.server_id = ?'
        );
        $stmt->execute([$serverId]);
        foreach ($stmt->fetchAll() as $row) {
            $config = json_decode((string) ($row['config_data'] ?? ''), true);
            $definition = json_decode((string) ($row['definition'] ?? ''), true);
            $subnet = is_array($config) ? trim((string) ($config['vpn_subnet'] ?? '')) : '';
            if ($subnet === '' && is_array($definition)) {
                $subnet = trim((string) ($definition['metadata']['vpn_subnet'] ?? ''));
            }
            if ($subnet !== '') {
                $subnets[] = $subnet;
            }
            foreach (['routing_interface_name', 'ingress_interface'] as $field) {
                $interface = trim((string) ($row[$field] ?? ''));
                if (DynamicRoutingCompiler::isSafeInterfaceName($interface)) {
                    $interfaces[$interface] = true;
                }
            }
            if (is_array($config)) {
                $interface = trim((string) ($config['interface_name'] ?? ''));
                if (DynamicRoutingCompiler::isSafeInterfaceName($interface)) {
                    $interfaces[$interface] = true;
                }
            }
            $fallbackInterface = match ((string) ($row['slug'] ?? '')) {
                'wireguard-standard' => 'wg0',
                'awg2' => 'awg0',
                default => '',
            };
            if ($fallbackInterface !== '') {
                $interfaces[$fallbackInterface] = true;
            }
        }

        $normalized = [];
        foreach ($subnets as $subnet) {
            try {
                $cidr = RoutingValidator::normalizeIpv4Cidr($subnet)['canonical_cidr'];
                $normalized[$cidr] = true;
            } catch (InvalidArgumentException $e) {
                error_log('Ignoring invalid dynamic routing source subnet: ' . $subnet);
            }
        }
        return [array_keys($normalized), array_keys($interfaces)];
    }

    private static function hostUpstreamRoutes(array $module): array
    {
        $routes = [];
        $upstreams = DynamicRoutingCompiler::parseDnsUpstreams((string) $module['dns_upstreams']);
        foreach ($upstreams as $upstream) {
            $host = explode('#', $upstream, 2)[0];
            foreach ($module['paths'] as $path) {
                if (empty($path['enabled'])) {
                    continue;
                }
                foreach ($path['cidrs'] as $cidr) {
                    if (self::cidrContainsIp($cidr, $host)) {
                        $routes[$host] = $path['interface_name'];
                        continue 3;
                    }
                }
            }
        }
        return $routes;
    }

    private static function cidrContainsIp(string $cidr, string $ip): bool
    {
        $normalized = RoutingValidator::normalizeIpv4Cidr($cidr);
        $ipValue = (int) sprintf('%u', ip2long($ip));
        $networkValue = (int) sprintf('%u', ip2long($normalized['network_ip']));
        $prefix = (int) $normalized['prefix_length'];
        $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
        return ($ipValue & $mask) === $networkValue;
    }

    private static function isSafeRemotePath(string $path): bool
    {
        return !str_contains($path, '..')
            && (str_starts_with($path, '/etc/wireguard/') || str_starts_with($path, '/opt/amnezia/')
                || str_starts_with($path, '/opt/awgcontrolpanel/'))
            && (bool) preg_match('#^/[a-zA-Z0-9_./-]+$#', $path);
    }

    private static function cleanRemoteError(string $output): string
    {
        $output = trim(preg_replace('/\e\[[0-9;]*m/', '', $output));
        return $output !== '' ? substr($output, -3500) : 'Удаленное применение завершилось без подтверждения';
    }
}
