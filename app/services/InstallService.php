<?php

declare(strict_types=1);

namespace Dabing;

use PDO;
use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/InstallService.php
 * Module Type: Service
 * Purpose: Gate and complete the first-run installation.
 * Responsibilities: Provision schema with one database account and atomically save runtime config.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates installation state, schema setup, and protected config. */
final class InstallService
{
    /** Default MySQL port used when the installer port field is blank.
     * Type: int; Units: TCP port; Scope: one initial database connection;
     * Consumers: AccountController, databaseConfig(); Rationale: use the standard MySQL port consistently.
     */
    public const DEFAULT_DB_PORT = 3306;
    /** Database connection timeout.
     * Type: int; Units: seconds; Scope: installer connection;
     * Consumers: PDO initialization; Rationale: avoid hanging setup requests.
     */
    private const CONNECTION_TIMEOUT = 5;

    private ?PDO $connection = null;
    private readonly string $lockPath;
    private readonly string $configPath;

    /** Create installer from storage and schema directories.
     * @param string $storageDirectory Absolute protected storage directory.
     * @param string $schemaDirectory Absolute database schema directory.
     * @return void No return value.
     */
    public function __construct(
        private readonly string $storageDirectory,
        private readonly string $schemaDirectory
    ) {
        $this->lockPath = $storageDirectory . DIRECTORY_SEPARATOR . 'install.lock';
        $this->configPath = $storageDirectory . DIRECTORY_SEPARATOR . 'config.php';
    }

    /** Determine whether a valid runtime configuration is installed.
     * @return bool True when config and expected schema are available.
     */
    public function isInstalled(): bool
    {
        if (!is_file($this->configPath)) {
            return false;
        }
        $config = $this->readConfig();
        if ($config === null) {
            throw new HttpError(503, 'Konfigurace instalace není platná.');
        }
        try {
            $this->connection = self::connect($config['runtime']);
            (new AccountModel($this->connection))->count();
            return true;
        } catch (Throwable $error) {
            throw new HttpError(503, 'Databáze není dostupná.');
        }
    }

    /** Return an active runtime database connection.
     * @return PDO Active PDO connection.
     * @throws HttpError When installation is incomplete or corrupt.
     */
    public function database(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }
        $config = $this->readConfig();
        if ($config === null) {
            throw new HttpError(503, 'Instalace není dokončena.');
        }
        try {
            $this->connection = self::connect($config['runtime']);
        } catch (Throwable $error) {
            throw new HttpError(503, 'Databáze není dostupná.');
        }
        return $this->connection;
    }

    /** Return the configured public origin for generated links.
     * Legacy installations without this setting return null.
     * @return string|null Normalized public origin or null when unset/unavailable.
     */
    public function publicBaseUrl(): ?string
    {
        $config = $this->readConfig();
        return $config['base_url'] ?? null;
    }

    /** Connect to the installed database with temporary schema-change credentials.
     *
     * The host, port, and database name come only from the protected installed
     * configuration. The supplied username and password are used for this
     * connection only and are never written to configuration or returned.
     *
     * @param string $dbUser Temporary database account with migration privileges.
     * @param string $dbPassword Temporary database password for this request.
     * @return PDO Connected schema-capable database handle.
     * @throws FormError When the installed config or temporary credentials fail.
     */
    public function migrationDatabase(string $dbUser, string $dbPassword): PDO
    {
        $user = trim($dbUser);
        $config = $this->readConfig();
        if ($user === '' || $config === null) {
            throw new FormError('Zadejte platný účet pro změnu schématu databáze.');
        }
        $runtime = $config['runtime'];
        $runtime['user'] = $user;
        $runtime['password'] = $dbPassword;
        try {
            return self::connect($runtime);
        } catch (Throwable $error) {
            throw new FormError('Databázi se nepodařilo otevřít pro aktualizaci schématu.');
        }
    }

    /** Complete first-run setup using one database account under an exclusive lock.
     * @param array{db_host:string,db_port:string,db_name:string,db_user:string,db_password:string,name:string,email:string,password:string} $input Database and initial administrator form fields.
     * @return void No return value.
     * @throws FormError When submitted setup data is invalid.
     * @throws HttpError When setup is already installed or another installation holds the lock.
     * @throws \PDOException When database connection, schema, or account operations fail.
     */
    public function install(array $input): void
    {
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new HttpError(409, 'Instalace již probíhá.');
        }
        try {
            if ($this->isInstalled()) {
                throw new HttpError(409, 'Instalace je již dokončena.');
            }
            $email = mb_strtolower(trim($input['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || strlen($email) > 254) {
                throw new FormError('Zadejte platnou e-mailovou adresu.');
            }
            $name = trim($input['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new FormError('Zadejte jméno do 120 znaků.');
            }
            AuthService::validateNewPassword($input['password']);

            $config = self::databaseConfig($input);
            $runtime = self::connect($config);
            $model = new InstallModel($runtime);
            $model->applySchema($this->schemaDirectory . DIRECTORY_SEPARATOR . 'accounts.sql');
            $model->applySchema($this->schemaDirectory . DIRECTORY_SEPARATOR . 'studio.sql');
            $model->applySchema($this->schemaDirectory . DIRECTORY_SEPARATOR . 'email-invites.sql');
            $migrations = UpdateMigrationService::discover(
                $this->schemaDirectory . DIRECTORY_SEPARATOR . 'migrations'
            );
            (new UpdateMigrationService($runtime))->apply($migrations);
            $account = $model->findAccount($email);
            if ($account === null) {
                $model->createInitialAdmin(
                    $name,
                    $email,
                    password_hash($input['password'], PASSWORD_DEFAULT)
                );
            } elseif (!$account['is_admin']
                || !password_verify($input['password'], $account['password_hash'])) {
                throw new HttpError(409, 'Instalaci nelze bezpečně obnovit.');
            }
            $this->writeConfig(['runtime' => $config]);
            $this->connection = $runtime;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Build the sole runtime/schema connection settings from installer fields.
     * @param array{db_host:string,db_port:string,db_name:string,db_user:string,db_password:string} $input Submitted database settings.
     * @return array{host:string,port:int,name:string,user:string,password:string} Connection settings.
     */
    private static function databaseConfig(array $input): array
    {
        $user = trim($input['db_user']);
        if ($user === '') {
            throw new FormError('Vyplňte úplné přihlašovací údaje databáze.');
        }
        $host = trim($input['db_host']);
        $name = trim($input['db_name']);
        $portInput = trim($input['db_port']);
        if ($portInput === '') {
            $portInput = (string) self::DEFAULT_DB_PORT;
        }
        $port = filter_var($portInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($host === '' || preg_match('/^[A-Za-z0-9.:-]+$/', $host) !== 1
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1
            || $port === false) {
            throw new FormError('Zkontrolujte údaje databáze.');
        }
        return [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $input['db_password'],
        ];
    }

    /** Open a strict UTF-8 MySQL PDO connection.
     * @param array{host:string,port:int,name:string,user:string,password:string} $config Connection settings.
     * @return PDO Connected database.
     */
    private static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], $config['port'], $config['name']
        );
        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
        ]);
    }

    /** Read and validate protected runtime configuration.
     * @return array{runtime:array{host:string,port:int,name:string,user:string,password:string},base_url?:string}|null Config or null when absent/corrupt.
     */
    private function readConfig(): ?array
    {
        if (!is_file($this->configPath)) {
            return null;
        }
        try {
            $config = require $this->configPath;
        } catch (Throwable $error) {
            return null;
        }
        if (!is_array($config) || !isset($config['runtime'])
            || !is_array($config['runtime'])) {
            return null;
        }
        $runtime = $config['runtime'];
        foreach (['host', 'port', 'name', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $runtime)
                || !is_scalar($runtime[$key])) {
                return null;
            }
        }
        if ((int) $runtime['port'] < 1 || (string) $runtime['host'] === '') {
            return null;
        }
        $validated = ['runtime' => [
            'host' => (string) $runtime['host'], 'port' => (int) $runtime['port'],
            'name' => (string) $runtime['name'], 'user' => (string) $runtime['user'],
            'password' => (string) $runtime['password'],
        ]];
        if (array_key_exists('base_url', $config)) {
            if (!is_string($config['base_url'])) {
                return null;
            }
            $baseUrlInput = trim($config['base_url']);
            if ($baseUrlInput !== '') {
                $baseUrl = Http::normalizePublicBaseUrl($baseUrlInput);
                if ($baseUrl === null) {
                    return null;
                }
                $validated['base_url'] = $baseUrl;
            }
        }
        return $validated;
    }

    /** Write runtime config by atomic rename with restrictive file mode.
     * @param array{runtime:array{host:string,port:int,name:string,user:string,password:string},base_url?:string} $config Runtime database settings and optional canonical public origin.
     * @return void No return value.
     */
    private function writeConfig(array $config): void
    {
        if (!is_dir($this->storageDirectory)
            && !mkdir($this->storageDirectory, 0700, true)
            && !is_dir($this->storageDirectory)) {
            throw new HttpError(500, 'Úložiště instalace nelze vytvořit.');
        }
        $payload = "<?php\ndeclare(strict_types=1);\nreturn "
            . var_export($config, true) . ";\n";
        $temporary = $this->configPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new HttpError(500, 'Konfiguraci instalace nelze uložit.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->configPath)) {
            @unlink($temporary);
            throw new HttpError(500, 'Konfiguraci instalace nelze uložit.');
        }
        @chmod($this->configPath, 0600);
    }
}
