<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/AudioProfileService.php
 * Module Type: Service
 * Purpose: Validate profile chains and coordinate profile persistence.
 * Responsibilities: Enforce canonical module settings and profile naming rules.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Coordinates validation and persistence for reusable audio profiles. */
final class AudioProfileService
{
    /** Allowed module identifiers in canonical order-preserving chains.
     * @var list<string>
     */
    private const MODULES = ['eq', 'compressor', 'normalize', 'reverb'];

    /** Type: list<int>; Units: hertz; Scope: EQ centers; Consumers: defaultChain(), validateChain();
     * Rationale: six voice bands cover warmth through air.
     */
    private const EQ_CENTERS_HZ = [80, 200, 500, 1250, 3150, 8000];

    /** Type: float; Units: hertz; Scope: EQ frequency; Consumers: validateChain();
     * Rationale: requested 20 Hz to 20 kHz frequency bounds.
     */
    private const EQ_MIN_HZ = 20;
    private const EQ_MAX_HZ = 20000;

    /** Type: float; Units: dB; Scope: EQ gain; Consumers: validateChain();
     * Rationale: requested ±18 dB adjustment limit.
     */
    private const EQ_GAIN_LIMIT_DB = 18;

    /** Type: float; Units: Q; Scope: EQ bandwidth; Consumers: validateChain();
     * Rationale: requested 0.2–8 band sharpness.
     */
    private const EQ_Q_MIN = 0.2;
    private const EQ_Q_MAX = 8;

    /** Type: float; Units: dB; Scope: compressor threshold; Consumers: validateChain();
     * Rationale: requested -60–0 dB range.
     */
    private const COMPRESSOR_THRESHOLD_MIN_DB = -60;
    private const COMPRESSOR_THRESHOLD_MAX_DB = 0;

    /** Type: float; Units: ratio; Scope: compressor ratio; Consumers: validateChain();
     * Rationale: requested 1:1–20:1 range.
     */
    private const COMPRESSOR_RATIO_MIN = 1;
    private const COMPRESSOR_RATIO_MAX = 20;

    /** Type: float; Units: dB; Scope: compressor makeup; Consumers: validateChain();
     * Rationale: requested 0–24 dB range.
     */
    private const COMPRESSOR_GAIN_MAX_DB = 24;

    /** Type: float; Units: dBFS; Scope: normalization; Consumers: validateChain();
     * Rationale: requested -24–0 dBFS target range.
     */
    private const NORMALIZE_MIN_DBFS = -24;
    private const NORMALIZE_MAX_DBFS = 0;

    /** Type: float; Units: percent; Scope: reverb mix; Consumers: validateChain();
     * Rationale: requested dry-to-wet 0–100% range.
     */
    private const REVERB_MIX_MAX_PERCENT = 100;

    /** Type: float; Units: seconds; Scope: reverb decay; Consumers: validateChain();
     * Rationale: requested 0.1–3 second range.
     */
    private const REVERB_DECAY_MIN_SECONDS = 0.1;
    private const REVERB_DECAY_MAX_SECONDS = 3;

    /** Type: int; Units: characters; Scope: profile names; Consumers: save();
     * Rationale: match the database name column's 120 character limit.
     */
    private const PROFILE_NAME_MAX_CHARS = 120;

    /** Build the audio profile service.
     * @param AudioProfileModel $model Profile persistence model.
     * @return void No value is returned.
     */
    public function __construct(private readonly AudioProfileModel $model)
    {
    }

    /** Return the standard bypassed chain for a new profile.
     * @return list<array{type:string,enabled:bool,settings:array<string,mixed>}> Defaults.
     */
    public function defaultChain(): array
    {
        $centers = self::EQ_CENTERS_HZ;
        $bands = [];
        foreach ($centers as $index => $frequency) {
            $bands[] = [
                'frequency' => $frequency, 'gain' => 0, 'q' => 1,
                'cutoff' => false,
            ];
        }
        return [
            ['type' => 'eq', 'enabled' => false, 'settings' => ['bands' => $bands]],
            ['type' => 'compressor', 'enabled' => false, 'settings' => [
                'threshold' => -18, 'ratio' => 3, 'gain' => 0,
            ]],
            ['type' => 'normalize', 'enabled' => false, 'settings' => ['target' => -3]],
            ['type' => 'reverb', 'enabled' => false, 'settings' => [
                'mix' => 0, 'decay' => 1,
            ]],
        ];
    }

    /** Validate and canonicalize an ordered module chain.
     * @param mixed $value Decoded untrusted JSON value.
     * @return list<array{type:string,enabled:bool,settings:array<string,mixed>}> Valid chain.
     * @throws FormError When a module or setting is malformed or out of range.
     */
    public function validateChain(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 4) {
            throw new FormError('Řetězec zvukových modulů není platný.');
        }
        $result = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || !in_array($item['type'] ?? null, self::MODULES, true)
                || !is_bool($item['enabled'] ?? null) || !is_array($item['settings'] ?? null)) {
                throw new FormError('Řetězec zvukových modulů není platný.');
            }
            $type = $item['type'];
            if (isset($seen[$type])) {
                throw new FormError('Zvukový modul je v řetězci vícekrát.');
            }
            $seen[$type] = true;
            $settings = $item['settings'];
            if ($type === 'eq') {
                $bands = $settings['bands'] ?? null;
                if (!is_array($bands) || count($bands) !== count(self::EQ_CENTERS_HZ)) {
                    throw new FormError('Ekvalizér musí obsahovat šest pásem.');
                }
                $clean = [];
                foreach (array_values($bands) as $index => $band) {
                    if (!is_array($band)
                        || !$this->numberIn($band['frequency'] ?? null, self::EQ_MIN_HZ, self::EQ_MAX_HZ)
                        || !$this->numberIn($band['gain'] ?? null, -self::EQ_GAIN_LIMIT_DB, self::EQ_GAIN_LIMIT_DB)
                        || !$this->numberIn($band['q'] ?? null, self::EQ_Q_MIN, self::EQ_Q_MAX)
                        || !is_bool($band['cutoff'] ?? null)
                        || (in_array($index, [0, 5], true) === false
                            && $band['cutoff'] !== false)) {
                        throw new FormError('Nastavení ekvalizéru není platné.');
                    }
                    $clean[] = [
                        'frequency' => $band['frequency'], 'gain' => $band['gain'],
                        'q' => $band['q'], 'cutoff' => $band['cutoff'],
                    ];
                }
                $settings = ['bands' => $clean];
            } elseif ($type === 'compressor') {
                if (!$this->numberIn($settings['threshold'] ?? null, self::COMPRESSOR_THRESHOLD_MIN_DB, self::COMPRESSOR_THRESHOLD_MAX_DB)
                    || !$this->numberIn($settings['ratio'] ?? null, self::COMPRESSOR_RATIO_MIN, self::COMPRESSOR_RATIO_MAX)
                    || !$this->numberIn($settings['gain'] ?? null, 0, self::COMPRESSOR_GAIN_MAX_DB)) {
                    throw new FormError('Nastavení kompresoru není platné.');
                }
                $settings = ['threshold' => $settings['threshold'],
                    'ratio' => $settings['ratio'], 'gain' => $settings['gain']];
            } elseif ($type === 'normalize') {
                if (!$this->numberIn($settings['target'] ?? null, self::NORMALIZE_MIN_DBFS, self::NORMALIZE_MAX_DBFS)) {
                    throw new FormError('Cílová úroveň není platná.');
                }
                $settings = ['target' => $settings['target']];
            } else {
                if (!$this->numberIn($settings['mix'] ?? null, 0, self::REVERB_MIX_MAX_PERCENT)
                    || !$this->numberIn($settings['decay'] ?? null, self::REVERB_DECAY_MIN_SECONDS, self::REVERB_DECAY_MAX_SECONDS)) {
                    throw new FormError('Nastavení dozvuku není platné.');
                }
                $settings = ['mix' => $settings['mix'], 'decay' => $settings['decay']];
            }
            $result[] = ['type' => $type, 'enabled' => $item['enabled'], 'settings' => $settings];
        }
        foreach (self::MODULES as $module) {
            if (!isset($seen[$module])) {
                throw new FormError('Chybí zvukový modul.');
            }
        }
        return $result;
    }

    /** Check whether a numeric value is finite and inside its inclusive limits.
     * @param mixed $value Submitted value.
     * @param float $minimum Inclusive minimum.
     * @param float $maximum Inclusive maximum.
     * @return bool True when valid.
     */
    private function numberIn(mixed $value, float $minimum, float $maximum): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= $minimum && $value <= $maximum;
    }

    /** Persist a validated profile.
     * @param int|null $id Existing profile identifier.
     * @param string $name Submitted display name.
     * @param mixed $chain Decoded submitted chain.
     * @return int Saved profile identifier.
     * @throws FormError When the name is invalid.
     */
    public function save(?int $id, string $name, mixed $chain): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::PROFILE_NAME_MAX_CHARS) {
            throw new FormError('Název profilu musí mít 1 až 120 znaků.');
        }
        $validated = $this->validateChain($chain);
        return $this->model->save(
            $id, $name,
            json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
