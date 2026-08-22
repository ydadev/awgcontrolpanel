<?php

require_once __DIR__ . '/../vendor/autoload.php';

function failClientActionAuthorizationTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$router = file_get_contents(__DIR__ . '/../public/index.php');
$clientView = file_get_contents(__DIR__ . '/../templates/clients/view.twig');
$serverView = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
if (!is_string($router) || !is_string($clientView) || !is_string($serverView)) {
    failClientActionAuthorizationTest('Client action sources could not be read');
}

foreach ([
    "Router::post('/clients/{id}/revoke'",
    "Router::post('/clients/{id}/restore'",
    "Router::post('/clients/{id}/delete'",
] as $route) {
    $start = strpos($router, $route);
    $block = $start === false ? '' : substr($router, $start, 1050);
    foreach (['requireAuth();', 'requireValidCsrfToken()', 'userCanMutateClient($user, $clientData)', 'http_response_code(403)'] as $required) {
        if (!str_contains($block, $required)) {
            failClientActionAuthorizationTest('HTML client action is missing protection: ' . $route . ' / ' . $required);
        }
    }
}

foreach ([
    "Router::post('/api/clients/{id}/revoke'",
    "Router::post('/api/clients/{id}/restore'",
    "Router::delete('/api/clients/{id}/delete'",
] as $route) {
    $start = strpos($router, $route);
    $block = $start === false ? '' : substr($router, $start, 1150);
    foreach (['JWT::requireAuth()', 'userCanMutateClient($user, $clientData)', 'http_response_code(403)'] as $required) {
        if (!str_contains($block, $required)) {
            failClientActionAuthorizationTest('API client action is missing protection: ' . $route . ' / ' . $required);
        }
    }
}

foreach (['$isSelf', "fetchColumn() !== 'user'", 'UserServerAccess::canCreateClients', 'UserServerAccess::canViewServer'] as $required) {
    if (!str_contains($router, $required)) {
        failClientActionAuthorizationTest('Client ownership policy omits: ' . $required);
    }
}

foreach (['/clients/{{ client.id }}/revoke', '/clients/{{ client.id }}/restore', '/clients/{{ client.id }}/delete', 'name="csrf_token" value="{{ csrf_token }}"', 'Удалить это подключение без возможности восстановления?'] as $required) {
    if (!str_contains($clientView, $required)) {
        failClientActionAuthorizationTest('Client page omits: ' . $required);
    }
}
if (substr_count($serverView, 'name="csrf_token" value="{{ csrf_token }}"') < 4) {
    failClientActionAuthorizationTest('Server page client action forms are missing CSRF tokens');
}

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'));
$twig->addFunction(new Twig\TwigFunction('t', static fn (string $key, array $params = []) => $key));
$twig->addFunction(new Twig\TwigFunction('getFlag', static fn (string $code) => $code));
foreach (['clients/view.twig', 'servers/view.twig'] as $templateName) {
    $source = new Twig\Source(file_get_contents(__DIR__ . '/../templates/' . $templateName), $templateName);
    $twig->parse($twig->tokenize($source));
}

echo "client_action_authorization_test: ok\n";
