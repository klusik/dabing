-- Project: Dabing
-- Repository: klusik/dabing
-- File: database/studio.sql
-- Module Type: Database schema
-- Purpose: Persist private book projects, collaborators, script lines, and takes.
-- Responsibilities: Define the transactional studio schema using installer users.
-- Author: Rudolf Klusal
-- License: All rights reserved.

CREATE TABLE IF NOT EXISTS dabing_projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dabing_projects_owner (owner_user_id),
    CONSTRAINT fk_dabing_projects_owner
        FOREIGN KEY (owner_user_id) REFERENCES dabing_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_project_members (
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    CONSTRAINT fk_dabing_members_project
        FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dabing_members_user
        FOREIGN KEY (user_id) REFERENCES dabing_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_project_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    claimed_by_user_id INT UNSIGNED NULL,
    claimed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dabing_invites_hash (token_hash),
    KEY idx_dabing_invites_project (project_id),
    CONSTRAINT fk_dabing_invites_project
        FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dabing_invites_claimant
        FOREIGN KEY (claimed_by_user_id) REFERENCES dabing_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_project_chapters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dabing_chapter_order (project_id, position),
    CONSTRAINT fk_dabing_chapters_project
        FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_script_lines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chapter_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    character_name VARCHAR(100) NOT NULL,
    text TEXT NOT NULL,
    selected_recording_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dabing_line_order (chapter_id, position),
    KEY idx_dabing_lines_selected (selected_recording_id),
    CONSTRAINT fk_dabing_lines_chapter
        FOREIGN KEY (chapter_id) REFERENCES dabing_project_chapters (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_recordings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    line_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    storage_key CHAR(64) NOT NULL,
    media_type VARCHAR(64) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dabing_recording_line_attempt (line_id, attempt_no),
    UNIQUE KEY uq_dabing_recording_storage_key (storage_key),
    CONSTRAINT fk_dabing_recordings_line
        FOREIGN KEY (line_id) REFERENCES dabing_script_lines (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dabing_recordings_user
        FOREIGN KEY (user_id) REFERENCES dabing_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_voice_assignments (
    project_id INT UNSIGNED NOT NULL,
    character_name VARCHAR(100)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- selected_recording_id is checked against the same line in every read/write query;
-- keeping it unconstrained avoids a circular table dependency and allows retry-safe installs.
