<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/AccountModel.php
 * Module Type: Model
 * Purpose: Persist account records and login throttle state.
 * Responsibilities: Execute prepared account and throttle queries.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns account SQL and database row mapping. */
final class AccountModel
{
    /** Maximum failures in a rolling window before temporary blocking.
     * Type: int; Units: attempts; Scope: one normalized address and IP;
     * Consumers: authentication checks; Rationale: slow repeated guessing.
     */
    private const MAX_ATTEMPTS = 8;
    /** Throttle window length.
     * Type: int; Units: seconds; Scope: one throttle key;
     * Consumers: authentication checks; Rationale: bound retry volume.
     */
    private const WINDOW_SECONDS = 900;
    /** Temporary block duration.
     * Type: int; Units: seconds; Scope: one throttle key;
     * Consumers: authentication checks; Rationale: pause automated retries.
     */
    private const BLOCK_SECONDS = 900;

    /** Create the model over the active application database.
     * @param PDO $database Active PDO connection.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Find one account by normalized email.
     * @param string $email Lowercase email address.
     * @return array{id:int,name:string,email:string,password_hash:string,is_admin:bool,is_active:bool,session_version:int}|null Account row or null.
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, password_hash, is_admin, is_active, session_version '
            . 'FROM dabing_users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'is_admin' => (bool) $row['is_admin'],
            'is_active' => (bool) $row['is_active'],
            'session_version' => (int) $row['session_version'],
        ];
    }

    /** Find one account by numeric identifier.
     * @param int $id Account primary key.
     * @return array{id:int,name:string,email:string,password_hash:string,is_admin:bool,is_active:bool,session_version:int}|null Account row or null.
     */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, password_hash, is_admin, is_active, session_version '
            . 'FROM dabing_users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'is_admin' => (bool) $row['is_admin'],
            'is_active' => (bool) $row['is_active'],
            'session_version' => (int) $row['session_version'],
        ];
    }

    /** Replace an account password only when its previous hash still matches.
     * @param int $id Account primary key.
     * @param string $expectedHash Previously observed password hash.
     * @param string $replacementHash Newly generated password hash.
     * @return bool True when exactly this current hash was replaced.
     */
    public function updatePasswordHash(
        int $id,
        string $expectedHash,
        string $replacementHash
    ): bool {
        $statement = $this->database->prepare(
            'UPDATE dabing_users SET password_hash = :replacement '
            . 'WHERE id = :id AND CAST(password_hash AS BINARY) '
            . '= CAST(:expected AS BINARY)'
        );
        $statement->execute([
            'replacement' => $replacementHash,
            'id' => $id,
            'expected' => $expectedHash,
        ]);
        return $statement->rowCount() === 1;
    }
    /** Insert a new account and return its generated ID.
     * @param string $name Display name.
     * @param string $email Normalized email address.
     * @param string $passwordHash Password hash from password_hash().
     * @param bool $isAdmin Whether this is the initial administrator.
     * @return int Newly created account ID.
     */
    public function create(string $name, string $email, string $passwordHash, bool $isAdmin): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO dabing_users (name, email, password_hash, is_admin) '
            . 'VALUES (:name, :email, :hash, :admin)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'hash' => $passwordHash,
            'admin' => $isAdmin ? 1 : 0,
        ]);
        return (int) $this->database->lastInsertId();
    }

    /** Count existing accounts to identify initial administrator creation.
     * @return int Number of stored accounts.
     */
    public function count(): int
    {
        return (int) $this->database->query(
            'SELECT COUNT(*) FROM dabing_users'
        )->fetchColumn();
    }

    /** Atomically count a throttle attempt before credential verification.
     * @param string $key Stable route and client throttle key.
     * @return bool True when the attempt remains within the configured limit.
     */
    public function beginAttempt(string $key): bool
    {
        $this->database->beginTransaction();
        try {
            $insert = $this->database->prepare(
                'INSERT IGNORE INTO dabing_login_attempts '
                . '(attempt_key, attempts, window_started_at) '
                . 'VALUES (:key, 0, UTC_TIMESTAMP())'
            );
            $insert->execute(['key' => $key]);
            $query = $this->database->prepare(
                'SELECT attempts, window_started_at FROM dabing_login_attempts '
                . 'WHERE attempt_key = :key FOR UPDATE'
            );
            $query->execute(['key' => $key]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $expired = !is_array($row)
                || strtotime((string) $row['window_started_at'] . ' UTC')
                    < time() - self::WINDOW_SECONDS;
            $attempts = $expired ? 1 : (int) $row['attempts'] + 1;
            $started = $expired ? gmdate('Y-m-d H:i:s')
                : (string) $row['window_started_at'];
            $blocked = $attempts >= self::MAX_ATTEMPTS
                ? gmdate('Y-m-d H:i:s', time() + self::BLOCK_SECONDS) : null;
            $update = $this->database->prepare(
                'UPDATE dabing_login_attempts SET attempts = :attempts, '
                . 'window_started_at = :started, blocked_until = :blocked '
                . 'WHERE attempt_key = :key'
            );
            $update->execute([
                'attempts' => $attempts, 'started' => $started,
                'blocked' => $blocked, 'key' => $key,
            ]);
            $this->database->commit();
            return $attempts <= self::MAX_ATTEMPTS;
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }
    /** Clear failed attempts after successful authentication.
     * @param string $key Hashed normalized email and client address.
     * @return void No return value.
     */
    public function clearAttempts(string $key): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM dabing_login_attempts WHERE attempt_key = :key'
        );
        $statement->execute(['key' => $key]);
    }
}
