<?php

require_once __DIR__ . '/../inc/UserPasswordPolicy.php';

$generatedOne = UserPasswordPolicy::resolveForNewUser('', 'user', false);
$generatedTwo = UserPasswordPolicy::resolveForNewUser('ignored-password', 'user', false);

if (strlen($generatedOne) < 40 || strlen($generatedTwo) < 40) {
    fwrite(STDERR, "Generated password is too short\n");
    exit(1);
}
if ($generatedOne === $generatedTwo || $generatedTwo === 'ignored-password') {
    fwrite(STDERR, "Generated passwords are not random or submitted password was retained\n");
    exit(1);
}
if (!preg_match('/[A-Z]/', $generatedOne)
    || !preg_match('/[a-z]/', $generatedOne)
    || !preg_match('/[0-9]/', $generatedOne)
    || strpos($generatedOne, '!') === false) {
    fwrite(STDERR, "Generated password does not meet complexity requirements\n");
    exit(1);
}

$knownPassword = 'Known-password-42';
if (UserPasswordPolicy::resolveForNewUser($knownPassword, 'user', true) !== $knownPassword
    || UserPasswordPolicy::resolveForNewUser($knownPassword, 'admin', false) !== $knownPassword) {
    fwrite(STDERR, "Known password was not retained for a site-enabled user\n");
    exit(1);
}

foreach ([
    ['', 'user', true],
    ['short', 'user', true],
    ['', 'admin', false],
] as [$password, $role, $siteAccess]) {
    try {
        UserPasswordPolicy::resolveForNewUser($password, $role, $siteAccess);
        fwrite(STDERR, "Required password validation was bypassed\n");
        exit(1);
    } catch (InvalidArgumentException $e) {
        // Expected.
    }
}

echo "user_password_policy_test: ok\n";
