<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/QrUtil.php';

$dataUri = QrUtil::pngBase64('AWG Control Panel QR resolution test');
if (!preg_match('#^data:image/png;base64,(.+)$#s', $dataUri, $match)) {
    fwrite(STDERR, "QR renderer did not return PNG data\n");
    exit(1);
}

$png = base64_decode($match[1], true);
$size = is_string($png) ? getimagesizefromstring($png) : false;
if (!is_array($size) || (int) $size[0] < 1000 || (int) $size[1] < 1000) {
    fwrite(STDERR, 'QR image resolution is too small: ' . json_encode($size) . "\n");
    exit(1);
}

echo 'qr_rendering_size_test: ok (' . (int) $size[0] . 'x' . (int) $size[1] . ")\n";

$mediumPayload = '';
for ($i = 0; strlen($mediumPayload) < 2150; $i++) {
    $mediumPayload .= hash('sha256', 'awg-medium-qr-' . $i);
}
$mediumPayload = substr($mediumPayload, 0, 2150);
$mediumDataUri = QrUtil::pngBase64($mediumPayload);
if (!preg_match('#^data:image/png;base64,(.+)$#s', $mediumDataUri, $mediumMatch)) {
    fwrite(STDERR, "Medium-level QR did not return PNG data\n");
    exit(1);
}
$mediumPng = base64_decode($mediumMatch[1], true);
$mediumSize = is_string($mediumPng) ? getimagesizefromstring($mediumPng) : false;
if (!is_array($mediumSize) || (int) $mediumSize[0] < 1000 || (int) $mediumSize[1] < 1000) {
    fwrite(STDERR, 'Medium-level QR image is invalid: ' . json_encode($mediumSize) . "\n");
    exit(1);
}

$qrSource = file_get_contents(__DIR__ . '/../inc/QrUtil.php');
if (!is_string($qrSource)
    || strpos($qrSource, 'ErrorCorrectionLevel::Medium') === false
    || strpos($qrSource, 'ErrorCorrectionLevel::Low') !== false) {
    fwrite(STDERR, "QR generator must use only medium error correction\n");
    exit(1);
}

echo 'qr_medium_payload_test: ok (' . strlen($mediumPayload) . " bytes)\n";
