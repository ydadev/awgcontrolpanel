<?php

final class WireGuardStats
{
    public static function buildDumpCommand(string $protocolSlug, string $containerName): string
    {
        if ($protocolSlug === 'wireguard-standard') {
            return 'wg show all dump 2>/dev/null';
        }

        $containerName = trim($containerName);
        if ($containerName === '') {
            return '';
        }

        $containerArg = escapeshellarg($containerName);
        if ($protocolSlug === 'awg2') {
            $inner = 'tool=$(command -v awg || command -v wg || true); '
                . '[ -n "$tool" ] && "$tool" show all dump 2>/dev/null';
            return 'docker exec ' . $containerArg . ' sh -c ' . escapeshellarg($inner);
        }

        return 'docker exec ' . $containerArg . ' wg show all dump 2>/dev/null';
    }

    public static function parsePeerDump(string $output, string $publicKey): ?array
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '' || trim($output) === '') {
            return null;
        }

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = strpos($line, "\t") !== false
                ? explode("\t", trim($line))
                : preg_split('/\s+/', trim($line));

            // `show all dump` prefixes peer rows with the interface name. Accept the
            // interface-specific dump format too, so the parser remains backward compatible.
            if (count($parts) >= 9) {
                $offset = 1;
            } elseif (count($parts) >= 8) {
                $offset = 0;
            } else {
                continue;
            }

            if (($parts[$offset] ?? '') !== $publicKey) {
                continue;
            }

            return [
                'last_handshake' => (int) ($parts[$offset + 4] ?? 0),
                'bytes_sent' => (int) ($parts[$offset + 5] ?? 0),
                'bytes_received' => (int) ($parts[$offset + 6] ?? 0),
            ];
        }

        return null;
    }
}
