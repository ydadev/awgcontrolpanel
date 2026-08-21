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
