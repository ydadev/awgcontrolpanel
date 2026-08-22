<?php

final class DatabaseSchemaFingerprint
{
    private const BOOKKEEPING_TABLES = [
        'schema_migrations',
        'migration_history_events',
    ];

    public static function applicationTableCount(PDO $pdo): int
    {
        $placeholders = implode(',', array_fill(0, count(self::BOOKKEEPING_TABLES), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . "AND TABLE_NAME NOT IN ({$placeholders})"
        );
        $stmt->execute(self::BOOKKEEPING_TABLES);
        return (int) $stmt->fetchColumn();
    }

    public static function calculate(PDO $pdo): string
    {
        $sections = [
            'tables' => "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION "
                . "FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
                . "AND TABLE_NAME NOT IN ('schema_migrations','migration_history_events') "
                . "ORDER BY TABLE_NAME",
            'columns' => "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, "
                . "COALESCE(COLUMN_DEFAULT, '<NULL>') AS COLUMN_DEFAULT, EXTRA, "
                . "COALESCE(COLLATION_NAME, '') AS COLLATION_NAME, COALESCE(GENERATION_EXPRESSION, '') AS GENERATION_EXPRESSION "
                . "FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME NOT IN ('schema_migrations','migration_history_events') "
                . "ORDER BY TABLE_NAME, ORDINAL_POSITION",
            'indexes' => "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, "
                . "COALESCE(COLUMN_NAME, '') AS COLUMN_NAME, COALESCE(COLLATION, '') AS COLLATION, "
                . "COALESCE(SUB_PART, 0) AS SUB_PART, INDEX_TYPE "
                . "FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME NOT IN ('schema_migrations','migration_history_events') "
                . "ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            'foreign_keys' => "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, "
                . "k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE "
                . "FROM information_schema.KEY_COLUMN_USAGE k "
                . "JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
                . "ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME "
                . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL "
                . "AND k.TABLE_NAME NOT IN ('schema_migrations','migration_history_events') "
                . "ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
        ];

        $context = hash_init('sha256');
        foreach ($sections as $section => $sql) {
            hash_update($context, "[{$section}]\n");
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded === false) {
                    throw new RuntimeException("Unable to encode schema section: {$section}");
                }
                hash_update($context, $encoded . "\n");
            }
        }

        return hash_final($context);
    }
}
