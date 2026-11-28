<?php
declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/AudioProfileModel.php
 * Module Type: Model
 * Purpose: Persist shared audio profiles and project overrides.
 * Responsibilities: Own profile and assignment SQL and row mapping.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Provides PDO persistence for profiles and their scoped assignments. */
final class AudioProfileModel
{
    /** Create the profile persistence model.
     * @param PDO $database Configured application database.
     * @return void No value is returned.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** List global profiles, optionally limited to project usage.
     * @param int|null $projectId Project whose referenced profiles are requested.
     * @return list<array{id:int,name:string,chain:list<array<string,mixed>>}> Profile rows.
     */
    public function profiles(?int $projectId = null): array
    {
        $sql = 'SELECT DISTINCT p.id, p.name, p.chain_json
                FROM dabing_audio_profiles p';
        $params = [];
        if ($projectId !== null) {
            $sql .= ' JOIN dabing_audio_profile_assignments a
                       ON a.profile_id = p.id AND a.project_id = ?';
            $params[] = $projectId;
        }
        $sql .= ' ORDER BY p.name, p.id';
        $query = $this->database->prepare($sql);
        $query->execute($params);
        return array_map(static function (array $row): array {
            $chain = json_decode((string) $row['chain_json'], true);
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'chain' => is_array($chain) ? $chain : [],
            ];
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Fetch one global profile for the administrator editor.
     * @param int $id Profile identifier.
     * @return array{id:int,name:string,chain:list<array<string,mixed>>}|null Profile or null.
     */
    public function profile(int $id): ?array
    {
        foreach ($this->profiles() as $profile) {
            if ($profile['id'] === $id) {
                return $profile;
            }
        }
        return null;
    }

    /** Create or update a global named profile.
     * @param int|null $id Existing profile identifier, or null for new.
     * @param string $name Validated display name.
     * @param string $chainJson Canonical JSON ordered module chain.
     * @return int Persisted profile identifier.
     */
    public function save(?int $id, string $name, string $chainJson): int
    {
        $this->database->beginTransaction();
        try {
        if ($id === null) {
            $query = $this->database->prepare(
                'INSERT INTO dabing_audio_profiles (name, chain_json) VALUES (?, ?)'
            );
            $query->execute([$name, $chainJson]);
            $id = (int) $this->database->lastInsertId();
        } else {
            $query = $this->database->prepare(
                'UPDATE dabing_audio_profiles SET name = ?, chain_json = ? WHERE id = ?'
            );
            $query->execute([$name, $chainJson, $id]);
        }
        $revision = $this->database->prepare(
            'UPDATE dabing_projects p
             JOIN dabing_audio_profile_assignments a ON a.project_id = p.id
             SET p.revision = p.revision + 1
             WHERE a.profile_id = ?'
        );
        $revision->execute([$id]);
        $this->database->commit();
        return $id;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Save or remove one project scoped profile override.
     * @param int $projectId Authorized project identifier.
     * @param string $scope voice, line, or take.
     * @param int|null $targetId Line or take id, null for a voice rule.
     * @param string|null $character Exact script character for voice scope.
     * @param int|null $userId Actor id for voice scope.
     * @param int|null $profileId Profile id; null records explicit no-profile.
     * @return void No value is returned.
     */
    public function assign(
        int $projectId,
        string $scope,
        ?int $targetId,
        ?string $character,
        ?int $userId,
        ?int $profileId
    ): void {
        $this->database->beginTransaction();
        try {
            $project = $this->database->prepare(
                'SELECT id FROM dabing_projects WHERE id = ? FOR UPDATE'
            );
            $project->execute([$projectId]);
            if ($project->fetchColumn() === false) {
                throw new HttpError(404, 'Kniha nebyla nalezena.');
            }
            $this->assertAssignmentTarget(
                $projectId, $scope, $targetId, $character, $userId
            );
            $where = 'project_id = ? AND scope = ? AND target_id ';
            $params = [$projectId, $scope];
            $where .= $targetId === null ? 'IS NULL' : '= ?';
            if ($targetId !== null) {
                $params[] = $targetId;
            }
            $where .= ' AND character_name ' . ($character === null ? 'IS NULL' : '= ?');
            if ($character !== null) {
                $params[] = $character;
            }
            $where .= ' AND user_id ' . ($userId === null ? 'IS NULL' : '= ?');
            if ($userId !== null) {
                $params[] = $userId;
            }
            $delete = $this->database->prepare(
                'DELETE FROM dabing_audio_profile_assignments WHERE ' . $where
            );
            $delete->execute($params);
            $insert = $this->database->prepare(
                'INSERT INTO dabing_audio_profile_assignments
                    (project_id, scope, target_id, character_name, user_id,
                     target_key, profile_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $projectId, $scope, $targetId, $character, $userId,
                $this->assignmentKey($scope, $targetId, $character, $userId),
                $profileId,
            ]);
            $revision = $this->database->prepare(
                'UPDATE dabing_projects SET revision = revision + 1 WHERE id = ?'
            );
            $revision->execute([$projectId]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Validate the assignment target while the project row is locked.
     * @param int $projectId Project scope.
     * @param string $scope Assignment scope.
     * @param int|null $targetId Line or take id.
     * @param string|null $character Exact role name.
     * @param int|null $userId Assigned actor id.
     * @return void No value is returned.
     * @throws HttpError When the target does not belong to the project.
     */
    private function assertAssignmentTarget(
        int $projectId,
        string $scope,
        ?int $targetId,
        ?string $character,
        ?int $userId
    ): void {
        if ($scope === 'voice') {
            $statement = $this->database->prepare(
                'SELECT u.id FROM dabing_users u
                 JOIN dabing_script_lines l
                   ON CAST(l.character_name AS BINARY) = CAST(? AS BINARY)
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ? AND u.id = ? AND u.is_active = 1
                 FOR UPDATE'
            );
            $statement->execute([$character, $projectId, $userId]);
        } elseif ($scope === 'line') {
            $statement = $this->database->prepare(
                'SELECT l.id FROM dabing_script_lines l
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ? AND l.id = ? FOR UPDATE'
            );
            $statement->execute([$projectId, $targetId]);
        } else {
            $statement = $this->database->prepare(
                'SELECT r.id FROM dabing_recordings r
                 JOIN dabing_script_lines l ON l.id = r.line_id
                 JOIN dabing_project_chapters c ON c.id = l.chapter_id
                 WHERE c.project_id = ? AND r.id = ? FOR UPDATE'
            );
            $statement->execute([$projectId, $targetId]);
        }
        if ($statement->fetchColumn() === false) {
            throw new HttpError(404, 'Cíl přiřazení nebyl nalezen.');
        }
    }

    /** Build a collision-free key for the non-null assignment uniqueness index.
     * @param string $scope Assignment scope.
     * @param int|null $targetId Line or take id.
     * @param string|null $character Exact character name.
     * @param int|null $userId Actor id.
     * @return string Canonical key unique within the scope.
     */
    private function assignmentKey(
        string $scope,
        ?int $targetId,
        ?string $character,
        ?int $userId
    ): string {
        if ($scope !== 'voice') {
            return (string) $targetId;
        }
        return strlen((string) $character) . ':' . $character . ':' . $userId;
    }

    /** Remove a scoped override so a more general rule can apply.
     * @param int $projectId Authorized project identifier.
     * @param string $scope voice, line, or take.
     * @param int|null $targetId Line or take id, null for voice scope.
     * @param string|null $character Exact character name for voice scope.
     * @param int|null $userId Actor id for voice scope.
     * @return void No value is returned.
     */
    public function remove(
        int $projectId,
        string $scope,
        ?int $targetId,
        ?string $character,
        ?int $userId
    ): void {
        $this->database->beginTransaction();
        try {
            $lock = $this->database->prepare(
                'SELECT id FROM dabing_projects WHERE id = ? FOR UPDATE'
            );
            $lock->execute([$projectId]);
            if ($lock->fetchColumn() === false) {
                throw new HttpError(404, 'Kniha nebyla nalezena.');
            }
            $parts = ['project_id = ?', 'scope = ?'];
            $params = [$projectId, $scope];
            foreach ([
                ['target_id', $targetId], ['character_name', $character],
                ['user_id', $userId],
            ] as [$column, $value]) {
                if ($value === null) {
                    $parts[] = $column . ' IS NULL';
                } else {
                    $parts[] = $column . ' = ?';
                    $params[] = $value;
                }
            }
            $query = $this->database->prepare(
                'DELETE FROM dabing_audio_profile_assignments WHERE '
                . implode(' AND ', $parts)
            );
            $query->execute($params);
            if ($query->rowCount() > 0) {
                $revision = $this->database->prepare(
                    'UPDATE dabing_projects SET revision = revision + 1 WHERE id = ?'
                );
                $revision->execute([$projectId]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Return all stored overrides for a project.
     * @param int $projectId Authorized project identifier.
     * @return list<array{scope:string,target_id:?int,character_name:?string,user_id:?int,profile_id:?int}> Override rows.
     */
    public function assignments(int $projectId): array
    {
        $query = $this->database->prepare(
            'SELECT scope, target_id, character_name, user_id, profile_id
             FROM dabing_audio_profile_assignments WHERE project_id = ?'
        );
        $query->execute([$projectId]);
        return array_map(static fn (array $row): array => [
            'scope' => (string) $row['scope'],
            'target_id' => $row['target_id'] === null ? null : (int) $row['target_id'],
            'character_name' => $row['character_name'] === null
                ? null : (string) $row['character_name'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'profile_id' => $row['profile_id'] === null ? null : (int) $row['profile_id'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Return every take in a book ordered with its derived filename context.
     * @param int $projectId Authorized owner/admin project.
     * @return array{title:string,takes:list<array{id:int,line_number:int,attempt_no:int,media_type:string,user_id:?int,character:string,line_id:int}>}|null Book metadata and rows.
     */
    public function manifestRows(int $projectId): ?array
    {
        $query = $this->database->prepare(
            'SELECT p.title, l.id AS line_id, l.character_name,
                    r.id AS recording_id, r.attempt_no, r.media_type, r.user_id,
                    (SELECT COUNT(*)
                     FROM dabing_project_chapters pc
                     JOIN dabing_script_lines pl ON pl.chapter_id = pc.id
                     WHERE pc.project_id = c.project_id
                       AND (pc.position < c.position
                         OR (pc.position = c.position AND pl.position <= l.position))) AS line_number
             FROM dabing_projects p
             LEFT JOIN dabing_project_chapters c ON c.project_id = p.id
             LEFT JOIN dabing_script_lines l ON l.chapter_id = c.id
             LEFT JOIN dabing_recordings r ON r.line_id = l.id
             WHERE p.id = ?
             ORDER BY c.position, c.id, l.position, l.id, r.attempt_no, r.id'
        );
        $query->execute([$projectId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $takes = [];
        foreach ($rows as $row) {
            if ($row['recording_id'] === null) {
                continue;
            }
            $takes[] = [
                'id' => (int) $row['recording_id'],
                'line_number' => (int) $row['line_number'],
                'attempt_no' => (int) $row['attempt_no'],
                'media_type' => (string) $row['media_type'],
                'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'character' => (string) $row['character_name'],
                'line_id' => (int) $row['line_id'],
            ];
        }
        return ['title' => (string) $rows[0]['title'], 'takes' => $takes];
    }
}
