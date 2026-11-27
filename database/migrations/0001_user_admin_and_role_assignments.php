<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: database/migrations/0001_user_admin_and_role_assignments.php
 * Module Type: Database migration definition
 * Purpose: Add active-account administration and multi-user role invitations.
 * Responsibilities: Upgrade legacy account, project, take, and invite metadata safely.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Return retry-safe SQL for upgrading the 0.1.1 database schema.
 * @return list<string> Ordered DDL statements safe after partial execution.
 */
return [
    "SET @dabing_ddl := (
        SELECT IF(COUNT(*) = 0,
            'ALTER TABLE dabing_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
            'SET @dabing_noop = 0')
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dabing_users'
          AND COLUMN_NAME = 'is_active'
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    'UPDATE dabing_email_invites
     SET expires_at = LEAST(expires_at, DATE_ADD(delivered_at, INTERVAL 24 HOUR))
     WHERE delivered_at IS NOT NULL AND claimed_at IS NULL
       AND invalidated_at IS NULL',
    "SET @dabing_ddl := (
        SELECT IF(COUNT(*) = 0,
            'ALTER TABLE dabing_users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0',
            'SET @dabing_noop = 0')
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dabing_users'
          AND COLUMN_NAME = 'session_version'
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := (
        SELECT IF(COUNT(*) = 0,
            'ALTER TABLE dabing_email_invites ADD COLUMN delivery_error VARCHAR(255) NULL',
            'SET @dabing_noop = 0')
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dabing_email_invites'
          AND COLUMN_NAME = 'delivery_error'
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    'CREATE TABLE IF NOT EXISTS dabing_email_invite_roles (
        invite_id INT UNSIGNED NOT NULL,
        character_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        PRIMARY KEY (invite_id, character_name),
        CONSTRAINT fk_dabing_email_invite_roles_invite
            FOREIGN KEY (invite_id) REFERENCES dabing_email_invites (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS dabing_voice_assignments (
        project_id INT UNSIGNED NOT NULL,
        character_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (project_id, character_name, user_id),
        KEY idx_dabing_voice_assignments_member (project_id, user_id),
        CONSTRAINT fk_dabing_voice_assignments_project
            FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
            ON DELETE CASCADE,
        CONSTRAINT fk_dabing_voice_assignments_user
            FOREIGN KEY (user_id) REFERENCES dabing_users (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    "SET @dabing_ddl := IF(
        EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'dabing_projects'
                  AND CONSTRAINT_NAME = 'fk_dabing_projects_owner'
                  AND DELETE_RULE <> 'SET NULL'),
        'ALTER TABLE dabing_projects DROP FOREIGN KEY fk_dabing_projects_owner',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dabing_projects'
                  AND COLUMN_NAME = 'owner_user_id' AND IS_NULLABLE = 'NO'),
        'ALTER TABLE dabing_projects MODIFY owner_user_id INT UNSIGNED NULL',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'dabing_projects'
                      AND CONSTRAINT_NAME = 'fk_dabing_projects_owner'),
        'ALTER TABLE dabing_projects ADD CONSTRAINT fk_dabing_projects_owner FOREIGN KEY (owner_user_id) REFERENCES dabing_users (id) ON DELETE SET NULL',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'dabing_recordings'
                  AND CONSTRAINT_NAME = 'fk_dabing_recordings_user'
                  AND DELETE_RULE <> 'SET NULL'),
        'ALTER TABLE dabing_recordings DROP FOREIGN KEY fk_dabing_recordings_user',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dabing_recordings'
                  AND COLUMN_NAME = 'user_id' AND IS_NULLABLE = 'NO'),
        'ALTER TABLE dabing_recordings MODIFY user_id INT UNSIGNED NULL',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'dabing_recordings'
                      AND CONSTRAINT_NAME = 'fk_dabing_recordings_user'),
        'ALTER TABLE dabing_recordings ADD CONSTRAINT fk_dabing_recordings_user FOREIGN KEY (user_id) REFERENCES dabing_users (id) ON DELETE SET NULL',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'dabing_voice_assignments'
                  AND CONSTRAINT_NAME = 'fk_dabing_voice_assignments_member'),
        'ALTER TABLE dabing_voice_assignments DROP FOREIGN KEY fk_dabing_voice_assignments_member',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'dabing_voice_assignments'
                      AND INDEX_NAME = 'PRIMARY' AND SEQ_IN_INDEX = 3),
        'ALTER TABLE dabing_voice_assignments DROP PRIMARY KEY, ADD PRIMARY KEY (project_id, character_name, user_id)',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := IF(
        NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'dabing_voice_assignments'
                      AND CONSTRAINT_NAME = 'fk_dabing_voice_assignments_user'),
        'ALTER TABLE dabing_voice_assignments ADD CONSTRAINT fk_dabing_voice_assignments_user FOREIGN KEY (user_id) REFERENCES dabing_users (id) ON DELETE CASCADE',
        'SET @dabing_noop = 0')",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
    "SET @dabing_ddl := (
        SELECT CASE
            WHEN EXISTS (SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'dabing_voice_assignments') = 0
                THEN 'SET @dabing_noop = 0'
            WHEN EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                         WHERE CONSTRAINT_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'dabing_voice_assignments'
                           AND CONSTRAINT_NAME = 'fk_dabing_voice_assignments_project')
                THEN 'SET @dabing_noop = 0'
            ELSE 'ALTER TABLE dabing_voice_assignments ADD CONSTRAINT fk_dabing_voice_assignments_project FOREIGN KEY (project_id) REFERENCES dabing_projects (id) ON DELETE CASCADE'
        END
    )",
    'PREPARE dabing_stmt FROM @dabing_ddl',
    'EXECUTE dabing_stmt',
    'DEALLOCATE PREPARE dabing_stmt',
];
