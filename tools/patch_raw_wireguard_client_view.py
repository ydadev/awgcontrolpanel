from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"Could not find patch target: {label}")
    return text.replace(old, new, 1)


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

    awg2_start = public.find("            if ($isAwg2 && !empty($clientData['config'])) {")
    if awg2_start == -1:
        raise SystemExit("Could not find patch target: AWG2 vpn URL block start")
    outer_catch = public.find("        } catch (Exception $e) {", awg2_start)
    if outer_catch == -1:
        raise SystemExit("Could not find patch target: client view outer catch after AWG2 block")
    awg2_block = public[awg2_start:outer_catch]
    raw_block = awg2_block + """
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
"""
    public = public[:awg2_start] + raw_block + public[outer_catch:]
    public = replace_once(
        public,
        "            'vpn_url_config' => $vpnUrlConfig,\n            'is_awg2' => $isAwg2",
        "            'vpn_url_config' => $vpnUrlConfig,\n            'raw_wireguard_config' => $rawWireguardConfig,\n            'raw_wireguard_qr_code' => $rawWireguardQrCode,\n            'raw_wireguard_title' => $rawWireguardTitle,\n            'raw_wireguard_hint' => $rawWireguardHint,\n            'is_awg2' => $isAwg2",
        "client view template variables",
    )
    public_path.write_text(public, encoding="utf-8")


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
    template_path.write_text(template, encoding="utf-8")

print("Raw WireGuard/AmneziaWG client config patch applied or already present.")
