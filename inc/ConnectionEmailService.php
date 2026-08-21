<?php

class ConnectionEmailService {
    private const WIREGUARD_PROTOCOLS = [
        'amnezia-wg-advanced',
        'wireguard-standard',
        'amnezia-wg',
        'awg2',
    ];

    public static function send(int $clientId, array $owner): void {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        if (!$clientData) {
            throw new RuntimeException('Подключение не найдено');
        }

        $config = $client->getConfig();
        if ($config === '') {
            throw new RuntimeException('Конфигурация подключения пуста');
        }

        $server = new VpnServer((int) $clientData['server_id']);
        $serverData = $server->getData();
        $protocol = self::findProtocol($clientData, $serverData);
        $protocolSlug = (string) ($protocol['slug'] ?? ($serverData['install_protocol'] ?? ''));
        $protocolName = (string) ($protocol['name'] ?? $protocolSlug);

        $qrCodes = [];
        $simpleQr = VpnClient::generateQRCode($config, $protocolSlug);
        if ($simpleQr === '') {
            $simpleQr = (string) ($clientData['qr_code'] ?? '');
        }
        if ($simpleQr !== '') {
            $qrCodes[] = ['label' => 'Amnezia VPN (Simple)', 'data_uri' => $simpleQr];
        }

        if ($protocolSlug === 'awg2') {
            $vpnUrlQr = VpnClient::generateQRCodeVpnUrl($config, $protocolSlug);
            if ($vpnUrlQr !== '') {
                $qrCodes[] = ['label' => 'Amnezia VPN (vpn:// URL)', 'data_uri' => $vpnUrlQr];
            }
        }

        if (in_array($protocolSlug, self::WIREGUARD_PROTOCOLS, true)) {
            try {
                require_once __DIR__ . '/QrUtil.php';
                $rawQr = QrUtil::pngBase64(
                    $config,
                    QrUtil::DEFAULT_SIZE,
                    QrUtil::DEFAULT_MARGIN,
                    'WireGuard config'
                );
                $qrCodes[] = [
                    'label' => $protocolSlug === 'wireguard-standard' ? 'WireGuard' : 'AmneziaWG',
                    'data_uri' => $rawQr,
                ];
            } catch (Throwable $e) {
                error_log('Failed to generate raw connection QR code for email: ' . $e->getMessage());
            }
        }

        EmailTwoFactorMailer::sendConnection(
            EmailTwoFactorSettings::runtime(),
            $owner,
            [
                'name' => (string) $clientData['name'],
                'config' => $config,
                'client_ip' => (string) ($clientData['client_ip'] ?? ''),
                'server_name' => (string) ($serverData['name'] ?? ''),
                'protocol_name' => $protocolName,
                'qr_codes' => $qrCodes,
            ]
        );
    }

    private static function findProtocol(array $clientData, array $serverData): array {
        $pdo = DB::conn();
        if (!empty($clientData['protocol_id'])) {
            $stmt = $pdo->prepare('SELECT slug, name FROM protocols WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $clientData['protocol_id']]);
        } else {
            $stmt = $pdo->prepare('SELECT slug, name FROM protocols WHERE slug = ? LIMIT 1');
            $stmt->execute([(string) ($serverData['install_protocol'] ?? '')]);
        }
        $protocol = $stmt->fetch();
        return is_array($protocol) ? $protocol : [];
    }
}
