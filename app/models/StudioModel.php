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
     * Type: int; Units: lines; Scope: one rendered project page; Consumers:
     * projectView(); Rationale: keeps the DOM and inline recording controls usable.
     */
    private const PROJECT_PAGE_SIZE = 80;

    /**
     * Type: int; Units: lines per statement; Scope: one project import;
     * Consumers: createProject(); Rationale: reduce database round trips while
     * keeping each four-parameter row batch bounded to 800 placeholders.
     */
    private const LINE_INSERT_BATCH_SIZE = 200;

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
            foreach ($script['chapters'] as $chapterIndex => $chapter) {
                $chapterInsert->execute([
                    $projectId,
                    $chapterIndex + 1,
                    $chapter['title'],
                ]);
                $chapterId = (int) $this->database->lastInsertId();
                foreach (array_chunk(
                    $chapter['lines'],
                    self::LINE_INSERT_BATCH_SIZE
                ) as $batchIndex => $lineBatch) {
                    $rowCount = count($lineBatch);
                    $valuesSql = implode(
                        ', ',
                        array_fill(0, $rowCount, '(?, ?, ?, ?)')
                    );
                    $lineInsert = $this->database->prepare(
                        'INSERT INTO dabing_script_lines
                            (chapter_id, position, character_name, text)
                         VALUES ' . $valuesSql
                    );
                    $parameters = [];
                    $firstPosition = ($batchIndex
                        * self::LINE_INSERT_BATCH_SIZE) + 1;
                    foreach ($lineBatch as $batchOffset => $line) {
                        $parameters[] = $chapterId;
                        $parameters[] = $firstPosition + $batchOffset;
                        $parameters[] = $line['character'];
                        $parameters[] = $line['text'];
                    }
                    $lineInsert->execute($parameters);
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
     * Fetch the current project page, complete playlist, and project-wide voices.
     *
     * @param int $projectId Requested project identifier.
     * @param int $userId Authenticated viewer identifier.
     * @param int $requestedPage One-based visible script page.
     * @return array{project:array{id:int,title:string,role:string},chapters:list<array{id:int,title:string,lines:list<array{id:int,number:int,position:int,character:string,text:string,selected_recording_id:?int,selected_recording_user_id:?int,assigned_name:?string,can_record:bool,takes:list<array{id:int,attempt_no:int,media_type:string,user_id:int,user_name:string,created_at:string,is_selected:bool,can_delete:bool}>}>}>,members:list<array{id:int,name:string,role:string}>,voices:list<array{character:string,assigned_user_id:?int,assigned_name:?string}>,playlist:list<array{recording_id:int,line_number:int,character:string,chapter_title:string,attempt_no:int,media_type:string}>,pagination:array{page:int,pages:int,total:int}}|null
     *     Ordered page and playback data, or null when access is not allowed.
     */
    public function projectView(
        int $projectId,
        int $userId,
        int $requestedPage = 1
    ): ?array {
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

        $totalQuery = $this->database->prepare(
            'SELECT COUNT(*)
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             WHERE c.project_id = ?'
        );
        $totalQuery->execute([$projectId]);
        $totalLines = (int) $totalQuery->fetchColumn();
        $pages = max(1, (int) ceil($totalLines / self::PROJECT_PAGE_SIZE));
        $page = max(1, min($requestedPage, $pages));
        $offset = ($page - 1) * self::PROJECT_PAGE_SIZE;

        $lineQuery = $this->database->prepare(
            'SELECT page_line.id, page_line.position, page_line.number,
                    page_line.chapter_id, page_line.chapter_title,
                    page_line.chapter_position, page_line.character_name,
                    page_line.text, page_line.selected_recording_id,
                    selected.user_id AS selected_user_id,
                    r.id AS recording_id, r.attempt_no, r.media_type,
                    r.user_id AS recording_user_id, u.name AS user_name,
                    r.created_at
             FROM (
                 SELECT l.id, l.position, l.chapter_id, l.character_name,
                        l.text, l.selected_recording_id,
                        c.title AS chapter_title, c.position AS chapter_position,
                        (SELECT COUNT(*)
                         FROM dabing_project_chapters prior_chapter
                         JOIN dabing_script_lines prior_line
                           ON prior_line.chapter_id = prior_chapter.id
                         WHERE prior_chapter.project_id = c.project_id
                           AND (prior_chapter.position < c.position
                             OR (prior_chapter.position = c.position
                               AND (prior_line.position < l.position
                                 OR (prior_line.position = l.position
                                   AND prior_line.id <= l.id))))) AS number
                 FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = :project
                 ORDER BY c.position, c.id, l.position, l.id
                 LIMIT :page_size OFFSET :page_offset
             ) page_line
             LEFT JOIN dabing_recordings selected
               ON selected.id = page_line.selected_recording_id
              AND selected.line_id = page_line.id
             LEFT JOIN dabing_recordings r ON r.line_id = page_line.id
             LEFT JOIN dabing_users u ON u.id = r.user_id
             ORDER BY page_line.chapter_position, page_line.chapter_id,
                      page_line.position, page_line.id, r.attempt_no'
        );
        $lineQuery->bindValue('project', $projectId, PDO::PARAM_INT);
        $lineQuery->bindValue('page_size', self::PROJECT_PAGE_SIZE, PDO::PARAM_INT);
        $lineQuery->bindValue('page_offset', $offset, PDO::PARAM_INT);
        $lineQuery->execute();
        $chapters = [];
        $chapterIndexes = [];
        $lineIndexes = [];
        foreach ($lineQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chapterId = (int) $row['chapter_id'];
            if (!isset($chapterIndexes[$chapterId])) {
                $chapterIndexes[$chapterId] = count($chapters);
                $chapters[] = [
                    'id' => $chapterId,
                    'title' => (string) $row['chapter_title'],
                    'lines' => [],
                ];
            }
            $chapterIndex = $chapterIndexes[$chapterId];
            $lineId = (int) $row['id'];
            $lineKey = (string) $lineId;
            if (!isset($lineIndexes[$lineKey])) {
                $lineIndexes[$lineKey] = count($chapters[$chapterIndex]['lines']);
                $chapters[$chapterIndex]['lines'][] = [
                    'id' => $lineId,
                    'number' => (int) $row['number'],
                    'position' => (int) $row['position'],
                    'character' => (string) $row['character_name'],
                    'text' => (string) $row['text'],
                    'selected_recording_id' => $row['selected_recording_id'] === null
                        ? null : (int) $row['selected_recording_id'],
                    'selected_recording_user_id' => $row['selected_user_id'] === null
                        ? null : (int) $row['selected_user_id'],
                    'assigned_name' => null,
                    'can_record' => true,
                    'takes' => [],
                ];
            }
            $lineIndex = $lineIndexes[$lineKey];
            if ($row['recording_id'] !== null) {
                $chapters[$chapterIndex]['lines'][$lineIndex]['takes'][] = [
                    'id' => (int) $row['recording_id'],
                    'attempt_no' => (int) $row['attempt_no'],
                    'media_type' => (string) $row['media_type'],
                    'user_id' => (int) $row['recording_user_id'],
                    'user_name' => (string) $row['user_name'],
                    'created_at' => (string) $row['created_at'],
                    'is_selected' => (int) $row['recording_id']
                        === (int) ($row['selected_recording_id'] ?? 0),
                    'can_delete' => $project['role'] === 'owner'
                        || (int) $row['recording_user_id'] === $userId,
                ];
            }
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

        $assignmentQuery = $this->database->prepare(
            'SELECT a.character_name, a.user_id, u.name AS assigned_name
             FROM dabing_voice_assignments a
             JOIN dabing_users u ON u.id = a.user_id
             WHERE a.project_id = ? ORDER BY a.character_name'
        );
        $assignmentQuery->execute([$projectId]);
        $assignments = [];
        foreach ($assignmentQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignments[bin2hex((string) $row['character_name'])] = [
                'assigned_user_id' => (int) $row['user_id'],
                'assigned_name' => (string) $row['assigned_name'],
            ];
        }

        $rolesQuery = $this->database->prepare(
            'SELECT l.id, l.character_name
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             WHERE c.project_id = ? ORDER BY c.position, c.id, l.position, l.id'
        );
        $rolesQuery->execute([$projectId]);
        $lineNumbers = [];
        $voicesByName = [];
        $lineNumber = 0;
        foreach ($rolesQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            ++$lineNumber;
            $lineNumbers[(string) $row['id']] = $lineNumber;
            $character = (string) $row['character_name'];
            $voiceKey = bin2hex($character);
            $assignment = $assignments[$voiceKey] ?? [
                'assigned_user_id' => null,
                'assigned_name' => null,
            ];
            $voicesByName[$voiceKey] ??= [
                'character' => $character,
                'assigned_user_id' => $assignment['assigned_user_id'],
                'assigned_name' => $assignment['assigned_name'],
            ];
        }

        $isOwner = (int) $projectRow['owner_user_id'] === $userId;
        foreach ($chapters as &$chapter) {
            foreach ($chapter['lines'] as &$line) {
                $assignment = $assignments[bin2hex($line['character'])] ?? [
                    'assigned_user_id' => null,
                    'assigned_name' => null,
                ];
                $line['assigned_name'] = $assignment['assigned_name'];
                $line['can_record'] = $isOwner
                    || $assignment['assigned_user_id'] === null
                    || $assignment['assigned_user_id'] === $userId;
            }
            unset($line);
        }
        unset($chapter);

        $playlistQuery = $this->database->prepare(
            'SELECT r.id AS recording_id, l.id AS line_id,
                    l.character_name, c.title AS chapter_title,
                    r.attempt_no, r.media_type
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_recordings r
               ON r.id = l.selected_recording_id AND r.line_id = l.id
             WHERE c.project_id = ? ORDER BY c.position, c.id, l.position, l.id'
        );
        $playlistQuery->execute([$projectId]);
        $playlist = [];
        foreach ($playlistQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $playlist[] = [
                'recording_id' => (int) $row['recording_id'],
                'line_number' => $lineNumbers[(string) $row['line_id']],
                'character' => (string) $row['character_name'],
                'chapter_title' => (string) $row['chapter_title'],
                'attempt_no' => (int) $row['attempt_no'],
                'media_type' => (string) $row['media_type'],
            ];
        }

        return [
            'project' => [
                'id' => (int) $projectRow['id'],
                'title' => (string) $projectRow['title'],
                'role' => (string) $projectRow['role'],
            ],
            'chapters' => $chapters,
            'members' => $members,
            'voices' => array_values($voicesByName),
            'playlist' => $playlist,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'total' => $totalLines,
            ],
        ];
    }

    /**
     * Insert a one-time invitation hash with expiration. with expiration.
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
     * Check whether a project member may record a line under voice assignment.
     *
     * @param int $projectId Requested project.
     * @param int $lineId Requested script line.
     * @param int $userId Authenticated user.
     * @return bool True for the owner, the assigned member, or an unassigned role.
     */
    public function canRecord(int $projectId, int $lineId, int $userId): bool
    {
        $statement = $this->database->prepare(
            'SELECT p.owner_user_id, a.user_id AS assigned_user_id
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_project_members m ON m.project_id = p.id
             LEFT JOIN dabing_voice_assignments a
               ON a.project_id = p.id
              AND CAST(a.character_name AS BINARY)
                  = CAST(l.character_name AS BINARY)
             WHERE p.id = ? AND l.id = ? AND m.user_id = ?'
        );
        $statement->execute([$projectId, $lineId, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        return (int) $row['owner_user_id'] === $userId
            || $row['assigned_user_id'] === null
            || (int) $row['assigned_user_id'] === $userId;
    }

    /**
     * Assign or clear one exact script character for a project owner.
     *
     * @param int $projectId Requested project.
     * @param int $ownerId Authenticated project owner.
     * @param string $character Exact character label from the script.
     * @param int|null $userId Member receiving the assignment, or null to clear.
     * @return void No value is returned.
     * @throws FormError When owner, character, or member validation fails.
     */
    public function assignVoice(
        int $projectId,
        int $ownerId,
        string $character,
        ?int $userId
    ): void {
        $this->database->beginTransaction();
        try {
            $owner = $this->database->prepare(
                'SELECT id FROM dabing_projects
                 WHERE id = ? AND owner_user_id = ?'
            );
            $owner->execute([$projectId, $ownerId]);
            if ($owner->fetchColumn() === false) {
                throw new FormError('Obsazení může upravit pouze autor knihy.');
            }

            $roles = $this->database->prepare(
                'SELECT l.id
                 FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ?
                   AND CAST(l.character_name AS BINARY) = CAST(? AS BINARY)
                 ORDER BY l.id FOR UPDATE'
            );
            $roles->execute([$projectId, $character]);
            if ($roles->fetchAll(PDO::FETCH_COLUMN) === []) {
                throw new FormError('Tato postava v knize nemá žádné repliky.');
            }

            if ($userId !== null) {
                $member = $this->database->prepare(
                    'SELECT 1 FROM dabing_project_members
                     WHERE project_id = ? AND user_id = ?'
                );
                $member->execute([$projectId, $userId]);
                if ($member->fetchColumn() === false) {
                    throw new FormError('Obsazení musí vybrat člena této knihy.');
                }
                $assignment = $this->database->prepare(
                    'INSERT INTO dabing_voice_assignments
                        (project_id, character_name, user_id)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                         assigned_at = CURRENT_TIMESTAMP'
                );
                $assignment->execute([$projectId, $character, $userId]);
            } else {
                $clear = $this->database->prepare(
                    'DELETE FROM dabing_voice_assignments
                     WHERE project_id = ?
                       AND CAST(character_name AS BINARY) = CAST(? AS BINARY)'
                );
                $clear->execute([$projectId, $character]);
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
                'SELECT p.title, p.owner_user_id,
                        a.user_id AS assigned_user_id,
                        c.project_id, c.position AS chapter_position,
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
                 LEFT JOIN dabing_voice_assignments a
                   ON a.project_id = p.id
                  AND CAST(a.character_name AS BINARY)
                      = CAST(l.character_name AS BINARY)
                 WHERE p.id = ? AND l.id = ? AND m.user_id = ?
                 FOR UPDATE'
            );
            $access->execute([$projectId, $lineId, $userId]);
            $line = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($line)) {
                throw new FormError('K této replice nemáte přístup.');
            }
            if ((int) $line['owner_user_id'] !== $userId
                && $line['assigned_user_id'] !== null
                && (int) $line['assigned_user_id'] !== $userId) {
                throw new FormError('Tato postava je přiřazena jinému hlasu.');
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
     * Lock a take and validate the requester before private-file staging.
     *
     * @param int $projectId Project containing the take.
     * @param int $lineId Script line containing the take.
     * @param int $recordingId Take selected for removal.
     * @param int $userId Authenticated owner or member.
     * @return array{line_id:int,recording_id:int,storage_key:string,selected_recording_id:?int}|null Locked row metadata, or null when unauthorized.
     * @throws FormError When the caller is a member but lacks delete rights.
     */
    public function beginTakeDeletion(
        int $projectId,
        int $lineId,
        int $recordingId,
        int $userId
    ): ?array {
        $this->database->beginTransaction();
        try {
            $query = $this->database->prepare(
                'SELECT p.owner_user_id, r.user_id, r.storage_key,
                        l.selected_recording_id
                 FROM dabing_projects p
                 JOIN dabing_project_members m ON m.project_id = p.id
                 JOIN dabing_project_chapters c ON c.project_id = p.id
                 JOIN dabing_script_lines l ON l.chapter_id = c.id
                 JOIN dabing_recordings r ON r.line_id = l.id
                 WHERE p.id = ? AND l.id = ? AND r.id = ? AND m.user_id = ?
                 FOR UPDATE'
            );
            $query->execute([$projectId, $lineId, $recordingId, $userId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->database->rollBack();
                return null;
            }
            if ((int) $row['owner_user_id'] !== $userId
                && (int) $row['user_id'] !== $userId) {
                $this->database->rollBack();
                throw new FormError('Tento pokus nemůžete odstranit.');
            }
            return [
                'line_id' => $lineId,
                'recording_id' => $recordingId,
                'storage_key' => (string) $row['storage_key'],
                'selected_recording_id' => $row['selected_recording_id'] === null
                    ? null : (int) $row['selected_recording_id'],
            ];
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Delete the locked take, repairing the active selection before commit.
     *
     * @param array{line_id:int,recording_id:int,storage_key:string,selected_recording_id:?int} $take Context returned by beginTakeDeletion().
     * @return void No value is returned.
     * @throws \RuntimeException When the locked database state cannot be updated.
     */
    public function finishTakeDeletion(array $take): void
    {
        if (!$this->database->inTransaction()) {
            throw new \RuntimeException('Take deletion transaction is not active.');
        }
        try {
            if ($take['selected_recording_id'] === $take['recording_id']) {
                $replacement = $this->database->prepare(
                    'SELECT id FROM dabing_recordings
                     WHERE line_id = ? AND id <> ? ORDER BY attempt_no, id LIMIT 1'
                );
                $replacement->execute([$take['line_id'], $take['recording_id']]);
                $nextId = $replacement->fetchColumn();
                $selection = $this->database->prepare(
                    'UPDATE dabing_script_lines SET selected_recording_id = ?
                     WHERE id = ?'
                );
                $selection->execute([
                    $nextId === false ? null : (int) $nextId,
                    $take['line_id'],
                ]);
                if ($selection->rowCount() !== 1) {
                    throw new \RuntimeException('Take selection could not be updated.');
                }
            }
            $delete = $this->database->prepare(
                'DELETE FROM dabing_recordings
                 WHERE id = ? AND line_id = ?'
            );
            $delete->execute([$take['recording_id'], $take['line_id']]);
            if ($delete->rowCount() !== 1) {
                throw new \RuntimeException('Locked take could not be deleted.');
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
     * Roll back an unfinished take deletion transaction.
     *
     * @return void No value is returned.
     */
    public function cancelTakeDeletion(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    /**
     * Check whether any recording row still references a private storage key.
     *
     * @param string $storageKey Validated random storage key.
     * @return bool True when a persisted take still uses the key.
     */
    public function hasStorageKey(string $storageKey): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM dabing_recordings WHERE storage_key = ? LIMIT 1'
        );
        $query->execute([$storageKey]);
        return $query->fetchColumn() !== false;
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
