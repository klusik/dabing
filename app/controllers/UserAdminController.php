<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/UserAdminController.php
 * Module Type: Controller
 * Purpose: Handle administrator-only user management requests.
 * Responsibilities: Enforce current admin access, CSRF and form parsing.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Dispatches user overview and account mutation forms. */
final class UserAdminController
{
    private readonly UserAdminService $service;

    /** Create the controller on the active database connection.
     * @param PDO $database Shared configured database connection.
     * @return void No return value.
     */
    public function __construct(PDO $database)
    {
        $this->service = new UserAdminService($database);
    }

    /** Render the user overview or apply one requested mutation.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Fresh authenticated user.
     * @return void No return value.
     */
    public function handle(array $user): void
    {
        if (!$user['is_admin']) {
            throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
        }
        $errors = [];
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            try {
                $targetId = Http::postId('user_id');
                if ($targetId === null) {
                    throw new FormError('Vyberte platný účet.');
                }
                $action = Http::post('action');
                if ($action === 'active' || $action === 'inactive') {
                    $this->service->setActive(
                        $user['id'], $targetId, $action === 'active'
                    );
                    Http::notice('success', 'Stav účtu byl změněn.');
                } elseif ($action === 'delete') {
                    if (Http::post('confirm_delete') !== 'yes') {
                        throw new FormError('Potvrďte odstranění účtu.');
                    }
                    $this->service->delete($user['id'], $targetId);
                    Http::notice('success', 'Účet byl odstraněn.');
                } elseif ($action === 'password') {
                    $this->service->resetPassword(
                        $user['id'], $targetId,
                        Http::post('password', false),
                        Http::post('password_confirm', false)
                    );
                    Http::notice('success', 'Heslo bylo resetováno; staré relace jsou odhlášeny.');
                } elseif ($action === 'roles') {
                    if (Http::post('roles_complete') !== '1') {
                        throw new FormError('Formulář obsazení je neúplný. Obnovte stránku a zkuste to znovu.');
                    }
                    $projectId = Http::postId('project_id');
                    if ($projectId === null) {
                        throw new FormError('Vyberte platnou knihu.');
                    }
                    $this->service->setRoles(
                        $user['id'], $targetId, $projectId,
                        self::postedRoles()
                    );
                    Http::notice('success', 'Obsazení knihy bylo uloženo.');
                } else {
                    throw new FormError('Požadovaná operace není podporována.');
                }
                Http::redirect('users');
            } catch (FormError $error) {
                $errors[] = $error->getMessage();
            }
        }
        $data = $this->service->overview();
        $data['errors'] = $errors;
        Http::render('users', 'Uživatelé', $data, $user);
    }

    /** Parse role values as a bounded list of strings.
     * @return list<string> Submitted role names.
     * @throws FormError When the submitted field shape is invalid.
     */
    private static function postedRoles(): array
    {
        $roles = $_POST['roles'] ?? [];
        if (!is_array($roles) || count($roles) > 500) {
            throw new FormError('Výběr postav není platný.');
        }
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new FormError('Výběr postav není platný.');
            }
        }
        return array_values(array_unique($roles));
    }
}
