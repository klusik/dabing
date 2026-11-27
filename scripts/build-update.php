<?php

declare(strict_types=1);

use Dabing\UpdatePackage;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: scripts/build-update.php
 * Module Type: Packaging CLI
 * Purpose: Build a deterministic ZIP update from source files and one version value.
 * Responsibilities: Enforce package allowlists, safe output paths, and a single archive root.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'UpdatePackage.php';

/** One fixed timestamp for every archive entry.
 * Type: int; Units: Unix seconds; Scope: every packaged file;
 * Consumers: ZipArchive::setMtimeName(); Rationale: remove source mtime variance.
 */
const PACKAGE_ENTRY_TIMESTAMP = 315532800;
/** Archive folder used as the only package root.
 * Type: string; Units: ZIP path component; Scope: one update archive;
 * Consumers: entry naming; Rationale: install files under one predictable root.
 */
const PACKAGE_ROOT_NAME = 'dabing';

/** Print command usage and exit when arguments are missing. */
function printUsage(): never
{
    fwrite(STDERR, "Usage: php scripts/build-update.php X.Y.Z /outside/repo/dabing-X.Y.Z.zip\n");
    exit(2);
}

/** Validate a three-part numeric release version.
 * @param string $version CLI version argument.
 * @return bool True for X.Y.Z with no leading zeroes except zero.
 */
function isValidVersion(string $version): bool
{
    return preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) === 1;
}

/** Resolve the target ZIP path and require it to remain outside the repository.
 * @param string $argument Destination path supplied by the operator.
 * @param string $repositoryRoot Canonical repository root.
 * @return array{path:string,directory:string} Canonical output path and directory.
 */
function resolveOutput(string $argument, string $repositoryRoot): array
{
    $parent = realpath(dirname($argument));
    if (!is_string($parent) || !is_dir($parent) || !is_writable($parent)) {
        throw new RuntimeException('Output directory must already exist and be writable.');
    }
    $fileName = basename($argument);
    if ($fileName === '' || $fileName === '.' || $fileName === '..'
        || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Output path must name a .zip file.');
    }
    $path = $parent . DIRECTORY_SEPARATOR . $fileName;
    $repositoryPrefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
    if (strncasecmp($path, $repositoryPrefix, strlen($repositoryPrefix)) === 0) {
        throw new RuntimeException('Update archives must be written outside the repository.');
    }
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Refusing to overwrite an existing output file.');
    }
    return ['path' => $path, 'directory' => $parent];
}

/** Collect packageable regular files in deterministic repository-relative order.
 * @param string $repositoryRoot Canonical source root.
 * @return list<array{relative:string,absolute:string}> Allowed regular files.
 */
function collectPackageFiles(string $repositoryRoot): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(
            $repositoryRoot,
            \RecursiveDirectoryIterator::SKIP_DOTS
        ),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $absolute = $entry->getPathname();
        $relative = str_replace('\\', '/', substr($absolute, strlen($repositoryRoot) + 1));
        if (UpdatePackage::allowsFile($relative)) {
            $files[$relative] = $absolute;
        }
    }
    if (!isset($files['version.json']) || !isset($files['storage/.htaccess'])) {
        throw new RuntimeException('Package allowlist must include version.json and storage/.htaccess.');
    }
    ksort($files, SORT_STRING);
    $result = [];
    foreach ($files as $relative => $absolute) {
        $result[] = ['relative' => $relative, 'absolute' => $absolute];
    }
    return $result;
}

/** Encode package version metadata while preserving other metadata keys.
 * @param string $version Override value supplied on the command line.
 * @return string Stable UTF-8 JSON file content.
 */
function versionContents(string $version): string
{
    $sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'version.json';
    $source = file_get_contents($sourcePath);
    if (!is_string($source)) {
        throw new RuntimeException('version.json could not be read.');
    }
    try {
        $metadata = json_decode($source, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('version.json is invalid JSON.', 0, $error);
    }
    if (!is_array($metadata) || !isset($metadata['version'])
        || !is_string($metadata['version'])) {
        throw new RuntimeException('version.json must contain a string version field.');
    }
    $metadata['version'] = $version;
    return json_encode(
        $metadata,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
}

/** Add one source file with normalized metadata and compression settings.
 * @param ZipArchive $archive Open output archive.
 * @param string $sourcePath Absolute source file path.
 * @param string $entryName Rooted archive entry path.
 * @param string|null $content Optional replacement bytes for version.json.
 * @return void No return value.
 */
function addPackageEntry(
    ZipArchive $archive,
    string $sourcePath,
    string $entryName,
    ?string $content = null
): void {
    $added = $content === null
        ? $archive->addFile($sourcePath, $entryName)
        : $archive->addFromString($entryName, $content);
    if (!$added
        || !$archive->setMtimeName($entryName, PACKAGE_ENTRY_TIMESTAMP)
        || !$archive->setCompressionName($entryName, ZipArchive::CM_DEFLATE, 9)
        || !$archive->setExternalAttributesName(
            $entryName,
            ZipArchive::OPSYS_UNIX,
            0100644 << 16
        )) {
        throw new RuntimeException('An update archive entry could not be added.');
    }
}

try {
    if ($argc !== 3) {
        printUsage();
    }
    $version = (string) $argv[1];
    if (!isValidVersion($version)) {
        throw new RuntimeException('Version must use the X.Y.Z format.');
    }
    $repositoryRoot = realpath(dirname(__DIR__));
    if (!is_string($repositoryRoot)) {
        throw new RuntimeException('Repository root could not be resolved.');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive is required to build an update.');
    }
    $output = resolveOutput((string) $argv[2], $repositoryRoot);
    $files = collectPackageFiles($repositoryRoot);
    $versionBytes = versionContents($version);
    $temporary = $output['directory'] . DIRECTORY_SEPARATOR . '.'
        . basename($output['path']) . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $archive = new ZipArchive();
    $opened = $archive->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL);
    if ($opened !== true) {
        throw new RuntimeException('A temporary update archive could not be opened.');
    }
    try {
        foreach ($files as $file) {
            $entryName = PACKAGE_ROOT_NAME . '/' . $file['relative'];
            $content = $file['relative'] === 'version.json' ? $versionBytes : null;
            addPackageEntry($archive, $file['absolute'], $entryName, $content);
        }
    } finally {
        if (!$archive->close()) {
            throw new RuntimeException('The update archive could not be finalized.');
        }
    }
    if (!rename($temporary, $output['path'])) {
        throw new RuntimeException('The update archive could not be moved into place.');
    }
    fwrite(STDOUT, sprintf("Built Dabing update %s (%d files).\n", $version, count($files)));
} catch (Throwable $error) {
    if (isset($temporary) && is_file($temporary)) {
        @unlink($temporary);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
