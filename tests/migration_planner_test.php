<?php

require_once __DIR__ . '/../inc/Migrations/MigrationCatalog.php';
require_once __DIR__ . '/../inc/Migrations/MigrationPlanner.php';

function migrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = sys_get_temp_dir() . '/migration-planner-' . bin2hex(random_bytes(6));
if (!mkdir($fixture, 0700, true) && !is_dir($fixture)) {
    throw new RuntimeException('Unable to create migration fixture directory');
}

try {
    file_put_contents($fixture . '/001_first.sql', "SELECT 1;\n");
    file_put_contents($fixture . '/003_third.sql', "SELECT 3;\n");
    $catalog = MigrationCatalog::discover($fixture);

    migrationAssert(count($catalog) === 2, 'Catalog must discover numbered SQL files');
    migrationAssert($catalog[0]['filename'] === '001_first.sql', 'Catalog must sort by numeric version');
    migrationAssert(strlen(MigrationCatalog::fingerprint($catalog)) === 64, 'Catalog fingerprint must be SHA-256');

    $fresh = MigrationPlanner::build($catalog, []);
    migrationAssert($fresh['counts']['pending'] === 2, 'Fresh database must report all migrations pending');
    migrationAssert(!$fresh['history_blocked'], 'A fresh database must not be treated as history drift');
    migrationAssert(!$fresh['current'], 'A fresh database with pending files is not current');

    $legacyWithoutLedger = MigrationPlanner::build($catalog, [], 5);
    migrationAssert(
        $legacyWithoutLedger['counts']['untracked_schema'] === 1,
        'An initialized database without ledger rows must require reconciliation'
    );
    migrationAssert($legacyWithoutLedger['history_blocked'], 'Untracked existing schema must block migration execution');

    $matching = MigrationPlanner::build($catalog, [
        ['filename' => '001_first.sql', 'checksum' => $catalog[0]['checksum']],
        ['filename' => '003_third.sql', 'checksum' => $catalog[1]['checksum']],
    ]);
    migrationAssert($matching['current'], 'Matching complete ledger must be current');

    $drift = MigrationPlanner::build($catalog, [
        ['filename' => '001_first.sql', 'checksum' => str_repeat('0', 64)],
    ]);
    migrationAssert($drift['counts']['drift'] === 1, 'Changed applied migration must be reported as drift');
    migrationAssert($drift['history_blocked'], 'Checksum drift must block history');

    $reconciled = MigrationPlanner::build($catalog, [
        ['filename' => '001_first.sql', 'checksum' => str_repeat('0', 64)],
    ], 1, [[
        'filename' => '001_first.sql',
        'accepted_checksum' => $catalog[0]['checksum'],
        'prior_checksum' => str_repeat('0', 64),
        'event_kind' => 'reconciled',
    ]]);
    migrationAssert($reconciled['counts']['reconciled'] === 1, 'A matching reconciliation event must preserve and accept prior drift');
    migrationAssert($reconciled['counts']['drift'] === 0, 'Accepted reconciliation must not remain checksum drift');

    $missingChecksum = MigrationPlanner::build($catalog, [
        ['filename' => '001_first.sql', 'checksum' => null],
    ]);
    migrationAssert($missingChecksum['counts']['unverified'] === 1, 'Null checksum must not be trusted');

    $gap = MigrationPlanner::build($catalog, [
        ['filename' => '003_third.sql', 'checksum' => $catalog[1]['checksum']],
    ]);
    migrationAssert($gap['counts']['out_of_order'] === 1, 'Applied migration after a gap must be blocked');

    $orphan = MigrationPlanner::build($catalog, [
        ['filename' => '999_removed.sql', 'checksum' => str_repeat('a', 64)],
    ]);
    migrationAssert($orphan['counts']['orphaned'] === 1, 'Ledger-only migration must be reported');
    migrationAssert($orphan['history_blocked'], 'Orphaned ledger entry must block history');

    echo "Migration planner tests passed\n";
} finally {
    foreach (glob($fixture . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($fixture);
}
