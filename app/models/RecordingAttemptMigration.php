<?php
declare(strict_types=1);

namespace Dabing;

use PDO;
use RuntimeException;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/RecordingAttemptMigration.php
 * Module Type: Model migration
 * Purpose: Upgrade installed recording rows to numbered take attempts.
 * Responsibilities: Introspect schema and safely backfill without deleting user data.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Applies the idempotent 001 recording-attempt schema migration. */
final class RecordingAttemptMigration
{
    /**
     * Create the migration runner.
     *
     * @param PDO $database DDL-capable connection to the installed Dabing database.
     * @return void
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Apply the versioned attempt-number upgrade without deleting recording rows.
     *
     * @return void No value is returned.
     * @throws RuntimeException When the table is missing, legacy row volume cannot fit,
     *     or the resulting schema cannot be made safe.
     */
    public function run(): void
    {
        $columns = $this->columns();
        if (!isset($columns['line_id'], $columns['user_id'], $columns['id'])) {
            throw new RuntimeException('dabing_recordings is missing required legacy columns.');
        }
        $this->assertBackfillFits();
        if (!isset($columns['attempt_no'])) {
            $this->database->exec(
                'ALTER TABLE dabing_recordings
                 ADD COLUMN attempt_no SMALLINT UNSIGNED NULL'
            );
            $columns = $this->columns();
        }

        if ($columns['attempt_no']['nullable']) {
            $this->backfill();
        }
        $this->ensureAttemptIndex();
        $columns = $this->columns();
        if ($columns['attempt_no']['nullable']) {
            $nullCount = (int) $this->database->query(
                'SELECT COUNT(*) FROM dabing_recordings WHERE attempt_no IS NULL'
            )->fetchColumn();
            if ($nullCount !== 0) {
                throw new RuntimeException('Some recording attempts remain unnumbered.');
            }
            $this->database->exec(
                'ALTER TABLE dabing_recordings
                 MODIFY COLUMN attempt_no SMALLINT UNSIGNED NOT NULL'
            );
        }
        $this->dropLegacyUserIndex();
    }

    /**
     * Read the recording table column nullability from the active database.
     *
     * @return array<string,array{nullable:bool}> Column metadata keyed by name.
     */
    private function columns(): array
    {
        $statement = $this->database->query(
            'SELECT COLUMN_NAME, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = \'dabing_recordings\''
        );
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) $row['COLUMN_NAME']] = [
                'nullable' => (string) $row['IS_NULLABLE'] === 'YES',
            ];
        }
        return $columns;
    }

    /**
     * Reject legacy data that cannot fit the supported attempt sequence.
     *
     * @return void No value is returned.
     * @throws RuntimeException When any line exceeds 999 existing recordings.
     */
    private function assertBackfillFits(): void
    {
        $overflow = $this->database->query(
            'SELECT line_id FROM dabing_recordings
             GROUP BY line_id HAVING COUNT(*) > ' . TakeNaming::MAX_ATTEMPTS . ' LIMIT 1'
        )->fetchColumn();
        if ($overflow !== false) {
            throw new RuntimeException(
                'A line has more existing takes than the supported '
                . TakeNaming::MAX_ATTEMPTS . ' attempts.'
            );
        }
    }
    /**
     * Number old recordings deterministically by line and ascending recording id.
     *
     * @return void No value is returned.
     * @throws RuntimeException When any line has more rows than the supported cap.
     */
    private function backfill(): void
    {

        $this->database->beginTransaction();
        try {
            $rows = $this->database->query(
                'SELECT id, line_id FROM dabing_recordings ORDER BY line_id, id'
            )->fetchAll(PDO::FETCH_ASSOC);
            $update = $this->database->prepare(
                'UPDATE dabing_recordings SET attempt_no = ? WHERE id = ?'
            );
            $lastLineId = null;
            $attempt = 0;
            foreach ($rows as $row) {
                $lineId = (int) $row['line_id'];
                if ($lineId !== $lastLineId) {
                    $lastLineId = $lineId;
                    $attempt = 0;
                }
                ++$attempt;
                $update->execute([$attempt, (int) $row['id']]);
            }
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Ensure a unique key exists on line and attempt number in that order.
     *
     * @return void No value is returned.
     * @throws RuntimeException When the expected index name conflicts with another key.
     */
    private function ensureAttemptIndex(): void
    {
        $indexes = $this->indexes();
        $targetColumns = ['line_id', 'attempt_no'];
        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] === $targetColumns) {
                return;
            }
        }
        if (isset($indexes['uq_dabing_recording_line_attempt'])) {
            throw new RuntimeException('The attempt index name is already used incompatibly.');
        }
        $this->database->exec(
            'ALTER TABLE dabing_recordings
             ADD UNIQUE KEY uq_dabing_recording_line_attempt (line_id, attempt_no)'
        );
    }

    /**
     * Drop the former one-recording-per-user constraint after the new key is live.
     *
     * @return void No value is returned.
     * @throws RuntimeException When the attempt key is absent or incompatible.
     */
    private function dropLegacyUserIndex(): void
    {
        $indexes = $this->indexes();
        if (!isset($indexes['uq_dabing_recording_line_attempt'])
            || !$indexes['uq_dabing_recording_line_attempt']['unique']
            || $indexes['uq_dabing_recording_line_attempt']['columns']
                !== ['line_id', 'attempt_no']) {
            throw new RuntimeException('The unique line-attempt key is not installed.');
        }
        if (isset($indexes['uq_dabing_recording_user_line'])) {
            $this->database->exec(
                'ALTER TABLE dabing_recordings
                 DROP INDEX uq_dabing_recording_user_line'
            );
        }
    }

    /**
     * Read index uniqueness and ordered column names for the recordings table.
     *
     * @return array<string,array{unique:bool,columns:list<string>}> Index metadata.
     */
    private function indexes(): array
    {
        $statement = $this->database->query(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = \'dabing_recordings\'
             ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexes[$name] ??= [
                'unique' => (int) $row['NON_UNIQUE'] === 0,
                'columns' => [],
            ];
            if ($row['COLUMN_NAME'] !== null) {
                $indexes[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            }
        }
        return $indexes;
    }
}