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
        if ($mime !== 'audio/mpeg'
            || !$this->hasValidMp3Frame($file['tmp_name'], $actualSize)) {
            throw new FormError('Nový pokus musí být platná nahrávka MP3.');
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
