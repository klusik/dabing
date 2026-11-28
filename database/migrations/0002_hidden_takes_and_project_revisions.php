<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: database/migrations/0002_hidden_takes_and_project_revisions.php
 * Module Type: Database migration definition
 * Purpose: Add reversible take visibility and persisted project revisions.
 * Responsibilities: Upgrade recording visibility and live-view revision storage.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Return retry-safe SQL for the 0.2.2 take-visibility upgrade.
 * @return list<string> Ordered DDL statements safe after partial execution.
 */
return [
    "SET @dabing_ddl := IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dabing_recordings'
              AND COLUMN_NAME = 'is_hidden'
        ),
        'ALTER TABLE dabing_recordings ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0',
        'SET @dabing_noop = 0'
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dabing_projects'
              AND COLUMN_NAME = 'revision'
        ),
        'ALTER TABLE dabing_projects ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'SET @dabing_noop = 0'
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
];
