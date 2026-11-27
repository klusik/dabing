<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/AuthService.php
 * Module Type: Service
 * Purpose: Apply account authentication and session policy.
 * Responsibilities: Validate credentials and manage versioned sessions.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates account policy and the authenticated session. */
final class AuthService
{
    /** Minimum accepted password length.
     * Type: int; Units: Unicode characters; Scope: account credentials;
     * Consumers: registration; Rationale: reject trivial passwords.
     */
    private const PASSWORD_MIN_LENGTH = 12;

    /** Maximum password bytes supported by the default bcrypt hash.
     * Type: int; Units: bytes; Scope: new account password;
     * Consumers: shared password validation; Rationale: avoid truncation.
     */
    private const PASSWORD_MAX_BYTES = 72;

    private readonly AccountModel $accounts;

    /** Create authentication service for the active database.
     * @param PDO $database Active PDO connection.
     * @return void No return value.
     */
    public function __construct(PDO $database)
    {
        $this->accounts = new AccountModel($database);
    }

    /** Return the current account after active, session-version and fingerprint checks.
     * Legacy sessions without a version use 0 only when their password fingerprint is valid.
     * @return array{id:int,name:string,email:string,is_admin:bool}|null Authenticated account or null.
     */
    public function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        $fingerprint = $_SESSION['auth_password_fingerprint'] ?? null;
        if (!is_array($user)) {
            unset(
                $_SESSION['auth_password_fingerprint'],
                $_SESSION['auth_session_version']
            );
            return null;
        }
        $sessionVersion = $_SESSION['auth_session_version'] ?? 0;
        if (!isset($user['id'], $user['name'], $user['email'])
            || !is_string($fingerprint)) {
            self::invalidateSessionAuthentication();
            return null;
        }
        $account = $this->accounts->findById((int) $user['id']);
        if ($account === null || !$account['is_active']
            || !is_int($sessionVersion)
            || $sessionVersion !== $account['session_version']
            || !hash_equals(
            $fingerprint,
            self::passwordFingerprint($account['password_hash'])
        )) {
            self::invalidateSessionAuthentication();
            return null;
        }
        return [
            'id' => $account['id'],
            'name' => $account['name'],
            'email' => $account['email'],
            'is_admin' => $account['is_admin'],
        ];
    }
    /** Authenticate credentials using a generic failure response.
     * @param string $email Submitted email.
     * @param string $password Submitted password.
     * @return array{id:int,name:string,email:string,is_admin:bool}|null Account on success, null on failure.
     */
    public function authenticate(string $email, string $password): ?array
    {
        $normalized = self::normalizeEmail($email);
        $key = self::throttleKey('login', $normalized);
        if (!$this->accounts->beginAttempt($key)) {
            return null;
        }
        $account = $this->accounts->findByEmail($normalized);
        if ($account === null || !$account['is_active']
            || !password_verify($password, $account['password_hash'])) {
            return null;
        }
        $this->accounts->clearAttempts($key);
        $_SESSION['user'] = [
            'id' => $account['id'], 'name' => $account['name'],
            'email' => $account['email'], 'is_admin' => $account['is_admin'],
        ];
        $_SESSION['auth_password_fingerprint'] = self::passwordFingerprint(
            $account['password_hash']
        );
        $_SESSION['auth_session_version'] = $account['session_version'];
        session_regenerate_id(true);
        return $this->currentUser();
    }

    /** Authenticate an account by identifier after an atomic invite claim.
     * @param int $id Account primary key.
     * @return array{id:int,name:string,email:string,is_admin:bool}|null Active account or null.
     */
    public function loginById(int $id): ?array
    {
        $account = $this->accounts->findById($id);
        if ($account === null || !$account['is_active']) {
            return null;
        }
        $_SESSION['user'] = [
            'id' => $account['id'], 'name' => $account['name'],
            'email' => $account['email'], 'is_admin' => $account['is_admin'],
        ];
        $_SESSION['auth_password_fingerprint'] = self::passwordFingerprint(
            $account['password_hash']
        );
        $_SESSION['auth_session_version'] = $account['session_version'];
        session_regenerate_id(true);
        return $this->currentUser();
    }

    /** Enforce the shared policy for newly chosen passwords.
     * @param string $password Candidate new password.
     * @param string $label Fixed field label for safe validation feedback.
     * @return void No return value.
     * @throws FormError When the password has unsupported bytes or is too short.
     */
    public static function validateNewPassword(
        string $password,
        string $label = 'Heslo'
    ): void {
        if (str_contains($password, "\0") || strlen($password) > self::PASSWORD_MAX_BYTES) {
            throw new FormError($label . ' není v podporovaném formátu.');
        }
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new FormError($label . ' musí mít alespoň 12 znaků.');
        }
    }
    /** Change the authenticated account password and invalidate other sessions.
     * @param string $currentPassword Current password, which may be legacy-weak.
     * @param string $password New password to validate and hash.
     * @param string $passwordConfirm Confirmation of the new password.
     * @return void No return value.
     * @throws FormError When current credentials or new password are invalid.
     * @throws HttpError When authentication changed concurrently.
     */
    public function changePassword(
        string $currentPassword,
        string $password,
        string $passwordConfirm
    ): void {
        $user = $this->currentUser();
        if ($user === null) {
            throw new HttpError(401, 'Přihlášení vypršelo.');
        }
        $account = $this->accounts->findById($user['id']);
        if ($account === null
            || !password_verify($currentPassword, $account['password_hash'])) {
            throw new FormError('Současné heslo není správné.');
        }
        self::validateNewPassword($password, 'Nové heslo');
        if (!hash_equals($password, $passwordConfirm)) {
            throw new FormError('Nová hesla se neshodují.');
        }
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if (!$this->accounts->updatePasswordHash(
            $user['id'],
            $account['password_hash'],
            $newHash
        )) {
            self::invalidateSessionAuthentication();
            throw new HttpError(409, 'Heslo se mezitím změnilo. Přihlaste se znovu.');
        }
        $_SESSION['auth_password_fingerprint'] = self::passwordFingerprint($newHash);
        session_regenerate_id(true);
    }
    /** Destroy the authenticated session and rotate its identifier.
     * @return void No return value.
     */
    public function logout(): void
    {
        unset(
            $_SESSION['user'], $_SESSION['auth_password_fingerprint'],
            $_SESSION['auth_session_version']
        );
        session_regenerate_id(true);
    }

    /** Fingerprint a stored password hash for session invalidation.
     * @param string $passwordHash Stored password hash.
     * @return string Stable SHA-256 fingerprint.
     */
    private static function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /** Invalidate authentication while preserving pending invite state.
     * @return void No return value.
     */
    private static function invalidateSessionAuthentication(): void
    {
        unset(
            $_SESSION['user'], $_SESSION['auth_password_fingerprint'],
            $_SESSION['auth_session_version']
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    /** Normalize email for consistent lookup and throttle keys.
     * @param string $email Submitted address.
     * @return string Trimmed lowercase address.
     */
    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Create a non-reversible throttle key from email and remote address.
     * @param string $scope Route identifier for throttle separation.
     * @param string $email Normalized submitted address.
     * @return string SHA-256 key.
     */
    private static function throttleKey(string $scope, string $email): string
    {
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', $scope . "\0" . $email . "\0" . $address);
    }
}
