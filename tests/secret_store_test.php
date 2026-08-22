<?php

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/Security/SecretStore.php';

function secretStoreAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$original = [];
foreach (['SECRET_STORE_KEY', 'SECRET_STORE_ACTIVE_KEY_ID', 'SECRET_STORE_PREVIOUS_KEYS'] as $name) {
    $value = getenv($name);
    $original[$name] = $value === false ? null : $value;
}

try {
    $oldKey = 'old-secret-store-key-material-0001';
    putenv('SECRET_STORE_KEY=' . $oldKey);
    putenv('SECRET_STORE_ACTIVE_KEY_ID=old');
    putenv('SECRET_STORE_PREVIOUS_KEYS=');

    $plaintext = 'not-visible-in-the-envelope';
    $oldEnvelope = SecretStore::encrypt($plaintext, 'test:alpha');
    secretStoreAssert(SecretStore::isEncrypted($oldEnvelope), 'New values must use a versioned envelope');
    secretStoreAssert(!str_contains($oldEnvelope, $plaintext), 'Ciphertext must not contain plaintext');
    secretStoreAssert(SecretStore::decrypt($oldEnvelope, 'test:alpha') === $plaintext, 'Round trip failed');
    secretStoreAssert(SecretStore::decrypt('legacy-plaintext', 'test:alpha') === 'legacy-plaintext', 'Dual-read plaintext failed');

    $contextRejected = false;
    try {
        SecretStore::decrypt($oldEnvelope, 'test:beta');
    } catch (RuntimeException $e) {
        $contextRejected = true;
    }
    secretStoreAssert($contextRejected, 'Ciphertext must be bound to its purpose');

    putenv('SECRET_STORE_KEY=new-secret-store-key-material-0002');
    putenv('SECRET_STORE_ACTIVE_KEY_ID=new');
    putenv('SECRET_STORE_PREVIOUS_KEYS=old=' . $oldKey);
    secretStoreAssert(SecretStore::needsRewrap($oldEnvelope), 'Previous-key ciphertext must require rewrap');
    $newEnvelope = SecretStore::rewrap($oldEnvelope, 'test:alpha');
    secretStoreAssert(str_starts_with($newEnvelope, 'awgsec:v1:new:'), 'Rewrap must use the active key id');
    secretStoreAssert(SecretStore::decrypt($newEnvelope, 'test:alpha') === $plaintext, 'Rewrapped value failed to decrypt');

    $tampered = $newEnvelope;
    $tamperIndex = strlen($tampered) - 8;
    $tampered[$tamperIndex] = $tampered[$tamperIndex] === 'A' ? 'B' : 'A';
    $tamperRejected = false;
    try {
        SecretStore::decrypt($tampered, 'test:alpha');
    } catch (RuntimeException $e) {
        $tamperRejected = true;
    }
    secretStoreAssert($tamperRejected, 'Tampered ciphertext must be rejected');
} finally {
    foreach ($original as $name => $value) {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}

echo "Secret store tests passed\n";
