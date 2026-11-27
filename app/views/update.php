<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/update.php
 * Module Type: View
 * Purpose: Present application version information and an administrator-confirmed update action.
 * Responsibilities: Display safe release metadata and collect optional migration database credentials.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
$currentVersion = (string) ($data['current_version'] ?? '0.0.0');
$release = is_array($data['latest_release'] ?? null)
    ? $data['latest_release']
    : null;
$releaseVersion = is_array($release) ? (string) ($release['version'] ?? '') : '';
$releaseHash = is_array($release) ? (string) ($release['sha256'] ?? '') : '';
$releaseSize = is_array($release) ? (int) ($release['size'] ?? 0) : 0;
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
$hasRelease = $release !== null;
?>
<section class="auth-layout auth-centered update-layout">
    <div class="auth-card update-card">
        <h1>Aktualizace</h1>
        <p class="update-current">Nainstalovaná verze <strong><?= Http::escape($currentVersion) ?></strong></p>
        <?php foreach ($errors as $error): ?>
            <p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p>
        <?php endforeach; ?>
        <?php if ($hasRelease): ?>
            <div class="update-release">
                <span class="update-release-label">Dostupná verze</span>
                <strong><?= Http::escape($releaseVersion) ?></strong>
                <?php if ($releaseSize > 0): ?>
                    <span class="update-size"><?= Http::escape(number_format($releaseSize, 0, ',', ' ')) ?> B</span>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= Http::escape(Http::url('update')) ?>" class="stack-form update-form">
                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                <input type="hidden" name="version" value="<?= Http::escape($releaseVersion) ?>">
                <input type="hidden" name="sha256" value="<?= Http::escape($releaseHash) ?>">
                <details class="update-db-details">
                    <summary>Databázová oprávnění (volitelné)</summary>
                    <p class="update-db-help">Vyplňte jen při změně schématu; účet musí mít právo měnit strukturu databáze.</p>
                    <label>DB uživatel
                        <input name="db_user" autocomplete="username">
                    </label>
                    <label>DB heslo
                        <input name="db_password" type="password" autocomplete="new-password">
                    </label>
                </details>
                <button class="button button-ink button-wide" type="submit">
                    Aktualizovat na <?= Http::escape($releaseVersion) ?>
                </button>
            </form>
        <?php elseif ($release === null && $errors === []): ?>
            <p class="muted update-empty">Není dostupná novější verze.</p>
        <?php endif; ?>
    </div>
</section>