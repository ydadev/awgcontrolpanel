from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"Could not find patch target: {label}")
    return text.replace(old, new, 1)


def trim_trailing_whitespace(text: str) -> str:
    return "\n".join(line.rstrip() for line in text.splitlines()) + "\n"


public_path = Path("public/index.php")
public = public_path.read_text(encoding="utf-8")

if "raw_wireguard_config" not in public:
    public = replace_once(
        public,
        "        $vpnUrlConfig = '';\n        $isAwg2 = false;",
        "        $vpnUrlConfig = '';\n        $rawWireguardConfig = '';\n        $rawWireguardQrCode = '';\n        $rawWireguardTitle = '';\n        $rawWireguardHint = '';\n        $isAwg2 = false;",
        "client view raw config variables",
    )
    public = replace_once(
        public,
        "            $protocol = null;\n            if (!empty($clientData['protocol_id'])) {",
        "            $protocol = null;\n            $protocolSlug = '';\n            if (!empty($clientData['protocol_id'])) {",
        "client view protocol slug initialization",
    )
    public = replace_once(
        public,
        "            if ($protocol) {\n                $clientData['show_text_content'] = !empty($protocol['show_text_content']);\n                $protocolSlug = $protocol['slug'] ?? '';\n                $isAwg2 = ($protocolSlug === 'awg2');\n            }\n            if ($protocol && ($protocol['output_template'] ?? '') !== '') {",
        "            if ($protocol) {\n                $clientData['show_text_content'] = !empty($protocol['show_text_content']);\n                $protocolSlug = $protocol['slug'] ?? '';\n                $isAwg2 = ($protocolSlug === 'awg2');\n            }\n            $isWireguardFamily = in_array($protocolSlug, ['amnezia-wg-advanced', 'wireguard-standard', 'amnezia-wg', 'awg2'], true);\n            if ($protocol && ($protocol['output_template'] ?? '') !== '') {",
        "client view wireguard family detection",
    )
    public = replace_once(
        public,
        "            'vpn_url_config' => $vpnUrlConfig,\n            'is_awg2' => $isAwg2",
        "            'vpn_url_config' => $vpnUrlConfig,\n            'raw_wireguard_config' => $rawWireguardConfig,\n            'raw_wireguard_qr_code' => $rawWireguardQrCode,\n            'raw_wireguard_title' => $rawWireguardTitle,\n            'raw_wireguard_hint' => $rawWireguardHint,\n            'is_awg2' => $isAwg2",
        "client view template variables",
    )

replacement = """            // Generate second QR code and vpn:// config for AWG2
            if ($isAwg2 && !empty($clientData['config'])) {
                try {
                    $qrCodeVpnUrl = VpnClient::generateQRCodeVpnUrl($clientData['config'], 'awg2');

                    // Generate vpn:// URL string using vpn:// format (JSON + zlib)
                    require_once __DIR__ . '/../inc/QrUtil.php';
                    $vpnUrlConfig = 'vpn://' . QrUtil::encodeVpnUrlConf($clientData['config'], 'awg2');
                } catch (Exception $e) {
                    // Ignore errors, just don't show the second QR
                }
            }

            if ($isWireguardFamily && !empty($clientData['config'])) {
                $rawWireguardConfig = (string) $clientData['config'];
                if ($protocolSlug === 'wireguard-standard') {
                    $rawWireguardTitle = 'WireGuard Config';
                    $rawWireguardHint = 'Scan with a WireGuard-compatible app or copy the config below';
                } else {
                    $rawWireguardTitle = 'AmneziaWG Config';
                    $rawWireguardHint = 'Scan with AmneziaWG app or copy the config below';
                }
                try {
                    require_once __DIR__ . '/../inc/QrUtil.php';
                    $rawWireguardQrCode = QrUtil::pngBase64($rawWireguardConfig, 300, 1, 'WireGuard config');
                } catch (Throwable $e) {
                    $rawWireguardQrCode = '';
                }
            }
        } catch (Exception $e) {
            $protocolOutput = '';
        }
        View::render('clients/view.twig', ["""

pattern = re.compile(
    r"^            // Generate second QR code and vpn:// config for AWG2\n.*?^        View::render\('clients/view\.twig', \[",
    re.MULTILINE | re.DOTALL,
)
public, count = pattern.subn(replacement, public, count=1)
if count != 1:
    raise SystemExit("Could not repair full client view render block")

public_path.write_text(trim_trailing_whitespace(public), encoding="utf-8")


template_path = Path("templates/clients/view.twig")
template = template_path.read_text(encoding="utf-8")

if "raw_wireguard_qr_code" not in template:
    raw_template = """  {% if raw_wireguard_qr_code %}
  <div class="bg-white rounded shadow p-6 text-center mt-6">
    <h3 class="font-bold mb-4">QR Code ({{ raw_wireguard_title }})</h3>
    <img src="{{ raw_wireguard_qr_code }}" alt="{{ raw_wireguard_title }} QR Code" class="mx-auto" style="max-width: 300px; width: 100%; height: auto;">
    <p class="text-sm text-gray-600 mt-2">{{ raw_wireguard_hint }}</p>
  </div>
  {% endif %}

  {% if raw_wireguard_config %}
  <div class="bg-white rounded shadow p-6 mt-6">
    <h3 class="font-bold mb-4">{{ raw_wireguard_title }} Text Configuration</h3>
    <pre class="mb-0 bg-gray-100 p-4 rounded text-left" style="white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word;">{{ raw_wireguard_config }}</pre>
  </div>
  {% endif %}

"""
    template = replace_once(
        template,
        "  {% if protocol_output and client.show_text_content %}",
        raw_template + "  {% if protocol_output and client.show_text_content %}",
        "client view raw config template block",
    )
    template_path.write_text(trim_trailing_whitespace(template), encoding="utf-8")

print("Raw WireGuard/AmneziaWG client config patch applied or repaired.")
