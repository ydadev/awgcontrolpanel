<?php

$layout = file_get_contents(__DIR__ . '/../templates/layout.twig');
$settings = file_get_contents(__DIR__ . '/../templates/settings.twig');
$users = file_get_contents(__DIR__ . '/../templates/users.twig');
$controller = file_get_contents(__DIR__ . '/../controllers/SettingsController.php');
$router = file_get_contents(__DIR__ . '/../public/index.php');

foreach ([$layout, $settings, $users, $controller, $router] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Cannot read users page sources\n");
        exit(1);
    }
}

if (substr_count($layout, 'href="/users"') < 2
    || substr_count($layout, '</i>Пользователи') < 2
    || substr_count($layout, '</i>Панель') < 2) {
    fwrite(STDERR, "Desktop or mobile navigation is missing users or short dashboard label\n");
    exit(1);
}

if (strpos($settings, 'id="tab-users"') !== false
    || strpos($settings, 'id="content-users"') !== false) {
    fwrite(STDERR, "Users are still embedded in settings\n");
    exit(1);
}

$requiredUsersFragments = [
    'id="user-search"',
    'placeholder="Поиск по имени или почте"',
    'data-user-name',
    'data-user-email',
    "toLocaleLowerCase('ru-RU')",
    'action="/users/add"',
    'action="/users/{{ u.id }}/server-access"',
];

foreach ($requiredUsersFragments as $fragment) {
    if (strpos($users, $fragment) === false) {
        fwrite(STDERR, "Missing users page feature: {$fragment}\n");
        exit(1);
    }
}

if (strpos($controller, 'public function users()') === false
    || strpos($controller, "header('Location: /settings#users')") !== false) {
    fwrite(STDERR, "Users controller or redirects are not migrated\n");
    exit(1);
}

if (!preg_match("/Router::get\('\/users'.*?requireAdmin\(\).*?->users\(\)/s", $router)) {
    fwrite(STDERR, "Admin-only users route is missing\n");
    exit(1);
}

echo "users_page_navigation_test: ok\n";
