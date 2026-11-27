<?php
declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/StudioModel.php
 * Module Type: Model
 * Purpose: Persist private studio projects, role grants, script lines, and takes.
 * Responsibilities: Own SQL queries and map rows for studio services.
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
     * @param bool $isAdmin Whether the authenticated user has global administrator access.
     * @return list<array{id:int,title:string,role:string,created_at:string,line_count:int,recording_count:int}>
     *     Project summary rows in newest-first order.
     */
    public function projectsForUser(int $userId, bool $isAdmin = false): array
    {
        $statement = $this->database->prepare(
            'SELECT p.id, p.title, p.created_at,
                    CASE WHEN :admin_role = 1 THEN \'owner\' ELSE \'member\' END AS role,
                    (SELECT COUNT(*) FROM dabing_project_chapters c
                     JOIN dabing_script_lines l ON l.chapter_id = c.id
                     WHERE c.project_id = p.id) AS line_count,
                    (SELECT COUNT(*) FROM dabing_recordings r
                     JOIN dabing_script_lines l ON l.id = r.line_id
                     JOIN dabing_project_chapters c ON c.id = l.chapter_id
                     WHERE c.project_id = p.id) AS recording_count
             FROM dabing_projects p
             WHERE (:admin_access = 1 OR EXISTS (SELECT 1 FROM dabing_voice_assignments a
                               WHERE a.project_id = p.id
                                 AND a.user_id = :member))
             ORDER BY p.created_at DESC, p.id DESC'
        );
        $statement->execute([
            'member' => $userId,
            'admin_role' => $isAdmin ? 1 : 0,
            'admin_access' => $isAdmin ? 1 : 0,
        ]);
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
     * Return exact, case-sensitive script roles in one project.
     *
     * @param int $projectId Requested project identifier.
     * @return list<string> Distinct roles in script order.
     */
    public function projectRoles(int $projectId): array
    {
        $statement = $this->database->prepare(
            'SELECT character_name
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             WHERE c.project_id = ?
             GROUP BY CAST(l.character_name AS BINARY), l.character_name
             ORDER BY MIN(c.position), MIN(l.position), MIN(l.id)'
        );
        $statement->execute([$projectId]);
        return array_map(
            static fn (mixed $role): string => (string) $role,
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
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
     * Fetch the current project page, complete playlist, and project-wide voices.
     *
     * @param int $projectId Requested project identifier.
     * @param int $userId Authenticated viewer identifier.
     * @param int $requestedPage One-based visible script page.
     * @param bool $isAdmin Whether the authenticated user has global administrator access.
     * @param bool $myTakesOnly Whether to page roles assigned to the viewer.
     * @return array{project:array{id:int,title:string,role:string},chapters:list<array{id:int,title:string,lines:list<array{id:int,number:int,position:int,character:string,text:string,selected_recording_id:?int,selected_recording_user_id:?int,assigned_name:?string,can_record:bool,takes:list<array{id:int,attempt_no:int,media_type:string,user_id:?int,user_name:string,created_at:string,is_selected:bool,can_delete:bool}>}>}>,members:list<array{id:int,name:string,is_active:bool,role:string}>,voices:list<array{character:string,assigned_user_id:?int,assigned_name:?string,assigned_users:list<int>}>,playlist:list<array{recording_id:int,line_number:int,character:string,chapter_title:string,attempt_no:int,media_type:string}>,pagination:array{page:int,pages:int,total:int},has_recordings:bool}|null
     *     Ordered page and playback data, or null when access is not allowed.
     */
    public function projectView(
        int $projectId,
        int $userId,
        int $requestedPage = 1,
        bool $isAdmin = false,
        bool $myTakesOnly = false
    ): ?array {
        $projectQuery = $this->database->prepare(
            'SELECT p.id, p.title,
                    CASE WHEN :admin_role = 1 THEN \'owner\' ELSE \'member\' END AS role,
                    EXISTS (
                        SELECT 1 FROM dabing_recordings any_recording
                        JOIN dabing_script_lines any_line
                          ON any_line.id = any_recording.line_id
                        JOIN dabing_project_chapters any_chapter
                          ON any_chapter.id = any_line.chapter_id
                        WHERE any_chapter.project_id = p.id
                    ) AS has_recordings
             FROM dabing_projects p
             WHERE p.id = :project AND (:admin_access = 1 OR EXISTS (SELECT 1 FROM dabing_voice_assignments grant_role
                          WHERE grant_role.project_id = p.id
                            AND grant_role.user_id = :member))'
        );
        $projectQuery->execute([
            'project' => $projectId,
            'member' => $userId,
            'admin_role' => $isAdmin ? 1 : 0,
            'admin_access' => $isAdmin ? 1 : 0,
        ]);
        $projectRow = $projectQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($projectRow)) {
            return null;
        }

        if ($myTakesOnly) {
            $totalQuery = $this->database->prepare(
                'SELECT COUNT(DISTINCT l.id)
                 FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ? AND EXISTS (
                     SELECT 1 FROM dabing_voice_assignments a
                     WHERE a.project_id = c.project_id AND a.user_id = ?
                       AND CAST(a.character_name AS BINARY)
                           = CAST(l.character_name AS BINARY))'
            );
            $totalQuery->execute([$projectId, $userId]);
        } else {
            $totalQuery = $this->database->prepare(
                'SELECT COUNT(*)
                 FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ?'
            );
            $totalQuery->execute([$projectId]);
        }
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
                   AND (:my_takes = 0 OR EXISTS (
                       SELECT 1 FROM dabing_voice_assignments own_role
                       WHERE own_role.project_id = c.project_id
                         AND own_role.user_id = :take_user
                         AND CAST(own_role.character_name AS BINARY)
                             = CAST(l.character_name AS BINARY)))
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
        $lineQuery->bindValue('my_takes', $myTakesOnly ? 1 : 0, PDO::PARAM_INT);
        $lineQuery->bindValue('take_user', $userId, PDO::PARAM_INT);
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
                    'user_id' => $row['recording_user_id'] === null
                        ? null : (int) $row['recording_user_id'],
                    'user_name' => is_string($row['user_name']) ? (string) $row['user_name'] : 'Smazaný uživatel',
                    'created_at' => (string) $row['created_at'],
                    'is_selected' => (int) $row['recording_id']
                        === (int) ($row['selected_recording_id'] ?? 0),
                    'can_delete' => $isAdmin
                        || ($row['recording_user_id'] !== null
                            && (int) $row['recording_user_id'] === $userId),
                ];
            }
        }

        $membersQuery = $this->database->prepare(
            'SELECT u.id, u.name, u.is_active,
                    GROUP_CONCAT(a.character_name ORDER BY a.character_name
                                 SEPARATOR \' · \') AS role
             FROM dabing_voice_assignments a
             JOIN dabing_users u ON u.id = a.user_id
             WHERE a.project_id = ?
             GROUP BY u.id, u.name, u.is_active ORDER BY u.name, u.id'
        );
        $membersQuery->execute([$projectId]);
        $members = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'is_active' => (int) $row['is_active'] === 1,
            'role' => (string) $row['role'],
        ], $membersQuery->fetchAll(PDO::FETCH_ASSOC));

        $assignmentQuery = $this->database->prepare(
            'SELECT a.character_name, a.user_id, u.name AS assigned_name
             FROM dabing_voice_assignments a
             LEFT JOIN dabing_users u ON u.id = a.user_id
             WHERE a.project_id = ? ORDER BY a.character_name'
        );
        $assignmentQuery->execute([$projectId]);
        $assignments = [];
        foreach ($assignmentQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = bin2hex((string) $row['character_name']);
            $assignments[$key] ??= [];
            $assignments[$key][] = [
                'assigned_user_id' => (int) $row['user_id'],
                'assigned_name' => is_string($row['assigned_name'])
                    ? (string) $row['assigned_name'] : 'Smazaný uživatel',
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
            $assignment = $assignments[$voiceKey][0] ?? [
                'assigned_user_id' => null, 'assigned_name' => null,
            ];
            $voicesByName[$voiceKey] ??= [
                'character' => $character,
                'assigned_user_id' => $assignment['assigned_user_id'],
                'assigned_name' => $assignment['assigned_name'],
                'assigned_users' => array_map(
                    static fn (array $assigned): int => $assigned['assigned_user_id'],
                    $assignments[$voiceKey] ?? []
                ),
            ];
        }

        foreach ($chapters as &$chapter) {
            foreach ($chapter['lines'] as &$line) {
                $lineAssignments = $assignments[bin2hex($line['character'])] ?? [];
                $line['assigned_name'] = implode(', ', array_column($lineAssignments, 'assigned_name'));
                $line['can_record'] = $isAdmin || in_array(
                    $userId,
                    array_column($lineAssignments, 'assigned_user_id'),
                    true
                );
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
            'has_recordings' => (int) $projectRow['has_recordings'] === 1,
        ];
    }

    /**
     * Return ordered mapped take metadata for an authorized whole-book export.
     *
     * @param int $projectId Requested project identifier.
     * @param int $userId Authenticated owner or project member.
     * @param bool $isAdmin Whether the requester has global administrator access.
     * @return array{title:string,takes:iterable<array{line_number:int,storage_key:string,media_type:string,byte_size:int,attempt_no:int}>}|null Book metadata and lazy takes, or null when access is denied.
     */
    public function exportTakes(int $projectId, int $userId, bool $isAdmin = false): ?array
    {
        $statement = $this->database->prepare(
            'SELECT p.title AS project_title, l.id AS line_id,
                    r.id AS recording_id, r.storage_key, r.media_type,
                    r.byte_size, r.attempt_no
             FROM dabing_projects p
             LEFT JOIN dabing_project_chapters c ON c.project_id = p.id
             LEFT JOIN dabing_script_lines l ON l.chapter_id = c.id
             LEFT JOIN dabing_recordings r ON r.line_id = l.id
             WHERE p.id = ? AND ? = 1
             ORDER BY c.position, c.id, l.position, l.id,
                      r.attempt_no, r.id'
        );
        $statement->execute([$projectId, $isAdmin ? 1 : 0]);
        $first = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($first)) {
            $statement->closeCursor();
            return null;
        }
        $takes = (function () use ($statement, $first): \Generator {
            $lineNumber = 0;
            $previousLineId = null;
            $row = $first;
            try {
                while (is_array($row)) {
                    if ($row['line_id'] !== null) {
                        $lineId = (int) $row['line_id'];
                        if ($lineId !== $previousLineId) {
                            ++$lineNumber;
                            $previousLineId = $lineId;
                        }
                        if ($row['recording_id'] !== null) {
                            yield [
                                'line_number' => $lineNumber,
                                'storage_key' => (string) $row['storage_key'],
                                'media_type' => (string) $row['media_type'],
                                'byte_size' => (int) $row['byte_size'],
                                'attempt_no' => (int) $row['attempt_no'],
                            ];
                        }
                    }
                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                }
            } finally {
                $statement->closeCursor();
            }
        })();
        return ['title' => (string) $first['project_title'], 'takes' => $takes];
    }

    /**
     * Select a take when the requesting user owns the project.
     *
     * @param int $projectId Project to authorize.
     * @param int $lineId Script line receiving selection.
     * @param int $recordingId Existing take to select.
     * @param int $userId Authenticated requester.
     * @param bool $isAdmin Whether the requester has global administrator access.
     * @return bool True when selected, false if any relation is unauthorized.
     */
    public function selectTake(
        int $projectId,
        int $lineId,
        int $recordingId,
        int $userId,
        bool $isAdmin = false
    ): bool {
        $statement = $this->database->prepare(
            'UPDATE dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_recordings r ON r.line_id = l.id
               AND r.id = :recording
             SET l.selected_recording_id = r.id
             WHERE l.id = :line AND c.project_id = :project
               AND :admin = 1'
        );
        $statement->execute([
            'recording' => $recordingId,
            'line' => $lineId,
            'project' => $projectId,
            'admin' => $isAdmin ? 1 : 0,
        ]);
        if ($statement->rowCount() === 1) {
            return true;
        }
        $verify = $this->database->prepare(
            'SELECT 1 FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             JOIN dabing_recordings r ON r.line_id = l.id
             WHERE p.id = ? AND ? = 1 AND l.id = ?
               AND r.id = ? AND l.selected_recording_id = r.id'
        );
        $verify->execute([$projectId, $isAdmin ? 1 : 0, $lineId, $recordingId]);
        return $verify->fetchColumn() !== false;
    }

    /**
     * Check whether a collaborator has the exact role grant for one line.
     *
     * @param int $projectId Requested project.
     * @param int $lineId Requested script line.
     * @param int $userId Authenticated user.
     * @return bool True only when a matching role grant exists.
     */
    public function canRecord(int $projectId, int $lineId, int $userId): bool
    {
        $statement = $this->database->prepare(
            'SELECT EXISTS (SELECT 1 FROM dabing_voice_assignments exact_role
                            WHERE exact_role.project_id = p.id
                              AND exact_role.user_id = ?
                              AND CAST(exact_role.character_name AS BINARY)
                                  = CAST(l.character_name AS BINARY)) AS role_access
             FROM dabing_script_lines l
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             JOIN dabing_projects p ON p.id = c.project_id
             WHERE p.id = ? AND l.id = ? AND EXISTS (
                   SELECT 1 FROM dabing_voice_assignments grant_role
                   WHERE grant_role.project_id = p.id
                     AND grant_role.user_id = ?)'
        );
        $statement->execute([$userId, $projectId, $lineId, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        return (int) $row['role_access'] === 1;
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
     * @param string|null $idempotencyPrefix Optional scoped storage-key lookup prefix.
     * @param bool $isAdmin Whether the requester has global administrator access.
     * @return array{storage_key:string,recording_id:int,attempt_no:int,line_number:int,project_title:string,media_type:string,reused:bool} Persisted or previously saved take data.
     * @throws FormError When access is denied or the attempt cap is exhausted.
     */
    public function addRecording(
        int $projectId,
        int $lineId,
        int $userId,
        string $storageKey,
        string $mediaType,
        int $byteSize,
        ?string $idempotencyPrefix = null,
        bool $isAdmin = false
    ): array {
        $this->database->beginTransaction();
        try {
            $access = $this->database->prepare(
                'SELECT p.title,
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
                 WHERE p.id = ? AND l.id = ?
                   AND (? = 1 OR EXISTS (
                       SELECT 1 FROM dabing_voice_assignments grant_role
                       WHERE grant_role.project_id = p.id
                         AND grant_role.user_id = ?))
                 FOR UPDATE'
            );
            $access->execute([$projectId, $lineId, $isAdmin ? 1 : 0, $userId]);
            $line = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($line)) {
                throw new FormError('K této replice nemáte přístup.');
            }
            if (!$isAdmin
                && !$this->lockExactRoleAssignment($projectId, $lineId, $userId)) {
                throw new FormError('Tato postava je přiřazena jinému hlasu.');
            }
            if ($idempotencyPrefix !== null) {
                $existingQuery = $this->database->prepare(
                    'SELECT id, attempt_no, storage_key, media_type
                     FROM dabing_recordings
                     WHERE line_id = ? AND user_id = ? AND storage_key LIKE ?
                     LIMIT 1 FOR UPDATE'
                );
                $existingQuery->execute([
                    $lineId,
                    $userId,
                    $idempotencyPrefix . '%',
                ]);
                $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    $this->database->commit();
                    return [
                        'storage_key' => (string) $existing['storage_key'],
                        'recording_id' => (int) $existing['id'],
                        'attempt_no' => (int) $existing['attempt_no'],
                        'line_number' => (int) $line['line_number'],
                        'project_title' => (string) $line['title'],
                        'media_type' => (string) $existing['media_type'],
                        'reused' => true,
                    ];
                }
            }

            $lastAttempt = $this->database->prepare(
                'SELECT attempt_no FROM dabing_recordings
                 WHERE line_id = ? ORDER BY attempt_no DESC, id DESC
                 LIMIT 1 FOR UPDATE'
            );
            $lastAttempt->execute([$lineId]);
            $lastValue = $lastAttempt->fetchColumn();
            $attemptNo = ($lastValue === false ? 0 : (int) $lastValue) + 1;
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
                'media_type' => $mediaType,
                'reused' => false,
            ];
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Check a user's exact role grant for the line's current character.
     *
     * @param int $projectId Project scope.
     * @param int $lineId Script line.
     * @param int $userId Authenticated user.
     * @return bool True when a case-sensitive role row still grants access.
     */
    private function lockExactRoleAssignment(
        int $projectId,
        int $lineId,
        int $userId
    ): bool {
        $statement = $this->database->prepare(
            'SELECT a.user_id FROM dabing_voice_assignments a
             JOIN dabing_script_lines l
               ON CAST(a.character_name AS BINARY)
                  = CAST(l.character_name AS BINARY)
             JOIN dabing_project_chapters c ON c.id = l.chapter_id
             WHERE a.project_id = ? AND a.user_id = ? AND l.id = ?
             FOR UPDATE'
        );
        $statement->execute([$projectId, $userId, $lineId]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Lock a take and validate the requester before private-file staging.
     *
     * @param int $projectId Project containing the take.
     * @param int $lineId Script line containing the take.
     * @param int $recordingId Take selected for removal.
     * @param int $userId Authenticated owner or member.
     * @param bool $isAdmin Whether the requester has global administrator access.
     * @return array{line_id:int,recording_id:int,storage_key:string,selected_recording_id:?int}|null Locked row metadata, or null when unauthorized.
     * @throws FormError When the caller is a member but lacks delete rights.
     */
    public function beginTakeDeletion(
        int $projectId,
        int $lineId,
        int $recordingId,
        int $userId,
        bool $isAdmin = false
    ): ?array {
        $this->database->beginTransaction();
        try {
            $query = $this->database->prepare(
                'SELECT r.user_id, r.storage_key,
                        l.selected_recording_id
                 FROM dabing_projects p
                 JOIN dabing_project_chapters c ON c.project_id = p.id
                 JOIN dabing_script_lines l ON l.chapter_id = c.id
                 JOIN dabing_recordings r ON r.line_id = l.id
                 WHERE p.id = ? AND l.id = ? AND r.id = ?
                   AND (? = 1 OR EXISTS (
                       SELECT 1 FROM dabing_voice_assignments grant_role
                       WHERE grant_role.project_id = p.id
                         AND grant_role.user_id = ?))
                 FOR UPDATE'
            );
            $query->execute([
                $projectId, $lineId, $recordingId,
                $isAdmin ? 1 : 0, $userId,
            ]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->database->rollBack();
                return null;
            }
            if (!$isAdmin && (int) $row['user_id'] !== $userId) {
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
     * @param bool $isAdmin Whether the requester has global administrator access.
     * @return array{id:int,project_id:int,project_title:string,line_number:int,attempt_no:int,storage_key:string,media_type:string,byte_size:int}|null Authorized take or null.
     */
    public function recordingForUser(int $recordingId, int $userId, bool $isAdmin = false): ?array
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
             WHERE r.id = ? AND (? = 1 OR EXISTS (
                 SELECT 1 FROM dabing_voice_assignments grant_role
                 WHERE grant_role.project_id = p.id
                   AND grant_role.user_id = ?))'
        );
        $statement->execute([$recordingId, $isAdmin ? 1 : 0, $userId]);
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
