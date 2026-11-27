<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/ScriptImport.php
 * Module Type: Service
 * Purpose: Validate and decode imported script JSON.
 * Responsibilities: Enforce import limits and return ordered validated chapters.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Validates the v1 JSON script import format. */
final class ScriptImport
{
    /**
     * Type: int; Units: bytes; Scope: script-import request; Consumers:
     * validate(); Rationale: bounds memory spent decoding user supplied JSON.
     */
    public const MAX_BYTES = 2097152;

    /**
     * Type: int; Units: JSON nesting levels; Scope: one import; Consumers:
     * validate(); Rationale: prevents excessive decoder recursion.
     */
    private const MAX_JSON_DEPTH = 32;

    /**
     * Type: int; Units: chapters; Scope: one imported script; Consumers:
     * validate(); Rationale: bounds project creation work.
     */
    private const MAX_CHAPTERS = 200;

    /**
     * Type: int; Units: lines; Scope: one imported script; Consumers:
     * validate(); Rationale: bounds rows and resulting studio rendering.
     */
    private const MAX_LINES = 1000;

    /**
     * Type: int; Units: UTF-8 characters; Scope: each title; Consumers:
     * validate(); Rationale: keeps display titles readable and bounded.
     */
    private const MAX_TITLE_LENGTH = 200;

    /**
     * Type: int; Units: UTF-8 characters; Scope: each character label;
     * Consumers: validate(); Rationale: bounds speaker names.
     */
    private const MAX_CHARACTER_LENGTH = 100;

    /**
     * Type: int; Units: UTF-8 characters; Scope: each line text; Consumers:
     * validate(); Rationale: prevents oversized individual utterances.
     */
    private const MAX_TEXT_LENGTH = 10000;

    /**
     * Validate and decode a v1 script payload.
     *
     * @param string $json Raw JSON bytes supplied by the project author.
     * @return array{title:string,chapters:list<array{title:string,lines:list<array{character:string,text:string}>}>}
     *     Normalized, ordered script data.
     * @throws FormError When JSON, structure, or configured limits are invalid.
     */
    public function validate(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new FormError('Scénář je příliš velký (nejvýše 2 MiB).');
        }

        try {
            $data = json_decode($json, false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FormError('Scénář není platný JSON.');
        }

        if (!($data instanceof \stdClass) || ($data->version ?? null) !== 1) {
            throw new FormError('Scénář musí mít verzi 1.');
        }

        $title = $this->text($data->title ?? null, 'Název knihy', self::MAX_TITLE_LENGTH);
        $chapters = $data->chapters ?? null;
        if (!is_array($chapters) || !array_is_list($chapters) || $chapters === []) {
            throw new FormError('Kapitoly: očekává se neprázdné pole.');
        }
        if (count($chapters) > self::MAX_CHAPTERS) {
            throw new FormError('Kapitoly: příliš mnoho položek.');
        }

        $result = [];
        $lineCount = 0;
        foreach ($chapters as $chapterIndex => $chapter) {
            $location = 'Kapitola ' . ($chapterIndex + 1);
            if (!($chapter instanceof \stdClass)) {
                throw new FormError($location . ': očekává se objekt.');
            }
            $chapterTitle = $this->text(
                $chapter->title ?? null,
                $location . ', název',
                self::MAX_TITLE_LENGTH
            );
            $lines = $chapter->lines ?? null;
            if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
                throw new FormError($location . ': očekává se neprázdné pole replik.');
            }
            $normalizedLines = [];
            foreach ($lines as $lineIndex => $line) {
                ++$lineCount;
                $lineLocation = $location . ', replika ' . ($lineIndex + 1);
                if ($lineCount > self::MAX_LINES) {
                    throw new FormError($lineLocation . ': scénář překračuje limit 1000 replik.');
                }
                if (!($line instanceof \stdClass)) {
                    throw new FormError($lineLocation . ': očekává se objekt.');
                }
                $normalizedLines[] = [
                    'character' => $this->text(
                        $line->character ?? null,
                        $lineLocation . ', postava',
                        self::MAX_CHARACTER_LENGTH
                    ),
                    'text' => $this->text(
                        $line->text ?? null,
                        $lineLocation . ', text',
                        self::MAX_TEXT_LENGTH
                    ),
                ];
            }
            $result[] = ['title' => $chapterTitle, 'lines' => $normalizedLines];
        }

        return ['title' => $title, 'chapters' => $result];
    }

    /**
     * Normalize a required nonempty text field.
     *
     * @param null|bool|int|float|string|list<mixed>|\stdClass $value Candidate value decoded from JSON.
     * @param string $location Human-readable field location.
     * @param int $maximumLength Maximum accepted Unicode character count.
     * @return string Trimmed validated text.
     * @throws FormError When the value is not bounded nonempty text.
     */
    private function text(mixed $value, string $location, int $maximumLength): string
    {
        if (!is_string($value)) {
            throw new FormError($location . ': očekává se text.');
        }
        $text = trim($value);
        if ($text === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1) {
            throw new FormError($location . ': text nesmí být prázdný.');
        }
        $length = preg_match_all('/./us', $text);
        if ($length === false || $length > $maximumLength) {
            throw new FormError($location . ': text je příliš dlouhý.');
        }
        return $text;
    }
}