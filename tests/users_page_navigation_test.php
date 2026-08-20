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
    '.users-table {',
    'min-width: 1500px;',
    '<div class="w-full mx-auto px-4 sm:px-6 lg:px-8">',
    '<table class="users-table w-full divide-y divide-gray-200">',
    'id="users-list-panel"',
    'id="add-user-password"',
    'id="add-user-site-access"',
    'id="add-user-role"',
    'function syncNewUserPassword()',
    "addUserPassword.disabled = !passwordIsRequired;",
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

if (strpos($layout, 'xl:space-x-4 2xl:space-x-6') === false
    || strpos($layout, 'flex flex-shrink-0 items-center') === false
    || strpos($layout, '{{ currentLang }}') === false) {
    fwrite(STDERR, "Responsive header layout is missing\n");
    exit(1);
}

if (strpos($controller, 'public function users()') === false
    || strpos($controller, "header('Location: /settings#users')") !== false) {
    fwrite(STDERR, "Users controller or redirects are not migrated\n");
    exit(1);
}

if (!preg_match("/Router::get\('\/users'.*?requireUserManager\(\).*?->users\(\)/s", $router)) {
    fwrite(STDERR, "User-manager route is missing\n");
    exit(1);
}

if (substr_count($layout, "user.role in ['admin', 'moderator']") < 2
    || strpos($users, '<option value="moderator">') === false
    || strpos($users, 'action="/users/{{ u.id }}/role"') === false) {
    fwrite(STDERR, "Moderator navigation or role controls are missing\n");
    exit(1);
}

echo "users_page_navigation_test: ok\n";
