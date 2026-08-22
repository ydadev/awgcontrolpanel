<?php

final class MigrationPlanner
{
    /**
     * @param array<int, array{version:int,filename:string,path:string,checksum:string}> $catalog
     * @param array<int, array{filename:string,checksum:mixed}> $ledgerRows
     * @return array{
     *   migrations:array<int,array<string,mixed>>,
     *   orphaned:array<int,string>,
     *   counts:array<string,int>,
     *   history_blocked:bool,
     *   current:bool
     * }
     */
    public static function build(
        array $catalog,
        array $ledgerRows,
        int $applicationTableCount = 0,
        array $historyEvents = []
    ): array
    {
        $ledger = [];
        foreach ($ledgerRows as $row) {
            $filename = (string) ($row['filename'] ?? '');
            if ($filename === '') {
                throw new RuntimeException('Migration ledger contains an empty filename');
            }
            if (isset($ledger[$filename])) {
                throw new RuntimeException("Migration ledger contains duplicate filename: {$filename}");
            }
            $ledger[$filename] = $row['checksum'] ?? null;
        }

        $acceptedEvents = [];
        foreach ($historyEvents as $event) {
            $filename = (string) ($event['filename'] ?? '');
            $checksum = strtolower(trim((string) ($event['accepted_checksum'] ?? '')));
            if ($filename === '' || $checksum === '') {
                continue;
            }
            $acceptedEvents[$filename][$checksum] = [
                'event_kind' => (string) ($event['event_kind'] ?? ''),
                'prior_checksum' => strtolower(trim((string) ($event['prior_checksum'] ?? ''))),
            ];
        }

        $catalogNames = [];
        $result = [];
        $counts = [
            'applied' => 0,
            'baselined' => 0,
            'reconciled' => 0,
            'pending' => 0,
            'drift' => 0,
            'unverified' => 0,
            'out_of_order' => 0,
            'orphaned' => 0,
            'untracked_schema' => 0,
        ];
        $pendingSeen = false;

        foreach ($catalog as $migration) {
            $filename = $migration['filename'];
            $catalogNames[$filename] = true;
            $status = 'pending';
            $recordedChecksum = null;

            if (array_key_exists($filename, $ledger)) {
                $recordedChecksum = $ledger[$filename];
                if (!is_string($recordedChecksum) || trim($recordedChecksum) === '') {
                    $status = 'unverified';
                } elseif (!hash_equals(strtolower($migration['checksum']), strtolower(trim($recordedChecksum)))) {
                    $event = $acceptedEvents[$filename][strtolower($migration['checksum'])] ?? null;
                    $status = $event !== null
                        && $event['event_kind'] === 'reconciled'
                        && $event['prior_checksum'] !== ''
                        && hash_equals($event['prior_checksum'], strtolower(trim($recordedChecksum)))
                            ? 'reconciled'
                            : 'drift';
                } else {
                    $event = $acceptedEvents[$filename][strtolower($migration['checksum'])] ?? null;
                    $status = $event !== null && $event['event_kind'] === 'baselined'
                        ? 'baselined'
                        : 'applied';
                }
            } else {
                $event = $acceptedEvents[$filename][strtolower($migration['checksum'])] ?? null;
                if ($event !== null && in_array($event['event_kind'], ['baselined', 'applied'], true)) {
                    $status = $event['event_kind'];
                }
            }

            if ($status === 'pending') {
                $pendingSeen = true;
            } elseif ($pendingSeen && in_array($status, ['applied', 'baselined', 'reconciled'], true)) {
                $status = 'out_of_order';
            }

            $counts[$status]++;
            $result[] = [
                'version' => $migration['version'],
                'filename' => $filename,
                'path' => $migration['path'],
                'checksum' => $migration['checksum'],
                'recorded_checksum' => $recordedChecksum,
                'status' => $status,
            ];
        }

        $orphaned = [];
        foreach (array_keys($ledger) as $filename) {
            if (!isset($catalogNames[$filename])) {
                $orphaned[] = $filename;
            }
        }
        sort($orphaned, SORT_STRING);
        $counts['orphaned'] = count($orphaned);
        $counts['untracked_schema'] = count($ledgerRows) === 0 && $applicationTableCount > 0 ? 1 : 0;

        $historyBlocked = $counts['drift'] > 0
            || $counts['unverified'] > 0
            || $counts['out_of_order'] > 0
            || $counts['orphaned'] > 0
            || $counts['untracked_schema'] > 0;

        return [
            'migrations' => $result,
            'orphaned' => $orphaned,
            'counts' => $counts,
            'history_blocked' => $historyBlocked,
            'current' => !$historyBlocked && $counts['pending'] === 0,
        ];
    }
}
