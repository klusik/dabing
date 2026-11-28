<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: database/migrations/0003_audio_profiles.php
 * Module Type: Database migration definition
 * Purpose: Add reusable audio profiles and project-scoped assignments.
 * Responsibilities: Create profile and override persistence for audio rendering.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Return idempotent SQL for reusable audio profiles and overrides.
 * @return list<string> Ordered DDL statements safe after partial execution.
 */
return [
    'CREATE TABLE IF NOT EXISTS dabing_audio_profiles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        chain_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dabing_audio_profiles_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS dabing_audio_profile_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id INT UNSIGNED NOT NULL,
        scope ENUM(\'voice\', \'line\', \'take\') NOT NULL,
        target_id INT UNSIGNED NULL,
        character_name VARCHAR(100)
            CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
        user_id INT UNSIGNED NULL,
        target_key VARCHAR(160)
            CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        profile_id INT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dabing_audio_assignment_target
            (project_id, scope, target_key),
        KEY idx_dabing_audio_assignment_profile (profile_id),
        CONSTRAINT fk_dabing_audio_assignment_project
            FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
            ON DELETE CASCADE,
        CONSTRAINT fk_dabing_audio_assignment_profile
            FOREIGN KEY (profile_id) REFERENCES dabing_audio_profiles (id)
            ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];
