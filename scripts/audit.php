<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: scripts/audit.php
 * Module Type: CI audit orchestrator
 * Purpose: Run the repository BASIC syntax, JSON, and schema checks.
 * Responsibilities: Inventory tracked sources, invoke native parsers, and verify repeatable DDL.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

use Dabing\InstallModel;

require_once dirname(__DIR__) . '/app/HttpError.php';
require_once dirname(__DIR__) . '/app/models/AccountModel.php';
require_once dirname(__DIR__) . '/app/models/InstallModel.php';

/** Run a command without shell interpolation and return captured output.
 * @param list<string> $command Executable and individual arguments.
 * @return array{status:int,stdout:string,stderr:string} Process result.
 */
function runCommand(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start audit command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [
        'status' => $status,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** Read the NUL-delimited tracked file inventory.
 * @return list<string> Repository-relative paths.
 */
function trackedFiles(): array
{
    $result = runCommand(['git', 'ls-files', '-z']);
    if ($result['status'] !== 0) {
        throw new RuntimeException('Unable to read tracked file inventory.');
    }
    $files = array_values(array_filter(explode("\0", $result['stdout'])));
    sort($files, SORT_STRING);
    return $files;
}

/** Report a failed check and terminate with a failure status.
 * @param string $message Concise safe audit explanation.
 * @return never Does not return.
 */
function fail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

/** Lint every tracked PHP and JavaScript file and parse tracked JSON.
 * @param list<string> $files Tracked repository paths.
 * @return void No return value.
 */
function auditSources(array $files): void
{
    $phpCount = 0;
    $jsCount = 0;
    $jsonCount = 0;
    foreach ($files as $file) {
        $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            $result = runCommand(['php', '-l', $absolute]);
            if ($result['status'] !== 0) {
                fail('PHP syntax failed for ' . $file . ': ' . trim($result['stderr'] . $result['stdout']));
            }
            ++$phpCount;
        } elseif ($extension === 'js') {
            $result = runCommand(['node', '--check', $absolute]);
            if ($result['status'] !== 0) {
                fail('JavaScript syntax failed for ' . $file . ': ' . trim($result['stderr'] . $result['stdout']));
            }
            ++$jsCount;
        } elseif ($extension === 'json') {
            try {
                json_decode((string) file_get_contents($absolute), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                fail('JSON parsing failed for ' . $file . '.');
            }
            ++$jsonCount;
        }
    }
    fwrite(STDOUT, sprintf(
        "PASS source syntax: %d PHP, %d JavaScript; JSON parse: %d files.\n",
        $phpCount,
        $jsCount,
        $jsonCount
    ));
}

/** Apply all actual schema files twice and verify required tables and defaults.
 * @return void No return value.
 */
function auditSchema(): void
{
    $host = getenv('DABING_DB_HOST');
    if (!is_string($host) || $host === '') {
        fwrite(STDOUT, "SKIP MariaDB schema smoke: DABING_DB_HOST is unset.\n");
        return;
    }
    $portText = getenv('DABING_DB_PORT') ?: '3306';
    $name = getenv('DABING_DB_NAME') ?: '';
    $user = getenv('DABING_DB_USER') ?: '';
    $password = getenv('DABING_DB_PASSWORD');
    $port = filter_var($portText, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($name === '' || $user === '' || $port === false
        || !is_string($password)) {
        fail('Database smoke environment is incomplete.');
    }
    $database = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $installer = new InstallModel($database);
    $root = dirname(__DIR__);
    foreach (['accounts.sql', 'studio.sql', 'email-invites.sql'] as $schema) {
        $installer->applySchema($root . '/database/' . $schema);
    }
    foreach (['accounts.sql', 'studio.sql', 'email-invites.sql'] as $schema) {
        $installer->applySchema($root . '/database/' . $schema);
    }

    $expected = [
        'dabing_users', 'dabing_login_attempts', 'dabing_projects',
        'dabing_project_members', 'dabing_project_invites',
        'dabing_project_chapters', 'dabing_script_lines', 'dabing_recordings',
        'dabing_voice_assignments', 'dabing_email_invites',
        'dabing_email_invite_limits',
    ];
    $query = $database->prepare(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = :schema'
    );
    $query->execute(['schema' => $name]);
    $tables = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tables[(string) $row['TABLE_NAME']] = [
            'engine' => (string) $row['ENGINE'],
            'collation' => (string) $row['TABLE_COLLATION'],
        ];
    }
    foreach ($expected as $table) {
        if (!isset($tables[$table])) {
            fail('Schema smoke is missing expected table ' . $table . '.');
        }
        if ($tables[$table]['engine'] !== 'InnoDB'
            || !str_starts_with($tables[$table]['collation'], 'utf8mb4_')) {
            fail('Schema smoke found an unexpected engine or collation for ' . $table . '.');
        }
    }
    $version = (string) $database->query('SELECT VERSION()')->fetchColumn();
    fwrite(STDOUT, sprintf(
        "PASS schema idempotence: applied all schema files twice on %s; verified %d InnoDB utf8mb4 tables.\n",
        $version,
        count($expected)
    ));
}

try {
    $files = trackedFiles();
    auditSources($files);
    auditSchema();
} catch (Throwable $error) {
    fail('Audit could not complete (' . get_class($error) . ').');
}