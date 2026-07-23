<?php

require_once __DIR__ . '/../inc/Branding.php';

$sanitize = new ReflectionMethod(Branding::class, 'sanitizeSvg');
$sanitize->setAccessible(true);

$safe = <<<'SVG'
<?xml version="1.0"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">
  <path fill="#ffcc00" d="M0 0h10v10H0z"/>
</svg>
SVG;

$sanitized = $sanitize->invoke(null, $safe);
if (stripos($sanitized, '<!DOCTYPE') !== false || stripos($sanitized, '<svg') === false) {
    fwrite(STDERR, "Safe SVG was not sanitized correctly\n");
    exit(1);
}

if (!empty($argv[1])) {
    $actual = @file_get_contents($argv[1]);
    if (!is_string($actual) || $actual === '') {
        fwrite(STDERR, "Cannot read SVG fixture: {$argv[1]}\n");
        exit(1);
    }
    $actualSanitized = $sanitize->invoke(null, $actual);
    if (stripos($actualSanitized, '<!DOCTYPE') !== false || stripos($actualSanitized, '<svg') === false) {
        fwrite(STDERR, "SVG fixture was not sanitized correctly\n");
        exit(1);
    }
}

$unsafe = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
try {
    $sanitize->invoke(null, $unsafe);
    fwrite(STDERR, "Unsafe SVG was accepted\n");
    exit(1);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $cause = $e instanceof ReflectionException ? $e : ($e->getPrevious() ?: $e);
    if (strpos($cause->getMessage(), 'unsafe content') === false) {
        throw $e;
    }
}

echo "branding_svg_test: ok\n";
