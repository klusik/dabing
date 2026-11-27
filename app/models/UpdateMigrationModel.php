<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/UpdateMigrationModel.php
 * Module Type: Model
 * Purpose: Store checksums for applied schema migrations.
 * Responsibilities: Inspect and update the migration ledger using PDO.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns schema migration ledger SQL and row mapping. */
final class UpdateMigrationModel
{
    /** Maximum migration identifier stored by the ledger.
     * Type: int; Units: characters; Scope: one migration ID;
     * Consumers: migration identifier validation; Rationale: bound ledger keys.
     */
    private const MIGRATION_ID_MAX_LENGTH = 128;

    /** SHA-256 hex digest length stored for each migration definition.
     * Type: int; Units: hexadecimal characters; Scope: one migration definition;
     * Consumers: ledger validation; Rationale: match SHA-256 output width.
     */
    private const DEFINITION_HASH_LENGTH = 64;

    /** Create the model on its designated database connection.
     * @param PDO $database Runtime or schema-capable database connection.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Check whether the migration ledger exists without attempting a failed query.
     * @return bool True when the current database contains the ledger table.
     */
    public function ledgerExists(): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() '
            . 'AND table_name = :table_name'
        );
        $statement->execute(['table_name' => 'dabing_schema_migrations']);
        return (int) $statement->fetchColumn() > 0;
    }

    /** Read migration IDs and definition hashes from the ledger.
     * @return array<string,string> Map of migration ID to recorded SHA-256 hash.
     */
    public function appliedHashes(): array
    {
        if (!$this->ledgerExists()) {
            return [];
        }
        $statement = $this->database->query(
            'SELECT migration_id, definition_sha256 '
            . 'FROM dabing_schema_migrations'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            $id = (string) $row['migration_id'];
            $hash = (string) $row['definition_sha256'];
            if (strlen($id) > self::MIGRATION_ID_MAX_LENGTH
                || strlen($hash) !== self::DEFINITION_HASH_LENGTH
                || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new \RuntimeException('The migration ledger contains invalid data.');
            }
            $applied[$id] = $hash;
        }
        return $applied;
    }

    /** Create the ledger only after the service found pending migrations.
     * @return void No return value.
     */
    public function createLedger(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS dabing_schema_migrations ('
            . 'migration_id VARCHAR(128) NOT NULL, '
            . 'definition_sha256 CHAR(64) NOT NULL, '
            . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY (migration_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
            . 'COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Execute one already-validated migration statement list.
     * @param list<string> $statements Non-empty SQL strings from a trusted migration file.
     * @return void No return value.
     */
    public function executeStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->database->exec($statement);
        }
    }

    /** Record a fully applied migration after all its SQL statements succeed.
     * @param string $migrationId Validated migration basename without extension.
     * @param string $definitionHash SHA-256 digest of the PHP definition file.
     * @return void No return value.
     */
    public function recordApplied(string $migrationId, string $definitionHash): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO dabing_schema_migrations '
            . '(migration_id, definition_sha256, applied_at) '
            . 'VALUES (:migration_id, :definition_sha256, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'migration_id' => $migrationId,
            'definition_sha256' => $definitionHash,
        ]);
    }
}
