<?php

declare(strict_types=1);

namespace Dabing;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/UpdateMigrationService.php
 * Module Type: Service
 * Purpose: Discover, validate, and apply staged incremental database migrations.
 * Responsibilities: Enforce migration naming, checksum history, and retry-safe orchestration.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates incremental schema changes without assuming runtime DDL rights. */
final class UpdateMigrationService
{
    /** Migration basename expression.
     * Type: string; Units: PCRE pattern; Scope: staged migration filenames;
     * Consumers: discover(), inspectPath(); Rationale: stable ordered numeric IDs.
     */
    private const MIGRATION_FILENAME_PATTERN = '/\A[0-9]{4}_[a-z0-9_]+\.php\z/D';

    /** Maximum stored migration identifier width.
     * Type: int; Units: characters; Scope: one migration identifier;
     * Consumers: inspectPath(); Rationale: match the ledger column width.
     */
    private const MIGRATION_ID_MAX_LENGTH = 128;

    /** Create the updater with the application's runtime connection.
     * @param PDO $runtime Runtime DML database connection.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $runtime)
    {
    }

    /** Discover trusted PHP migration files in sorted filename order.
     * @param string $directory Absolute database/migrations directory.
     * @return list<string> Absolute paths in deterministic migration order.
     */
    public static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        if (is_link($directory)) {
            throw new RuntimeException('Migration directory must not be a symlink.');
        }
        $realDirectory = realpath($directory);
        if (!is_string($realDirectory)
            || strcasecmp(basename($realDirectory), 'migrations') !== 0
            || strcasecmp(basename(dirname($realDirectory)), 'database') !== 0) {
            throw new RuntimeException('Migrations must use a database/migrations directory.');
        }
        $paths = [];
        foreach (new \FilesystemIterator($realDirectory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Migration entries must not be symlinks.');
            }
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }
            if (preg_match(self::MIGRATION_FILENAME_PATTERN, $entry->getFilename()) !== 1) {
                throw new RuntimeException('Migration filenames must use NNNN_name.php.');
            }
            $paths[] = $entry->getPathname();
        }
        usort(
            $paths,
            static fn(string $left, string $right): int => strcmp(
                basename($left),
                basename($right)
            )
        );
        return $paths;
    }

    /** Apply pending staged migrations after validating the entire pending set.
     *
     * Migration PHP files must return list<string> SQL statements. Their SQL
     * must be backward-compatible and safe to retry after partial MariaDB DDL,
     * since DDL can implicitly commit before the ledger row is written.
     *
     * @param list<string> $paths Absolute trusted staged migration file paths.
     * @param PDO|null $schemaDatabase Optional DDL-capable connection; runtime is default.
     * @return int Number of migrations newly recorded as applied.
     */
    public function apply(array $paths, ?PDO $schemaDatabase = null): int
    {
        if (!array_is_list($paths)) {
            throw new RuntimeException('Migration paths must be a list.');
        }
        if ($paths === []) {
            return 0;
        }
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new RuntimeException('Migration paths must be strings.');
            }
            $metadata = $this->inspectPath($path);
            if (isset($files[$metadata['id']])) {
                throw new RuntimeException('Duplicate migration IDs are not allowed.');
            }
            $files[$metadata['id']] = $metadata;
        }
        ksort($files, SORT_STRING);

        $ledgerConnection = $schemaDatabase ?? $this->runtime;
        $ledger = new UpdateMigrationModel($ledgerConnection);
        $appliedHashes = $ledger->appliedHashes();
        $pendingFiles = [];
        foreach ($files as $id => $file) {
            if (isset($appliedHashes[$id])) {
                if (!hash_equals($appliedHashes[$id], $file['hash'])) {
                    throw new RuntimeException('An applied migration definition has changed.');
                }
                continue;
            }
            $pendingFiles[$id] = $file;
        }
        if ($pendingFiles === []) {
            return 0;
        }

        $pendingDefinitions = [];
        foreach ($pendingFiles as $id => $file) {
            $pendingDefinitions[$id] = $this->loadDefinition($file);
        }

        if (!$ledger->ledgerExists()) {
            $ledger->createLedger();
        }
        foreach ($pendingDefinitions as $id => $definition) {
            $ledger->executeStatements($definition['statements']);
            $ledger->recordApplied($id, $definition['hash']);
        }
        return count($pendingDefinitions);
    }

    /** Inspect a migration path and hash it without evaluating PHP code.
     * @param string $path Absolute migration file path.
     * @return array{id:string,path:string,hash:string} Validated path metadata.
     */
    private function inspectPath(string $path): array
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($normalizedPath, '/')
            || preg_match('~\A[A-Za-z]:/~D', $normalizedPath) === 1;
        if (!$isAbsolute || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Migration path must be an absolute regular file.');
        }
        $realPath = realpath($path);
        if (!is_string($realPath)
            || strcasecmp(basename(dirname($realPath)), 'migrations') !== 0
            || strcasecmp(basename(dirname(dirname($realPath))), 'database') !== 0) {
            throw new RuntimeException('Migration path must be inside database/migrations.');
        }
        $fileName = basename($realPath);
        if (preg_match(self::MIGRATION_FILENAME_PATTERN, $fileName) !== 1) {
            throw new RuntimeException('Migration filenames must use NNNN_name.php.');
        }
        $id = substr($fileName, 0, -4);
        if (strlen($id) > self::MIGRATION_ID_MAX_LENGTH) {
            throw new RuntimeException('Migration ID exceeds the ledger limit.');
        }
        $hash = hash_file('sha256', $realPath);
        if (!is_string($hash)) {
            throw new RuntimeException('Migration definition checksum could not be calculated.');
        }
        return ['id' => $id, 'path' => $realPath, 'hash' => $hash];
    }

    /** Load and validate a pending migration definition before database writes.
     * @param array{id:string,path:string,hash:string} $file Inspected migration metadata.
     * @return array{id:string,hash:string,statements:list<string>} Valid SQL definition.
     */
    private function loadDefinition(array $file): array
    {
        $currentHash = hash_file('sha256', $file['path']);
        if (!is_string($currentHash) || !hash_equals($file['hash'], $currentHash)) {
            throw new RuntimeException('Migration changed while it was being prepared.');
        }
        try {
            $statements = require $file['path'];
        } catch (Throwable $error) {
            throw new RuntimeException('Migration definition could not be loaded.', 0, $error);
        }
        if (!is_array($statements) || !array_is_list($statements)
            || $statements === []) {
            throw new RuntimeException('Migration must return a non-empty list of SQL statements.');
        }
        foreach ($statements as $statement) {
            if (!is_string($statement) || trim($statement) === '') {
                throw new RuntimeException('Migration statements must be non-empty SQL strings.');
            }
        }
        return [
            'id' => $file['id'],
            'hash' => $file['hash'],
            'statements' => array_values($statements),
        ];
    }
}