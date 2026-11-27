<?php
declare(strict_types=1);

namespace Dabing;

use Throwable;
use PDOException;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/UpdateService.php
 * Module Type: Service
 * Purpose: Stage, validate, and apply verified application releases.
 * Responsibilities: Coordinate package validation, exclusive replacement, migrations, and recovery records.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Coordinates an application update while retaining a recoverable file inventory. */
final class UpdateService
{
    /**
     * Type: int; Units: hexadecimal characters; Scope: private workspace key;
     * Consumers: createWorkspace(); Rationale: random path independent of user input.
     */
    private const WORKSPACE_KEY_LENGTH = 32;

    /**
     * Type: string; Units: filename; Scope: protected storage directory;
     * Consumers: apply(), currentVersion(); Rationale: detect and describe incomplete updates.
     */
    private const RECOVERY_MARKER = 'update-recovery.json';

    /**
     * Type: string; Units: directory name; Scope: protected storage directory;
     * Consumers: createWorkspace(), cleanupWorkspace(); Rationale: isolate transient release files.
     */
    private const WORK_DIRECTORY = 'update-work';

    /**
     * Type: list<int>; Units: MySQL vendor error codes; Scope: runtime-account migration;
     * Consumers: isSchemaPermissionError(); Rationale: map only known privilege failures to form guidance.
     */
    private const MYSQL_SCHEMA_PERMISSION_CODES = [1044, 1142, 1143, 1227];

    private readonly string $rootDirectory;
    private readonly string $storageDirectory;
    private readonly GitHubReleaseClient $releaseClient;
    private readonly UpdatePackage $package;

    /** Create the updater for an installed application root.
     * @param string $root Absolute application root.
     * @param InstallService $install Installer providing runtime and temporary DDL connections.
     * @param ApplicationUpdateLock $lock Shared application lock.
     * @return void No return value.
     * @throws HttpError When the application root or protected storage is invalid.
     */
    public function __construct(
        string $root,
        private readonly InstallService $install,
        private readonly ApplicationUpdateLock $lock
    ) {
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot) || is_link($root)) {
            throw new HttpError(500, 'Adresář aplikace není dostupný.');
        }
        $storage = $resolvedRoot . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage) || is_link($storage)) {
            throw new HttpError(500, 'Chráněné úložiště aplikace není dostupné.');
        }
        $this->rootDirectory = $resolvedRoot;
        $this->storageDirectory = $storage;
        $this->releaseClient = new GitHubReleaseClient();
        $this->package = new UpdatePackage();
    }

    /** Read the installed version, allowing an unversioned development checkout.
     * @return string Canonical installed version, or 0.0.0 when no manifest exists.
     * @throws HttpError When an existing version manifest is malformed.
     */
    public function currentVersion(): string
    {
        $path = $this->rootDirectory . DIRECTORY_SEPARATOR . 'version.json';
        if (!file_exists($path)) {
            return '0.0.0';
        }
        if (is_link($path) || !is_file($path)) {
            throw new HttpError(503, 'Soubor verze aplikace není platný.');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new HttpError(503, 'Soubor verze aplikace nelze načíst.');
        }
        try {
            $manifest = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new HttpError(503, 'Soubor verze aplikace není platný.');
        }
        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (!is_string($version) || !$this->isVersion($version)) {
            throw new HttpError(503, 'Soubor verze aplikace neobsahuje platnou verzi.');
        }
        return $version;
    }

    /** Fetch the current stable release metadata from the fixed GitHub source.
     * @return array{version:string,url:string,sha256:string,size:int}|null Valid release or null when none is published.
     */
    public function latestRelease(): ?array
    {
        return $this->releaseClient->latest();
    }

    /** Re-fetch, stage, and apply one exact stable release.
     * @param string $version Expected release version.
     * @param string $sha256 Expected release SHA-256 digest.
     * @param string $dbUser Optional temporary schema-capable database user.
     * @param string $dbPassword Optional temporary schema-capable database password.
     * @return int Number of newly applied database migrations.
     * @throws HttpError When validation, locking, migration, or replacement fails.
     */
    public function apply(
        string $version,
        string $sha256,
        string $dbUser,
        string $dbPassword
    ): int {
        if (!$this->isVersion($version)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new HttpError(400, 'Údaje aktualizace nejsou platné.');
        }
        $initialVersion = $this->currentVersion();
        if (version_compare($version, $initialVersion, '<=')) {
            throw new HttpError(409, 'Aktualizace musí mít novější verzi.');
        }
        if (($dbUser === '') !== ($dbPassword === '')) {
            throw new FormError('Vyplňte oba údaje pro změnu schématu, nebo ponechte oba prázdné.');
        }
        $release = $this->releaseClient->latest();
        if ($release === null || $release['version'] !== $version
            || !hash_equals($release['sha256'], $sha256)) {
            throw new HttpError(409, 'Vydání se mezitím změnilo. Načtěte stránku znovu.');
        }
        $workspace = $this->createWorkspace();
        $markerWritten = false;
        $lockAcquired = false;
        $writesStarted = false;
        try {
            $zipPath = $workspace . DIRECTORY_SEPARATOR . 'release.zip';
            $stageDirectory = $workspace . DIRECTORY_SEPARATOR . 'stage';
            $backupDirectory = $workspace . DIRECTORY_SEPARATOR . 'backup';
            $this->releaseClient->download($release, $zipPath);
            $this->verifyDownloadedPackage($zipPath, $release);
            $package = $this->package->stage($zipPath, $stageDirectory, $version);

            if (!$this->lock->acquireExclusive()) {
                throw new HttpError(409, 'Aplikaci právě používá jiný požadavek. Zkuste to znovu.');
            }
            $lockAcquired = true;
            if ($this->currentVersion() !== $initialVersion) {
                throw new HttpError(409, 'Verze aplikace se mezitím změnila. Načtěte stránku znovu.');
            }
            if ($this->recoveryMarkerExists()) {
                throw new HttpError(503, 'Předchozí aktualizace vyžaduje obnovu správce.');
            }
            $inventory = $this->prepareInventory($package['files'], $backupDirectory);
            $marker = [
                'format' => 1,
                'workspace' => basename($workspace),
                'from_version' => $initialVersion,
                'to_version' => $version,
                'sha256' => $sha256,
                'phase' => 'prepared',
                'files' => $inventory,
            ];
            $this->writeRecoveryMarker($marker);
            $markerWritten = true;

            $migrationCount = 0;
            if ($package['migrations'] !== []) {
                $schemaDatabase = $dbUser === ''
                    ? null : $this->install->migrationDatabase($dbUser, $dbPassword);
                $migrationService = new UpdateMigrationService($this->install->database());
                try {
                    $migrationCount = $migrationService->apply(
                        $package['migrations'],
                        $schemaDatabase
                    );
                } catch (PDOException $error) {
                    if ($dbUser === '' && $this->isSchemaPermissionError($error)) {
                        throw new FormError(
                            'Vyplňte pole „Databázová oprávnění“ pro změnu schématu.'
                        );
                    }
                    throw $error;
                }
            }

            $writesStarted = true;
            $this->applyPackageFiles($package['files']);
            $this->applyVersionFile($package['files']);
            $this->invalidateOpcodeCache($inventory);
            if (!$this->removeRecoveryMarker()) {
                throw new HttpError(500, 'Záznam obnovy aktualizace nelze odstranit.');
            }
            $markerWritten = false;
            return $migrationCount;
        } catch (Throwable $error) {
            if ($markerWritten && $lockAcquired) {
                try {
                    if ($writesStarted) {
                        $this->restoreInventory($marker, $workspace);
                        $this->invalidateOpcodeCache($inventory);
                    }
                    if (!$this->removeRecoveryMarker()) {
                        throw new HttpError(500, 'Záznam obnovy nelze odstranit.');
                    }
                    $markerWritten = false;
                } catch (Throwable $restoreError) {
                    error_log('Dabing update rollback failed: ' . $restoreError->getMessage());
                    throw new HttpError(
                        503,
                        'Aktualizace se zastavila a vyžaduje ruční obnovu ze storage/update-work.'
                    );
                }
            }
            throw $error;
        } finally {
            if ($lockAcquired) {
                $this->lock->release();
            }
            if (!$markerWritten) {
                $this->cleanupWorkspace($workspace);
            }
        }
    }

    /** Create a private randomly named update workspace.
     * @return string Absolute workspace path.
     * @throws HttpError When the private workspace cannot be created safely.
     */
    private function createWorkspace(): string
    {
        $workRoot = $this->storageDirectory . DIRECTORY_SEPARATOR . self::WORK_DIRECTORY;
        if (!is_dir($workRoot)
            && !mkdir($workRoot, 0700)
            && !is_dir($workRoot)) {
            throw new HttpError(500, 'Dočasné úložiště aktualizace nelze vytvořit.');
        }
        if (is_link($workRoot)) {
            throw new HttpError(500, 'Dočasné úložiště aktualizace není bezpečné.');
        }
        $key = bin2hex(random_bytes(self::WORKSPACE_KEY_LENGTH / 2));
        $workspace = $workRoot . DIRECTORY_SEPARATOR . $key;
        if (!mkdir($workspace, 0700)) {
            throw new HttpError(500, 'Pracovní složku aktualizace nelze vytvořit.');
        }
        foreach (['stage', 'backup'] as $directory) {
            if (!mkdir($workspace . DIRECTORY_SEPARATOR . $directory, 0700)) {
                $this->cleanupWorkspace($workspace);
                throw new HttpError(500, 'Pracovní složku aktualizace nelze připravit.');
            }
        }
        @chmod($workRoot, 0700);
        return $workspace;
    }

    /** Back up every original file and return a complete replacement inventory.
     * @param list<array{path:string,staged_path:string}> $files Validated staged package files.
     * @param string $backupDirectory Private backup root.
     * @return list<array{path:string,original:bool}> Complete present/absent inventory.
     * @throws HttpError When a live destination is unsafe or cannot be backed up.
     */
    private function prepareInventory(array $files, string $backupDirectory): array
    {
        $inventory = [];
        foreach ($files as $file) {
            $path = $file['path'];
            if ($path === 'storage/.htaccess') {
                continue;
            }
            $destination = $this->destinationPath($path);
            $present = file_exists($destination);
            if (is_link($destination) || ($present && !is_file($destination))) {
                throw new HttpError(503, 'Cílový soubor aktualizace není běžný soubor.');
            }
            $inventory[] = ['path' => $path, 'original' => $present];
            if (!$present) {
                continue;
            }
            $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $backupParent = dirname($backupPath);
            if (!is_dir($backupParent)
                && !mkdir($backupParent, 0700, true)
                && !is_dir($backupParent)) {
                throw new HttpError(500, 'Záložní kopii aktualizace nelze připravit.');
            }
            if (!copy($destination, $backupPath)) {
                throw new HttpError(500, 'Záložní kopii souboru nelze vytvořit.');
            }
            @chmod($backupPath, 0600);
            $sourceHash = hash_file('sha256', $destination);
            $backupHash = hash_file('sha256', $backupPath);
            if (!is_string($sourceHash) || !is_string($backupHash)
                || !hash_equals($sourceHash, $backupHash)) {
                throw new HttpError(500, 'Záložní kopie souboru není úplná.');
            }
        }
        return $inventory;
    }

    /** Apply package files other than the version manifest.
     * @param list<array{path:string,staged_path:string}> $files Validated staged package files.
     * @return void No return value.
     * @throws HttpError When a file cannot be atomically replaced.
     */
    private function applyPackageFiles(array $files): void
    {
        foreach ($files as $file) {
            if ($file['path'] === 'version.json' || $file['path'] === 'storage/.htaccess') {
                continue;
            }
            $this->replaceFile($file['path'], $file['staged_path']);
        }
    }

    /** Replace version.json after all files and database migrations succeed.
     * @param list<array{path:string,staged_path:string}> $files Validated staged package files.
     * @return void No return value.
     * @throws HttpError When the package has no version manifest or replacement fails.
     */
    private function applyVersionFile(array $files): void
    {
        foreach ($files as $file) {
            if ($file['path'] === 'version.json') {
                $this->replaceFile($file['path'], $file['staged_path']);
                return;
            }
        }
        throw new HttpError(503, 'Aktualizační balíček neobsahuje soubor verze.');
    }

    /** Replace one destination via a same-directory temporary file.
     * @param string $relativePath Validated package-relative path.
     * @param string $sourcePath Verified staged source file.
     * @return void No return value.
     * @throws HttpError When a path or atomic replacement operation fails.
     */
    private function replaceFile(string $relativePath, string $sourcePath): void
    {
        $destination = $this->destinationPath($relativePath);
        $parent = dirname($destination);
        if (!is_dir($parent)
            && !mkdir($parent, 0755, true)
            && !is_dir($parent)) {
            throw new HttpError(500, 'Adresář souboru aktualizace nelze vytvořit.');
        }
        if (is_link($destination) || (file_exists($destination) && !is_file($destination))) {
            throw new HttpError(503, 'Cílový soubor aktualizace není bezpečný.');
        }
        if (is_file($destination)) {
            $sourceHash = hash_file('sha256', $sourcePath);
            $destinationHash = hash_file('sha256', $destination);
            if (is_string($sourceHash) && is_string($destinationHash)
                && hash_equals($destinationHash, $sourceHash)) {
                return;
            }
        }
        $temporary = $destination . '.update-' . bin2hex(random_bytes(8));
        if (!copy($sourcePath, $temporary)) {
            throw new HttpError(500, 'Nový soubor aktualizace nelze připravit.');
        }
        @chmod($temporary, 0644);
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            if (PHP_OS_FAMILY === 'Windows' && $relativePath === 'index.php') {
                throw new HttpError(
                    500,
                    'Stabilní index.php nelze při běhu Windows nahradit. Proveďte aktualizaci v režimu údržby podle README.'
                );
            }
            throw new HttpError(500, 'Nový soubor aktualizace nelze uložit.');
        }
    }

    /** Restore all inventoried destinations from the complete backup set.
     * @param array{workspace:string,files:list<array{path:string,original:bool}>} $marker Valid recovery record.
     * @param string $workspace Known private workspace directory.
     * @return void No return value.
     * @throws HttpError When any original cannot be restored completely.
     */
    private function restoreInventory(array $marker, string $workspace): void
    {
        $backupRoot = $workspace . DIRECTORY_SEPARATOR . 'backup';
        foreach ($marker['files'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_bool($entry['original'] ?? null)) {
                throw new HttpError(503, 'Záznam obnovy aktualizace není platný.');
            }
            $path = $entry['path'];
            if (!UpdatePackage::allowsFile($path) || $path === 'storage/.htaccess') {
                throw new HttpError(503, 'Záznam obnovy obsahuje nepovolenou cestu.');
            }
            $destination = $this->destinationPath($path);
            if ($entry['original']) {
                $backup = $backupRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (!is_file($backup) || is_link($backup)) {
                    throw new HttpError(503, 'Záložní kopie pro obnovu chybí.');
                }
                if (is_link($destination)) {
                    throw new HttpError(503, 'Cílový soubor obnovy není bezpečný.');
                }
                if (is_file($destination)) {
                    $currentHash = hash_file('sha256', $destination);
                    $backupHash = hash_file('sha256', $backup);
                    if (is_string($currentHash) && is_string($backupHash)
                        && hash_equals($backupHash, $currentHash)) {
                        continue;
                    }
                }
                $parent = dirname($destination);
                if (!is_dir($parent)
                    && !mkdir($parent, 0755, true)
                    && !is_dir($parent)) {
                    throw new HttpError(500, 'Adresář pro obnovu nelze vytvořit.');
                }
                $restore = $destination . '.restore-' . bin2hex(random_bytes(8));
                if (!copy($backup, $restore) || !rename($restore, $destination)) {
                    @unlink($restore);
                    throw new HttpError(500, 'Původní soubor nelze obnovit.');
                }
            } else {
                if (is_link($destination)) {
                    throw new HttpError(503, 'Cílový soubor obnovy není bezpečný.');
                }
                if (file_exists($destination) && !unlink($destination)) {
                    throw new HttpError(500, 'Nově vytvořený soubor nelze při obnově odstranit.');
                }
            }
        }
    }

    /** Build a destination after validating package policy and live parent directories.
     * @param string $relativePath Slash-separated allowlisted package path.
     * @return string Absolute live destination.
     * @throws HttpError When the path is outside the allowlist or crosses a symlink.
     */
    private function destinationPath(string $relativePath): string
    {
        if (!UpdatePackage::allowsFile($relativePath) || $relativePath === 'storage/.htaccess') {
            throw new HttpError(503, 'Cesta aktualizace není povolená.');
        }
        $segments = explode('/', $relativePath);
        $current = $this->rootDirectory;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if ($index < $lastIndex && is_link($current)) {
                throw new HttpError(503, 'Adresář aktualizace obsahuje symbolický odkaz.');
            }
        }
        return $current;
    }

    /** Persist a complete recovery inventory before database or file changes.
     * @param array{format:int,workspace:string,from_version:string,to_version:string,sha256:string,phase:string,files:list<array{path:string,original:bool}>} $marker Complete update recovery record.
     * @return void No return value.
     * @throws HttpError When the protected recovery record cannot be stored.
     */
    private function writeRecoveryMarker(array $marker): void
    {
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . self::RECOVERY_MARKER;
        if (file_exists($path) || is_link($path)) {
            throw new HttpError(503, 'Předchozí aktualizace vyžaduje obnovu správce.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || file_put_contents($temporary, $json, LOCK_EX) === false) {
            @unlink($temporary);
            throw new HttpError(500, 'Záznam obnovy aktualizace nelze uložit.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new HttpError(500, 'Záznam obnovy aktualizace nelze uložit.');
        }
        @chmod($path, 0600);
    }

    /** Remove the recovery record after complete success or rollback.
     * @return bool True when absent or removed.
     */
    private function removeRecoveryMarker(): bool
    {
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . self::RECOVERY_MARKER;
        return !file_exists($path) || (!is_link($path) && unlink($path));
    }

    /** Check whether any recovery record blocks further updates.
     * @return bool True when a recovery record exists.
     */
    private function recoveryMarkerExists(): bool
    {
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . self::RECOVERY_MARKER;
        return file_exists($path) || is_link($path);
    }

    /** Remove one workspace tree after confirming its exact private parent.
     * @param string $workspace Workspace path created for the current request.
     * @return void No return value.
     */
    private function cleanupWorkspace(string $workspace): void
    {
        $workRoot = $this->storageDirectory . DIRECTORY_SEPARATOR . self::WORK_DIRECTORY;
        $key = basename($workspace);
        if (preg_match('/\A[a-f0-9]{' . self::WORKSPACE_KEY_LENGTH . '}\z/D', $key) !== 1
            || dirname($workspace) !== $workRoot || is_link($workspace) || !is_dir($workspace)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entryPath);
            } else {
                @unlink($entryPath);
            }
        }
        @rmdir($workspace);
    }

    /** Check for a canonical semantic version string.
     * @param string $version Candidate version.
     * @return bool True when the version is canonical X.Y.Z.
     */
    private function isVersion(string $version): bool
    {
        return preg_match(
            '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D',
            $version
        ) === 1;
    }

    /** Verify downloaded package size and digest again at the orchestration boundary.
     * @param string $zipPath Private package file path.
     * @param array{version:string,url:string,sha256:string,size:int} $release Validated GitHub metadata.
     * @return void No return value.
     * @throws HttpError When file size or SHA-256 differs from release metadata.
     */
    private function verifyDownloadedPackage(string $zipPath, array $release): void
    {
        $size = filesize($zipPath);
        $digest = hash_file('sha256', $zipPath);
        if (!is_int($size) || $size !== $release['size']
            || !is_string($digest) || !hash_equals($release['sha256'], $digest)) {
            throw new HttpError(503, 'Ověření staženého ZIP balíčku selhalo.');
        }
    }

    /** Invalidate cached PHP bytecode after deployment or rollback.
     * @param list<array{path:string,original:bool}> $inventory Updated package file inventory.
     * @return void No return value.
     */
    private function invalidateOpcodeCache(array $inventory): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        foreach ($inventory as $entry) {
            opcache_invalidate($this->destinationPath($entry['path']), true);
        }
    }

    /** Identify a known MySQL privilege error without masking other PDO failures.
     * @param PDOException $error Database error raised while applying migrations.
     * @return bool True only for the documented MySQL privilege vendor codes.
     */
    private function isSchemaPermissionError(PDOException $error): bool
    {
        $errorInfo = $error->errorInfo;
        $vendorCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;
        return is_int($vendorCode)
            && in_array($vendorCode, self::MYSQL_SCHEMA_PERMISSION_CODES, true);
    }
}
