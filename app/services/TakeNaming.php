<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/TakeNaming.php
 * Module Type: Service
 * Purpose: Build safe filenames for numbered takes and book archives.
 * Responsibilities: Normalize Czech titles and name supported take and archive downloads.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Creates download filenames for take attempts. */
final class TakeNaming
{
    /**
     * Type: int; Units: attempts; Scope: one script line; Consumers: fileName();
     * Rationale: keeps the three-digit attempt suffix within its documented format.
     */
    public const MAX_ATTEMPTS = 999;

    /**
     * Type: int; Units: lines; Scope: one book; Consumers: fileName();
     * Rationale: reserves four digits for globally ordered script lines.
     */
    public const MAX_LINE_NUMBER = 9999;

    /**
     * Convert a title and positive ordinals into a safe download filename.
     *
     * @param string $title Book title used for the readable slug.
     * @param int $lineNumber One-based global script line number.
     * @param int $attemptNumber One-based line attempt number.
     * @param string $mediaType Server-detected MIME type for the stored audio.
     * @return string Safe lowercase ASCII filename with a format extension.
     * @throws FormError When numbering or media type is unsupported.
     */
    public static function fileName(
        string $title,
        int $lineNumber,
        int $attemptNumber,
        string $mediaType
    ): string {
        if ($lineNumber < 1 || $lineNumber > self::MAX_LINE_NUMBER
            || $attemptNumber < 1 || $attemptNumber > self::MAX_ATTEMPTS) {
            throw new FormError('Číslo repliky nebo pokusu je mimo povolený rozsah.');
        }
        $extensions = [
            'audio/mpeg' => 'mp3',
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'application/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'video/mp4' => 'mp4',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/vnd.wave' => 'wav',
        ];
        if (!isset($extensions[$mediaType])) {
            throw new FormError('Formát nahrávky nemá podporovanou příponu.');
        }
        $slug = self::titleSlug($title);
        return $slug . '_' . sprintf('%04d_%03d', $lineNumber, $attemptNumber)
            . '.' . $extensions[$mediaType];
    }

    /**
     * Create a safe archive filename for a book's saved takes.
     *
     * @param string $title Original book title.
     * @return string Lowercase ASCII filename ending in _nahravky.zip.
     */
    public static function archiveFileName(string $title): string
    {
        return self::titleSlug($title) . '_nahravky.zip';
    }

    /**
     * Convert a Czech title to a lowercase ASCII underscore slug.
     *
     * @param string $title Original book title.
     * @return string Safe slug, or the stable fallback when no ASCII letters remain.
     */
    private static function titleSlug(string $title): string
    {
        $ascii = strtr($title, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e',
            'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
            'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y',
            'ž' => 'z', 'Á' => 'a', 'Ä' => 'a', 'Č' => 'c', 'Ď' => 'd',
            'É' => 'e', 'Ě' => 'e', 'Í' => 'i', 'Ň' => 'n', 'Ó' => 'o',
            'Ř' => 'r', 'Š' => 's', 'Ť' => 't', 'Ú' => 'u', 'Ů' => 'u',
            'Ý' => 'y', 'Ž' => 'z',
        ]);
        $slug = strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        if (!is_string($slug)) {
            return 'kniha';
        }
        $slug = trim($slug, '_');
        return $slug === '' ? 'kniha' : $slug;
    }
}