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
use Dabing\StudioModel;
use Dabing\UpdateMigrationService;

require_once dirname(__DIR__) . '/app/HttpError.php';
require_once dirname(__DIR__) . '/app/models/AccountModel.php';
require_once dirname(__DIR__) . '/app/models/InstallModel.php';
require_once dirname(__DIR__) . '/app/models/StudioModel.php';
require_once dirname(__DIR__) . '/app/models/UpdateMigrationModel.php';
require_once dirname(__DIR__) . '/app/services/UpdateMigrationService.php';

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

/** Exercise native PHP mail() against an isolated sendmail capture command.
 * @return void No return value.
 */
function auditMailTransport(): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        fwrite(STDOUT,
            "SKIP native mail capture: Windows PHP uses a different mail transport.\n"
        );
        return;
    }

    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'dabing-mail-audit-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Mail audit directory could not be created.');
    }
    $capturePath = $directory . DIRECTORY_SEPARATOR . 'messages.txt';
    $captureCommand = $directory . DIRECTORY_SEPARATOR . 'capture.sh';
    $configuredStorage = $directory . DIRECTORY_SEPARATOR . 'configured';
    $stage = 'prepare isolated capture';
    $safeDetail = null;
    try {
        if (!mkdir($configuredStorage, 0700)) {
            throw new RuntimeException('Configured mail fixture directory failed.');
        }
        $captureScript = "#!/bin/sh\n"
            . 'printf "\\n--DABING-ARGS-BEGIN--\\n" >> '
            . escapeshellarg($capturePath) . "\n"
            . 'printf "<%s>\\n" "$@" >> '
            . escapeshellarg($capturePath) . "\n"
            . 'printf "%s\\n" "--DABING-MESSAGE-BEGIN--" >> '
            . escapeshellarg($capturePath) . "\n"
            . 'cat >> ' . escapeshellarg($capturePath) . "\n"
            . 'printf "\\n--DABING-END--\\n" >> '
            . escapeshellarg($capturePath) . "\n";
        if (file_put_contents($captureCommand, $captureScript) === false) {
            throw new RuntimeException('Mail capture command could not be written.');
        }
        chmod($captureCommand, 0700);

        $root = dirname(__DIR__);
        $configuredFrom = 'mailer@transport.test';
        $mailConfig = '<?php return ' . var_export([
            'from' => $configuredFrom,
        ], true) . ';' . PHP_EOL;
        if (file_put_contents(
            $configuredStorage . DIRECTORY_SEPARATOR . 'mail.php',
            $mailConfig
        ) === false) {
            throw new RuntimeException('Configured mail fixture failed.');
        }
        $sender = 'admin+tag@example.test';
        $externalAdmin = 'manager+reply@external.test';
        $childCode = 'require ' . var_export($root . '/app/FormError.php', true) . ';'
            . 'require ' . var_export($root . '/app/Http.php', true) . ';'
            . 'require ' . var_export($root . '/app/services/InviteMailer.php', true) . ';'
            . '$_SERVER["HTTP_HOST"] = "books.example.test:8443";'
            . '$_SERVER["REQUEST_URI"] = "/subdir/index.php";'
            . '$defaultStorage = ' . var_export($directory, true) . ';'
            . '$configuredStorage = ' . var_export($configuredStorage, true) . ';'
            . 'if (file_exists($defaultStorage . "/mail.php")) { exit(21); }'
            . '$mailer = new \\Dabing\\InviteMailer($defaultStorage);'
            . '$adminSender = ' . var_export($sender, true) . ';'
            . '$externalAdmin = ' . var_export($externalAdmin, true) . ';'
            . 'if (!$mailer->sendInvite("invite@dabing.test",'
            . '"0123456789abcdef0123456789abcdef", ["Rook"],'
            . '"28. 11. 2026 12:00 Europe/Prague", $adminSender)) { exit(22); }'
            . 'if (!$mailer->sendRoleAssignmentNotice("voice@dabing.test",'
            . '73, "Test book", ["Rook", "Guard"], $adminSender)) { exit(23); }'
            . 'if (!$mailer->sendInvite("external@dabing.test",'
            . '"11223344556677889900aabbccddeeff", [],'
            . '"28. 11. 2026 12:00 Europe/Prague", $externalAdmin)) { exit(24); }'
            . '$configuredMailer = new \\Dabing\\InviteMailer($configuredStorage);'
            . 'if (!$configuredMailer->sendRoleAssignmentNotice("configured@dabing.test",'
            . '74, "Configured book", ["Pilot"], $externalAdmin)) { exit(25); }'
            . '$invalidAdmin = "admin" . chr(10) . "@books.example.test";'
            . 'if (!$mailer->sendInvite("invalid-admin@dabing.test",'
            . '"ffeeddccbbaa00998877665544332211", [],'
            . '"28. 11. 2026 12:00 Europe/Prague", $invalidAdmin)) { exit(26); }';
        $sendmailPath = '/bin/sh ' . escapeshellarg($captureCommand) . ' -t -i';
        $stage = 'run PHP mail fixtures';
        $result = runCommand([
            PHP_BINARY,
            '-n',
            '-d',
            'sendmail_path=' . $sendmailPath,
            '-r',
            $childCode,
        ]);
        if ($result['status'] !== 0) {
            $stage = 'run PHP mail fixtures (exit code '
                . $result['status'] . ')';
            throw new RuntimeException('PHP fixture subprocess failed.');
        }
        $stage = 'read sendmail capture';
        $capture = file_get_contents($capturePath);
        if (!is_string($capture)) {
            throw new RuntimeException('Native mail capture was not created.');
        }
        $stage = 'parse captured messages';
        $matchCount = preg_match_all(
            '/--DABING-ARGS-BEGIN--\r?\n((?:<[^>\r\n]*>\r?\n)*)'
            . '--DABING-MESSAGE-BEGIN--\r?\n(.*?)\r?\n--DABING-END--/s',
            $capture,
            $matches,
            PREG_SET_ORDER
        );
        if ($matchCount !== 5) {
            throw new RuntimeException('Native mail capture did not contain five messages.');
        }
        $stage = 'validate sender and message assertions';
        $headersByMessage = [];
        $messages = [];
        $argumentsByMessage = [];
        foreach ($matches as $match) {
            $rawArguments = (string) $match[1];
            preg_match_all('/^<([^>\r\n]*)>$/m', $rawArguments, $argumentMatches);
            $argumentsByMessage[] = array_values($argumentMatches[1]);
            $rawMessage = (string) $match[2];
            $messageParts = preg_split('/\r?\n\r?\n/', $rawMessage, 2);
            if (!is_array($messageParts) || count($messageParts) !== 2) {
                throw new RuntimeException('Captured message headers were incomplete.');
            }
            $headersByMessage[] = $messageParts[0];
            $messages[] = quoted_printable_decode($messageParts[1]);
        }
        $headerValue = static function (string $message, string $header): ?string {
            if (preg_match('/^' . preg_quote($header, '/')
                . ':\\s*([^\\r\\n]*)/mi', $message, $headerMatch) !== 1) {
                return null;
            }
            return trim((string) $headerMatch[1]);
        };
        $displayMailbox = static function (?string $mailbox) use (
            $sender,
            $externalAdmin,
            $configuredFrom
        ): string {
            if ($mailbox === null) {
                return '<absent>';
            }
            return in_array(
                $mailbox,
                [$sender, $externalAdmin, $configuredFrom],
                true
            ) ? $mailbox : '<other>';
        };
        $displayArguments = static function (array $arguments) use (
            $sender,
            $externalAdmin,
            $configuredFrom
        ): string {
            $safeArguments = array_map(
                static function (string $argument) use (
                    $sender,
                    $externalAdmin,
                    $configuredFrom
                ): string {
                    return in_array(
                        $argument,
                        ['-t', '-i', '-f', $sender, $externalAdmin, $configuredFrom],
                        true
                    ) ? $argument : '<other>';
                },
                $arguments
            );
            return '[' . implode(' ', $safeArguments) . ']';
        };
        $expectedFrom = [
            $sender,
            $sender,
            null,
            $configuredFrom,
            null,
        ];
        $expectedReplyTo = [
            null,
            null,
            $externalAdmin,
            $externalAdmin,
            null,
        ];
        $expectedEnvelope = [
            $sender,
            $sender,
            null,
            $configuredFrom,
            null,
        ];
        foreach ($messages as $index => $message) {
            $stage = 'validate message ' . ($index + 1) . ' sender and arguments';
            $headers = $headersByMessage[$index];
            $from = $headerValue($headers, 'From');
            $replyTo = $headerValue($headers, 'Reply-To');
            $expectedArguments = $expectedEnvelope[$index] === null
                ? ['-t', '-i']
                : ['-t', '-i', '-f', $expectedEnvelope[$index]];
            if ($from !== $expectedFrom[$index]) {
                $safeDetail = 'From mismatch: expected '
                    . $displayMailbox($expectedFrom[$index]) . ', got '
                    . $displayMailbox($from) . '.';
                throw new RuntimeException(
                    'Mail From assertion failed.'
                );
            }
            if ($replyTo !== $expectedReplyTo[$index]) {
                $safeDetail = 'Reply-To mismatch: expected '
                    . $displayMailbox($expectedReplyTo[$index]) . ', got '
                    . $displayMailbox($replyTo) . '.';
                throw new RuntimeException(
                    'Mail Reply-To assertion failed.'
                );
            }
            if ($argumentsByMessage[$index] !== $expectedArguments) {
                $safeDetail = 'sendmail argv mismatch: expected '
                    . $displayArguments($expectedArguments) . ', got '
                    . $displayArguments($argumentsByMessage[$index]) . '.';
                throw new RuntimeException(
                    'Mail envelope assertion failed.'
                );
            }
            if (!str_contains($headers, 'multipart/alternative')
                || !str_contains(
                    $message,
                    'http://books.example.test:8443/subdir/?route='
                )
                || $headerValue($headers, 'To') !== [
                    'invite@dabing.test',
                    'voice@dabing.test',
                    'external@dabing.test',
                    'configured@dabing.test',
                    'invalid-admin@dabing.test',
                ][$index]) {
                throw new RuntimeException(
                    'Captured recipient, MIME type, or public base URL was incorrect.'
                );
            }
        }
        $expectedContent = [
            ['route=email-join', '0123456789abcdef0123456789abcdef'],
            ['route=project&id=73', 'Rook', 'Guard'],
            ['route=email-join'],
            ['route=project&id=74', 'Pilot'],
            [],
        ];
        foreach ($expectedContent as $index => $needles) {
            $stage = 'validate message ' . ($index + 1) . ' route and content';
            foreach ($needles as $needle) {
                if (!str_contains($messages[$index], $needle)) {
                    throw new RuntimeException('Captured message content mismatch.');
                }
            }
        }
        fwrite(STDOUT,
            "PASS native mail fallback: default, external, configured, and invalid "
            . "admin sender cases preserve MIME and routing.\n"
        );
    } catch (Throwable $error) {
        throw new RuntimeException(
            'Native mail audit failed during ' . $stage . ' ('
            . get_class($error) . ').'
            . ($safeDetail === null ? '' : ' ' . $safeDetail),
            0,
            $error
        );
    } finally {
        foreach ([
            $capturePath,
            $captureCommand,
            $configuredStorage . DIRECTORY_SEPARATOR . 'mail.php',
        ] as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($configuredStorage) && !is_link($configuredStorage)) {
            rmdir($configuredStorage);
        }
        if (is_dir($directory) && !is_link($directory)) {
            rmdir($directory);
        }
    }
}

/** Upgrade the published 0.1.1 schema in an isolated disposable database.
 * @param string $host MariaDB service hostname.
 * @param int $port MariaDB service port.
 * @param string $rootPassword Disposable CI root-account password.
 * @return void No return value.
 */
function auditLegacyUpgrade(string $host, int $port, string $rootPassword): void
{
    $databaseName = 'dabing_upgrade_' . bin2hex(random_bytes(8));
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        'root',
        $rootPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $admin->exec('CREATE DATABASE `' . $databaseName . '` '
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $database = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $host,
                $port,
                $databaseName
            ),
            'root',
            $rootPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // This immutable commit is the published 0.1.1 schema baseline.
        $baseline = '0c82576b77e8df0f09a4a3e7cca02dcd4a93b315';
        foreach (['accounts.sql', 'studio.sql', 'email-invites.sql'] as $schema) {
            $source = runCommand([
                'git', 'show', $baseline . ':database/' . $schema,
            ]);
            if ($source['status'] !== 0 || trim($source['stdout']) === '') {
                throw new RuntimeException('The published legacy schema is unavailable.');
            }
            foreach (array_filter(array_map('trim', explode(';', $source['stdout']))) as $statement) {
                $database->exec($statement);
            }
        }

        $database->exec(
            "INSERT INTO dabing_users (id,name,email,password_hash,is_admin) VALUES "
            . "(1,'Owner','owner@example.test','fixture',1), "
            . "(2,'Voice','voice@example.test','fixture',0)"
        );
        $database->exec(
            "INSERT INTO dabing_projects (id,owner_user_id,title) "
            . "VALUES (42,1,'Legacy book')"
        );
        $database->exec(
            'INSERT INTO dabing_project_members (project_id,user_id) '
            . 'VALUES (42,1),(42,2)'
        );
        $database->exec(
            "INSERT INTO dabing_project_chapters (id,project_id,position,title) "
            . "VALUES (51,42,1,'Chapter one')"
        );
        $database->exec(
            "INSERT INTO dabing_script_lines (id,chapter_id,position,character_name,text) "
            . "VALUES (61,51,1,'Rook','Keep the gate.'), "
            . "(62,51,2,'Scout','I see the road.')"
        );
        $database->exec(
            "INSERT INTO dabing_recordings "
            . '(id,line_id,user_id,storage_key,media_type,byte_size,attempt_no) '
            . "VALUES (77,61,1,REPEAT('a',64),'audio/webm',1234,1), "
            . "(78,61,1,REPEAT('c',64),'audio/webm',1234,2)"
        );
        $database->exec(
            'UPDATE dabing_script_lines SET selected_recording_id = 77 WHERE id = 61'
        );
        $database->exec(
            "INSERT INTO dabing_voice_assignments (project_id,character_name,user_id) "
            . "VALUES (42,'Rook',1)"
        );
        $database->exec(
            "INSERT INTO dabing_email_invites "
            . '(id,project_id,invited_email,code_hash,created_by_user_id,'
            . 'expires_at,delivered_at) VALUES '
            . "(88,42,'new-voice@example.test',REPEAT('b',64),1,"
            . 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY),'
            . 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY))'
        );

        $migrations = UpdateMigrationService::discover(
            dirname(__DIR__) . '/database/migrations'
        );
        $migrationService = new UpdateMigrationService($database);
        $assert($migrationService->apply($migrations) === count($migrations),
            'Legacy schema did not apply all staged migrations.');
        $assert($migrationService->apply($migrations) === 0,
            'Legacy migration could not be retried after ledger commit.');
        $database->exec(
            "INSERT INTO dabing_voice_assignments (project_id,character_name,user_id) "
            . "VALUES (42,'Rook',2)"
        );

        $assert((int) $database->query(
            'SELECT COUNT(*) FROM dabing_voice_assignments '
            . "WHERE project_id=42 AND character_name='Rook'"
        )->fetchColumn() === 2, 'Legacy role assignment rows were not preserved.');
        $assert((int) $database->query(
            'SELECT selected_recording_id FROM dabing_script_lines WHERE id=61'
        )->fetchColumn() === 77, 'Selected recording ID changed during upgrade.');
        $assert((string) $database->query(
            'SELECT storage_key FROM dabing_recordings WHERE id=77'
        )->fetchColumn() === str_repeat('a', 64),
            'Legacy recording row or storage key was not preserved.');
        $inviteExpiry = (string) $database->query(
            'SELECT expires_at FROM dabing_email_invites WHERE id=88'
        )->fetchColumn();
        $assert(strtotime($inviteExpiry . ' UTC') <= time(),
            'A legacy delivered invite exceeded its 24-hour lifetime.');

        $studio = new StudioModel($database);
        $initialRevision = $studio->projectRevision(42, 2);
        $assert(is_string($initialRevision),
            'Assigned collaborator could not read the project revision.');
        $assert($studio->setTakeHidden(42, 61, 78, 1, true),
            'Take uploader could not hide an unselected take.');
        $hiddenRevision = $studio->projectRevision(42, 2);
        $assert(is_string($hiddenRevision) && $hiddenRevision !== $initialRevision,
            'Effective take visibility change did not advance project revision.');
        $assert($studio->setTakeHidden(42, 61, 78, 1, true),
            'Idempotent hide retry was not accepted.');
        $assert($studio->projectRevision(42, 2) === $hiddenRevision,
            'Idempotent hide retry changed project revision.');

        $ownerView = $studio->projectView(42, 1, 1, false, false, [], false);
        $peerView = $studio->projectView(42, 2, 1, false, false, [], false);
        $adminView = $studio->projectView(42, 2, 1, true, false, [], false);
        $assert(is_array($ownerView) && is_array($peerView)
            && is_array($adminView), 'A project view was unavailable to an expected viewer.');
        $filteredPeerView = $studio->projectView(
            42, 2, 1, false, false, ['Scout'], false
        );
        $assert(is_array($filteredPeerView)
            && $filteredPeerView['pagination']['total'] === 1
            && $filteredPeerView['chapters'][0]['lines'][0]['character'] === 'Scout'
            && !$filteredPeerView['chapters'][0]['lines'][0]['can_record']
            && $peerView['chapters'][0]['lines'][0]['can_record'],
            'Collaborator character filter altered role-specific recording rights.');
        $rookVoice = array_values(array_filter(
            $adminView['voices'],
            static fn (array $voice): bool => $voice['character'] === 'Rook'
        ));
        $assert(count($rookVoice) === 1
            && $rookVoice[0]['line_count'] === 1
            && $rookVoice[0]['dubbed_count'] === 1
            && $rookVoice[0]['assigned_names'] === ['Owner', 'Voice'],
            'Voice summary did not count one line/selected take or both assignees.');
        $ownerTakes = $ownerView['chapters'][0]['lines'][0]['takes'];
        $peerTakes = $peerView['chapters'][0]['lines'][0]['takes'];
        $adminTakes = $adminView['chapters'][0]['lines'][0]['takes'];
        $ownerHidden = array_values(array_filter(
            $ownerTakes,
            static fn (array $take): bool => $take['id'] === 78
        ));
        $peerHidden = array_values(array_filter(
            $peerTakes,
            static fn (array $take): bool => $take['id'] === 78
        ));
        $adminHidden = array_values(array_filter(
            $adminTakes,
            static fn (array $take): bool => $take['id'] === 78
        ));
        $assert(count($ownerHidden) === 1 && $ownerHidden[0]['hidden']
            && $ownerHidden[0]['can_hide'] && !$ownerHidden[0]['can_delete'],
            'Uploader could not see the hidden badge or had permanent-delete access.');
        $assert($peerHidden === [],
            'Hidden unselected take remained visible to another collaborator.');
        $assert(count($adminHidden) === 1 && $adminHidden[0]['can_hide']
            && $adminHidden[0]['can_delete'],
            'Administrator could not view or permanently delete the hidden take.');
        $assert($studio->recordingForUser(78, 2) === null,
            'Hidden unselected take remained directly playable to a collaborator.');
        $assert($studio->recordingForUser(77, 2) !== null,
            'Selected take became unavailable to a collaborator.');
        $assert($studio->beginTakeDeletion(42, 61, 78, 2, false) === null,
            'Collaborator could permanently delete another take.');
        $lockedTake = $studio->beginTakeDeletion(42, 61, 78, 1, true);
        $assert(is_array($lockedTake),
            'Administrator could not begin permanent take deletion.');
        $studio->cancelTakeDeletion();

        $assert($studio->selectTake(42, 61, 78, 1, true),
            'Administrator could not select the hidden take.');
        $selectedRevision = $studio->projectRevision(42, 2);
        $assert(is_string($selectedRevision) && $selectedRevision !== $hiddenRevision,
            'Effective selection change did not advance project revision.');
        $assert(!$studio->setTakeHidden(42, 61, 78, 2, false),
            'Collaborator could restore another user\'s hidden take.');
        $selectedPeerView = $studio->projectView(42, 2, 1, false, false, [], false);
        $assert(is_array($selectedPeerView)
            && $selectedPeerView['chapters'][0]['lines'][0]['selected_recording_id'] === 78
            && ($selectedPeerView['playlist'][0]['recording_id'] ?? null) === 78
            && $studio->recordingForUser(78, 2) !== null,
            'A selected hidden take stopped appearing or playing in the shared playlist.');
        $assert($studio->setTakeHidden(42, 61, 78, 2, false, true),
            'Administrator could not restore a hidden take.');
        $restoredRevision = $studio->projectRevision(42, 2);
        $assert(is_string($restoredRevision) && $restoredRevision !== $selectedRevision,
            'Effective restore did not advance project revision.');
        $assert($studio->setTakeHidden(42, 61, 78, 2, false, true)
            && $studio->projectRevision(42, 2) === $restoredRevision,
            'Idempotent restore retry changed project revision.');
        $assert($studio->selectTake(42, 61, 77, 1, true),
            'Administrator could not restore the original legacy selection.');

        $database->exec('DELETE FROM dabing_users WHERE id=1');
        $preserved = $database->query(
            'SELECT p.owner_user_id, r.user_id, r.storage_key, '
            . 'l.selected_recording_id '
            . 'FROM dabing_projects p '
            . 'JOIN dabing_project_chapters c ON c.project_id=p.id '
            . 'JOIN dabing_script_lines l ON l.chapter_id=c.id '
            . 'JOIN dabing_recordings r ON r.line_id=l.id '
            . 'WHERE p.id=42 AND r.id=77'
        )->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($preserved)
            && $preserved['owner_user_id'] === null
            && $preserved['user_id'] === null
            && (string) $preserved['storage_key'] === str_repeat('a', 64)
            && (int) $preserved['selected_recording_id'] === 77,
            'Deleting an account removed a project, recording, or selection.');
        $assert((int) $database->query(
            'SELECT COUNT(*) FROM dabing_voice_assignments '
            . "WHERE project_id=42 AND character_name='Rook' AND user_id=2"
        )->fetchColumn() === 1,
            'Deleting one assigned user removed another user\'s assignment.');
        fwrite(STDOUT,
            "PASS legacy upgrade: 0.1.1 rows, take selection, multi-user role, "
            . "hidden-take access, project revisions, 24-hour invite cap, "
            . "account deletion, and retry preserved.\n"
        );
    } finally {
        $admin->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
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
    $migrationPaths = UpdateMigrationService::discover(
        $root . '/database/migrations'
    );
    $migrationService = new UpdateMigrationService($database);
    $migrationCount = $migrationService->apply($migrationPaths);
    $repeatMigrationCount = $migrationService->apply($migrationPaths);
    if ($repeatMigrationCount !== 0) {
        fail('Schema migration was not idempotent on repeat application.');
    }
    foreach (['accounts.sql', 'studio.sql', 'email-invites.sql'] as $schema) {
        $installer->applySchema($root . '/database/' . $schema);
    }
    $migrationService->apply($migrationPaths);

    $expected = [
        'dabing_users', 'dabing_login_attempts', 'dabing_projects',
        'dabing_project_members', 'dabing_project_invites',
        'dabing_project_chapters', 'dabing_script_lines', 'dabing_recordings',
        'dabing_voice_assignments', 'dabing_email_invites',
        'dabing_email_invite_roles', 'dabing_email_invite_limits',
        'dabing_schema_migrations',
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
    $requiredColumns = [
        'dabing_users' => ['is_active', 'session_version'],
        'dabing_email_invites' => ['delivery_error'],
        'dabing_projects' => ['owner_user_id', 'revision'],
        'dabing_recordings' => ['user_id', 'is_hidden'],
    ];
    $columnQuery = $database->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT '
        . 'FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table_name'
    );
    foreach ($requiredColumns as $table => $columns) {
        $columnQuery->execute(['schema' => $name, 'table_name' => $table]);
        $actualColumns = [];
        foreach ($columnQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actualColumns[(string) $row['COLUMN_NAME']] = [
                'nullable' => (string) $row['IS_NULLABLE'],
                'default' => $row['COLUMN_DEFAULT'],
            ];
        }
        foreach ($columns as $column) {
            if (!array_key_exists($column, $actualColumns)) {
                fail('Schema smoke is missing ' . $table . '.' . $column . '.');
            }
        }
        if (($table === 'dabing_projects'
                && $actualColumns['owner_user_id']['nullable'] !== 'YES')
            || ($table === 'dabing_recordings'
                && $actualColumns['user_id']['nullable'] !== 'YES')) {
            fail('Schema smoke found author-preserving columns that are not nullable.');
        }
        foreach ([
            'revision' => '0',
            'is_hidden' => '0',
        ] as $column => $default) {
            if (isset($actualColumns[$column])
                && (string) $actualColumns[$column]['default'] !== $default) {
                fail('Schema smoke found an unexpected default for '
                    . $table . '.' . $column . '.');
            }
        }
    }
    $foreignKeys = $database->query(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE '
        . 'FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE()'
    )->fetchAll(PDO::FETCH_ASSOC);
    $deleteRules = [];
    foreach ($foreignKeys as $foreignKey) {
        $deleteRules[(string) $foreignKey['TABLE_NAME'] . ':'
            . (string) $foreignKey['CONSTRAINT_NAME']] =
                (string) $foreignKey['DELETE_RULE'];
    }
    foreach ([
        'dabing_projects:fk_dabing_projects_owner' => 'SET NULL',
        'dabing_recordings:fk_dabing_recordings_user' => 'SET NULL',
        'dabing_voice_assignments:fk_dabing_voice_assignments_project' => 'CASCADE',
        'dabing_voice_assignments:fk_dabing_voice_assignments_user' => 'CASCADE',
        'dabing_email_invite_roles:fk_dabing_email_invite_roles_invite' => 'CASCADE',
    ] as $key => $rule) {
        if (($deleteRules[$key] ?? null) !== $rule) {
            fail('Schema smoke found an unexpected delete rule for ' . $key . '.');
        }
    }
    $assignmentKey = $database->query(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() '
        . "AND TABLE_NAME = 'dabing_voice_assignments' "
        . "AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($assignmentKey !== ['project_id', 'character_name', 'user_id']) {
        fail('Schema smoke found an unexpected voice-assignment primary key.');
    }
    $ledgerCount = (int) $database->query(
        'SELECT COUNT(*) FROM dabing_schema_migrations'
    )->fetchColumn();
    if ($ledgerCount !== count($migrationPaths)) {
        fail('Schema smoke found incomplete migration ledger history.');
    }
    $rootPassword = getenv('DABING_DB_ROOT_PASSWORD');
    if (is_string($rootPassword) && $rootPassword !== '') {
        auditLegacyUpgrade($host, (int) $port, $rootPassword);
    } else {
        fwrite(STDOUT,
            "SKIP legacy upgrade regression: DABING_DB_ROOT_PASSWORD is unset.\n"
        );
    }
    $version = (string) $database->query('SELECT VERSION()')->fetchColumn();
    fwrite(STDOUT, sprintf(
        "PASS schema migration/install: %d applied, repeat-safe on %s; verified %d InnoDB utf8mb4 tables.\n",
        $migrationCount,
        $version,
        count($expected)
    ));
}

try {
    $files = trackedFiles();
    auditSources($files);
    try {
        auditMailTransport();
    } catch (RuntimeException $error) {
        fail($error->getMessage());
    }
    auditSchema();
} catch (Throwable $error) {
    fail('Audit could not complete (' . get_class($error) . ').');
}
