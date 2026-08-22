<?php

final class MigrationCatalog
{
    /**
     * @return array<int, array{version:int,filename:string,path:string,checksum:string}>
     */
    public static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Migration directory does not exist: {$directory}");
        }

        $paths = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $byVersion = [];

        foreach ($paths as $path) {
            $filename = basename($path);
            if (!preg_match('/^(\d{3})_[A-Za-z0-9_.-]+\.sql$/', $filename, $matches)) {
                throw new RuntimeException("Invalid migration filename: {$filename}");
            }

            $version = (int) $matches[1];
            if (isset($byVersion[$version])) {
                throw new RuntimeException(sprintf(
                    'Duplicate migration version %03d: %s and %s',
                    $version,
                    $byVersion[$version]['filename'],
                    $filename
                ));
            }

            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException("Unable to hash migration: {$filename}");
            }

            $byVersion[$version] = [
                'version' => $version,
                'filename' => $filename,
                'path' => $path,
                'checksum' => strtolower($checksum),
            ];
        }

        ksort($byVersion, SORT_NUMERIC);
        return array_values($byVersion);
    }

    /**
     * @param array<int, array{filename:string,checksum:string}> $migrations
     */
    public static function fingerprint(array $migrations): string
    {
        $lines = array_map(
            static fn(array $migration): string => $migration['filename'] . ':' . strtolower($migration['checksum']),
            $migrations
        );

        return hash('sha256', implode("\n", $lines));
    }
}
