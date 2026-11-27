-- Project: Dabing
-- Repository: klusik/dabing
-- File: database/email-invites.sql
-- Module Type: Database schema
-- Purpose: Store email invitations, selected voice roles, and sending limits.
-- Responsibilities: Persist invite status and optional role selections.
-- Author: Rudolf Klusal
-- License: All rights reserved.

CREATE TABLE IF NOT EXISTS dabing_email_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    invited_email VARCHAR(254) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    claimed_by_user_id INT UNSIGNED NULL,
    claimed_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    delivery_error VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dabing_email_invites_code (code_hash),
    KEY idx_dabing_email_invites_project_email (project_id, invited_email),
    CONSTRAINT fk_dabing_email_invites_project
        FOREIGN KEY (project_id) REFERENCES dabing_projects (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dabing_email_invites_creator
        FOREIGN KEY (created_by_user_id) REFERENCES dabing_users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dabing_email_invites_claimant
        FOREIGN KEY (claimed_by_user_id) REFERENCES dabing_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_email_invite_roles (
    invite_id INT UNSIGNED NOT NULL,
    character_name VARCHAR(100)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (invite_id, character_name),
    CONSTRAINT fk_dabing_email_invite_roles_invite
        FOREIGN KEY (invite_id) REFERENCES dabing_email_invites (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dabing_email_invite_limits (
    owner_user_id INT UNSIGNED NOT NULL,
    sent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    PRIMARY KEY (owner_user_id),
    CONSTRAINT fk_dabing_email_invite_limits_owner
        FOREIGN KEY (owner_user_id) REFERENCES dabing_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
