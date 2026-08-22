<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

function twigSyntaxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$templatesRoot = realpath(__DIR__ . '/../templates');
twigSyntaxAssert(is_string($templatesRoot), 'Templates directory does not exist');

$twig = new Environment(new FilesystemLoader($templatesRoot), [
    'cache' => false,
    'autoescape' => 'html',
]);
$twig->addFunction(new TwigFunction('t', static fn(string $key, array $params = []): string => $key));
$twig->addFunction(new TwigFunction('getFlag', static fn(string $code): string => $code));
$twig->addFilter(new TwigFilter('trans', static fn(string $key, array $params = []): string => $key));
$twig->addFilter(new TwigFilter('bytes_format', static fn($bytes): string => (string) $bytes));

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesRoot));
$count = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'twig') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($templatesRoot) + 1));
    try {
        $twig->load($relative);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: Twig syntax error in {$relative}: {$e->getMessage()}\n");
        exit(1);
    }
    $count++;
}

twigSyntaxAssert($count > 0, 'No Twig templates were checked');
echo "Twig syntax tests passed ({$count} templates)\n";
