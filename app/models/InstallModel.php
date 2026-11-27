<?php

declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/models/InstallModel.php
 * Module Type: Model
 * Purpose: Create the database schema and initial administrator.
 * Responsibilities: Apply SQL schema files and account bootstrap transaction.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns database schema creation and initial account persistence. */
final class InstallModel
{
    /** Create model on the installer database connection.
     * @param PDO $database Database connection with DDL privileges.
     * @return void No return value.
     */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Apply one trusted schema file, with resumable statements.
     * @param string $schemaPath Absolute path to a repository SQL file.
     * @return void No return value.
     * @throws HttpError When the schema file cannot be read.
     */
    public function applySchema(string $schemaPath): void
    {
        $sql = file_get_contents($schemaPath);
        if (!is_string($sql)) {
            throw new HttpError(500, 'Schéma databáze není dostupné.');
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->database->exec($statement);
        }
    }

    /** Find the intended initial administrator by normalized email.
     * @param string $email Normalized account email.
     * @return array{id:int,name:string,email:string,password_hash:string,is_admin:bool}|null Account row or null.
     */
    public function findAccount(string $email): ?array
    {
        return (new AccountModel($this->database))->findByEmail($email);
    }

    /** Create the initial administrator unless it already exists.
     * @param string $name Display name.
     * @param string $email Normalized email address.
     * @param string $passwordHash Password hash.
     * @return void No return value.
     */
    public function createInitialAdmin(string $name, string $email, string $passwordHash): void
    {
        $accounts = new AccountModel($this->database);
        if ($accounts->count() > 0) {
            $existing = $accounts->findByEmail($email);
            if ($existing === null || !$existing['is_admin']) {
                throw new HttpError(409, 'Instalaci nelze bezpečně obnovit.');
            }
            return;
        }
        $accounts->create($name, $email, $passwordHash, true);
    }
}