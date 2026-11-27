<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/UserAdminModel.php
 * Module Type: Model
 * Purpose: Persist account administration and role assignment data.
 * Responsibilities: List accounts and mutate versioned users transactionally.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns user administration queries and account mutations. */
final class UserAdminModel
{
    /** Create the model on the active database connection.
     * @param PDO $database Shared configured database connection.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** List accounts with their book role assignments.
     * @return list<array{id:int,name:string,email:string,is_admin:bool,is_active:bool,books:list<array{id:int,title:string,roles:list<string>}>}> Account overview.
     */
    public function users(): array
    {
        $users = $this->database->query(
            'SELECT id, name, email, is_admin, is_active
             FROM dabing_users ORDER BY email, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $bookQuery = $this->database->prepare(
            'SELECT p.id, p.title, a.character_name
             FROM dabing_voice_assignments a
             JOIN dabing_projects p ON p.id = a.project_id
             WHERE a.user_id = ?
             ORDER BY p.title, p.id, a.character_name'
        );
        $result = [];
        foreach ($users as $user) {
            $bookQuery->execute([(int) $user['id']]);
            $books = [];
            foreach ($bookQuery->fetchAll(PDO::FETCH_ASSOC) as $book) {
                $projectId = (int) $book['id'];
                $books[$projectId] ??= [
                    'id' => $projectId,
                    'title' => (string) $book['title'],
                    'roles' => [],
                ];
                $books[$projectId]['roles'][] = (string) $book['character_name'];
            }
            $result[] = [
                'id' => (int) $user['id'], 'name' => (string) $user['name'],
                'email' => (string) $user['email'],
                'is_admin' => (bool) $user['is_admin'],
                'is_active' => (bool) $user['is_active'],
                'books' => array_values($books),
            ];
        }
        return $result;
    }

    /** List existing books for role editor selectors.
     * @return list<array{id:int,title:string}> Books ordered by title.
     */
    public function projects(): array
    {
        $rows = $this->database->query(
            'SELECT id, title FROM dabing_projects ORDER BY title, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'title' => (string) $row['title'],
        ], $rows);
    }

    /** List active users eligible for a project's casting controls.
     * @return list<array{id:int,name:string,email:string,is_active:bool}> Accounts ordered by name.
     */
    public function castingUsers(): array
    {
        $rows = $this->database->query(
            'SELECT id, name, email, is_active FROM dabing_users '
            . 'ORDER BY name, email, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'email' => (string) $row['email'], 'is_active' => (bool) $row['is_active'],
        ], $rows);
    }

    /** Add or remove one exact role from a user's book casting.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param int $projectId Book identifier.
     * @param string $character Exact script character name.
     * @param bool $assigned Whether the grant should exist.
     * @return bool Whether the target account, book, and exact role exist.
     */
    public function setRole(
        int $actorId,
        int $userId,
        int $projectId,
        string $character,
        bool $assigned
    ): bool {
        return $this->transactionalMutation(
            $actorId,
            $userId,
            function () use ($userId, $projectId, $character, $assigned): bool {
                $role = $this->database->prepare(
                    'SELECT 1 FROM dabing_script_lines l
                     JOIN dabing_project_chapters c ON c.id = l.chapter_id
                     JOIN dabing_projects p ON p.id = c.project_id
                     WHERE p.id = ? AND CAST(l.character_name AS BINARY)
                       = CAST(? AS BINARY) LIMIT 1 FOR UPDATE'
                );
                $role->execute([$projectId, $character]);
                if ($role->fetchColumn() === false) {
                    return false;
                }
                if ($assigned) {
                    $statement = $this->database->prepare(
                        'INSERT IGNORE INTO dabing_voice_assignments '
                        . '(project_id, character_name, user_id) VALUES (?, ?, ?)'
                    );
                } else {
                    $statement = $this->database->prepare(
                        'DELETE FROM dabing_voice_assignments '
                        . 'WHERE project_id = ? AND user_id = ? '
                        . 'AND CAST(character_name AS BINARY) = CAST(? AS BINARY)'
                    );
                }
                $statement->execute($assigned
                    ? [$projectId, $character, $userId]
                    : [$projectId, $userId, $character]);
                return true;
            },
            false
        );
    }

    /** Add exact roles and return only grants inserted by this transaction.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param int $projectId Existing book identifier.
     * @param list<string> $roles Exact role names selected for this book.
     * @return array{email:string,project_title:string,roles:list<string>}|null New grants and mail context, or null when account/book vanished.
     * @throws FormError When roles are empty or no longer belong to the book.
     * @throws HttpError When the actor is not an active administrator.
     */
    public function grantRoles(
        int $actorId,
        int $userId,
        int $projectId,
        array $roles
    ): ?array {
        if ($roles === []) {
            throw new FormError('Vyberte alespoň jednu postavu.');
        }
        $context = null;
        $found = $this->transactionalMutation(
            $actorId,
            $userId,
            function () use (
                $userId,
                $projectId,
                $roles,
                &$context
            ): bool {
                $project = $this->database->prepare(
                    'SELECT title FROM dabing_projects WHERE id = ? FOR UPDATE'
                );
                $project->execute([$projectId]);
                $projectTitle = $project->fetchColumn();
                if (!is_string($projectTitle)) {
                    return false;
                }
                $roleQuery = $this->database->prepare(
                    'SELECT l.character_name
                     FROM dabing_script_lines l
                     JOIN dabing_project_chapters c ON c.id = l.chapter_id
                     WHERE c.project_id = ?
                     ORDER BY l.id FOR UPDATE'
                );
                $roleQuery->execute([$projectId]);
                $available = [];
                foreach ($roleQuery->fetchAll(PDO::FETCH_COLUMN) as $role) {
                    $value = (string) $role;
                    $available[bin2hex($value)] = $value;
                }
                $requested = [];
                foreach ($roles as $role) {
                    $key = bin2hex($role);
                    if (!isset($available[$key])) {
                        throw new FormError(
                            'Obsazení obsahuje postavu, která již není v knize.'
                        );
                    }
                    $requested[$key] = $available[$key];
                }
                $emailQuery = $this->database->prepare(
                    'SELECT email FROM dabing_users WHERE id = ?'
                );
                $emailQuery->execute([$userId]);
                $email = $emailQuery->fetchColumn();
                if (!is_string($email)) {
                    return false;
                }
                $insert = $this->database->prepare(
                    'INSERT IGNORE INTO dabing_voice_assignments '
                    . '(project_id, character_name, user_id) VALUES (?, ?, ?)'
                );
                $created = [];
                foreach ($requested as $role) {
                    $insert->execute([$projectId, $role, $userId]);
                    if ($insert->rowCount() === 1) {
                        $created[] = $role;
                    }
                }
                $context = [
                    'email' => $email,
                    'project_title' => $projectTitle,
                    'roles' => $created,
                ];
                return true;
            }
        );
        return $found && is_array($context) ? $context : null;
    }

    /** Change active state and revoke all older sessions atomically.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param bool $active Requested active state.
     * @return bool Whether the target was updated.
     */
    public function setActive(int $actorId, int $userId, bool $active): bool
    {
        return $this->mutateAccount($actorId, $userId, static function (
            PDO $database
        ) use ($userId, $active): bool {
            $statement = $database->prepare(
                'UPDATE dabing_users SET is_active = ?, '
                . 'session_version = session_version + 1 WHERE id = ?'
            );
            $statement->execute([$active ? 1 : 0, $userId]);
            return $statement->rowCount() === 1;
        }, !$active);
    }

    /** Replace a target password and revoke every prior session.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param string $passwordHash Newly generated password hash.
     * @return bool Whether the target was updated.
     */
    public function resetPassword(int $actorId, int $userId, string $passwordHash): bool
    {
        return $this->mutateAccount($actorId, $userId, static function (
            PDO $database
        ) use ($userId, $passwordHash): bool {
            $statement = $database->prepare(
                'UPDATE dabing_users SET password_hash = ?, '
                . 'session_version = session_version + 1 WHERE id = ?'
            );
            $statement->execute([$passwordHash, $userId]);
            return $statement->rowCount() === 1;
        });
    }

    /** Remove an account while preserving book and recording rows.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @return bool Whether the target was deleted.
     */
    public function delete(int $actorId, int $userId): bool
    {
        return $this->mutateAccount($actorId, $userId, static function (
            PDO $database
        ) use ($userId): bool {
            $statement = $database->prepare('DELETE FROM dabing_users WHERE id = ?');
            $statement->execute([$userId]);
            return $statement->rowCount() === 1;
        }, true);
    }

    /** Replace all of a user's roles for one book under account locks.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param int $projectId Book identifier.
     * @param list<string> $roles Exact validated script roles.
     * @return bool Whether assignment state was updated.
     */
    public function replaceRoles(
        int $actorId,
        int $userId,
        int $projectId,
        array $roles
    ): bool {
        return $this->transactionalMutation(
            $actorId,
            $userId,
            function () use ($userId, $projectId, $roles): bool {
                $exists = $this->database->prepare(
                    'SELECT 1 FROM dabing_projects WHERE id = ? FOR UPDATE'
                );
                $exists->execute([$projectId]);
                if ($exists->fetchColumn() === false) {
                    return false;
                }
                $roleQuery = $this->database->prepare(
                    'SELECT l.character_name
                     FROM dabing_script_lines l
                     JOIN dabing_project_chapters c ON c.id = l.chapter_id
                     WHERE c.project_id = ?
                     ORDER BY l.id FOR UPDATE'
                );
                $roleQuery->execute([$projectId]);
                $available = array_values(array_unique(array_map(
                    static fn (mixed $role): string => (string) $role,
                    $roleQuery->fetchAll(PDO::FETCH_COLUMN)
                )));
                foreach ($roles as $role) {
                    if (!in_array($role, $available, true)) {
                        throw new FormError('Obsazení obsahuje neplatnou postavu.');
                    }
                }
                $delete = $this->database->prepare(
                    'DELETE FROM dabing_voice_assignments '
                    . 'WHERE user_id = ? AND project_id = ?'
                );
                $delete->execute([$userId, $projectId]);
                $insert = $this->database->prepare(
                    'INSERT INTO dabing_voice_assignments '
                    . '(project_id, character_name, user_id) VALUES (?, ?, ?)'
                );
                foreach ($roles as $role) {
                    $insert->execute([$projectId, $role, $userId]);
                }
                return true;
            }
        );
    }

    /** Run a target account action after fresh administrator and target locks.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param callable(PDO):bool $action Database mutation receiving this connection.
     * @param bool $protectSelf Whether to reject self-targeted operations.
     * @return bool Whether the action succeeded.
     */
    private function mutateAccount(
        int $actorId,
        int $userId,
        callable $action,
        bool $protectSelf = false
    ): bool {
        return $this->transactionalMutation(
            $actorId,
            $userId,
            fn (): bool => $action($this->database),
            $protectSelf
        );
    }

    /** Lock and verify both actors for one admin-only mutation.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target account identifier.
     * @param callable():bool $action Transaction body.
     * @param bool $protectSelf Whether to reject self-targeted operations.
     * @return bool Whether the action succeeded.
     */
    private function transactionalMutation(
        int $actorId,
        int $userId,
        callable $action,
        bool $protectSelf = false
    ): bool {
        $this->database->beginTransaction();
        try {
            $accounts = $this->database->prepare(
                'SELECT id, is_admin, is_active FROM dabing_users '
                . 'WHERE id IN (?, ?) ORDER BY id FOR UPDATE'
            );
            $accounts->execute([$actorId, $userId]);
            $locked = [];
            foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
                $locked[(int) $account['id']] = $account;
            }
            $actorRow = $locked[$actorId] ?? null;
            if (!is_array($actorRow) || !(bool) $actorRow['is_admin']
                || !(bool) $actorRow['is_active']) {
                $this->database->rollBack();
                throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
            }
            if ($protectSelf && $actorId === $userId) {
                $this->database->rollBack();
                throw new FormError('Tento účet nelze spravovat sám sobě.');
            }
            $targetRow = $locked[$userId] ?? null;
            if (!is_array($targetRow)) {
                $this->database->rollBack();
                return false;
            }
            $result = $action();
            $this->database->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }
}
