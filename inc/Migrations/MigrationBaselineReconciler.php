<?php

final class MigrationBaselineReconciler
{
    /**
     * @param array<int, array{version:int,filename:string,path:string,checksum:string}> $catalog
     */
    public static function verify(PDO $pdo, array $catalog, array $manifest): array
    {
        foreach (['format_version', 'baseline_id', 'backup_id', 'catalog_fingerprint', 'schema_fingerprint', 'postcondition_profile', 'baseline_through'] as $field) {
            if (!array_key_exists($field, $manifest) || $manifest[$field] === '') {
                throw new RuntimeException("Baseline manifest is missing: {$field}");
            }
        }
        if ((int) $manifest['format_version'] !== 1) {
            throw new RuntimeException('Unsupported baseline manifest format');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', (string) $manifest['baseline_id'])) {
            throw new RuntimeException('Invalid baseline_id');
        }
        if ($manifest['postcondition_profile'] !== 'legacy-production-through-093-v1') {
            throw new RuntimeException('Unknown baseline postcondition profile');
        }

        $catalogFingerprint = MigrationCatalog::fingerprint($catalog);
        if (!hash_equals(strtolower((string) $manifest['catalog_fingerprint']), $catalogFingerprint)) {
            throw new RuntimeException('Migration catalog fingerprint does not match the manifest');
        }

        $schemaFingerprint = DatabaseSchemaFingerprint::calculate($pdo);
        if (!hash_equals(strtolower((string) $manifest['schema_fingerprint']), $schemaFingerprint)) {
            throw new RuntimeException('Database schema fingerprint does not match the manifest');
        }

        $ledgerRows = $pdo->query('SELECT filename, checksum FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
        $ledger = [];
        foreach ($ledgerRows as $row) {
            $ledger[(string) $row['filename']] = strtolower(trim((string) ($row['checksum'] ?? '')));
        }

        $catalogByName = [];
        $throughVersion = null;
        foreach ($catalog as $migration) {
            $catalogByName[$migration['filename']] = $migration;
            if ($migration['filename'] === $manifest['baseline_through']) {
                $throughVersion = $migration['version'];
            }
        }
        if ($throughVersion === null) {
            throw new RuntimeException('baseline_through is not present in the migration catalog');
        }

        $declared = [];
        $entries = [];
        foreach ($catalog as $migration) {
            if ($migration['version'] <= $throughVersion) {
                $declared[$migration['filename']] = 'baselined';
            }
        }
        foreach (($manifest['baselined_files'] ?? []) as $filename) {
            self::declare($declared, (string) $filename, 'baselined');
        }
        foreach (($manifest['applied_files'] ?? []) as $filename) {
            self::declare($declared, (string) $filename, 'applied');
        }
        foreach (($manifest['reconciled_files'] ?? []) as $filename => $priorChecksum) {
            self::declare($declared, (string) $filename, 'reconciled');
        }

        if (count($declared) !== count($catalog)) {
            throw new RuntimeException('Baseline manifest does not classify every migration exactly once');
        }
        foreach ($ledger as $filename => $_checksum) {
            if (!isset($catalogByName[$filename])) {
                throw new RuntimeException("Ledger contains a migration absent from the catalog: {$filename}");
            }
        }

        foreach ($catalog as $migration) {
            $filename = $migration['filename'];
            if (!isset($declared[$filename])) {
                throw new RuntimeException("Unclassified migration: {$filename}");
            }
            $kind = $declared[$filename];
            $current = strtolower($migration['checksum']);
            $recorded = $ledger[$filename] ?? null;
            $prior = null;

            if ($kind === 'applied') {
                if ($recorded === null || !hash_equals($current, $recorded)) {
                    throw new RuntimeException("Applied migration is not recorded with its current checksum: {$filename}");
                }
            } elseif ($kind === 'reconciled') {
                $prior = strtolower(trim((string) $manifest['reconciled_files'][$filename]));
                if ($prior === '' || $recorded === null || !hash_equals($prior, $recorded)) {
                    throw new RuntimeException("Prior checksum does not match the ledger: {$filename}");
                }
                if (hash_equals($current, $prior)) {
                    throw new RuntimeException("Reconciled migration has no checksum drift: {$filename}");
                }
            } elseif ($recorded !== null && !hash_equals($current, $recorded)) {
                throw new RuntimeException("Baselined migration conflicts with an existing ledger checksum: {$filename}");
            }

            $entries[] = [
                'filename' => $filename,
                'checksum' => $current,
                'prior_checksum' => $prior,
                'event_kind' => $kind,
                'ledger_missing' => $recorded === null,
            ];
        }

        $checks = LegacyBaseline093Postconditions::verify($pdo);
        $failedChecks = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
        if ($failedChecks !== []) {
            throw new RuntimeException('Baseline postconditions failed: ' . implode(', ', $failedChecks));
        }

        return [
            'baseline_id' => (string) $manifest['baseline_id'],
            'backup_id' => (string) $manifest['backup_id'],
            'catalog_fingerprint' => $catalogFingerprint,
            'schema_fingerprint' => $schemaFingerprint,
            'postcondition_profile' => (string) $manifest['postcondition_profile'],
            'entries' => $entries,
            'checks' => $checks,
        ];
    }

    public static function apply(PDO $pdo, array $verification): int
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS migration_history_events ("
            . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,"
            . "baseline_id VARCHAR(100) NOT NULL,"
            . "filename VARCHAR(255) NOT NULL,"
            . "accepted_checksum CHAR(64) NOT NULL,"
            . "prior_checksum CHAR(64) NULL,"
            . "event_kind ENUM('applied','baselined','reconciled') NOT NULL,"
            . "catalog_fingerprint CHAR(64) NOT NULL,"
            . "schema_fingerprint CHAR(64) NOT NULL,"
            . "backup_id VARCHAR(255) NOT NULL,"
            . "postcondition_profile VARCHAR(100) NOT NULL,"
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . "UNIQUE KEY uniq_migration_history_event (baseline_id, filename),"
            . "KEY idx_migration_history_filename (filename, accepted_checksum)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $event = $pdo->prepare(
            'INSERT INTO migration_history_events '
            . '(baseline_id, filename, accepted_checksum, prior_checksum, event_kind, catalog_fingerprint, schema_fingerprint, backup_id, postcondition_profile) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'accepted_checksum = IF(accepted_checksum = VALUES(accepted_checksum), accepted_checksum, NULL), '
            . 'prior_checksum = IF(prior_checksum <=> VALUES(prior_checksum), prior_checksum, NULL), '
            . 'event_kind = IF(event_kind = VALUES(event_kind), event_kind, NULL)'
        );
        $baselineLedger = $pdo->prepare(
            'INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)'
        );

        $inserted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($verification['entries'] as $entry) {
                $event->execute([
                    $verification['baseline_id'],
                    $entry['filename'],
                    $entry['checksum'],
                    $entry['prior_checksum'],
                    $entry['event_kind'],
                    $verification['catalog_fingerprint'],
                    $verification['schema_fingerprint'],
                    $verification['backup_id'],
                    $verification['postcondition_profile'],
                ]);
                if ($entry['ledger_missing']) {
                    $baselineLedger->execute([$entry['filename'], $entry['checksum']]);
                }
                $inserted++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $inserted;
    }

    private static function declare(array &$declared, string $filename, string $kind): void
    {
        if ($filename === '' || isset($declared[$filename])) {
            throw new RuntimeException("Migration is missing or classified more than once: {$filename}");
        }
        $declared[$filename] = $kind;
    }
}
