<?php
declare(strict_types=1);

namespace Dabing;

use ZipArchive;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/UpdatePackage.php
 * Module Type: Service
 * Purpose: Validate and stage the exact file set allowed in a release update.
 * Responsibilities: Reject unsafe ZIP entries and map verified version and migration files.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Stages allowlisted update files without extracting arbitrary archive paths. */
final class UpdatePackage
{
    /**
     * Type: int; Units: bytes; Scope: downloaded ZIP; Consumers: stage();
     * Rationale: bound compressed release storage before archive parsing.
     */
    private const MAX_ARCHIVE_BYTES = 209715200;

    /**
     * Type: int; Units: bytes; Scope: expanded release; Consumers: stage();
     * Rationale: bound disk use from compressed ZIP entries.
     */
    private const MAX_EXPANDED_BYTES = 524288000;

    /**
     * Type: int; Units: ZIP entries; Scope: one release package; Consumers: stage();
     * Rationale: prevent path and metadata exhaustion from oversized archives.
     */
    private const MAX_ARCHIVE_ENTRIES = 20000;

    /**
     * Type: int; Units: files; Scope: one staged release; Consumers: stage();
     * Rationale: bound the in-memory applied-file inventory.
     */
    private const MAX_PACKAGE_FILES = 10000;

    /**
     * Type: int; Units: JSON nesting levels; Scope: package version manifest;
     * Consumers: readVersionManifest(); Rationale: manifest has a fixed shallow shape.
     */
    private const VERSION_JSON_MAX_DEPTH = 8;

    /**
     * Stage the package using the fixed release file allowlist.
     *
     * @param string $zipPath Private downloaded ZIP file.
     * @param string $stageDirectory Empty private staging directory.
     * @param string $expectedVersion Release version from GitHub metadata.
     * @return array{version:string,files:list<array{path:string,staged_path:string}>,migrations:list<string>} Verified version, file map, and sorted staged migrations.
     * @throws HttpError When the archive is invalid, unsafe, oversized, or incomplete.
     */
    public function stage(
        string $zipPath,
        string $stageDirectory,
        string $expectedVersion
    ): array {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpError(503, 'Rozšíření ZIP není dostupné.');
        }
        $zipSize = filesize($zipPath);
        if (!is_int($zipSize) || $zipSize < 1 || $zipSize > self::MAX_ARCHIVE_BYTES
            || is_link($zipPath) || !is_dir($stageDirectory) || is_link($stageDirectory)) {
            throw new HttpError(503, 'ZIP balíček aktualizace není platný.');
        }
        $stageRoot = realpath($stageDirectory);
        if (!is_string($stageRoot)) {
            throw new HttpError(500, 'Dočasný adresář aktualizace není dostupný.');
        }
        $archive = new ZipArchive();
        if ($archive->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new HttpError(503, 'ZIP balíček aktualizace nelze otevřít.');
        }
        $files = [];
        $migrations = [];
        $seenPaths = [];
        $rootSeen = false;
        $requiredPaths = [
            '.htaccess',
            'index.php',
            'version.json',
            'app/Bootstrap.php',
            'app/Http.php',
            'app/HttpError.php',
            'app/FormError.php',
            'app/services/InstallService.php',
            'app/services/ApplicationUpdateLock.php',
            'app/services/UpdateMigrationService.php',
            'app/models/UpdateMigrationModel.php',
            'app/services/GitHubReleaseClient.php',
            'app/services/UpdatePackage.php',
            'app/services/UpdateService.php',
            'database/accounts.sql',
            'database/studio.sql',
            'public/assets/site.css',
            'public/assets/site.js',
        ];
        $foundRequired = [];
        $expandedBytes = 0;
        try {
            if ($archive->numFiles < 1
                || $archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new HttpError(503, 'ZIP balíček obsahuje nepovolený počet položek.');
            }
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new HttpError(503, 'ZIP balíček obsahuje neplatnou položku.');
                }
                $entryName = $stat['name'];
                $isDirectory = str_ends_with($entryName, '/');
                $archivePath = $isDirectory
                    ? substr($entryName, 0, -1) : $entryName;
                if ($archivePath === 'dabing' && $isDirectory) {
                    if ($rootSeen) {
                        throw new HttpError(503, 'ZIP balíček obsahuje duplicitní kořen.');
                    }
                    $rootSeen = true;
                    continue;
                }
                if (!str_starts_with($archivePath, 'dabing/')) {
                    throw new HttpError(503, 'ZIP balíček neobsahuje očekávaný kořen dabing/.');
                }
                $relativePath = substr($archivePath, strlen('dabing/'));
                if (!$this->isSafeRelativePath($relativePath)) {
                    throw new HttpError(503, 'ZIP balíček obsahuje nebezpečnou cestu.');
                }
                $externalSystem = 0;
                $externalAttributes = 0;
                $hasAttributes = $archive->getExternalAttributesIndex(
                    $index,
                    $externalSystem,
                    $externalAttributes
                );
                if ($hasAttributes && $externalSystem === ZipArchive::OPSYS_UNIX) {
                    // Unix file type bits distinguish regular files, directories, and links.
                    $fileType = ($externalAttributes >> 16) & 0xF000;
                    if ($fileType === 0xA000
                        || ($fileType !== 0
                            && $fileType !== 0x8000
                            && $fileType !== 0x4000)) {
                        throw new HttpError(503, 'ZIP balíček obsahuje odkaz nebo zvláštní soubor.');
                    }
                    if ($fileType === 0x4000) {
                        $isDirectory = true;
                    }
                }
                $pathKey = strtolower($relativePath);
                if (isset($seenPaths[$pathKey])) {
                    throw new HttpError(503, 'ZIP balíček obsahuje duplicitní cestu.');
                }
                $seenPaths[$pathKey] = true;
                if ($isDirectory) {
                    continue;
                }
                if (!self::allowsFile($relativePath)) {
                    throw new HttpError(503, 'ZIP balíček obsahuje soubor mimo povolený seznam.');
                }
                if ($relativePath === 'storage/.htaccess') {
                    continue;
                }
                $entrySize = $stat['size'] ?? null;
                if (!is_int($entrySize) || $entrySize < 0
                    || $entrySize > self::MAX_EXPANDED_BYTES - $expandedBytes
                    || count($files) >= self::MAX_PACKAGE_FILES) {
                    throw new HttpError(503, 'ZIP balíček překračuje povolenou velikost.');
                }
                $expandedBytes += $entrySize;
                $destination = $stageRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $destinationDirectory = dirname($destination);
                if (!is_dir($destinationDirectory)
                    && !mkdir($destinationDirectory, 0700, true)
                    && !is_dir($destinationDirectory)) {
                    throw new HttpError(500, 'Soubory aktualizace nelze připravit.');
                }
                $source = $archive->getStream($entryName);
                $target = @fopen($destination, 'x+b');
                if (!is_resource($source) || !is_resource($target)) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    if (is_resource($target)) {
                        fclose($target);
                    }
                    @unlink($destination);
                    throw new HttpError(503, 'Soubor aktualizace nelze bezpečně rozbalit.');
                }
                $copiedBytes = stream_copy_to_stream($source, $target, $entrySize + 1);
                $flushed = fflush($target);
                fclose($source);
                fclose($target);
                if (!is_int($copiedBytes) || $copiedBytes !== $entrySize || !$flushed) {
                    @unlink($destination);
                    throw new HttpError(503, 'Soubor aktualizace má neplatný obsah.');
                }
                chmod($destination, 0600);
                $files[] = [
                    'path' => $relativePath,
                    'staged_path' => $destination,
                ];
                if (in_array($relativePath, $requiredPaths, true)) {
                    $foundRequired[$relativePath] = true;
                }
                if (preg_match(
                    '/\Adatabase\/migrations\/[0-9]{4}_[a-z0-9_]+\.php\z/D',
                    $relativePath
                ) === 1) {
                    $migrations[] = $destination;
                }
            }
            foreach ($requiredPaths as $requiredPath) {
                if (!isset($foundRequired[$requiredPath])) {
                    throw new HttpError(503, 'ZIP balíčku chybí důležitý soubor aplikace.');
                }
            }
            $manifest = $this->readVersionManifest($stageRoot . DIRECTORY_SEPARATOR . 'version.json');
            if (!hash_equals($expectedVersion, $manifest)) {
                throw new HttpError(503, 'Verze v ZIP balíčku neodpovídá vydání.');
            }
            sort($migrations, SORT_STRING);
            return [
                'version' => $manifest,
                'files' => $files,
                'migrations' => $migrations,
            ];
        } finally {
            $archive->close();
        }
    }

    /** Determine whether a normalized package path is permitted for updates.
     *
     * @param string $path Relative slash-separated archive file path.
     * @return bool True when the path is in the fixed update allowlist.
     */
    public static function allowsFile(string $path): bool
    {
        if (!self::isSafeRelativePath($path)) {
            return false;
        }
        if (in_array($path, [
            '.htaccess',
            'index.php',
            'version.json',
            'README.md',
            'LICENSE',
            'LICENSE.md',
            'COPYING',
            'COPYING.txt',
            'scripts/create-install-key.php',
            'scripts/migrate_recording_attempts.php',
            'storage/.htaccess',
        ], true)) {
            return true;
        }
        if (preg_match('/\Aapp\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.php\z/D', $path) === 1
            || preg_match('/\Adatabase\/[A-Za-z0-9_-]+\.sql\z/D', $path) === 1
            || preg_match('/\Adatabase\/migrations\/[0-9]{4}_[a-z0-9_]+\.php\z/D', $path) === 1
            || preg_match('/\Apublic\/assets\/[A-Za-z0-9_-]+\.(?:css|js|json|svg|png|webp|ico)\z/D', $path) === 1
            || preg_match('/\Aconfig\/[A-Za-z0-9_-]+\.example\.php\z/D', $path) === 1) {
            return true;
        }
        return preg_match(
            '/\Adocs\/third-party\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_.-]+\.(?:md|txt)\z/D',
            $path
        ) === 1;
    }

    /** Validate a release manifest and return its canonical version string.
     *
     * @param string $path Staged version.json path.
     * @return string Canonical semantic version without a v prefix.
     * @throws HttpError When the manifest is malformed or has an invalid version.
     */
    private function readVersionManifest(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new HttpError(503, 'ZIP balíčku chybí platná verze aplikace.');
        }
        try {
            $manifest = json_decode(
                $contents,
                true,
                self::VERSION_JSON_MAX_DEPTH,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $error) {
            throw new HttpError(503, 'Soubor version.json v ZIP balíčku není platný.');
        }
        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (!is_string($version)
            || preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $version) !== 1) {
            throw new HttpError(503, 'Soubor version.json neobsahuje platnou verzi.');
        }
        return $version;
    }

    /** Reject paths that can escape or alias the private staging root.
     *
     * @param string $path Relative archive path without a directory suffix.
     * @return bool True when every path segment is safe.
     */
    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")
            || str_contains($path, '\\') || str_starts_with($path, '/')
            || str_contains($path, ':')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
