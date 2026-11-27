<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/UpdateController.php
 * Module Type: Controller
 * Purpose: Gate and process administrator-triggered application updates.
 * Responsibilities: Verify administrator state, validate CSRF and package identity, and render PRG notices.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Handles the administrator-only update page and apply action. */
final class UpdateController
{
    /** Create the updater controller with its domain service.
     * @param UpdateService $updates Application update service.
     * @return void No return value.
     */
    public function __construct(private readonly UpdateService $updates)
    {
    }

    /** Handle an update request after checking the fresh account again.
     * @param array{id:int,name:string,email:string,is_admin:bool}|null $user Freshly authenticated user.
     * @return void Renders the update page or issues a PRG redirect.
     * @throws HttpError When this request is not authorized or has the wrong method.
     */
    public function handle(?array $user): void
    {
        if ($user === null || !$user['is_admin']) {
            throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
        }
        if (Http::method() === 'POST') {
            $this->apply();
            return;
        }
        $this->show($user);
    }

    /** Validate and apply the exact package named by the submitted form.
     * @return void The result is reported through a session notice and redirect.
     */
    private function apply(): void
    {
        Http::requireCsrf();
        $version = Http::post('version');
        $sha256 = Http::post('sha256');
        $dbUser = Http::post('db_user');
        $dbPassword = Http::post('db_password', false);
        unset($_POST['db_user'], $_POST['db_password']);
        if (preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $version) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            Http::notice('error', 'Údaje vydání nejsou platné. Načtěte stránku znovu.');
            Http::redirect('update');
        }

        session_write_close();
        $noticeType = 'success';
        $noticeMessage = 'Aktualizace byla dokončena.';
        try {
            $this->updates->apply($version, $sha256, $dbUser, $dbPassword);
        } catch (FormError|HttpError $error) {
            $noticeType = 'error';
            $noticeMessage = $error->getMessage();
        } catch (\Throwable $error) {
            error_log(sprintf(
                'Dabing application update failed: %s at %s:%d',
                get_class($error), $error->getFile(), $error->getLine()
            ));
            $noticeType = 'error';
            $noticeMessage = 'Aktualizaci se nepodařilo dokončit.';
        } finally {
            unset($dbPassword);
            Http::boot();
        }
        Http::notice($noticeType, $noticeMessage);
        Http::redirect('update');
    }

    /** Show the current version and latest release to a confirmed administrator.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Freshly authenticated user.
     * @return void Renders the admin update form without retaining credentials.
     */
    private function show(array $user): void
    {
        $currentVersion = $this->updates->currentVersion();
        $latestRelease = null;
        $errors = [];
        session_write_close();
        try {
            $latestRelease = $this->updates->latestRelease();
            if ($latestRelease !== null
                && (preg_match(
                    '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D',
                    $latestRelease['version']
                ) !== 1
                || !version_compare(
                    $latestRelease['version'], $currentVersion, '>'
                ))) {
                $latestRelease = null;
            }
        } catch (\Throwable $error) {
            error_log(sprintf(
                'Dabing release check failed: %s at %s:%d',
                get_class($error), $error->getFile(), $error->getLine()
            ));
            $errors[] = 'Kontrola nové verze se nezdařila. Zkuste to později.';
        } finally {
            Http::boot();
        }
        Http::render('update', 'Aktualizace', [
            'current_version' => $currentVersion,
            'latest_release' => $latestRelease,
            'errors' => $errors,
        ], $user);
    }
}