<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Exports and restores academic-records tables.
 * Writes SQL dumps for download/archive and JSON companions for `wpds:restore`.
 */
class DatabaseBackupService
{
    /**
     * Tables in dependency order (parents before children).
     *
     * @var list<string>
     */
    public const TABLES = [
        'users',
        'staff_profiles',
        'programs',
        'program_majors',
        'subjects',
        'curriculum_items',
        'school_terms',
        'student_profiles',
        'class_sections',
        'admissions',
        'enrollment_subjects',
        'grades',
        'grade_submissions',
        'grade_change_requests',
        'audit_logs',
    ];

    public function directory(): string
    {
        $dir = (string) config('backup.path', storage_path('app/backups'));

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * @return array{generated_at: string, app: string, tables: array<string, array<int, array<string, mixed>>>}
     */
    public function snapshot(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'app' => (string) config('app.name'),
            'tables' => $tables,
        ];
    }

    public function filename(string $extension = 'sql'): string
    {
        $ext = ltrim(strtolower($extension), '.');

        return 'wpds-backup-'.now()->format('Ymd-His').'.'.$ext;
    }

    /**
     * Write SQL + JSON backup pair to disk. Returns the SQL file meta.
     *
     * @return array{name: string, path: string, size: int, generated_at: string, format: string}
     */
    public function writeToDisk(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $snapshot = $this->snapshot();
        $stamp = now()->format('Ymd-His');
        $sqlName = "wpds-backup-{$stamp}.sql";
        $jsonName = "wpds-backup-{$stamp}.json";
        $sqlPath = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$sqlName;
        $jsonPath = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$jsonName;

        file_put_contents($sqlPath, $this->toSql($snapshot));
        file_put_contents($jsonPath, json_encode($snapshot, JSON_PRETTY_PRINT));

        try {
            $retention = app(BackupScheduleService::class)->get()['retention_days'] ?? null;
            if ($retention !== null) {
                config(['backup.retention_days' => (int) $retention]);
            }
        } catch (\Throwable) {
            // schedule service optional during early boot/tests
        }

        $this->pruneOldBackups($directory);

        return [
            'name' => $sqlName,
            'path' => $sqlPath,
            'size' => (int) filesize($sqlPath),
            'generated_at' => $snapshot['generated_at'],
            'format' => 'sql',
            'json_name' => $jsonName,
        ];
    }

    /**
     * @param  array{generated_at?: string, app?: string, tables: array<string, array<int, array<string, mixed>>>}  $snapshot
     */
    public function toSql(array $snapshot): string
    {
        $generatedAt = $snapshot['generated_at'] ?? now()->toIso8601String();
        $app = $snapshot['app'] ?? (string) config('app.name');
        $driver = DB::connection()->getDriverName();
        $lines = [
            '-- ============================================================',
            '-- West Prime Digital System — academic records backup',
            '-- Generated: '.$generatedAt,
            '-- Application: '.$app,
            '-- Source driver: '.$driver,
            '-- ============================================================',
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach (self::TABLES as $table) {
            $rows = $snapshot['tables'][$table] ?? [];
            $lines[] = '-- ------------------------------------------------------------';
            $lines[] = '-- Table: '.$table.' ('.count($rows).' rows)';
            $lines[] = '-- ------------------------------------------------------------';
            $lines[] = 'DELETE FROM `'.$table.'`;';

            if ($rows === []) {
                $lines[] = '';

                continue;
            }

            $columns = array_keys($rows[0]);
            $colList = implode(', ', array_map(fn ($c) => '`'.$c.'`', $columns));

            foreach (array_chunk($rows, 50) as $chunk) {
                $valueGroups = [];
                foreach ($chunk as $row) {
                    $values = [];
                    foreach ($columns as $col) {
                        $values[] = $this->sqlLiteral($row[$col] ?? null);
                    }
                    $valueGroups[] = '('.implode(', ', $values).')';
                }
                $lines[] = 'INSERT INTO `'.$table.'` ('.$colList.') VALUES';
                $lines[] = implode(",\n", $valueGroups).';';
            }
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, format: string, size: int, generated_at: string|null}>
     */
    public function listFiles(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (File::files($directory) as $file) {
            $name = $file->getFilename();
            if (! $this->isSafeBackupName($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'format' => strtolower($file->getExtension()),
                'size' => $file->getSize(),
                'generated_at' => date('c', $file->getMTime()),
            ];
        }

        usort($files, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $files;
    }

    public function resolvePath(string $filename, ?string $directory = null): string
    {
        if (! $this->isSafeBackupName($filename)) {
            throw new InvalidArgumentException('Invalid backup filename.');
        }

        $directory = $directory ?: $this->directory();
        $path = realpath($directory.DIRECTORY_SEPARATOR.$filename);
        $base = realpath($directory);

        if ($path === false || $base === false || ! str_starts_with($path, $base) || ! is_file($path)) {
            throw new InvalidArgumentException('Backup file not found.');
        }

        return $path;
    }

    public function deleteFile(string $filename, ?string $directory = null): void
    {
        $path = $this->resolvePath($filename, $directory);
        File::delete($path);

        // Also remove companion file (.sql <-> .json) when present.
        $companion = preg_replace('/\.(sql|json)$/i', '', $filename);
        if (is_string($companion)) {
            foreach (['.sql', '.json'] as $ext) {
                $other = $companion.$ext;
                if ($other === $filename) {
                    continue;
                }
                try {
                    File::delete($this->resolvePath($other, $directory));
                } catch (InvalidArgumentException) {
                    // companion missing — ignore
                }
            }
        }
    }

    public function isSafeBackupName(string $filename): bool
    {
        return (bool) preg_match('/^wpds-backup-\d{8}-\d{6}\.(sql|json)$/', $filename);
    }

    public function pruneOldBackups(?string $directory = null): int
    {
        $days = (int) config('backup.retention_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $directory = $directory ?: $this->directory();
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach ($this->listFiles($directory) as $file) {
            $mtime = strtotime((string) $file['generated_at']) ?: 0;
            if ($mtime > 0 && $mtime < $cutoff) {
                try {
                    File::delete($this->resolvePath($file['name'], $directory));
                    $removed++;
                } catch (InvalidArgumentException) {
                    // ignore
                }
            }
        }

        return $removed;
    }

    /**
     * @param  array{tables: array<string, array<int, array<string, mixed>>>}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $tables = $snapshot['tables'] ?? [];
        $driver = DB::connection()->getDriverName();

        $this->toggleForeignKeyChecks($driver, false);

        DB::transaction(function () use ($tables) {
            foreach (array_reverse(self::TABLES) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || empty($tables[$table])) {
                    continue;
                }

                foreach (array_chunk($tables[$table], 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        $this->toggleForeignKeyChecks($driver, true);
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        return "'".str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $string
        )."'";
    }

    private function toggleForeignKeyChecks(string $driver, bool $enabled): void
    {
        match ($driver) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS='.($enabled ? '1' : '0')),
            'sqlite' => DB::statement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF')),
            default => null,
        };
    }
}
