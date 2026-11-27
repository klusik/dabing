<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/RecordingStorage.php
 * Module Type: Service
 * Purpose: Safely stage and serve private audio recordings.
 * Responsibilities: Validate uploads, manage pending files and ZIPs, and stream audio.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Stores private audio outside the public document tree. */
final class RecordingStorage
{
    /**
     * Type: int; Units: bytes; Scope: one uploaded take; Consumers: stage();
     * Rationale: bounds per-recording disk use at 32 MiB.
     */
    private const MAX_BYTES = 33554432;

    /**
     * Type: int; Units: hexadecimal characters; Scope: idempotent take keys;
     * Consumers: stage(), StudioService::addTake(); Rationale: match token to key prefix.
     */
    public const IDEMPOTENCY_PREFIX_HEX_CHARS = 32;

    /**
     * Type: int; Units: random bytes; Scope: idempotent take key suffix;
     * Consumers: stage(); Rationale: keep each complete storage key unpredictable.
     */
    private const RANDOM_SUFFIX_BYTES = 16;

    /**
     * Type: int; Units: random bytes; Scope: one temporary archive key;
     * Consumers: createExportArchive(), removeExportArchive();
     * Rationale: 32 hexadecimal characters make unguessable ZIP names.
     */
    private const EXPORT_ARCHIVE_KEY_BYTES = 16;



    /**
     * Prepare private recording storage.
     *
     * @param string $storageDirectory Absolute installation storage directory.
     * @return void
     */
    public function __construct(private readonly string $storageDirectory)
    {
    }

    /**
     * Validate upload contents and stage under an unpredictable key.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file PHP upload metadata.
     * @param string|null $keyPrefix Optional validated 32-character idempotency prefix.
     * @return array{key:string,mime:string,size:int} Safe staged-file metadata.
     * @throws FormError When upload or detected content is invalid.
     */
    public function stage(array $file, ?string $keyPrefix = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] < 1
            || $file['size'] > self::MAX_BYTES
            || !is_uploaded_file($file['tmp_name'])) {
            throw new FormError('Nahrávka je neplatná nebo přesahuje 32 MiB.');
        }
        $actualSize = filesize($file['tmp_name']);
        if (!is_int($actualSize)
            || $actualSize < 1
            || $actualSize > self::MAX_BYTES
            || $actualSize !== $file['size']) {
            throw new FormError('Velikost nahrávky nelze ověřit.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== 'audio/mpeg'
            || !$this->hasValidMp3Frame($file['tmp_name'], $actualSize)) {
            throw new FormError('Nový pokus musí být platná nahrávka MP3.');
        }
        if ($keyPrefix !== null
            && (strlen($keyPrefix) !== self::IDEMPOTENCY_PREFIX_HEX_CHARS
                || preg_match('/\A[a-f0-9]+\z/D', $keyPrefix) !== 1)) {
            throw new FormError('Identifikátor nahrávky je neplatný.');
        }
        $key = $keyPrefix === null
            ? bin2hex(random_bytes(32))
            : $keyPrefix . bin2hex(random_bytes(self::RANDOM_SUFFIX_BYTES));
        $directory = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recordings';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)
            && !is_dir($directory)) {
            throw new HttpError(500, 'Soukromé úložiště nahrávek není dostupné.');
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $key;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new HttpError(500, 'Nahrávku se nepodařilo uložit.');
        }
        chmod($destination, 0600);
        return ['key' => $key, 'mime' => $mime, 'size' => $file['size']];
    }


    /**
     * Verify an MPEG Layer III frame in a detected audio/mpeg file.
     *
     * @param string $path Temporary uploaded file path.
     * @param int $size Verified finite file size in bytes.
     * @return bool True when a complete valid Layer III frame is present.
     */
    private function hasValidMp3Frame(string $path, int $size): bool
    {
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            return false;
        }
        try {
            $prefix = fread($stream, 10);
            if (!is_string($prefix)) {
                return false;
            }
            $offset = 0;
            if (str_starts_with($prefix, 'ID3')) {
                if (strlen($prefix) !== 10) {
                    return false;
                }
                for ($index = 6; $index <= 9; ++$index) {
                    if ((ord($prefix[$index]) & 0x80) !== 0) {
                        return false;
                    }
                }
                $tagSize = (ord($prefix[6]) << 21)
                    | (ord($prefix[7]) << 14)
                    | (ord($prefix[8]) << 7)
                    | ord($prefix[9]);
                $footerSize = ord($prefix[3]) === 4
                    && (ord($prefix[5]) & 0x10) !== 0 ? 10 : 0;
                $offset = 10 + $tagSize + $footerSize;
            }
            if ($offset + 4 > $size || fseek($stream, $offset) !== 0) {
                return false;
            }
            $bytes = fread($stream, 4);
            if (!is_string($bytes) || strlen($bytes) !== 4) {
                return false;
            }
            $unpacked = unpack('Nheader', $bytes);
            if (!is_array($unpacked)) {
                return false;
            }
            $header = $unpacked['header'];
            $version = ($header >> 19) & 0x03;
            $layer = ($header >> 17) & 0x03;
            $bitrateIndex = ($header >> 12) & 0x0F;
            $sampleRateIndex = ($header >> 10) & 0x03;
            if (($header & 0xFFE00000) !== 0xFFE00000
                || $version === 1
                || $layer !== 1
                || $bitrateIndex === 0 || $bitrateIndex === 15
                || $sampleRateIndex === 3) {
                return false;
            }
            // Header bit fields, bitrate tables, sample rates, and frame coefficients
            // follow MPEG Audio Layer III frame encoding rules.
            $mpegOneBitrates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
            $mpegTwoBitrates = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
            $sampleRates = [44100, 48000, 32000];
            $sampleRate = $sampleRates[$sampleRateIndex];
            if ($version === 2) {
                $sampleRate = intdiv($sampleRate, 2);
            } elseif ($version === 0) {
                $sampleRate = intdiv($sampleRate, 4);
            }
            $bitrate = ($version === 3 ? $mpegOneBitrates : $mpegTwoBitrates)
                [$bitrateIndex];
            $padding = ($header >> 9) & 0x01;
            $coefficient = $version === 3 ? 144000 : 72000;
            $frameLength = intdiv($coefficient * $bitrate, $sampleRate) + $padding;
            return $offset + $frameLength <= $size;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Create a private temporary ZIP file for one book export.
     *
     * @return array{key:string,path:string} Random archive key and private path.
     * @throws HttpError When private export storage cannot be created.
     */
    public function createExportArchive(): array
    {
        $root = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . 'exports';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new HttpError(500, 'Soukromé úložiště exportu není dostupné.');
        }
        $key = bin2hex(random_bytes(self::EXPORT_ARCHIVE_KEY_BYTES));
        $path = $root . DIRECTORY_SEPARATOR . $key . '.zip';
        $stream = @fopen($path, 'x+b');
        if (!is_resource($stream)) {
            throw new HttpError(500, 'Dočasný archiv nelze vytvořit.');
        }
        fclose($stream);
        if (!chmod($path, 0600)) {
            @unlink($path);
            throw new HttpError(500, 'Dočasný archiv nelze zabezpečit.');
        }
        return ['key' => $key, 'path' => $path];
    }

    /**
     * Resolve a take path with a verified persisted byte count.
     *
     * @param string $storageKey Authorized take storage key.
     * @param int $expectedBytes Database byte count for the take.
     * @return string|null Readable private canonical or pending path, or null.
     */
    public function readablePath(string $storageKey, int $expectedBytes): ?string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $storageKey) !== 1
            || $expectedBytes < 1) {
            return null;
        }
        foreach ([$this->path($storageKey), $this->pendingPath($storageKey)] as $path) {
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $stream = @fopen($path, 'rb');
            if (!is_resource($stream)) {
                continue;
            }
            $metadata = fstat($stream);
            fclose($stream);
            if (is_array($metadata)
                && (int) ($metadata['size'] ?? -1) === $expectedBytes) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Remove a private temporary ZIP file after delivery or a failed build.
     *
     * @param string $key Random archive key returned by createExportArchive().
     * @return void No value is returned.
     */
    public function removeExportArchive(string $key): void
    {
        if (strlen($key) !== self::EXPORT_ARCHIVE_KEY_BYTES * 2
            || preg_match('/\A[a-f0-9]+\z/D', $key) !== 1) {
            return;
        }
        $path = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR
            . 'exports' . DIRECTORY_SEPARATOR . $key . '.zip';
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Delete a staged or obsolete file when present.
     *
     * @param string $key Random 64-character storage key.
     * @return void
     */
    public function delete(string $key): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            return;
        }
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Move an existing take into its deterministic private pending-delete path.
     *
     * @param string $key Random database storage key.
     * @return bool True when staged or absent; false for conflicting paths.
     */
    public function moveToPending(string $key): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            return false;
        }
        $source = $this->path($key);
        $pending = $this->pendingPath($key);
        $sourceExists = file_exists($source) || is_link($source);
        $pendingExists = file_exists($pending) || is_link($pending);
        if (($sourceExists && (!is_file($source) || is_link($source)))
            || ($pendingExists && (!is_file($pending) || is_link($pending)))
            || ($sourceExists && $pendingExists)) {
            return false;
        }
        if ($pendingExists || !$sourceExists) {
            return true;
        }
        $directory = dirname($pending);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)
            && !is_dir($directory)) {
            return false;
        }
        if (!rename($source, $pending)) {
            return false;
        }
        chmod($pending, 0600);
        return true;
    }


    /**
     * Remove a pending take file after its recording row is gone.
     *
     * @param string $key Random database storage key.
     * @return bool True when absent or successfully removed.
     */
    public function deletePending(string $key): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            return false;
        }
        $path = $this->pendingPath($key);
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        return !is_link($path) && is_file($path) && unlink($path);
    }

    /**
     * Read up to a bounded batch of valid pending-delete storage keys.
     *
     * @param int $limit Maximum number of keys to return.
     * @return list<string> Pending storage keys in directory iteration order.
     */
    public function pendingKeys(int $limit): array
    {
        $keys = [];
        if ($limit < 1) {
            return $keys;
        }
        $directory = dirname($this->pendingPath(str_repeat('0', 64)));
        if (!is_dir($directory)) {
            return $keys;
        }
        $entries = new \DirectoryIterator($directory);
        foreach ($entries as $entry) {
            if ($entry->isFile() && !$entry->isLink()
                && preg_match('/\A[a-f0-9]{64}\z/D', $entry->getFilename()) === 1) {
                $keys[] = $entry->getFilename();
                if (count($keys) >= $limit) {
                    break;
                }
            }
        }
        return $keys;
    }

    /**
     * Open an authorized recording for streaming, including rollback recovery.
     *
     * @param string $key Random database storage key.
     * @return resource|false Open binary handle or false when unavailable.
     */
    public function open(string $key)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            return false;
        }
        $stream = @fopen($this->path($key), 'rb');
        if (is_resource($stream)) {
            return $stream;
        }
        return @fopen($this->pendingPath($key), 'rb');
    }

    /**
     * Resolve a validated key beneath the pending-delete directory.
     *
     * @param string $key Validated random key.
     * @return string Absolute private pending path.
     */
    private function pendingPath(string $key): string
    {
        return rtrim($this->storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR
            . 'pending' . DIRECTORY_SEPARATOR . $key;
    }

    /**
     * Resolve a validated key beneath the private recordings directory.
     *
     * @param string $key Validated random key.
     * @return string Absolute private path.
     */
    private function path(string $key): string
    {
        return rtrim($this->storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . $key;
    }
}
