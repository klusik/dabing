<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/RecordingStorage.php
 * Module Type: Service
 * Purpose: Safely stage and serve private audio recordings.
 * Responsibilities: Validate finite uploads, randomize keys, and stream byte ranges.
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


    /** @var list<string> Detected content formats accepted for audio. */
    private const ALLOWED_MIME_TYPES = [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'application/ogg',
        'audio/mp4',
        'video/mp4',
        'audio/x-m4a',
        'audio/wav',
        'audio/x-wav',
        'audio/vnd.wave',
    ];

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
     * @return array{key:string,mime:string,size:int} Safe staged-file metadata.
     * @throws FormError When upload or detected content is invalid.
     */
    public function stage(array $file): array
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
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new FormError('Podporujeme nahrávky WebM, Ogg, M4A/MP4 a WAV.');
        }
        $key = bin2hex(random_bytes(32));
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
     * Open an authorized recording for streaming.
     *
     * @param string $key Random database storage key.
     * @return resource|false Open binary handle or false when unavailable.
     */
    public function open(string $key)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            return false;
        }
        return fopen($this->path($key), 'rb');
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