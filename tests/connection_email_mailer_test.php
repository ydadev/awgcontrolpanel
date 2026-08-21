<?php

require_once __DIR__ . '/../inc/EmailTwoFactorMailer.php';

$png = 'data:image/png;base64,' . base64_encode('fake-png-data');
$message = EmailTwoFactorMailer::buildConnectionMessage(
    ['email' => 'owner@example.test', 'name' => '<Владелец>'],
    [
        'name' => 'work_laptop',
        'config' => "[Interface]\nAddress = 10.0.0.2/32\n",
        'server_name' => '<spb1>',
        'protocol_name' => 'AmneziaWG 2.0',
        'client_ip' => '10.9.2.10',
        'qr_codes' => [
            ['label' => '<AmneziaWG>', 'data_uri' => $png],
            ['label' => 'broken', 'data_uri' => 'not-a-data-uri'],
        ],
    ]
);

$checks = [
    $message['subject'] === 'Подключение к ЗСПД',
    $message['recipient'] === 'owner@example.test',
    $message['attachments'][0]['name'] === 'work_laptop.conf',
    $message['attachments'][0]['content'] === "[Interface]\nAddress = 10.0.0.2/32\n",
    count($message['inline_images']) === 1,
    str_contains($message['html'], '<h1 style="font-size:24px;margin:0 0 20px">Подключение к ЗСПД</h1>'),
    str_contains($message['html'], '&lt;Владелец&gt;'),
    str_contains($message['html'], '&lt;spb1&gt;'),
    str_contains($message['html'], '&lt;AmneziaWG&gt;'),
    str_contains($message['html'], 'width="760"'),
    str_contains($message['html'], 'width:100%;max-width:760px'),
    !str_contains($message['html'], 'display:inline-block'),
    !str_contains($message['html'], '<Владелец>'),
    !str_contains($message['html'], 'Address = 10.0.0.2/32'),
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Connection email assertion failed: {$index}\n");
        exit(1);
    }
}

$template = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
$route = file_get_contents(__DIR__ . '/../public/index.php');
$service = file_get_contents(__DIR__ . '/../inc/ConnectionEmailService.php');
if (!is_string($template) || !is_string($route) || !is_string($service)) {
    fwrite(STDERR, "Cannot read connection creation sources\n");
    exit(1);
}
if (strpos($template, 'name="send_email"') === false || strpos($template, 'Отправить на почту') === false) {
    fwrite(STDERR, "Send email checkbox is missing\n");
    exit(1);
}
if (strpos($route, 'ConnectionEmailService::send($clientId, $connectionOwner)') === false) {
    fwrite(STDERR, "Connection email delivery is not wired to creation\n");
    exit(1);
}
foreach (['Amnezia VPN (Simple)', 'Amnezia VPN (vpn:// URL)', "'WireGuard' : 'AmneziaWG'"] as $qrVariant) {
    if (strpos($service, $qrVariant) === false) {
        fwrite(STDERR, "Connection QR variant is missing: {$qrVariant}\n");
        exit(1);
    }
}

$clientView = file_get_contents(__DIR__ . '/../templates/clients/view.twig');
$qrUtil = file_get_contents(__DIR__ . '/../inc/QrUtil.php');
if (
    !is_string($clientView)
    || strpos($clientView, 'openQrModal(this)') === false
    || strpos($clientView, 'width: min(100%, 720px)') === false
    || !is_string($qrUtil)
    || strpos($qrUtil, 'DEFAULT_SIZE = 1200') === false
) {
    fwrite(STDERR, "Large clickable QR rendering is missing\n");
    exit(1);
}

echo "connection_email_mailer_test: ok\n";
