<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/UserAdminService.php
 * Module Type: Service
 * Purpose: Apply user administration policy and role validation.
 * Responsibilities: Guard mutations, validate passwords and exact script roles.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates administrator actions and exact role validation. */
final class UserAdminService
{
    private readonly UserAdminModel $users;
    private readonly StudioModel $studio;

    /** Create the service on the active database connection.
     * @param PDO $database Shared configured database connection.
     * @return void No return value.
     */
    public function __construct(PDO $database)
    {
        $this->users = new UserAdminModel($database);
        $this->studio = new StudioModel($database);
    }

    /** Build the overview and available book list.
     * @return array{users:list<array{id:int,name:string,email:string,is_admin:bool,is_active:bool,books:list<array{id:int,title:string,roles:list<string>}>>>,projects:list<array{id:int,title:string,roles:list<string>}>} Administration view model.
     */
    public function overview(): array
    {
        $projects = $this->users->projects();
        foreach ($projects as &$project) {
            $project['roles'] = $this->studio->projectRoles($project['id']);
        }
        unset($project);
        return ['users' => $this->users->users(), 'projects' => $projects];
    }

    /** Replace a user's exact role grants for one existing book.
     * @param int $adminId Current administrator identifier.
     * @param int $userId Target user identifier.
     * @param int $projectId Existing book identifier.
     * @param list<string> $roles Requested exact roles.
     * @return void No return value.
     * @throws FormError When account, book, or roles are invalid.
     */
    public function setRoles(
        int $adminId,
        int $userId,
        int $projectId,
        array $roles
    ): void {
        $available = $this->studio->projectRoles($projectId);
        foreach ($roles as $role) {
            if (!in_array($role, $available, true)) {
                throw new FormError('Obsazení obsahuje neplatnou postavu.');
            }
        }
        if (!$this->users->replaceRoles($adminId, $userId, $projectId, $roles)) {
            throw new FormError('Účet nebo kniha již neexistuje.');
        }
    }

    /** Return accounts for book casting controls, including inactive grants to remove.
     * @return list<array{id:int,name:string,email:string,is_active:bool}> Account choices.
     */
    public function castingUsers(): array
    {
        return $this->users->castingUsers();
    }

    /** Add or remove one role for one user with exact script-name validation.
     * @param int $actorId Current administrator identifier.
     * @param int $userId Target user identifier.
     * @param int $projectId Existing book identifier.
     * @param string $character Exact script character name.
     * @param bool $assigned Whether the user should have the role.
     * @return void No return value.
     * @throws FormError When the role, account, or book is invalid.
     */
    public function setRole(
        int $actorId,
        int $userId,
        int $projectId,
        string $character,
        bool $assigned
    ): void {
        if (!in_array($character, $this->studio->projectRoles($projectId), true)) {
            throw new FormError('Tato postava v knize neexistuje.');
        }
        if (!$this->users->setRole(
            $actorId,
            $userId,
            $projectId,
            $character,
            $assigned
        )) {
            throw new FormError('Účet je neaktivní nebo kniha se změnila.');
        }
    }

    /** Deactivate or reactivate a target account.
     * @param int $adminId Current administrator identifier.
     * @param int $userId Target user identifier.
     * @param bool $active Requested state.
     * @return void No return value.
     * @throws FormError When account state could not be changed.
     */
    public function setActive(int $adminId, int $userId, bool $active): void
    {
        if (!$this->users->setActive($adminId, $userId, $active)) {
            throw new FormError('Účet již neexistuje.');
        }
    }

    /** Reset one target account password and revoke old sessions.
     * @param int $adminId Current administrator identifier.
     * @param int $userId Target user identifier.
     * @param string $password New password.
     * @param string $confirmation Confirmation value.
     * @return void No return value.
     * @throws FormError When password is invalid or the account disappeared.
     */
    public function resetPassword(
        int $adminId,
        int $userId,
        string $password,
        string $confirmation
    ): void {
        AuthService::validateNewPassword($password, 'Nové heslo');
        if (!hash_equals($password, $confirmation)) {
            throw new FormError('Nová hesla se neshodují.');
        }
        if (!$this->users->resetPassword(
            $adminId,
            $userId,
            password_hash($password, PASSWORD_DEFAULT)
        )) {
            throw new FormError('Účet již neexistuje.');
        }
    }

    /** Delete one account while preserving book recordings.
     * @param int $adminId Current administrator identifier.
     * @param int $userId Target user identifier.
     * @return void No return value.
     * @throws FormError When the account disappeared.
     */
    public function delete(int $adminId, int $userId): void
    {
        if (!$this->users->delete($adminId, $userId)) {
            throw new FormError('Účet již neexistuje.');
        }
    }
}
