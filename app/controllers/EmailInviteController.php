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
     * @param string $adminEmail Email from the refreshed authenticated account.
     * @return void No return value.
     */
    public function send(int $ownerId, string $adminEmail): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není povolena.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $action = Http::post('action');
        $inviteId = Http::postId('invite_id');
        $successMessage = null;
        try {
            if ($action === 'cancel' && $inviteId !== null) {
                $this->service->cancel($projectId, $ownerId, $inviteId);
            } elseif ($action === 'resend' && $inviteId !== null) {
                $this->service->resend(
                    $projectId, $ownerId, $inviteId, $adminEmail
                );
            } elseif ($action === 'send') {
                $roles = $_POST['roles'] ?? [];
                if (Http::post('roles_end') !== '1'
                    || !is_array($roles)
                    || count(array_filter($roles, 'is_string')) !== count($roles)) {
                    throw new FormError('Výběr postav není platný.');
                }
                $roles = array_values($roles);
                $recipientUserId = Http::postId('recipient_user_id');
                $rawRecipientUserId = Http::post('recipient_user_id');
                if ($rawRecipientUserId !== '' && $recipientUserId === null) {
                    throw new FormError('Vybraný účet není platný.');
                }
                if ($recipientUserId !== null) {
                    if (Http::post('email') !== '') {
                        throw new FormError(
                            'U vybraného účtu nevyplňujte nové e-mailové pole.'
                        );
                    }
                    $grant = $this->service->assignExistingAccount(
                        $projectId,
                        $ownerId,
                        $recipientUserId,
                        $roles,
                        $adminEmail
                    );
                    $successMessage = $grant['roles'] === []
                        ? 'Vybrané postavy již účtu patřily; e-mail nebyl odeslán.'
                        : 'Postavy byly přiřazeny a informační e-mail odeslán.';
                } else {
                    $this->service->sendToProject(
                        $projectId,
                        $ownerId,
                        Http::post('email'),
                        $roles,
                        $adminEmail
                    );
                    $successMessage = 'E-mailová pozvánka byla odeslána.';
                }
            } else {
                throw new FormError('Požadavek na pozvánku není platný.');
            }
        } catch (FormError $error) {
            Http::notice('error', $error->getMessage());
            Http::redirect('project', ['id' => $projectId]);
        }
        Http::notice('success', $successMessage ?? match ($action) {
            'cancel' => 'Pozvánka byla zrušena.',
            'resend' => 'Pozvánka byla znovu odeslána.',
            default => 'E-mailová pozvánka byla odeslána.',
        });
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
        $wrongAccount = false;
        if ($invite === null) {
            $this->renderInvalid($user);
        }
        if ($invite['existing_account']) {
            if ($user === null) {
                $_SESSION['pending_email_invite_code'] = $code;
                Http::redirect('login');
            }
            if (!hash_equals($invite['email'], mb_strtolower($user['email']))) {
                $_SESSION['pending_email_invite_code'] = $code;
                $wrongAccount = true;
            }
        } elseif ($user !== null) {
            $_SESSION['pending_email_invite_code'] = $code;
            $wrongAccount = true;
        }

        $errors = [];
        if ($method === 'POST' && !$wrongAccount) {
            try {
                if ($invite['existing_account']) {
                    $projectId = $this->service->claimExisting($code, $user);
                    unset($_SESSION['pending_email_invite_code']);
                    Http::notice('success', 'Pozvánka byla přijata.');
                    Http::redirect(
                        $invite['roles'] === [] ? 'projects' : 'project',
                        $invite['roles'] === [] ? [] : ['id' => $projectId]
                    );
                }
                $claimed = $this->service->claimNew(
                    $code,
                    Http::post('name'),
                    Http::post('password', false),
                    Http::post('password_confirm', false)
                );
                unset($_SESSION['pending_email_invite_code']);
                $auth->loginById($claimed['id']);
                Http::notice('success', 'Účet byl vytvořen a pozvánka přijata.');
                Http::redirect(
                    $invite['roles'] === [] ? 'projects' : 'project',
                    $invite['roles'] === [] ? [] : ['id' => $claimed['project_id']]
                );
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
            'wrong_account' => $wrongAccount,
            'roles' => $invite['roles'],
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
