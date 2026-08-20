<?php

$template = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
if (!is_string($template) || $template === '') {
    fwrite(STDERR, "Cannot read server view template\n");
    exit(1);
}

$requiredFragments = [
    '.connections-panel {',
    'width: calc(100vw - 2rem);',
    'max-width: calc(100vw - 4rem);',
    '.connections-table th,',
    '<div class="connections-panel bg-white rounded shadow">',
    '<table class="connections-table w-full" style="min-width: 1240px;">',
    'flex flex-col gap-3 sm:flex-row',
];

foreach ($requiredFragments as $fragment) {
    if (strpos($template, $fragment) === false) {
        fwrite(STDERR, "Missing responsive connections layout: {$fragment}\n");
        exit(1);
    }
}

if (strpos($template, 'style="min-width: 1240px;"') === false) {
    fwrite(STDERR, "Mobile table overflow fallback is missing\n");
    exit(1);
}

echo "server_connections_layout_test: ok\n";
