<?php
declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: scripts/migrate_recording_attempts.php
 * Module Type: CLI migration entry point
 * Purpose: Run the versioned recording-attempt migration with a separate DDL account.
 * Responsibilities: Read connection coordinates from protected config and password from stdin.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

use Dabing\RecordingAttemptMigration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/services/TakeNaming.php';
require_once dirname(__DIR__) . '/app/models/RecordingAttemptMigration.php';
$configPath = dirname(__DIR__) . '/storage/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Dabing installation config was not found.\n");
    exit(1);
}
$config = require $configPath;
$runtime = is_array($config) ? ($config['runtime'] ?? null) : null;
$ddlUser = $argv[1] ?? '';
if (!is_array($runtime) || !is_string($ddlUser) || trim($ddlUser) === '') {
    fwrite(STDERR, "Usage: php scripts/migrate_recording_attempts.php DDL_USER\n");
    exit(2);
}
foreach (['host', 'port', 'name'] as $field) {
    if (!isset($runtime[$field]) || !is_scalar($runtime[$field])) {
        fwrite(STDERR, "The protected runtime config is incomplete.\n");
        exit(2);
    }
}
fwrite(STDERR, "Enter the DDL account password on stdin: ");
$passwordInput = fgets(STDIN);
if (!is_string($passwordInput)) {
    fwrite(STDERR, "No password was received on stdin.\n");
    exit(2);
}
$password = rtrim($passwordInput, "\r\n");
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string) $runtime['host'],
    (int) $runtime['port'],
    (string) $runtime['name']
);
try {
    $database = new PDO($dsn, trim($ddlUser), $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new RecordingAttemptMigration($database))->run();
    fwrite(STDOUT, "Recording-attempt migration 001 completed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Recording-attempt migration failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    unset($password, $passwordInput, $database);
}