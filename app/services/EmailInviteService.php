<?php

declare(strict_types=1);

namespace Dabing;

use PDO;
use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/EmailInviteService.php
 * Module Type: Service
 * Purpose: Enforce email invitation, delivery, and first-visit claim policy.
 * Responsibilities: Authorize senders, issue one-use codes, and coordinate atomic claims.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates private project email invitation workflows. */
final class EmailInviteService
{
    /** Invitation lifetime.
     * Type: int; Units: seconds; Scope: one emailed invitation;
     * Consumers: sendToProject(); Rationale: require timely recipient action.
     */
    private const INVITE_TTL_SECONDS = 604800;
    /** Display-name bound for first-visit account creation.
     * Type: int; Units: Unicode characters; Scope: one account;
     * Consumers: claimNew(); Rationale: match account storage capacity.
     */
    private const NAME_MAX_LENGTH = 120;

    private readonly EmailInviteModel $invites;
    private readonly StudioModel $studio;
    private readonly InviteMailer $mailer;

    /** Create invitation workflow dependencies.
     * @param PDO $database Runtime database connection.
     * @param string $storageDirectory Protected storage directory.
     * @return void No return value.
     */
    public function __construct(PDO $database, string $storageDirectory)
    {
        $this->invites = new EmailInviteModel($database);
        $this->studio = new StudioModel($database);
        $this->mailer = new InviteMailer($storageDirectory);
    }

    /** Create and send an owner-authorized project invitation.
     * @param int $projectId Target project identifier.
     * @param int $ownerId Authenticated owner identifier.
     * @param string $email Submitted recipient address.
     * @return void No return value.
     * @throws FormError When ownership, address, quota, config, or delivery fails.
     */
    public function sendToProject(int $projectId, int $ownerId, string $email): void
    {
        $project = $this->studio->projectView($projectId, $ownerId);
        if ($project === null || $project['project']['role'] !== 'owner') {
            throw new FormError('E-mailovou pozvánku může poslat pouze autor projektu.');
        }
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254) {
            throw new FormError('Zadejte platnou e-mailovou adresu.');
        }
        $code = bin2hex(random_bytes(16));
        $codeHash = hash('sha256', $code);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::INVITE_TTL_SECONDS);
        $inviteId = $this->invites->issue(
            $projectId,
            $ownerId,
            $email,
            $codeHash,
            $expiresAt
        );
        try {
            if (!$this->mailer->sendInvite($email, $code)
                || !$this->invites->markDelivered($inviteId)) {
                throw new FormError('E-mail se nepodařilo odeslat. Zkontrolujte nastavení pošty.');
            }
        } catch (Throwable $error) {
            $this->invites->invalidate($inviteId);
            if ($error instanceof FormError) {
                throw $error;
            }
            throw new FormError('E-mail se nepodařilo odeslat. Zkontrolujte nastavení pošty.');
        }
    }

    /** Preview only a delivered, active code without returning it to the inviter.
     * @param string $code Presented one-time code.
     * @return array{email:string,project_title:string,existing_account:bool}|null Recipient view data or null.
     */
    public function preview(string $code): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $code) !== 1) {
            return null;
        }
        $invite = $this->invites->findPending(hash('sha256', $code));
        if ($invite === null) {
            return null;
        }
        return [
            'email' => $invite['email'],
            'project_title' => $invite['project_title'],
            'existing_account' => $invite['recipient_user_id'] !== null,
        ];
    }

    /** Claim an invitation for the matching authenticated recipient.
     * @param string $code Presented one-time code.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Authenticated account.
     * @return int Joined project identifier.
     * @throws FormError When the code or recipient does not match.
     */
    public function claimExisting(string $code, array $user): int
    {
        $invite = $this->preview($code);
        if ($invite === null || !$invite['existing_account']
            || !hash_equals($invite['email'], mb_strtolower($user['email']))) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        $claim = $this->invites->claimExisting(hash('sha256', $code), $user['id']);
        if ($claim === null || !$claim['email_matches']) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        return $claim['project_id'];
    }

    /** Create the invited user's account and project membership atomically.
     * @param string $code Presented one-time code.
     * @param string $name Submitted display name.
     * @param string $password New account password.
     * @param string $passwordConfirm Confirmation of the new password.
     * @return int Joined project identifier.
     * @throws FormError When validation, account creation, or invite claim fails.
     */
    public function claimNew(
        string $code,
        string $name,
        string $password,
        string $passwordConfirm
    ): int {
        $invite = $this->preview($code);
        if ($invite === null || $invite['existing_account']) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new FormError('Zadejte jméno do 120 znaků.');
        }
        AuthService::validateNewPassword($password);
        if (!hash_equals($password, $passwordConfirm)) {
            throw new FormError('Hesla se neshodují.');
        }
        $claim = $this->invites->claimNew(
            hash('sha256', $code),
            $name,
            password_hash($password, PASSWORD_DEFAULT)
        );
        if ($claim === null) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        return $claim['project_id'];
    }
}