<?php

declare(strict_types=1);

namespace Dabing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/EmailInviteService.php
 * Module Type: Service
 * Purpose: Apply addressed invitation lifecycle and recipient claim policy.
 * Responsibilities: Authorize owners, validate roles, send, cancel, and claim invites.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates owner invitation management and atomic recipient claims. */
final class EmailInviteService
{
    /**
     * Type: int; Units: seconds; Scope: one invitation from sending;
     * Consumers: deliver(); Rationale: expire emailed links 24 hours after transport starts.
     */
    private const INVITE_TTL_SECONDS = 86400;
    /**
     * Type: int; Units: Unicode characters; Scope: new account display name;
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
    public function __construct(
        private readonly PDO $database,
        string $storageDirectory
    )
    {
        $this->invites = new EmailInviteModel($database);
        $this->studio = new StudioModel($database);
        $this->mailer = new InviteMailer($storageDirectory);
    }

    /** List owner-managed invitations with Prague-local display timestamps.
     * @param int $projectId Target book.
     * @param int $ownerId Authenticated owner.
     * @return list<array{id:int,email:string,roles:list<string>,state:string,expires_at_local:?string,delivery_error:?string,created_at_local:string}> View rows.
     */
    public function listForOwner(int $projectId, int $ownerId): array
    {
        $this->requireOwner($projectId, $ownerId);
        return array_map(function (array $row): array {
            $state = 'pending';
            if ($row['claimed_at'] !== null) {
                $state = 'claimed';
            } elseif ($row['invalidated_at'] !== null) {
                $state = $row['delivery_error'] !== null ? 'failed' : 'cancelled';
            } elseif (strtotime($row['expires_at'] . ' UTC') <= time()) {
                $state = 'expired';
            } elseif ($row['delivered_at'] !== null) {
                $state = 'sent';
            }
            return [
                'id' => $row['id'], 'email' => $row['email'], 'roles' => $row['roles'],
                'state' => $state,
                'expires_at_local' => $row['delivered_at'] === null ? null : $this->localDate($row['expires_at']),
                'delivery_error' => $row['delivery_error'],
                'created_at_local' => $this->localDate($row['created_at']),
            ];
        }, $this->invites->listForProject($projectId));
    }

    /** Create and send an owner-authorized addressed invitation.
     * @param int $projectId Target book.
     * @param int $ownerId Authenticated owner.
     * @param string $email Submitted address.
     * @param list<string> $roles Requested exact character names.
     * @return void No return value.
     * @throws FormError When owner, address, role, quota, or delivery is invalid.
     */
    public function sendToProject(int $projectId, int $ownerId, string $email, array $roles = []): void
    {
        $this->requireOwner($projectId, $ownerId);
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new FormError('Zadejte platnou e-mailovou adresu.');
        }
        if ($this->invites->accountExistsForEmail($email)) {
            throw new FormError(
                'Tento e-mail patří existujícímu účtu. Vyberte ho ze seznamu nebo požádejte správce o přiřazení.'
            );
        }
        $roles = $this->validateRoles($projectId, $roles);
        $code = bin2hex(random_bytes(16));
        $hash = hash('sha256', $code);
        $id = $this->invites->issue(
            $projectId, $ownerId, $email, $roles, $hash, $this->expiry()
        );
        $this->deliver($id, $email, $code, $hash, $roles);
    }

    /** Grant selected roles to an existing account and notify only for new grants.
     * @param int $projectId Target book.
     * @param int $adminId Active administrator performing the change.
     * @param int $userId Existing recipient account.
     * @param list<string> $roles Exact selected book roles.
     * @return array{email:string,project_title:string,roles:list<string>} Grant details and newly assigned roles.
     * @throws FormError When roles are invalid or notification delivery fails after grants save.
     * @throws HttpError When the actor is not an active administrator.
     */
    public function assignExistingAccount(
        int $projectId,
        int $adminId,
        int $userId,
        array $roles
    ): array {
        $grant = (new UserAdminService($this->database))->grantRoles(
            $adminId,
            $userId,
            $projectId,
            $roles
        );
        if ($grant['roles'] === []) {
            return $grant;
        }
        try {
            $sent = $this->mailer->sendRoleAssignmentNotice(
                $grant['email'],
                $projectId,
                $grant['project_title'],
                $grant['roles']
            );
        } catch (Throwable) {
            $sent = false;
        }
        if (!$sent) {
            throw new FormError(
                'Postavy byly přiřazeny, ale informační e-mail se nepodařilo odeslat.'
            );
        }
        return $grant;
    }

    /** Cancel one unclaimed invitation owned by the current project.
     * @param int $projectId Parent book.
     * @param int $ownerId Authenticated owner.
     * @param int $inviteId Invitation row identifier.
     * @return void No return value.
     */
    public function cancel(int $projectId, int $ownerId, int $inviteId): void
    {
        $this->requireOwner($projectId, $ownerId);
        if (!$this->invites->cancel($inviteId, $projectId)) {
            throw new FormError('Pozvánku se nepodařilo zrušit.');
        }
    }

    /** Resend and rotate code for any unclaimed invitation, preserving selected roles.
     * @param int $projectId Parent book.
     * @param int $ownerId Authenticated owner.
     * @param int $inviteId Invitation row identifier.
     * @return void No return value.
     */
    public function resend(int $projectId, int $ownerId, int $inviteId): void
    {
        $this->requireOwner($projectId, $ownerId);
        $code = bin2hex(random_bytes(16));
        $hash = hash('sha256', $code);
        $row = $this->invites->rotate(
            $inviteId, $projectId, $ownerId, $hash, $this->expiry()
        );
        if ($row === null) {
            throw new FormError('Pozvánku se nepodařilo obnovit.');
        }
        $this->deliver($row['id'], $row['email'], $code, $hash, $row['roles']);
    }

    /** Deliver a previously persisted invite, recording failures without retaining a usable token.
     * @param int $id Invitation identifier.
     * @param string $email Recipient address.
     * @param string $code Raw one-use code.
     * @param string $hash Expected stored digest.
     * @param list<string> $roles Selected exact roles.
     * @return void No return value.
     */
    private function deliver(int $id, string $email, string $code, string $hash, array $roles): void
    {
        try {
            $sentAt = gmdate('Y-m-d H:i:s');
            $expiry = (new DateTimeImmutable($sentAt, new DateTimeZone('UTC')))
                ->modify('+' . self::INVITE_TTL_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s');
            if (!$this->mailer->sendInvite(
                $email, $code, $roles, $this->localDate($expiry)
            )
                || !$this->invites->markDelivered($id, $hash, $sentAt, $expiry)) {
                throw new FormError('E-mail se nepodařilo odeslat. Zkontrolujte nastavení pošty.');
            }
        } catch (Throwable $error) {
            $this->invites->markFailed(
                $id,
                $hash,
                'Nepodařilo se doručit e-mail; zkontrolujte nastavení pošty.'
            );
            if ($error instanceof FormError) {
                throw $error;
            }
            throw new FormError('E-mail se nepodařilo odeslat. Zkontrolujte nastavení pošty.');
        }
    }

    /** Preview a delivered, active code.
     * @param string $code Presented token.
     * @return array{email:string,project_title:string,existing_account:bool,roles:list<string>} Preview or null.
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
            'email' => $invite['email'], 'project_title' => $invite['project_title'],
            'existing_account' => $invite['recipient_user_id'] !== null,
            'roles' => $invite['roles'],
        ];
    }

    /** Claim an invite for an existing matching active account.
     * @param string $code Presented token.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Authenticated account.
     * @return int Joined project identifier.
     * @throws FormError When code or recipient does not match.
     */
    public function claimExisting(string $code, array $user): int
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $code) !== 1) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        $invite = $this->invites->findPending(hash('sha256', $code));
        if ($invite === null || $invite['recipient_user_id'] === null
            || !hash_equals(mb_strtolower($invite['email']), mb_strtolower($user['email']))) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        $projectId = $this->invites->claimExisting(
            hash('sha256', $code),
            $user['id']
        );
        if ($projectId === null) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        return $projectId;
    }

    /** Create account and atomically claim invite.
     * @param string $code Presented token.
     * @param string $name Display name.
     * @param string $password New password.
     * @param string $passwordConfirm Confirmation.
     * @return array{id:int,project_id:int} New account and project IDs.
     * @throws FormError When validation or claim fails.
     */
    public function claimNew(string $code, string $name, string $password, string $passwordConfirm): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $code) !== 1
            || ($invite = $this->invites->findPending(hash('sha256', $code))) === null
            || $invite['recipient_user_id'] !== null) {
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
        $claimed = $this->invites->claimNew(hash('sha256', $code), $name, password_hash($password, PASSWORD_DEFAULT));
        if ($claimed === null) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        return $claimed;
    }

    /** Ensure the current account owns the project.
     * @param int $projectId Project identifier.
     * @param int $ownerId Owner account identifier.
     * @return void No return value.
     * @throws FormError When caller is not the active owner.
     */
    private function requireOwner(int $projectId, int $ownerId): void
    {
        if (!$this->invites->canManageProject($projectId, $ownerId)) {
            throw new FormError('E-mailové pozvánky může spravovat pouze správce knihy.');
        }
    }

    /** Validate exact role names against the current script.
     * @param int $projectId Project identifier.
     * @param list<string> $roles Submitted role names.
     * @return list<string> Unique exact role names.
     * @throws FormError When a role is not part of the book.
     */
    private function validateRoles(int $projectId, array $roles): array
    {
        $available = [];
        foreach ($this->studio->projectRoles($projectId) as $character) {
            $available[bin2hex($character)] = $character;
        }
        $result = [];
        foreach ($roles as $role) {
            $key = bin2hex($role);
            if (!isset($available[$key])) {
                throw new FormError('Vyberte pouze postavy z této knihy.');
            }
            $result[$key] = $available[$key];
        }
        return array_values($result);
    }

    /** Return a SQL UTC expiry timestamp 24 hours from now.
     * @return string UTC datetime.
     */
    private function expiry(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::INVITE_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    }

    /** Format a stored UTC timestamp using current Prague CET/CEST offset.
     * @param string $utc Stored SQL datetime.
     * @return string Prague-local timestamp with timezone abbreviation.
     */
    private function localDate(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Prague'))->format('d.m.Y H:i:s T');
    }
}
