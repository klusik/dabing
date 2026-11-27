<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/AuthService.php
 * Module Type: Service
 * Purpose: Apply account authentication and registration policy.
 * Responsibilities: Validate credentials, throttle attempts, and manage sessions.
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

    private readonly AccountModel $accounts;

    /** Create authentication service for the active database.
     * @param PDO $database Active PDO connection.
     * @return void No return value.
     */
    public function __construct(PDO $database)
    {
        $this->accounts = new AccountModel($database);
    }

    /** Return the current session account, if its shape is valid.
     * @return array{id:int,name:string,email:string,is_admin:bool}|null Authenticated account or null.
     */
    public function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['name'], $user['email'])) {
            return null;
        }
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'is_admin' => (bool) ($user['is_admin'] ?? false),
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
        if ($account === null || !password_verify($password, $account['password_hash'])) {
            return null;
        }
        $this->accounts->clearAttempts($key);
        unset($account['password_hash']);
        $_SESSION['user'] = $account;
        session_regenerate_id(true);
        return $this->currentUser();
    }

    /** Register a regular collaborator account.
     * @param string $name Display name.
     * @param string $email Submitted email.
     * @param string $password Submitted password.
     * @param string $passwordConfirm Password confirmation.
     * @return array{id:int,name:string,email:string,is_admin:bool} Created account.
     * @throws FormError When validation or uniqueness fails.
     */
    public function register(string $name, string $email, string $password, string $passwordConfirm): array
    {
        $name = trim($name);
        $email = self::normalizeEmail($email);
        if (!$this->accounts->beginAttempt(self::throttleKey('register', ''))) {
            throw new FormError('Registrace je dočasně omezena. Zkuste to později.');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new FormError('Zadejte jméno do 120 znaků.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254) {
            throw new FormError('Zadejte platnou e-mailovou adresu.');
        }
        if (str_contains($password, "\0") || strlen($password) > 72) {
            throw new FormError('Heslo není v podporovaném formátu.');
        }
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new FormError('Heslo musí mít alespoň 12 znaků.');
        }
        if (!hash_equals($password, $passwordConfirm)) {
            throw new FormError('Hesla se neshodují.');
        }
        if ($this->accounts->findByEmail($email) !== null) {
            throw new FormError('Registraci se nepodařilo dokončit.');
        }
        $admin = false;
        try {
            $id = $this->accounts->create(
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $admin
            );
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new FormError('Registraci se nepodařilo dokončit.');
            }
            throw $error;
        }
        return ['id' => $id, 'name' => $name, 'email' => $email, 'is_admin' => $admin];
    }

    /** Destroy the authenticated session and rotate its identifier.
     * @return void No return value.
     */
    public function logout(): void
    {
        unset($_SESSION['user']);
        session_regenerate_id(true);
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