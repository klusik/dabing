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
 * Responsibilities: Validate setup key, provision schema, and atomically save runtime config.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Coordinates installation state, schema setup, and protected config. */
final class InstallService
{
    /** Minimum acceptable setup-key length.
     * Type: int; Units: characters; Scope: one installation;
     * Consumers: setup key validation; Rationale: require operator-generated entropy.
     */
    private const SETUP_KEY_MIN_LENGTH = 24;
    /** Database connection timeout.
     * Type: int; Units: seconds; Scope: installer connection;
     * Consumers: PDO initialization; Rationale: avoid hanging setup requests.
     */
    private const CONNECTION_TIMEOUT = 5;

    private ?PDO $connection = null;
    private readonly string $installKeyPath;
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
        $this->installKeyPath = $storageDirectory . DIRECTORY_SEPARATOR . 'install.key';
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

    /** Report whether the operator setup key is present and usable.
     * @return bool True when the key file contains at least 24 characters.
     */
    public function keyReady(): bool
    {
        $key = @file_get_contents($this->installKeyPath);
        return is_string($key) && strlen(trim($key)) >= self::SETUP_KEY_MIN_LENGTH;
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

    /** Complete first-run setup under an exclusive lock.
     * @param array{install_key:string,db_host:string,db_port:string,db_name:string,db_user:string,db_password:string,setup_user:string,setup_password:string,name:string,email:string,password:string,password_confirm:string} $input Validated form fields.
     * @return void No return value.
     * @throws FormError When submitted setup data is invalid.
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
            $key = @file_get_contents($this->installKeyPath);
            if (!is_string($key) || strlen(trim($key)) < self::SETUP_KEY_MIN_LENGTH
                || !hash_equals(trim($key), trim($input['install_key']))) {
                throw new FormError('Instalační klíč není správný.');
            }
            $config = self::databaseConfig($input, 'runtime');
            $runtime = self::connect($config);
            $setupUser = trim($input['setup_user']);
            $setupPassword = $input['setup_password'];
            $hasSetup = $setupUser !== '' || $setupPassword !== '';
            if ($hasSetup && ($setupUser === '' || $setupPassword === '')) {
                throw new FormError('Vyplňte oba údaje setup databáze, nebo ponechte oba prázdné.');
            }
            $ddl = $hasSetup
                ? self::connect(self::databaseConfig($input, 'setup')) : $runtime;
            $model = new InstallModel($ddl);
            $model->applySchema($this->schemaDirectory . DIRECTORY_SEPARATOR . 'accounts.sql');
            $model->applySchema($this->schemaDirectory . DIRECTORY_SEPARATOR . 'studio.sql');
            $email = mb_strtolower(trim($input['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || strlen($email) > 254) {
                throw new FormError('Zadejte platnou e-mailovou adresu.');
            }
            if (trim($input['name']) === '' || mb_strlen(trim($input['name'])) > 120) {
                throw new FormError('Zadejte jméno do 120 znaků.');
            }
            if (str_contains($input['password'], "\0")
                || strlen($input['password']) > 72
                || mb_strlen($input['password']) < 12
                || !hash_equals($input['password'], $input['password_confirm'])) {
                throw new FormError('Heslo musí mít alespoň 12 znaků a potvrzení se musí shodovat.');
            }
            $accountModel = new InstallModel($runtime);
            $account = $accountModel->findAccount($email);
            if ($account === null) {
                $accountModel->createInitialAdmin(
                    trim($input['name']),
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

    /** Build connection settings from submitted values.
     * @param array<string,string> $input Installer form values.
     * @param string $prefix Credential set name, runtime or setup.
     * @return array{host:string,port:int,name:string,user:string,password:string} Connection settings.
     */
    private static function databaseConfig(array $input, string $prefix): array
    {
        $user = $prefix === 'setup' ? trim($input['setup_user']) : trim($input['db_user']);
        $password = $prefix === 'setup' ? $input['setup_password'] : $input['db_password'];
        if ($user === '' || ($prefix === 'setup' && $password === '')) {
            throw new FormError('Vyplňte úplné přihlašovací údaje databáze.');
        }
        $host = trim($input['db_host']);
        $name = trim($input['db_name']);
        $port = filter_var($input['db_port'], FILTER_VALIDATE_INT);
        if ($host === '' || preg_match('/^[A-Za-z0-9.:-]+$/', $host) !== 1
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1
            || $port === false || $port < 1 || $port > 65535) {
            throw new FormError('Zkontrolujte údaje databáze.');
        }
        return ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password];
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
     * @return array{runtime:array{host:string,port:int,name:string,user:string,password:string}}|null Config or null when absent/corrupt.
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
        return ['runtime' => [
            'host' => (string) $runtime['host'], 'port' => (int) $runtime['port'],
            'name' => (string) $runtime['name'], 'user' => (string) $runtime['user'],
            'password' => (string) $runtime['password'],
        ]];
    }

    /** Write runtime config by atomic rename with restrictive file mode.
     * @param array{runtime:array{host:string,port:int,name:string,user:string,password:string}} $config Runtime-only configuration.
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