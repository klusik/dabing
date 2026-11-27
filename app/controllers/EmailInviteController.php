<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/EmailInviteController.php
 * Module Type: Controller
 * Purpose: Handle owner email invitations and recipient first-visit claims.
 * Responsibilities: Enforce HTTP/CSRF and pass prepared invite data to fixed views.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Dispatches project email invitation requests. */
final class EmailInviteController
{
    /** Create controller with invitation policy service.
     * @param EmailInviteService $service Email invitation service.
     * @return void No return value.
     */
    public function __construct(private readonly EmailInviteService $service)
    {
    }

    /** Send an owner-authorized invitation without exposing its code.
     * @param int $ownerId Authenticated owner identifier.
     * @return void No return value.
     */
    public function send(int $ownerId): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není povolena.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        try {
            $this->service->sendToProject(
                $projectId,
                $ownerId,
                Http::post('email')
            );
        } catch (FormError $error) {
            Http::notice('error', $error->getMessage());
            Http::redirect('project', ['id' => $projectId]);
        }
        Http::notice('success', 'E-mailová pozvánka byla odeslána.');
        Http::redirect('project', ['id' => $projectId]);
    }

    /** Preview, authenticate, or claim a one-time invitation code.
     * @param AuthService $auth Authentication service for existing recipients.
     * @return void No return value.
     */
    public function join(AuthService $auth): void
    {
        $method = Http::method();
        if ($method !== 'GET' && $method !== 'POST') {
            throw new HttpError(405, 'Metoda není povolena.');
        }
        if ($method === 'POST') {
            Http::requireCsrf();
        }
        $code = $method === 'POST' ? Http::post('code') : Http::query('code');
        $invite = $this->service->preview($code);
        $user = $auth->currentUser();
        if ($invite === null) {
            $this->renderInvalid($user);
        }
        if ($invite['existing_account']) {
            if ($user === null) {
                $_SESSION['pending_email_invite_code'] = $code;
                Http::redirect('login');
            }
            if (!hash_equals($invite['email'], mb_strtolower($user['email']))) {
                $this->renderInvalid($user);
            }
        } elseif ($user !== null) {
            $this->renderInvalid($user);
        }

        $errors = [];
        if ($method === 'POST') {
            try {
                if ($invite['existing_account']) {
                    $projectId = $this->service->claimExisting($code, $user);
                    unset($_SESSION['pending_email_invite_code']);
                    Http::notice('success', 'Do knihy jste byli přidáni.');
                    Http::redirect('project', ['id' => $projectId]);
                }
                $projectId = $this->service->claimNew(
                    $code,
                    Http::post('name'),
                    Http::post('password', false),
                    Http::post('password_confirm', false)
                );
                unset($_SESSION['pending_email_invite_code']);
                Http::notice('success', 'Účet byl vytvořen. Přihlaste se a otevřete pozvanou knihu.');
                Http::redirect('login');
            } catch (FormError $error) {
                $errors[] = $error->getMessage();
            }
        }
        Http::render('email-join', 'Pozvánka do knihy', [
            'errors' => $errors,
            'invite_valid' => true,
            'existing_account' => $invite['existing_account'],
            'recipient_email' => $invite['email'],
            'project_title' => $invite['project_title'],
            'code' => $code,
        ], $user);
    }

    /** Render a generic invalid invitation page without recipient details.
     * @param array{id:int,name:string,email:string,is_admin:bool}|null $user Current user.
     * @return never Does not return.
     */
    private function renderInvalid(?array $user): never
    {
        Http::render('email-join', 'Pozvánka do knihy', [
            'errors' => [],
            'invite_valid' => false,
            'existing_account' => false,
            'recipient_email' => '',
            'project_title' => '',
            'code' => '',
        ], $user);
        exit;
    }
}