#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Security/SecretStore.php';

$command = $argv[1] ?? 'status';
if (!in_array($command, ['status', 'migrate'], true)) {
    fwrite(STDERR, "Usage: php bin/settings-secrets.php [status|migrate --confirm=encrypt-settings-secrets]\n");
    exit(64);
}

try {
    Config::load(__DIR__ . '/../.env');
    SecretStore::assertReady();
    $pdo = DB::conn();

    $loadRows = static function (PDO $pdo, bool $forUpdate): array {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $rows = [];
        $apiRows = $pdo->query(
            'SELECT id, service_name, api_key FROM api_keys WHERE api_key <> \'\'' . $suffix
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apiRows as $row) {
            $service = strtolower(trim((string) $row['service_name']));
            $rows[] = [
                'table' => 'api_keys',
                'id' => (int) $row['id'],
                'value' => (string) $row['api_key'],
                'purpose' => 'api_key:' . $service,
            ];
        }

        $ldapRows = $pdo->query(
            'SELECT id, bind_password FROM ldap_configs WHERE bind_password <> \'\'' . $suffix
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ldapRows as $row) {
            $rows[] = [
                'table' => 'ldap_configs',
                'id' => (int) $row['id'],
                'value' => (string) $row['bind_password'],
                'purpose' => 'ldap:bind_password',
            ];
        }
        return $rows;
    };

    $classify = static function (array $rows): array {
        $counts = ['total' => 0, 'plaintext' => 0, 'current' => 0, 'rewrap' => 0, 'unreadable' => 0];
        foreach ($rows as $row) {
            $counts['total']++;
            try {
                SecretStore::decrypt($row['value'], $row['purpose']);
                if (!SecretStore::isEncrypted($row['value'])) {
                    $counts['plaintext']++;
                } elseif (SecretStore::needsRewrap($row['value'])) {
                    $counts['rewrap']++;
                } else {
                    $counts['current']++;
                }
            } catch (Throwable $e) {
                $counts['unreadable']++;
            }
        }
        return $counts;
    };

    $rows = $loadRows($pdo, false);
    $counts = $classify($rows);
    printf(
        "settings_secrets total=%d plaintext=%d current=%d rewrap=%d unreadable=%d\n",
        $counts['total'],
        $counts['plaintext'],
        $counts['current'],
        $counts['rewrap'],
        $counts['unreadable']
    );

    if ($command === 'status') {
        exit($counts['unreadable'] === 0 ? 0 : 2);
    }

    if (!in_array('--confirm=encrypt-settings-secrets', array_slice($argv, 2), true)) {
        throw new RuntimeException('Migration requires --confirm=encrypt-settings-secrets');
    }
    if ($counts['unreadable'] > 0) {
        throw new RuntimeException('Unreadable secret envelopes exist; migration refused');
    }

    $lock = (int) $pdo->query("SELECT GET_LOCK('awgcontrolpanel:settings-secrets', 0)")->fetchColumn();
    if ($lock !== 1) {
        throw new RuntimeException('Another settings-secret migration holds the database lock');
    }

    $updated = 0;
    try {
        $pdo->beginTransaction();
        $lockedRows = $loadRows($pdo, true);
        foreach ($lockedRows as $row) {
            if (!SecretStore::needsRewrap($row['value'])) {
                continue;
            }
            $next = SecretStore::rewrap($row['value'], $row['purpose']);
            if ($row['table'] === 'api_keys') {
                $stmt = $pdo->prepare('UPDATE api_keys SET api_key = ?, updated_at = NOW() WHERE id = ?');
            } else {
                $stmt = $pdo->prepare('UPDATE ldap_configs SET bind_password = ?, updated_at = NOW() WHERE id = ?');
            }
            $stmt->execute([$next, $row['id']]);
            $updated += $stmt->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('awgcontrolpanel:settings-secrets')");
    }

    $final = $classify($loadRows($pdo, false));
    if ($final['plaintext'] !== 0 || $final['rewrap'] !== 0 || $final['unreadable'] !== 0) {
        throw new RuntimeException('Post-migration secret verification failed');
    }
    printf("settings_secrets_migration=pass updated=%d current=%d\n", $updated, $final['current']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Settings secret operation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
