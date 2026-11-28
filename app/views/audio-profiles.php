<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/audio-profiles.php
 * Module Type: View
 * Purpose: Present the standalone audio profile editor.
 * Responsibilities: Render profile selection, ordered effects and local preview.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
$profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
$profile = is_array($data['profile'] ?? null) ? $data['profile'] : null;
$defaultChain = is_array($data['default_chain'] ?? null) ? $data['default_chain'] : [];
$sample = is_array($data['sample'] ?? null) ? $data['sample'] : null;
$chain = $profile !== null && is_array($profile['chain'] ?? null)
    ? $profile['chain']
    : $defaultChain;
$submittedJson = is_string($data["submitted_chain_json"] ?? null)
    ? (string) $data["submitted_chain_json"] : "";
$submittedChain = $submittedJson !== "" ? json_decode($submittedJson, true) : null;
$chain = is_array($submittedChain) ? $submittedChain : $chain;
$chainJson = json_encode($chain, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<section class="profile-editor" data-profile-editor
    data-chain="<?= Http::escape(is_string($chainJson) ? $chainJson : '[]') ?>"
    data-sample-url="<?= Http::escape((string) ($sample['url'] ?? '')) ?>">
    <header class="profile-head">
        <a class="back-link" href="<?= Http::escape(Http::url('projects')) ?>" aria-label="Zpět na knihy">←</a>
        <h1>Zvukové profily</h1>

    </header>
    <div class="profile-toolbar">
        <label class="profile-select-label">Profil
            <select data-profile-select aria-label="Vybrat profil">
                <?php foreach ($profiles as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"<?= $profile !== null && (int) $profile['id'] === (int) $item['id'] ? ' selected' : '' ?>><?= Http::escape((string) $item['name']) ?></option>
                <?php endforeach; ?>
                <option value=""<?= $profile === null ? " selected" : "" ?>>Nový profil</option>
            </select>
        </label>
        <button class="profile-button" type="button" data-new-profile>＋ Nový</button>
        <span class="profile-toolbar-spacer"></span>
        <label class="file-button" title="Načíst místní zvukový soubor">↥ Soubor
            <input type="file" accept="audio/*" data-local-audio aria-label="Načíst zvukový soubor">
        </label>
        <span class="profile-file-name" data-file-name><?= Http::escape((string) ($sample['label'] ?? 'Bez ukázky')) ?></span>
    </div>
    <div class="mixer-grid">
        <section class="mixer-channel">
            <h2><span class="channel-led"></span>Řetězec efektů</h2>
            <ol class="effect-list" data-effect-list aria-label="Pořadí efektů"></ol>
            <div class="effect-editor" data-effect-editor aria-live="polite"></div>
        </section>
        <section class="mixer-channel eq-channel">
            <h2><span class="channel-led channel-led-lime"></span>Šestipásmový EQ</h2>
            <canvas class="eq-graph" data-eq-graph aria-label="Frekvenční odezva ekvalizéru"></canvas>
            <div class="eq-bands" data-eq-bands></div>
            <div class="eq-edges">
                <label class="eq-cutoff"><input type="checkbox" data-edge="highpass"><span>High-pass</span></label>
                <label class="eq-cutoff"><input type="checkbox" data-edge="lowpass"><span>Low-pass</span></label>
            </div>
        </section>
        <section class="mixer-channel monitor-channel">
            <h2><span class="channel-led channel-led-coral"></span>Monitor</h2>
            <div class="monitor-top">
                <button class="profile-button profile-play" type="button" data-play disabled>▶ Poslech</button>
                <button class="profile-button" type="button" data-play-original disabled>◉ Originál</button>
            </div>
            <canvas class="spectrum-graph" data-spectrum aria-label="Spektrum přehrávaného zvuku"></canvas>
            <div class="smoothing-control"><span>Vyhlazení</span><button class="eq-knob" type="button" data-smoothing aria-label="Vyhlazení spektra">8</button><output data-smoothing-value>8</output></div>
            <audio controls preload="none" data-preview-player aria-label="Přehrávač ukázky"></audio>
            <p class="editor-status" data-editor-status aria-live="polite">Vyberte soubor nebo ukázku uložené nahrávky.</p>
        </section>
    </div>
    <form class="profile-save" method="post" action="<?= Http::escape(Http::url('audio-profiles')) ?>">
        <?php if (is_string($data["form_error"] ?? null) && $data["form_error"] !== ""): ?><p class="profile-form-error" role="alert"><?= Http::escape($data["form_error"]) ?></p><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="profile_id" value="<?= Http::escape((string) ($data["profile_id"] ?? $profile["id"] ?? "")) ?>" data-profile-id>
        <input type="hidden" name="sample" value="<?= Http::escape((string) ($sample['id'] ?? '')) ?>">
        <input type="hidden" name="chain" value="<?= Http::escape(is_string($chainJson) ? $chainJson : '[]') ?>" data-chain-input>
        <label for="profile-name">Název profilu</label>
        <input id="profile-name" name="name" maxlength="120" required value="<?= Http::escape((string) ($data["submitted_name"] ?? $profile["name"] ?? "")) ?>" placeholder="Např. Teplý hlas">
        <button class="profile-button profile-save-button" type="submit">Uložit profil</button>
    </form>
</section>
