<?php

final class ClientAllowedIpsPolicy
{
    public const MODE_FULL = 'full';
    public const MODE_LOCAL_BYPASS = 'local_bypass';

    private const SETTINGS_NAMESPACE = 'vpn';
    private const SETTINGS_KEY = 'client_allowed_ips';
    private const DEFAULT_SERVER_NAMES = ['spb1', 'mos1'];
    private const WIREGUARD_PROTOCOLS = [
        'wireguard-standard',
        'amnezia-wg',
        'amnezia-wg-advanced',
        'awg2',
    ];

    private const IPV4_EXCLUSIONS = [
        '10.0.0.0/8',
        '100.64.0.0/10',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '224.0.0.0/4',
        '78.138.138.156/30',
        '91.245.39.232/29',
        '178.205.241.240/29',
        '178.205.139.156/32',
        '178.207.157.126/32',
    ];

    private const LEGACY_SERVER_ENDPOINTS = [
        '91.206.92.82/32',
        '185.211.103.32/32',
        '194.87.69.189/32',
        '195.133.67.55/32',
        '176.32.37.19/32',
        '185.211.103.180/32',
    ];

    private const IPV6_ALLOWED = [
        '::/1',
        '8000::/2',
        'c000::/3',
        'e000::/4',
        'f000::/5',
        'f800::/6',
        'fe00::/9',
        'fec0::/10',
    ];

    private static ?array $cachedSettings = null;

    public static function supports(int $serverId, string $protocolSlug): bool
    {
        return self::isServerEnabled($serverId)
            && in_array(strtolower(trim($protocolSlug)), self::WIREGUARD_PROTOCOLS, true);
    }

    public static function isServerEnabled(int $serverId): bool
    {
        return $serverId > 0 && in_array($serverId, self::get()['server_ids'], true);
    }

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_LOCAL_BYPASS ? self::MODE_LOCAL_BYPASS : self::MODE_FULL;
    }

    public static function allowedIps(string $mode): string
    {
        if (self::normalizeMode($mode) === self::MODE_FULL) {
            return '0.0.0.0/0, ::/0';
        }

        return self::get()['allowed_ips'];
    }

    public static function allowedIpsForEndpoint(string $mode, string $endpoint): string
    {
        $allowedIps = self::allowedIps($mode);
        if (self::normalizeMode($mode) !== self::MODE_LOCAL_BYPASS) {
            return $allowedIps;
        }

        $packed = @inet_pton(trim($endpoint));
        if ($packed === false || strlen($packed) !== 4) {
            return $allowedIps;
        }

        $excluded = ['start' => unpack('N', $packed)[1], 'prefix' => 32];
        $entries = [];
        foreach (array_map('trim', explode(',', $allowedIps)) as $entry) {
            if ($entry === '' || strpos($entry, ':') !== false) {
                if ($entry !== '') {
                    $entries[] = $entry;
                }
                continue;
            }

            $network = self::parseIpv4Cidr($entry);
            foreach (self::excludeNetwork($network, $excluded) as $remaining) {
                $entries[] = long2ip($remaining['start']) . '/' . $remaining['prefix'];
            }
        }

        return implode(', ', $entries);
    }

    public static function get(): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $settings = self::defaults();
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare(
                'SELECT value FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([self::SETTINGS_NAMESPACE, self::SETTINGS_KEY]);
            $value = $stmt->fetchColumn();
            if ($value) {
                $stored = json_decode((string) $value, true);
                if (is_array($stored)) {
                    $storedText = (string) ($stored['allowed_ips_text'] ?? $settings['allowed_ips_text']);
                    if (
                        (int) ($stored['endpoint_policy_version'] ?? 1) < 2
                        && trim($storedText) === trim(self::legacyDefaultAllowedIpsText())
                    ) {
                        $storedText = self::defaultAllowedIpsText();
                    }
                    $settings['allowed_ips_text'] = $storedText;
                    $settings['server_ids'] = self::sanitizeServerIds($stored['server_ids'] ?? []);
                }
            }
        } catch (Throwable $e) {
            // Defaults keep config generation available during install and tests.
        }

        try {
            $settings['allowed_ips'] = self::canonicalizeAllowedIpsText($settings['allowed_ips_text']);
        } catch (Throwable $e) {
            $settings['allowed_ips_text'] = self::defaultAllowedIpsText();
            $settings['allowed_ips'] = self::defaultAllowedIps();
        }

        self::$cachedSettings = $settings;
        return self::$cachedSettings;
    }

    public static function saveFromInput(array $input): array
    {
        $text = trim((string) ($input['allowed_ips_text'] ?? ''));
        $allowedIps = self::canonicalizeAllowedIpsText($text);
        if (strlen($allowedIps) > 50000) {
            throw new InvalidArgumentException('Список AllowedIPs слишком большой. Максимальный размер после обработки: 50 000 символов.');
        }

        $serverIds = self::sanitizeServerIds($input['server_ids'] ?? []);
        $validServerIds = [];
        if ($serverIds !== []) {
            $pdo = DB::conn();
            $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
            $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE id IN (' . $placeholders . ')');
            $stmt->execute($serverIds);
            $validServerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            sort($validServerIds);
        }

        $stored = [
            'allowed_ips_text' => $text,
            'server_ids' => $validServerIds,
            'endpoint_policy_version' => 2,
        ];
        self::saveRaw($stored);
        self::$cachedSettings = [
            'allowed_ips_text' => $text,
            'allowed_ips' => $allowedIps,
            'server_ids' => $validServerIds,
        ];

        return self::$cachedSettings;
    }

    public static function canonicalizeAllowedIpsText(string $text): string
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(?:--|#|\/\/)/', $line)) {
                continue;
            }
            $line = preg_replace('/\s+(?:--|#|\/\/).*$/', '', $line) ?? $line;
            foreach (preg_split('/[\s,]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                $canonical = self::canonicalCidr($entry);
                $entries[$canonical] = true;
            }
        }

        if ($entries === []) {
            throw new InvalidArgumentException('Добавьте хотя бы один корректный CIDR в AllowedIPs.');
        }

        return implode(', ', array_keys($entries));
    }

    public static function defaultAllowedIpsText(): string
    {
        return implode("\n", array_merge(self::buildIpv4Complement(), self::IPV6_ALLOWED));
    }

    private static function legacyDefaultAllowedIpsText(): string
    {
        return implode("\n", array_merge(
            self::buildIpv4Complement(array_merge(self::IPV4_EXCLUSIONS, self::LEGACY_SERVER_ENDPOINTS)),
            self::IPV6_ALLOWED
        ));
    }

    private static function defaults(): array
    {
        return [
            'allowed_ips_text' => self::defaultAllowedIpsText(),
            'allowed_ips' => self::defaultAllowedIps(),
            'server_ids' => self::defaultServerIds(),
        ];
    }

    private static function defaultAllowedIps(): string
    {
        return implode(', ', array_merge(self::buildIpv4Complement(), self::IPV6_ALLOWED));
    }

    private static function defaultServerIds(): array
    {
        try {
            $pdo = DB::conn();
            $placeholders = implode(',', array_fill(0, count(self::DEFAULT_SERVER_NAMES), '?'));
            $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE LOWER(name) IN (' . $placeholders . ') ORDER BY id');
            $stmt->execute(self::DEFAULT_SERVER_NAMES);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function sanitizeServerIds($serverIds): array
    {
        if (!is_array($serverIds)) {
            return [];
        }
        $serverIds = array_values(array_unique(array_filter(array_map('intval', $serverIds), static fn(int $id): bool => $id > 0)));
        sort($serverIds);
        return $serverIds;
    }

    private static function canonicalCidr(string $cidr): string
    {
        if (!preg_match('/^(.+)\/(\d{1,3})$/', trim($cidr), $match)) {
            throw new InvalidArgumentException('Некорректная запись AllowedIPs: ' . $cidr);
        }

        $packed = @inet_pton($match[1]);
        if ($packed === false) {
            throw new InvalidArgumentException('Некорректный IP-адрес в AllowedIPs: ' . $cidr);
        }
        $bits = strlen($packed) === 4 ? 32 : 128;
        $prefix = (int) $match[2];
        if ($prefix < 0 || $prefix > $bits) {
            throw new InvalidArgumentException('Некорректная маска в AllowedIPs: ' . $cidr);
        }

        $bytes = array_values(unpack('C*', $packed));
        $remaining = $prefix;
        foreach ($bytes as &$byte) {
            if ($remaining >= 8) {
                $remaining -= 8;
                continue;
            }
            if ($remaining <= 0) {
                $byte = 0;
                continue;
            }
            $byte &= (0xff << (8 - $remaining)) & 0xff;
            $remaining = 0;
        }
        unset($byte);

        $network = inet_ntop(pack('C*', ...$bytes));
        if ($network === false) {
            throw new InvalidArgumentException('Не удалось нормализовать AllowedIPs: ' . $cidr);
        }

        return strtolower($network) . '/' . $prefix;
    }

    private static function saveRaw(array $settings): void
    {
        $pdo = DB::conn();
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare(
            'SELECT id FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([self::SETTINGS_NAMESPACE, self::SETTINGS_KEY]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $update = $pdo->prepare('UPDATE settings SET value = CAST(? AS JSON), updated_at = NOW() WHERE id = ?');
            $update->execute([$json, $id]);
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO settings (user_id, namespace, `key`, value) VALUES (NULL, ?, ?, CAST(? AS JSON))'
        );
        $insert->execute([self::SETTINGS_NAMESPACE, self::SETTINGS_KEY, $json]);
    }

    private static function buildIpv4Complement(?array $exclusions = null): array
    {
        $networks = [['start' => 0, 'prefix' => 0]];
        foreach ($exclusions ?? self::IPV4_EXCLUSIONS as $cidr) {
            $excluded = self::parseIpv4Cidr($cidr);
            $next = [];
            foreach ($networks as $network) {
                foreach (self::excludeNetwork($network, $excluded) as $remaining) {
                    $next[] = $remaining;
                }
            }
            $networks = $next;
        }

        usort($networks, static function (array $left, array $right): int {
            return $left['start'] <=> $right['start'] ?: $left['prefix'] <=> $right['prefix'];
        });

        return array_map(static function (array $network): string {
            return long2ip($network['start']) . '/' . $network['prefix'];
        }, $networks);
    }

    private static function parseIpv4Cidr(string $cidr): array
    {
        [$address, $prefixText] = explode('/', $cidr, 2);
        $packed = inet_pton($address);
        $prefix = (int) $prefixText;
        if ($packed === false || strlen($packed) !== 4 || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('Invalid IPv4 CIDR in client AllowedIPs policy: ' . $cidr);
        }

        $start = unpack('N', $packed)[1];
        $size = 1 << (32 - $prefix);
        $start = intdiv($start, $size) * $size;

        return ['start' => $start, 'prefix' => $prefix];
    }

    private static function excludeNetwork(array $network, array $excluded): array
    {
        $networkSize = 1 << (32 - $network['prefix']);
        $excludedSize = 1 << (32 - $excluded['prefix']);
        $networkEnd = $network['start'] + $networkSize;
        $excludedEnd = $excluded['start'] + $excludedSize;

        if ($excluded['start'] >= $networkEnd || $excludedEnd <= $network['start']) {
            return [$network];
        }
        if ($excluded['start'] <= $network['start'] && $excludedEnd >= $networkEnd) {
            return [];
        }

        $childPrefix = $network['prefix'] + 1;
        $childSize = 1 << (32 - $childPrefix);
        $left = ['start' => $network['start'], 'prefix' => $childPrefix];
        $right = ['start' => $network['start'] + $childSize, 'prefix' => $childPrefix];

        return array_merge(
            self::excludeNetwork($left, $excluded),
            self::excludeNetwork($right, $excluded)
        );
    }
}
