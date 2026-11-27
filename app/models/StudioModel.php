<?php
declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/StudioModel.php
 * Module Type: Model
 * Purpose: Persist studio projects, membership, invites, script lines, and takes.
 * Responsibilities: Own SQL queries and map database rows for studio services.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Provides PDO persistence for private studio data. */
final class StudioModel
{
    /**
     * Create the persistence model.
     *
     * @param PDO $database Shared configured database connection.
     * @return void
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * List projects accessible to one user.
     *
     * @param int $userId Authenticated user identifier.
     * @return list<array{id:int,title:string,role:string,created_at:string,line_count:int,recording_count:int}>
     *     Project summary rows in newest-first order.
     */
    public function projectsForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT p.id, p.title, p.created_at,
                    CASE WHEN p.owner_user_id = :owner THEN \'owner\'
                         ELSE \'member\' END AS role,
                    (SELECT COUNT(*) FROM dabing_project_chapters c
                     JOIN dabing_script_lines l ON l.chapter_id = c.id
                     WHERE c.project_id = p.id) AS line_count,
                    (SELECT COUNT(*) FROM dabing_recordings r
                     JOIN dabing_script_lines l ON l.id = r.line_id
                     JOIN dabing_project_chapters c ON c.id = l.chapter_id
                     WHERE c.project_id = p.id) AS recording_count
             FROM dabing_projects p
             JOIN dabing_project_members m ON m.project_id = p.id
             WHERE m.user_id = :member
             ORDER BY p.created_at DESC, p.id DESC'
        );
        $statement->execute(['owner' => $userId, 'member' => $userId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'role' => (string) $row['role'],
            'created_at' => (string) $row['created_at'],
            'line_count' => (int) $row['line_count'],
            'recording_count' => (int) $row['recording_count'],
        ], $rows);
    }

    /**
     * Insert a new project with ordered chapters and lines in one transaction.
     *
     * @param int $ownerId Owner's authenticated user identifier.
     * @param array{title:string,chapters:list<array{title:string,lines:list<array{number:int,character:string,text:string}>}>} $script Validated import.
     * @return int New project identifier.
     */
    public function createProject(int $ownerId, array $script): int
    {
        $this->database->beginTransaction();
        try {
            $project = $this->database->prepare(
                'INSERT INTO dabing_projects (owner_user_id, title) VALUES (?, ?)'
            );
            $project->execute([$ownerId, $script['title']]);
            $projectId = (int) $this->database->lastInsertId();

            $member = $this->database->prepare(
                'INSERT INTO dabing_project_members (project_id, user_id) VALUES (?, ?)'
            );
            $member->execute([$projectId, $ownerId]);
            $chapterInsert = $this->database->prepare(
                'INSERT INTO dabing_project_chapters (project_id, position, title)
                 VALUES (?, ?, ?)'
            );
            $lineInsert = $this->database->prepare(
                'INSERT INTO dabing_script_lines
                    (chapter_id, position, character_name, text)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($script['chapters'] as $chapterIndex => $chapter) {
                $chapterInsert->execute([
                    $projectId,
                    $chapterIndex + 1,
                    $chapter['title'],
                ]);
                $chapterId = (int) $this->database->lastInsertId();
                foreach ($chapter['lines'] as $lineIndex => $line) {
                    $lineInsert->execute([
                        $chapterId,
                        $lineIndex + 1,
                        $line['character'],
                        $line['text'],
                    ]);
                }
            }
            $this->database->commit();
            return $projectId;
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Check whether a user owns a project without loading its script.
     *
     * @param int $projectId Requested project identifier.
     * @param int $userId Authenticated user identifier.
     * @return bool True only for the project owner.
     */
    public function isOwner(int $projectId, int $userId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM dabing_projects
             WHERE id = ? AND owner_user_id = ?'
        );
        $statement->execute([$projectId, $userId]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Fetch one project view model when the user is a member.
     *
     * @param int $projectId Requested project identifier.
     * @param int $userId Authenticated viewer identifier.
     * @return array{project:array{id:int,title:string,role:string},chapters:list<array{id:int,title:string,lines:list<array{id:int,number:int,position:int,character:string,text:string,selected_recording_id:?int,selected_recording_user_id:?int,takes:list<array{id:int,attempt_no:int,media_type:string,user_id:int,user_name:string,created_at:string,is_selected:bool}>}>}>,members:list<array{id:int,name:string,role:string}>}|null
     *     Ordered project data, or null when access is not allowed.
     */
    public function projectView(int $projectId, int $userId): ?array
    {
        $projectQuery = $this->database->prepare(
            'SELECT p.id, p.title, p.owner_user_id,
                    CASE WHEN p.owner_user_id = :owner THEN \'owner\'
                         ELSE \'member\' END AS role
             FROM dabing_projects p
             JOIN dabing_project_members m ON m.project_id = p.id
             WHERE p.id = :project AND m.user_id = :member'
        );
        $projectQuery->execute([
            'owner' => $userId,
            'project' => $projectId,
            'member' => $userId,
        ]);
        $projectRow = $projectQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($projectRow)) {
            return null;
        }

        $chapterQuery = $this->database->prepare(
            'SELECT id, title FROM dabing_project_chapters
             WHERE project_id = ? ORDER BY position, id'
        );
        $chapterQuery->execute([$projectId]);
        $chapterRows = $chapterQuery->fetchAll(PDO::FETCH_ASSOC);
        $chapters = [];
        $globalLineNumber = 0;
        $lineQuery = $this->database->prepare(
            'SELECT l.id, l.position, l.character_name, l.text,
                    l.selected_recording_id, selected.user_id AS selected_user_id,
                    r.id AS recording_id, r.user_id AS recording_user_id,
                    r.attempt_no, r.media_type,
                    u.name AS user_name, r.created_at
             FROM dabing_script_lines l
             LEFT JOIN dabing_recordings selected
               ON selected.id = l.selected_recording_id AND selected.line_id = l.id
             LEFT JOIN dabing_recordings r ON r.line_id = l.id
             LEFT JOIN dabing_users u ON u.id = r.user_id
             WHERE l.chapter_id = :chapter
             ORDER BY l.position, l.id, r.created_at, r.id'
        );
        foreach ($chapterRows as $chapterRow) {
            $lineQuery->execute(['chapter' => (int) $chapterRow['id']]);
            $lineRows = $lineQuery->fetchAll(PDO::FETCH_ASSOC);
            $lines = [];
            foreach ($lineRows as $row) {
                $lineId = (int) $row['id'];
                $lineIndex = count($lines) - 1;
                if ($lineIndex < 0 || $lines[$lineIndex]['id'] !== $lineId) {
                    ++$globalLineNumber;
                    $lines[] = [
                        'id' => $lineId,
                        'number' => $globalLineNumber,
                        'position' => (int) $row['position'],
                        'character' => (string) $row['character_name'],
                        'text' => (string) $row['text'],
                        'selected_recording_id' => $row['selected_recording_id'] === null
                            ? null : (int) $row['selected_recording_id'],
                        'selected_recording_user_id' => $row['selected_user_id'] === null
                            ? null : (int) $row['selected_user_id'],
                        'takes' => [],
                    ];
                    $lineIndex = count($lines) - 1;
                }
                if ($row['recording_id'] !== null) {
                    $lines[$lineIndex]['takes'][] = [
                        'id' => (int) $row['recording_id'],
                        'attempt_no' => (int) $row['attempt_no'],
                        'media_type' => (string) $row['media_type'],
                        'user_id' => (int) $row['recording_user_id'],
                        'user_name' => (string) $row['user_name'],
                        'created_at' => (string) $row['created_at'],
                        'is_selected' => (int) $row['recording_id']
                            === (int) ($row['selected_recording_id'] ?? 0),
                    ];
                }
            }
            $chapters[] = [
                'id' => (int) $chapterRow['id'],
                'title' => (string) $chapterRow['title'],
                'lines' => $lines,
            ];
        }

        $membersQuery = $this->database->prepare(
            'SELECT u.id, u.name, CASE WHEN u.id = p.owner_user_id
                    THEN \'owner\' ELSE \'member\' END AS role
             FROM dabing_project_members m
             JOIN dabing_users u ON u.id = m.user_id
             JOIN dabing_projects p ON p.id = m.project_id
             WHERE m.project_id = ? ORDER BY role, u.name, u.id'
        );
        $membersQuery->execute([$projectId]);
        $members = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
        ], $membersQuery->fetchAll(PDO::FETCH_ASSOC));

        return [
            'project' => [
                'id' => (int) $projectRow['id'],
                'title' => (string) $projectRow['title'],
                'role' => (string) $projectRow['role'],
            ],
            'chapters' => $chapters,
            'members' => $members,
        ];
    }

    /**
     * Insert a one-time invitation hash with expiration.
     *
     * @param int $projectId Project receiving a collaborator.
     * @param string $tokenHash SHA-256 digest of the raw invitation token.
     * @param string $expiresAt SQL timestamp expiration.
     * @return void
     */
    public function createInvite(int $projectId, string $tokenHash, string $expiresAt): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO dabing_project_invites (project_id, token_hash, expires_at)
             VALUES (?, ?, ?)'
        );
        $statement->execute([$projectId, $tokenHash, $expiresAt]);
    }

    /**
     * Find a usable invitation by digest and lock it for claim.
     *
     * @param string $tokenHash SHA-256 digest of the presented token.
     * @return array{id:int,project_id:int}|null Usable invite or null.
     */
    public function lockInvite(string $tokenHash): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, project_id FROM dabing_project_invites
             WHERE token_hash = ? AND claimed_at IS NULL AND expires_at > UTC_TIMESTAMP()
             FOR UPDATE'
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
        ] : null;
    }

    /**
     * Atomically claim an invite and add the user as a project member.
     *
     * @param string $tokenHash SHA-256 digest of the presented token.
     * @param int $userId Claiming authenticated user identifier.
     * @return int Project identifier joined by the user.
     * @throws FormError When invitation is invalid or already used.
     */
    public function claimInvite(string $tokenHash, int $userId): int
    {
        $this->database->beginTransaction();
        try {
            $invite = $this->lockInvite($tokenHash);
            if ($invite === null) {
                throw new FormError('Pozvánka je neplatná, expirovaná nebo již použitá.');
            }
            $member = $this->database->prepare(
                'INSERT IGNORE INTO dabing_project_members (project_id, user_id)
                 VALUES (?, ?)'
            );
            $member->execute([$invite['project_id'], $userId]);
            $claim = $this->database->prepare(
                'UPDATE dabing_project_invites
                 SET claimed_by_user_id = ?, claimed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND claimed_at IS NULL'
            );
            $claim->execute([$userId, $invite['id']]);
            if ($claim->rowCount() !== 1) {
                throw new FormError('Pozvánka již byla použita.');
            }
            $this->database->commit();
            return $invite['project_id'];
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Select a take when the requesting user owns the project.
     *
     * @param int $projectId Project to authorize.
     * @param int $lineId Script line receiving selection.
     * @param int $recordingId Existing take to select.
     * @param int $userId Authenticated requester.
     * @return bool True when selected, false if any relation is unauthorized.
     */
    public function selectTake(
        int $projectId,
        int $lineId,
        int $recordingId,
        int $userId
    ): bool {
        $statement = $this->database->prepare(
            'UPDATE dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_recordings r ON r.line_id = l.id
               AND r.id = :recording
             SET l.selected_recording_id = r.id
             WHERE l.id = :line AND c.project_id = :project
               AND p.owner_user_id = :owner'
        );
        $statement->execute([
            'recording' => $recordingId,
            'line' => $lineId,
            'project' => $projectId,
            'owner' => $userId,
        ]);
        if ($statement->rowCount() === 1) {
            return true;
        }
        $verify = $this->database->prepare(
            'SELECT 1 FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_recordings r ON r.line_id = l.id
             WHERE p.id = ? AND p.owner_user_id = ? AND l.id = ?
               AND r.id = ? AND l.selected_recording_id = r.id'
        );
        $verify->execute([$projectId, $userId, $lineId, $recordingId]);
        return $verify->fetchColumn() !== false;
    }

    /**
     * Read access metadata for an authorized project line.
     *
     * @param int $lineId Requested script line identifier.
     * @param int $userId Authenticated user identifier.
     * @return array{project_id:int}|null Authorized line scope or null.
     */
    public function lineAccess(int $lineId, int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT c.project_id
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_project_members m ON m.project_id = c.project_id
             WHERE l.id = ? AND m.user_id = ?'
        );
        $statement->execute([$lineId, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['project_id' => (int) $row['project_id']] : null;
    }

    /**
     * Append a new attempt after locking the authorized script line.
     *
     * @param int $projectId Project scope for authorization.
     * @param int $lineId Script line receiving the new take.
     * @param int $userId Authenticated recorder.
     * @param string $storageKey Random non-executable relative storage key.
     * @param string $mediaType Verified audio MIME type.
     * @param int $byteSize Verified upload size.
     * @return array{storage_key:string,recording_id:int,attempt_no:int,line_number:int,project_title:string} Persisted take and naming data.
     * @throws FormError When access is denied or the attempt cap is exhausted.
     */
    public function addRecording(
        int $projectId,
        int $lineId,
        int $userId,
        string $storageKey,
        string $mediaType,
        int $byteSize
    ): array {
        $this->database->beginTransaction();
        try {
            $access = $this->database->prepare(
                'SELECT p.title, c.project_id, c.position AS chapter_position,
                        l.position AS line_position,
                        (SELECT COUNT(*)
                         FROM dabing_project_chapters prior_chapter
                         JOIN dabing_script_lines prior_line
                           ON prior_line.chapter_id = prior_chapter.id
                         WHERE prior_chapter.project_id = c.project_id
                           AND (prior_chapter.position < c.position
                             OR (prior_chapter.position = c.position
                               AND prior_line.position <= l.position))) AS line_number
                 FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 JOIN dabing_projects p ON p.id = c.project_id
                 JOIN dabing_project_members m ON m.project_id = p.id
                 WHERE p.id = ? AND l.id = ? AND m.user_id = ?
                 FOR UPDATE'
            );
            $access->execute([$projectId, $lineId, $userId]);
            $line = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($line)) {
                throw new FormError('K této replice nemáte přístup.');
            }

            $lastAttempt = $this->database->prepare(
                'SELECT COALESCE(MAX(attempt_no), 0)
                 FROM dabing_recordings WHERE line_id = ?'
            );
            $lastAttempt->execute([$lineId]);
            $attemptNo = (int) $lastAttempt->fetchColumn() + 1;
            if ($attemptNo > TakeNaming::MAX_ATTEMPTS) {
                throw new FormError('Tato replika už má maximální počet pokusů.');
            }

            $insert = $this->database->prepare(
                'INSERT INTO dabing_recordings
                    (line_id, user_id, storage_key, media_type, byte_size, attempt_no)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $lineId,
                $userId,
                $storageKey,
                $mediaType,
                $byteSize,
                $attemptNo,
            ]);
            $recordingId = (int) $this->database->lastInsertId();
            $selection = $this->database->prepare(
                'UPDATE dabing_script_lines SET selected_recording_id = ?
                 WHERE id = ? AND selected_recording_id IS NULL'
            );
            $selection->execute([$recordingId, $lineId]);
            $this->database->commit();
            return [
                'storage_key' => $storageKey,
                'recording_id' => $recordingId,
                'attempt_no' => $attemptNo,
                'line_number' => (int) $line['line_number'],
                'project_title' => (string) $line['title'],
            ];
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Fetch authorized recording and its ordered filename context.
     *
     * @param int $recordingId Requested recording identifier.
     * @param int $userId Authenticated user identifier.
     * @return array{id:int,project_id:int,project_title:string,line_number:int,attempt_no:int,storage_key:string,media_type:string,byte_size:int}|null Authorized take or null.
     */
    public function recordingForUser(int $recordingId, int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT r.id, c.project_id, p.title AS project_title,
                    r.attempt_no, r.storage_key, r.media_type, r.byte_size,
                    (SELECT COUNT(*)
                     FROM dabing_project_chapters prior_chapter
                     JOIN dabing_script_lines prior_line
                       ON prior_line.chapter_id = prior_chapter.id
                     WHERE prior_chapter.project_id = c.project_id
                       AND (prior_chapter.position < c.position
                         OR (prior_chapter.position = c.position
                           AND prior_line.position <= l.position))) AS line_number
             FROM dabing_recordings r
             JOIN dabing_script_lines l ON l.id = r.line_id
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_project_members m ON m.project_id = c.project_id
             WHERE r.id = ? AND m.user_id = ?'
        );
        $statement->execute([$recordingId, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'project_title' => (string) $row['project_title'],
            'line_number' => (int) $row['line_number'],
            'attempt_no' => (int) $row['attempt_no'],
            'storage_key' => (string) $row['storage_key'],
            'media_type' => (string) $row['media_type'],
            'byte_size' => (int) $row['byte_size'],
        ] : null;
    }
}