<?php

declare(strict_types=1);

namespace Dabing;

use PDO;
use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/EmailInviteModel.php
 * Module Type: Model
 * Purpose: Persist email invitations and atomic role claims.
 * Responsibilities: Own invite SQL, delivery state, quotas, and assignments.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns transactional email invitation persistence. */
final class EmailInviteModel
{
    /**
     * Type: int; Units: messages; Scope: one owner per rolling hour;
     * Consumers: reserveSend(); Rationale: bound invitation mail abuse.
     */
    private const MAX_OWNER_SENDS = 10;
    /**
     * Type: int; Units: seconds; Scope: owner send quota;
     * Consumers: reserveSend(); Rationale: reset quota hourly.
     */
    private const SEND_WINDOW_SECONDS = 3600;
    /**
     * Type: int; Units: attempts; Scope: one invitation;
     * Consumers: lockInvite(); Rationale: bound claim retries.
     */
    private const MAX_CLAIM_ATTEMPTS = 5;

    /** Create model on the active PDO connection.
     * @param PDO $database Configured application database.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Check that an active project owner or active administrator manages the book.
     * @param int $projectId Project identifier.
     * @param int $userId Authenticated account identifier.
     * @return bool True when the user can manage invitations for this project.
     */
    public function canManageProject(int $projectId, int $userId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM dabing_projects p JOIN dabing_users u ON u.id = :user '
            . 'WHERE p.id = :project AND u.is_active = 1 '
            . 'AND (p.owner_user_id = u.id OR u.is_admin = 1) LIMIT 1'
        );
        $query->execute(['user' => $userId, 'project' => $projectId]);
        return $query->fetchColumn() !== false;
    }

    /** Check whether any account already owns a normalized email address.
     * @param string $email Normalized email address.
     * @return bool True when the email belongs to an account, active or inactive.
     */
    public function accountExistsForEmail(string $email): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM dabing_users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $query->execute(['email' => $email]);
        return $query->fetchColumn() !== false;
    }

    /** Create pending invitation and reserve one send against owner quota.
     * @param int $projectId Target book identifier.
     * @param int $ownerId Authenticated book owner.
     * @param string $email Normalized recipient email.
     * @param list<string> $roles Exact character names.
     * @param string $codeHash SHA-256 hash of raw token.
     * @param string $expiresAt UTC expiry timestamp.
     * @return int New invitation ID.
     * @throws FormError When owner quota is exhausted.
     */
    public function issue(int $projectId, int $ownerId, string $email, array $roles, string $codeHash, string $expiresAt): int
    {
        $this->database->beginTransaction();
        try {
            $this->reserveSend($ownerId);
            $expire = $this->database->prepare(
                'UPDATE dabing_email_invites SET invalidated_at = UTC_TIMESTAMP() '
                . 'WHERE project_id = :project AND invited_email = :email '
                . 'AND claimed_at IS NULL AND invalidated_at IS NULL'
            );
            $expire->execute(['project' => $projectId, 'email' => $email]);
            $insert = $this->database->prepare(
                'INSERT INTO dabing_email_invites '
                . '(project_id, invited_email, code_hash, created_by_user_id, expires_at) '
                . 'VALUES (:project, :email, :hash, :owner, :expires)'
            );
            $insert->execute([
                'project' => $projectId, 'email' => $email,
                'hash' => $codeHash, 'owner' => $ownerId, 'expires' => $expiresAt,
            ]);
            $id = (int) $this->database->lastInsertId();
            $this->replaceRoles($id, $roles);
            $this->database->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Reserve an email send inside the current transaction.
     * @param int $ownerId Owner account identifier.
     * @return void No return value.
     * @throws FormError When hourly send limit is exceeded.
     */
    private function reserveSend(int $ownerId): void
    {
        $this->database->prepare(
            'INSERT IGNORE INTO dabing_email_invite_limits '
            . '(owner_user_id, sent_count, window_started_at) '
            . 'VALUES (:owner, 0, UTC_TIMESTAMP())'
        )->execute(['owner' => $ownerId]);
        $query = $this->database->prepare(
            'SELECT sent_count, window_started_at FROM dabing_email_invite_limits '
            . 'WHERE owner_user_id = :owner FOR UPDATE'
        );
        $query->execute(['owner' => $ownerId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpError(500, 'Pozvánku nelze vytvořit.');
        }
        $expired = strtotime((string) $row['window_started_at'] . ' UTC')
            < time() - self::SEND_WINDOW_SECONDS;
        $count = $expired ? 1 : (int) $row['sent_count'] + 1;
        if (!$expired && $count > self::MAX_OWNER_SENDS) {
            throw new FormError('Další e-mailovou pozvánku můžete poslat později.');
        }
        $this->database->prepare(
            'UPDATE dabing_email_invite_limits SET sent_count = :count, '
            . 'window_started_at = :started WHERE owner_user_id = :owner'
        )->execute([
            'count' => $count,
            'started' => $expired ? gmdate('Y-m-d H:i:s') : $row['window_started_at'],
            'owner' => $ownerId,
        ]);
    }

    /** Replace saved roles for one invitation.
     * @param int $id Invitation identifier.
     * @param list<string> $roles Exact validated character names.
     * @return void No return value.
     */
    private function replaceRoles(int $id, array $roles): void
    {
        $delete = $this->database->prepare(
            'DELETE FROM dabing_email_invite_roles WHERE invite_id = :id'
        );
        $delete->execute(['id' => $id]);
        $insert = $this->database->prepare(
            'INSERT INTO dabing_email_invite_roles (invite_id, character_name) '
            . 'VALUES (:id, :character)'
        );
        foreach ($roles as $role) {
            $insert->execute(['id' => $id, 'character' => $role]);
        }
    }

    /** Record successful transport only if the token has not been rotated or cancelled.
     * @param int $id Invitation identifier.
     * @param string $hash Expected current code hash.
     * @param string $sentAt UTC send-start timestamp.
     * @param string $expiry UTC expiry exactly one configured lifetime after send start.
     * @return bool True when delivery state was recorded.
     */
    public function markDelivered(int $id, string $hash, string $sentAt, string $expiry): bool
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET delivered_at = :sent, expires_at = :expiry, '
            . 'delivery_error = NULL WHERE id = :id AND code_hash = :hash '
            . 'AND delivered_at IS NULL AND claimed_at IS NULL '
            . 'AND invalidated_at IS NULL'
        );
        $statement->execute([
            'sent' => $sentAt, 'expiry' => $expiry, 'id' => $id, 'hash' => $hash,
        ]);
        return $statement->rowCount() === 1;
    }

    /** Record a safe transport failure only for the same still-current token.
     * @param int $id Invitation identifier.
     * @param string $hash Expected current code hash.
     * @param string $summary Non-secret operator-recognizable summary.
     * @return void No return value.
     */
    public function markFailed(int $id, string $hash, string $summary): void
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET invalidated_at = UTC_TIMESTAMP(), '
            . 'delivery_error = :error WHERE id = :id AND code_hash = :hash '
            . 'AND delivered_at IS NULL AND claimed_at IS NULL '
            . 'AND invalidated_at IS NULL'
        );
        $statement->execute(['error' => mb_substr($summary, 0, 255), 'id' => $id, 'hash' => $hash]);
    }

    /** List owner invitations with their exact saved roles and derived state.
     * @param int $projectId Target project identifier.
     * @return list<array{id:int,email:string,expires_at:string,created_at:string,delivered_at:?string,claimed_at:?string,invalidated_at:?string,delivery_error:?string,roles:list<string>}> Invitation rows.
     */
    public function listForProject(int $projectId): array
    {
        $query = $this->database->prepare(
            'SELECT id, invited_email, expires_at, created_at, delivered_at, '
            . 'claimed_at, invalidated_at, delivery_error FROM dabing_email_invites '
            . 'WHERE project_id = :project ORDER BY created_at DESC, id DESC'
        );
        $query->execute(['project' => $projectId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $roles = $this->database->prepare(
            'SELECT character_name FROM dabing_email_invite_roles '
            . 'WHERE invite_id = :id ORDER BY character_name'
        );
        $result = [];
        foreach ($rows as $row) {
            $roles->execute(['id' => $row['id']]);
            $result[] = [
                'id' => (int) $row['id'], 'email' => (string) $row['invited_email'],
                'expires_at' => (string) $row['expires_at'],
                'created_at' => (string) $row['created_at'],
                'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
                'claimed_at' => $row['claimed_at'] === null ? null : (string) $row['claimed_at'],
                'invalidated_at' => $row['invalidated_at'] === null ? null : (string) $row['invalidated_at'],
                'delivery_error' => $row['delivery_error'] === null ? null : (string) $row['delivery_error'],
                'roles' => array_map(static fn (array $role): string => (string) $role['character_name'], $roles->fetchAll(PDO::FETCH_ASSOC)),
            ];
        }
        return $result;
    }

    /** Cancel one pending invitation by row and project identifiers.
     * @param int $id Invitation identifier.
     * @param int $projectId Parent book identifier.
     * @return bool True when a pending invite was invalidated.
     */
    public function cancel(int $id, int $projectId): bool
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET invalidated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id AND project_id = :project AND claimed_at IS NULL '
            . 'AND invalidated_at IS NULL'
        );
        $statement->execute(['id' => $id, 'project' => $projectId]);
        return $statement->rowCount() === 1;
    }

    /** Replace a failed, expired, or pending invite with a new token atomically.
     * @param int $id Invitation identifier.
     * @param int $projectId Parent book identifier.
     * @param int $ownerId Authenticated owner reserving the resend.
     * @param string $hash New token hash.
     * @param string $expiry New UTC expiry.
     * @return array{id:int,email:string,roles:list<string>} Updated invitation data or null on conflict.
     */
    public function rotate(int $id, int $projectId, int $ownerId, string $hash, string $expiry): ?array
    {
        $this->database->beginTransaction();
        try {
            $this->reserveSend($ownerId);
            $query = $this->database->prepare(
                'SELECT invited_email, delivery_error, invalidated_at FROM dabing_email_invites '
                . 'WHERE id = :id AND project_id = :project '
                . 'AND claimed_at IS NULL FOR UPDATE'
            );
            $query->execute(['id' => $id, 'project' => $projectId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->database->rollBack();
                return null;
            }
            if ($row['invalidated_at'] !== null
                && $row['delivery_error'] === null) {
                $this->database->rollBack();
                return null;
            }
            $this->database->prepare(
                'UPDATE dabing_email_invites SET code_hash = :hash, expires_at = :expires, '
                . 'delivered_at = NULL, invalidated_at = NULL, delivery_error = NULL, attempts = 0 '
                . 'WHERE id = :id AND project_id = :project AND claimed_at IS NULL'
            )->execute(['hash' => $hash, 'expires' => $expiry, 'id' => $id, 'project' => $projectId]);
            $roles = $this->database->prepare(
                'SELECT character_name FROM dabing_email_invite_roles WHERE invite_id = :id'
            );
            $roles->execute(['id' => $id]);
            $roleList = array_map(static fn (array $role): string => (string) $role['character_name'], $roles->fetchAll(PDO::FETCH_ASSOC));
            $this->database->commit();
            return ['id' => $id, 'email' => (string) $row['invited_email'], 'roles' => $roleList];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Find a delivered, unexpired invitation by SHA-256 digest.
     * @param string $hash Token digest.
     * @return array{id:int,project_id:int,email:string,project_title:string,recipient_user_id:?int,roles:list<string>} Invite preview or null.
     */
    public function findPending(string $hash): ?array
    {
        $query = $this->database->prepare(
            'SELECT i.id, i.project_id, i.invited_email, p.title, u.id AS user_id '
            . 'FROM dabing_email_invites i JOIN dabing_projects p ON p.id = i.project_id '
            . 'LEFT JOIN dabing_users u ON LOWER(u.email) = LOWER(i.invited_email) '
            . 'WHERE i.code_hash = :hash AND i.delivered_at IS NOT NULL '
            . 'AND i.expires_at > UTC_TIMESTAMP() AND i.claimed_at IS NULL '
            . 'AND i.invalidated_at IS NULL LIMIT 1'
        );
        $query->execute(['hash' => $hash]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $roles = $this->database->prepare(
            'SELECT character_name FROM dabing_email_invite_roles WHERE invite_id = :id'
        );
        $roles->execute(['id' => $row['id']]);
        return [
            'id' => (int) $row['id'], 'project_id' => (int) $row['project_id'],
            'email' => (string) $row['invited_email'], 'project_title' => (string) $row['title'],
            'recipient_user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'roles' => array_map(static fn (array $role): string => (string) $role['character_name'], $roles->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** Atomically claim invite for an existing active account matching the exact email.
     * @param string $hash Expected token digest.
     * @param int $userId Authenticated account identifier.
     * @return int|null Project ID or null when claim failed.
     */
    public function claimExisting(string $hash, int $userId): ?int
    {
        return $this->claim($hash, $userId);
    }

    /** Atomically create an account and claim invite.
     * @param string $hash Expected token digest.
     * @param string $name Account display name.
     * @param string $passwordHash Password hash.
     * @return array{id:int,project_id:int}|null Account and project IDs or null.
     */
    public function claimNew(string $hash, string $name, string $passwordHash): ?array
    {
        $this->database->beginTransaction();
        try {
            $invite = $this->lockInvite($hash);
            if ($invite === null) {
                $this->database->commit();
                return null;
            }
            $this->incrementAttempt($invite['id']);
            $existing = $this->database->prepare('SELECT id FROM dabing_users WHERE LOWER(email) = LOWER(:email) LIMIT 1 FOR UPDATE');
            $existing->execute(['email' => $invite['email']]);
            if ($existing->fetchColumn() !== false) {
                $this->database->commit();
                return null;
            }
            $create = $this->database->prepare(
                'INSERT INTO dabing_users (name, email, password_hash, is_admin) VALUES (:name, :email, :hash, 0)'
            );
            $create->execute(['name' => $name, 'email' => $invite['email'], 'hash' => $passwordHash]);
            $userId = (int) $this->database->lastInsertId();
            $this->grantRoles($invite['id'], $invite['project_id'], $userId);
            $this->consume($invite['id'], $userId);
            $this->database->commit();
            return ['id' => $userId, 'project_id' => $invite['project_id']];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($error instanceof \PDOException && (string) $error->getCode() === '23000') {
                return null;
            }
            throw $error;
        }
    }

    /** Claim token for an existing account.
     * @param string $hash Expected digest.
     * @param int $userId Existing active account identifier.
     * @return int|null Project ID or null.
     */
    private function claim(string $hash, int $userId): ?int
    {
        $this->database->beginTransaction();
        try {
            $invite = $this->lockInvite($hash);
            if ($invite === null) {
                $this->database->commit();
                return null;
            }
            $this->incrementAttempt($invite['id']);
            $account = $this->database->prepare(
                'SELECT email, is_active FROM dabing_users WHERE id = :id FOR UPDATE'
            );
            $account->execute(['id' => $userId]);
            $row = $account->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !(bool) $row['is_active']
                || !hash_equals(mb_strtolower($invite['email']), mb_strtolower((string) $row['email']))) {
                $this->database->commit();
                return null;
            }
            $this->grantRoles($invite['id'], $invite['project_id'], $userId);
            $this->consume($invite['id'], $userId);
            $this->database->commit();
            return $invite['project_id'];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Lock the current valid token and read its invitation ID.
     * @param string $hash Expected token digest.
     * @return array{id:int,project_id:int,email:string}|null Valid locked invite.
     */
    private function lockInvite(string $hash): ?array
    {
        $query = $this->database->prepare(
            'SELECT id, project_id, invited_email, attempts FROM dabing_email_invites '
            . 'WHERE code_hash = :hash AND delivered_at IS NOT NULL '
            . 'AND expires_at > UTC_TIMESTAMP() AND claimed_at IS NULL '
            . 'AND invalidated_at IS NULL FOR UPDATE'
        );
        $query->execute(['hash' => $hash]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['attempts'] >= self::MAX_CLAIM_ATTEMPTS) {
            return null;
        }
        return ['id' => (int) $row['id'], 'project_id' => (int) $row['project_id'], 'email' => (string) $row['invited_email']];
    }

    /** Count one claim submission for the currently locked invitation.
     * @param int $id Invitation identifier.
     * @return void No return value.
     */
    private function incrementAttempt(int $id): void
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET attempts = attempts + 1 WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /** Apply saved exact roles as access assignments; empty roles grant no access.
     * @param int $inviteId Invitation identifier.
     * @param int $projectId Project identifier.
     * @param int $userId Recipient account identifier.
     * @return void No return value.
     */
    private function grantRoles(int $inviteId, int $projectId, int $userId): void
    {
        $query = $this->database->prepare(
            'SELECT r.character_name FROM dabing_email_invite_roles r '
            . 'WHERE r.invite_id = :invite AND EXISTS ('
            . 'SELECT 1 FROM dabing_project_chapters c JOIN dabing_script_lines l ON l.chapter_id = c.id '
            . 'WHERE c.project_id = :project AND CAST(l.character_name AS BINARY) = CAST(r.character_name AS BINARY))'
        );
        $query->execute(['invite' => $inviteId, 'project' => $projectId]);
        $roles = $query->fetchAll(PDO::FETCH_COLUMN);
        $countQuery = $this->database->prepare(
            'SELECT COUNT(*) FROM dabing_email_invite_roles WHERE invite_id = :id'
        );
        $countQuery->execute(['id' => $inviteId]);
        $expectedCount = (int) $countQuery->fetchColumn();
        if (count($roles) !== $expectedCount) {
            throw new FormError('Role pozvánky již nejsou v knize dostupné.');
        }
        foreach ($roles as $role) {
            $insert = $this->database->prepare(
                'INSERT IGNORE INTO dabing_voice_assignments (project_id, character_name, user_id) '
                . 'VALUES (:project, :character, :user)'
            );
            $insert->execute(['project' => $projectId, 'character' => $role, 'user' => $userId]);
        }
    }

    /** Consume invitation after all role writes succeed.
     * @param int $id Invitation identifier.
     * @param int $userId Recipient account identifier.
     * @return void No return value.
     */
    private function consume(int $id, int $userId): void
    {
        $update = $this->database->prepare(
            'UPDATE dabing_email_invites SET claimed_by_user_id = :user, claimed_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id AND claimed_at IS NULL AND invalidated_at IS NULL'
        );
        $update->execute(['user' => $userId, 'id' => $id]);
        if ($update->rowCount() !== 1) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
    }
}
