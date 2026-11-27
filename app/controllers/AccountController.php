<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/AccountController.php
 * Module Type: Controller
 * Purpose: Handle installation and account HTTP forms.
 * Responsibilities: Enforce methods and CSRF, pass policy to services, render view models.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Handles first-run, login, registration, and logout requests. */
final class AccountController
{
    /** Create account controller.
     * @param InstallService $install Installer service.
     * @return void No return value.
     */
    public function __construct(private readonly InstallService $install)
    {
    }

    /** Show or process the first-run wizard.
     * @return void No return value.
     */
    public function install(): void
    {
        if ($this->install->isInstalled()) {
            Http::redirect('login');
        }
        $values = [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '',
            'db_user' => '', 'name' => '', 'email' => '',
        ];
        $errors = [];
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            $input = [
                'install_key' => Http::post('install_key'),
                'db_host' => Http::post('db_host'), 'db_port' => Http::post('db_port'),
                'db_name' => Http::post('db_name'), 'db_user' => Http::post('db_user'),
                'db_password' => Http::post('db_password', false),
                'setup_user' => Http::post('setup_user'),
                'setup_password' => Http::post('setup_password', false),
                'name' => Http::post('name'), 'email' => Http::post('email'),
                'password' => Http::post('password', false),
                'password_confirm' => Http::post('password_confirm', false),
            ];
            foreach ($values as $key => $_value) {
                $values[$key] = $input[$key];
            }
            try {
                $this->install->install($input);
                Http::notice('success', 'Instalace je dokončena. Přihlaste se.');
                Http::redirect('login');
            } catch (FormError $error) {
                $errors[] = $error->getMessage();
            } catch (\PDOException $error) {
                $errors[] = 'Databázové nastavení se nezdařilo. Ověřte připojení a oprávnění databázového uživatele.';
            }
        }
        Http::render('install', 'Instalace', [
            'errors' => $errors,
            'values' => $values,
            'keyReady' => $this->install->keyReady(),
        ]);
    }

    /** Show or process account login.
     * @param AuthService $auth Authentication service.
     * @return void No return value.
     */
    public function login(AuthService $auth): void
    {
        if ($auth->currentUser() !== null) {
            Http::redirectAfterLogin();
        }
        $errors = [];
        $values = ['email' => ''];
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            $email = Http::post('email');
            $password = Http::post('password', false);
            $values['email'] = $email;
            $user = $auth->authenticate($email, $password);
            if ($user === null) {
                $errors[] = 'E-mail nebo heslo není správné, případně je přihlášení dočasně omezeno.';
            } else {
                Http::redirectAfterLogin();
            }
        }
        Http::render('login', 'Přihlášení', [
            'errors' => $errors, 'values' => $values,
        ]);
    }

    /** Show or process open registration for project-access links.
     * @param AuthService $auth Authentication service.
     * @return void No return value.
     */
    public function register(AuthService $auth): void
    {
        if ($auth->currentUser() !== null) {
            Http::redirectAfterLogin();
        }
        $errors = [];
        $values = ['name' => '', 'email' => ''];
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            $values = ['name' => Http::post('name'), 'email' => Http::post('email')];
            try {
                $auth->register(
                    $values['name'], $values['email'], Http::post('password', false),
                    Http::post('password_confirm', false)
                );
                Http::notice('success', 'Účet je vytvořen. Nyní se můžete přihlásit.');
                Http::redirect('login');
            } catch (FormError $error) {
                $errors[] = $error->getMessage();
            } catch (\PDOException $error) {
                $errors[] = 'Databázové nastavení se nezdařilo. Ověřte připojení a oprávnění databázového uživatele.';
            }
        }
        Http::render('register', 'Registrace', [
            'errors' => $errors, 'values' => $values,
        ]);
    }

    /** Process logout using CSRF-protected POST.
     * @param AuthService $auth Authentication service.
     * @return void No return value.
     */
    public function logout(AuthService $auth): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není povolena.');
        }
        Http::requireCsrf();
        $auth->logout();
        Http::notice('success', 'Byli jste odhlášeni.');
        Http::redirect('login');
    }
}