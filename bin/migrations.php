#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Migrations/MigrationCatalog.php';
require_once __DIR__ . '/../inc/Migrations/MigrationPlanner.php';
require_once __DIR__ . '/../inc/Migrations/DatabaseSchemaFingerprint.php';
require_once __DIR__ . '/../inc/Migrations/LegacyBaseline093Postconditions.php';
require_once __DIR__ . '/../inc/Migrations/MigrationBaselineReconciler.php';

$command = $argv[1] ?? 'plan';
$knownCommands = ['plan', 'verify', 'verify-history', 'schema-fingerprint', 'baseline-verify', 'baseline-apply'];
if (!in_array($command, $knownCommands, true)) {
    fwrite(STDERR, "Usage: php bin/migrations.php [plan|verify|verify-history|schema-fingerprint|baseline-verify FILE|baseline-apply FILE --confirm=ID]\n");
    exit(64);
}

try {
    Config::load(__DIR__ . '/../.env');
    $pdo = DB::conn();
    $catalog = MigrationCatalog::discover(__DIR__ . '/../migrations');

    if ($command === 'schema-fingerprint') {
        printf(
            "schema_fingerprint=%s application_tables=%d\n",
            DatabaseSchemaFingerprint::calculate($pdo),
            DatabaseSchemaFingerprint::applicationTableCount($pdo)
        );
        exit(0);
    }

    if (in_array($command, ['baseline-verify', 'baseline-apply'], true)) {
        $manifestPath = $argv[2] ?? '';
        if ($manifestPath === '' || !is_file($manifestPath)) {
            throw new RuntimeException('Baseline manifest file is required');
        }
        $manifestJson = file_get_contents($manifestPath);
        $manifest = $manifestJson === false ? null : json_decode($manifestJson, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Baseline manifest is not valid JSON');
        }

        $verification = MigrationBaselineReconciler::verify($pdo, $catalog, $manifest);
        printf(
            "baseline=%s catalog=%s schema=%s checks=%d entries=%d verification=pass\n",
            $verification['baseline_id'],
            $verification['catalog_fingerprint'],
            $verification['schema_fingerprint'],
            count($verification['checks']),
            count($verification['entries'])
        );

        if ($command === 'baseline-verify') {
            exit(0);
        }

        $confirm = '';
        foreach (array_slice($argv, 3) as $argument) {
            if (strpos($argument, '--confirm=') === 0) {
                $confirm = substr($argument, strlen('--confirm='));
            }
        }
        if (!hash_equals($verification['baseline_id'], $confirm)) {
            throw new RuntimeException('baseline-apply requires --confirm with the exact baseline_id');
        }

        $lock = (int) $pdo->query("SELECT GET_LOCK('awgcontrolpanel:migration-reconcile', 0)")->fetchColumn();
        if ($lock !== 1) {
            throw new RuntimeException('Another migration reconciliation process holds the database lock');
        }
        try {
            $events = MigrationBaselineReconciler::apply($pdo, $verification);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('awgcontrolpanel:migration-reconcile')");
        }
        printf("baseline_apply=pass history_events=%d\n", $events);
        exit(0);
    }

    $tableExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    )->fetchColumn();
    $historyTableExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_history_events'"
    )->fetchColumn();

    $ledgerRows = $tableExists
        ? $pdo->query('SELECT filename, checksum FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $historyEvents = $historyTableExists
        ? $pdo->query(
            'SELECT filename, accepted_checksum, prior_checksum, event_kind '
            . 'FROM migration_history_events ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC)
        : [];

    $plan = MigrationPlanner::build(
        $catalog,
        $ledgerRows,
        DatabaseSchemaFingerprint::applicationTableCount($pdo),
        $historyEvents
    );
    $counts = $plan['counts'];

    printf(
        "catalog=%d fingerprint=%s applied=%d baselined=%d reconciled=%d pending=%d drift=%d unverified=%d out_of_order=%d orphaned=%d untracked_schema=%d\n",
        count($catalog),
        MigrationCatalog::fingerprint($catalog),
        $counts['applied'],
        $counts['baselined'],
        $counts['reconciled'],
        $counts['pending'],
        $counts['drift'],
        $counts['unverified'],
        $counts['out_of_order'],
        $counts['orphaned'],
        $counts['untracked_schema']
    );

    foreach ($plan['migrations'] as $migration) {
        if (!in_array($migration['status'], ['applied', 'baselined', 'reconciled'], true)) {
            printf("%s %s\n", $migration['status'], $migration['filename']);
        }
    }
    foreach ($plan['orphaned'] as $filename) {
        printf("orphaned %s\n", $filename);
    }
    if ($counts['untracked_schema'] > 0) {
        echo "untracked_schema Existing application tables have no migration history\n";
    }

    if ($command === 'plan') {
        exit(0);
    }
    if ($plan['history_blocked']) {
        fwrite(STDERR, "Migration history is unsafe; reconcile it before applying SQL.\n");
        exit(2);
    }
    if ($command === 'verify' && !$plan['current']) {
        fwrite(STDERR, "Pending migrations exist.\n");
        exit(3);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration inspection failed: ' . $e->getMessage() . "\n");
    exit(1);
}
