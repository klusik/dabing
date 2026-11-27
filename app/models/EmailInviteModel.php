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
 * Purpose: Persist one-time email invitations and bounded send/claim activity.
 * Responsibilities: Own SQL transactions and atomically grant project membership.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns invitation SQL and transactional membership updates. */
final class EmailInviteModel
{
    /** Per-owner sending limit.
     * Type: int; Units: messages; Scope: one owner per rolling hour;
     * Consumers: issue(); Rationale: bound invitation mail abuse.
     */
    private const MAX_OWNER_SENDS = 10;
    /** Owner sending window.
     * Type: int; Units: seconds; Scope: one owner;
     * Consumers: issue(); Rationale: reset send quota hourly.
     */
    private const SEND_WINDOW_SECONDS = 3600;
    /** Maximum claim submissions for one code.
     * Type: int; Units: attempts; Scope: one invitation;
     * Consumers: claim transactions; Rationale: bound retries and guessing.
     */
    private const MAX_CLAIM_ATTEMPTS = 5;

    private readonly AccountModel $accounts;

    /** Create the model on the runtime database connection.
     * @param PDO $database Active PDO connection.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
        $this->accounts = new AccountModel($database);
    }

    /** Create a pending invitation and count the owner's send.
     * @param int $projectId Target project identifier.
     * @param int $ownerId Authenticated project owner.
     * @param string $email Normalized recipient email.
     * @param string $codeHash SHA-256 hash of the emailed code.
     * @param string $expiresAt UTC SQL datetime.
     * @return int New invitation identifier.
     * @throws FormError When the owner's sending limit is reached.
     */
    public function issue(
        int $projectId,
        int $ownerId,
        string $email,
        string $codeHash,
        string $expiresAt
    ): int {
        $this->database->beginTransaction();
        try {
            $insertLimit = $this->database->prepare(
                'INSERT IGNORE INTO dabing_email_invite_limits '
                . '(owner_user_id, sent_count, window_started_at) '
                . 'VALUES (:owner, 0, UTC_TIMESTAMP())'
            );
            $insertLimit->execute(['owner' => $ownerId]);
            $readLimit = $this->database->prepare(
                'SELECT sent_count, window_started_at '
                . 'FROM dabing_email_invite_limits '
                . 'WHERE owner_user_id = :owner FOR UPDATE'
            );
            $readLimit->execute(['owner' => $ownerId]);
            $limit = $readLimit->fetch(PDO::FETCH_ASSOC);
            if (!is_array($limit)) {
                throw new HttpError(500, 'Pozvánku nelze vytvořit.');
            }
            $expired = strtotime((string) $limit['window_started_at'] . ' UTC')
                < time() - self::SEND_WINDOW_SECONDS;
            $count = $expired ? 1 : (int) $limit['sent_count'] + 1;
            if (!$expired && $count > self::MAX_OWNER_SENDS) {
                throw new FormError('Další e-mailovou pozvánku můžete poslat později.');
            }
            $updateLimit = $this->database->prepare(
                'UPDATE dabing_email_invite_limits SET sent_count = :count, '
                . 'window_started_at = :started WHERE owner_user_id = :owner'
            );
            $updateLimit->execute([
                'count' => $count,
                'started' => $expired ? gmdate('Y-m-d H:i:s')
                    : (string) $limit['window_started_at'],
                'owner' => $ownerId,
            ]);
            $invalidate = $this->database->prepare(
                'UPDATE dabing_email_invites SET invalidated_at = UTC_TIMESTAMP() '
                . 'WHERE project_id = :project AND invited_email = :email '
                . 'AND claimed_at IS NULL AND invalidated_at IS NULL'
            );
            $invalidate->execute(['project' => $projectId, 'email' => $email]);
            $insert = $this->database->prepare(
                'INSERT INTO dabing_email_invites '
                . '(project_id, invited_email, code_hash, created_by_user_id, expires_at) '
                . 'VALUES (:project, :email, :hash, :owner, :expires)'
            );
            $insert->execute([
                'project' => $projectId, 'email' => $email, 'hash' => $codeHash,
                'owner' => $ownerId, 'expires' => $expiresAt,
            ]);
            $id = (int) $this->database->lastInsertId();
            $this->database->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Mark an invite usable only after mail transport accepts it.
     * @param int $id Invitation identifier.
     * @return bool True when the row moved into delivered state.
     */
    public function markDelivered(int $id): bool
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET delivered_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id AND delivered_at IS NULL '
            . 'AND claimed_at IS NULL AND invalidated_at IS NULL'
        );
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** Invalidate an invitation after transport failure.
     * @param int $id Invitation identifier.
     * @return void No return value.
     */
    public function invalidate(int $id): void
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET invalidated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id AND claimed_at IS NULL AND invalidated_at IS NULL'
        );
        $statement->execute(['id' => $id]);
    }

    /** Look up a delivered invitation and whether its email has an account.
     * @param string $codeHash SHA-256 hash of the presented code.
     * @return array{id:int,project_id:int,email:string,project_title:string,recipient_user_id:?int}|null Valid invite data or null.
     */
    public function findPending(string $codeHash): ?array
    {
        $statement = $this->database->prepare(
            'SELECT i.id, i.project_id, i.invited_email, p.title, u.id AS user_id '
            . 'FROM dabing_email_invites i '
            . 'JOIN dabing_projects p ON p.id = i.project_id '
            . 'LEFT JOIN dabing_users u ON u.email = i.invited_email '
            . 'WHERE i.code_hash = :hash AND i.delivered_at IS NOT NULL '
            . 'AND i.expires_at > UTC_TIMESTAMP() AND i.claimed_at IS NULL '
            . 'AND i.invalidated_at IS NULL AND i.attempts < :max_attempts '
            . 'LIMIT 1'
        );
        $statement->execute([
            'hash' => $codeHash, 'max_attempts' => self::MAX_CLAIM_ATTEMPTS,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'email' => (string) $row['invited_email'],
            'project_title' => (string) $row['title'],
            'recipient_user_id' => $row['user_id'] === null
                ? null : (int) $row['user_id'],
        ];
    }

    /** Atomically grant an invitation to a matching existing account.
     * @param string $codeHash SHA-256 hash of the presented code.
     * @param int $userId Signed-in recipient account identifier.
     * @return array{project_id:int,email_matches:bool}|null Claim result or invalid code.
     */
    public function claimExisting(string $codeHash, int $userId): ?array
    {
        $this->database->beginTransaction();
        try {
            $invite = $this->lockInvite($codeHash);
            if ($invite === null) {
                $this->database->commit();
                return null;
            }
            $this->incrementAttempt($invite['id']);
            $user = $this->accounts->findById($userId);
            $matches = $user !== null
                && hash_equals(mb_strtolower($invite['email']), mb_strtolower($user['email']));
            if (!$matches) {
                $this->database->commit();
                return ['project_id' => $invite['project_id'], 'email_matches' => false];
            }
            $this->addMembership($invite['project_id'], $userId);
            $this->consume($invite['id'], $userId);
            $this->database->commit();
            return ['project_id' => $invite['project_id'], 'email_matches' => true];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Atomically create a recipient account and claim the invitation.
     * @param string $codeHash SHA-256 hash of the presented code.
     * @param string $name New account display name.
     * @param string $passwordHash New account password hash.
     * @return array{project_id:int,email:string}|null New claim or account conflict.
     */
    public function claimNew(
        string $codeHash,
        string $name,
        string $passwordHash
    ): ?array {
        $this->database->beginTransaction();
        try {
            $invite = $this->lockInvite($codeHash);
            if ($invite === null) {
                $this->database->commit();
                return null;
            }
            $this->incrementAttempt($invite['id']);
            if ($this->accounts->findByEmail($invite['email']) !== null) {
                $this->database->commit();
                return null;
            }
            $userId = $this->accounts->create(
                $name, $invite['email'], $passwordHash, false
            );
            $this->addMembership($invite['project_id'], $userId);
            $this->consume($invite['id'], $userId);
            $this->database->commit();
            return [
                'project_id' => $invite['project_id'],
                'email' => $invite['email'],
            ];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($error instanceof \PDOException
                && (string) $error->getCode() === '23000') {
                return null;
            }
            throw $error;
        }
    }

    /** Lock a valid delivered invite in the current transaction.
     * @param string $codeHash SHA-256 invitation code hash.
     * @return array{id:int,project_id:int,email:string}|null Claimable invite or null.
     */
    private function lockInvite(string $codeHash): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, project_id, invited_email, attempts '
            . 'FROM dabing_email_invites WHERE code_hash = :hash '
            . 'AND delivered_at IS NOT NULL AND expires_at > UTC_TIMESTAMP() '
            . 'AND claimed_at IS NULL AND invalidated_at IS NULL FOR UPDATE'
        );
        $statement->execute(['hash' => $codeHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['attempts'] >= self::MAX_CLAIM_ATTEMPTS) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'email' => (string) $row['invited_email'],
        ];
    }

    /** Increment the attempts for the currently locked invite.
     * @param int $id Locked invitation identifier.
     * @return void No return value.
     */
    private function incrementAttempt(int $id): void
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET attempts = attempts + 1 WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /** Add project membership inside the open invite transaction.
     * @param int $projectId Project identifier.
     * @param int $userId Recipient account identifier.
     * @return void No return value.
     */
    private function addMembership(int $projectId, int $userId): void
    {
        $statement = $this->database->prepare(
            'INSERT IGNORE INTO dabing_project_members (project_id, user_id) '
            . 'VALUES (:project, :user)'
        );
        $statement->execute(['project' => $projectId, 'user' => $userId]);
    }

    /** Consume an invitation inside the open transaction.
     * @param int $id Invitation identifier.
     * @param int $userId Recipient account identifier.
     * @return void No return value.
     */
    private function consume(int $id, int $userId): void
    {
        $statement = $this->database->prepare(
            'UPDATE dabing_email_invites SET claimed_by_user_id = :user, '
            . 'claimed_at = UTC_TIMESTAMP() WHERE id = :id '
            . 'AND claimed_at IS NULL AND invalidated_at IS NULL'
        );
        $statement->execute(['user' => $userId, 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
    }
}