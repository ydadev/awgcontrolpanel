from pathlib import Path

path = Path('inc/VpnClient.php')
text = path.read_text()

if "Fix client key generation on test installs" in text:
    raise SystemExit('unexpected marker found')

fn_start = text.index('    private static function generateClientKeys')
key_start = text.index("        $containerName = $serverData['container_name'];", fn_start)
key_end = text.index('        $parts = explode("---", trim($out));', key_start)
new_keygen_body = '''        $containerName = (string) ($serverData['container_name'] ?? '');
        $protocolSlug = (string) ($serverData['install_protocol'] ?? '');
        $isAwg2 = (stripos($containerName, 'awg2') !== false || $protocolSlug === 'awg2');
        $wgTool = $isAwg2 ? 'awg' : 'wg';

        $keygenScript = sprintf(
            'set -e; umask 077; priv=$(%s genkey | tr -d "\\r\\n"); [ -n "$priv" ] || { echo empty_private_key; exit 1; }; pub=$(printf "%%s\\n" "$priv" | %s pubkey | tr -d "\\r\\n"); [ -n "$pub" ] || { echo empty_public_key; exit 1; }; printf "%%s\\n---\\n%%s\\n" "$priv" "$pub"',
            $wgTool,
            $wgTool
        );
        if ($protocolSlug === 'wireguard-standard') {
            $cmd = 'sh -lc ' . escapeshellarg($keygenScript);
        } else {
            $cmd = sprintf(
                'docker exec -i %s sh -lc %s',
                escapeshellarg($containerName),
                escapeshellarg($keygenScript)
            );
        }

        $server = new VpnServer((int) ($serverData['id'] ?? 0));
        $out = (string) $server->executeCommand($cmd, true);
'''
text = text[:key_start] + new_keygen_body + text[key_end:]

add_start = text.index('    public static function addClientToServer')
add_head_start = text.index("        $containerName = $serverData['container_name'];", add_start)
add_head_end = text.index('        // AWG2: try awg0.conf', add_head_start)
new_add_head = '''        $containerName = (string) ($serverData['container_name'] ?? '');
        $protocolSlug = (string) ($serverData['install_protocol'] ?? '');
        $isAwg2 = (stripos($containerName, 'awg2') !== false || $protocolSlug === 'awg2');
        $configDir = '/opt/amnezia/awg';
        $presharedKey = (string) ($serverData['preshared_key'] ?? '');

        if ($protocolSlug === 'wireguard-standard') {
            $publicKey = trim($publicKey);
            if ($publicKey === '') {
                throw new Exception('Refusing to add client with empty public key');
            }
            if ($presharedKey === '') {
                throw new Exception('Refusing to add WireGuard client without preshared key');
            }

            $peerBlock = "\\n[Peer]\\n";
            $peerBlock .= "PublicKey = {$publicKey}\\n";
            $peerBlock .= "PresharedKey = {$presharedKey}\\n";
            $peerBlock .= "AllowedIPs = {$clientIP}/32\\n";

            $script = "set -e\\n";
            $script .= "tmp=\\$(mktemp)\\n";
            $script .= "printf '%s\\n' " . escapeshellarg($presharedKey) . " > \\\"\\$tmp\\\"\\n";
            $script .= "wg set wg0 peer " . escapeshellarg($publicKey) . " preshared-key \\\"\\$tmp\\\" allowed-ips " . escapeshellarg($clientIP . '/32') . "\\n";
            $script .= "rm -f \\\"\\$tmp\\\"\\n";
            $script .= "cat >> /etc/wireguard/wg0.conf <<'EOF'\\n" . $peerBlock . "EOF\\n";

            $server = new VpnServer((int) ($serverData['id'] ?? 0));
            $server->executeCommand('sh -lc ' . escapeshellarg($script), true);
            return;
        }

'''
text = text[:add_head_start] + new_add_head + text[add_head_end:]
old_psk_line = "        $presharedKey = $serverData['preshared_key'];\n        $publicKey = trim($publicKey);"
if old_psk_line not in text:
    raise SystemExit('old PSK line not found')
text = text.replace(old_psk_line, "        $publicKey = trim($publicKey);", 1)

path.write_text(text)
print('patched inc/VpnClient.php')
