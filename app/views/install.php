<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/install.php
 * Module Type: View
 * Purpose: Guide an operator through first-time database setup.
 * Responsibilities: Collect database credentials and first administrator details.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="auth-layout auth-centered">
    <div class="auth-card install-card">
        <h2>Nastavení instalace</h2>
        <form method="post" action="<?= Http::escape(Http::url('install')) ?>" class="stack-form">
            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <div class="form-divider"><span>Databáze</span></div>
            <div class="field-grid">
                <label>Server <input name="db_host" value="<?= Http::escape((string) ($data['values']['db_host'] ?? 'localhost')) ?>" autocomplete="off" required></label>
                <label>Port <input name="db_port" inputmode="numeric" value="<?= Http::escape((string) ($data['values']['db_port'] ?? '')) ?>" required></label>
            </div>
            <label>Název databáze <input name="db_name" value="<?= Http::escape((string) ($data['values']['db_name'] ?? '')) ?>" autocomplete="off" required></label>
            <div class="field-grid">
                <label>Uživatel <input name="db_user" value="<?= Http::escape((string) ($data['values']['db_user'] ?? '')) ?>" autocomplete="username" required></label>
                <label>Heslo <input name="db_password" type="password" autocomplete="new-password"></label>
            </div>
            <div class="form-divider"><span>První správce</span></div>
            <div class="field-grid">
                <label>Uživatelské jméno <input name="name" value="<?= Http::escape((string) ($data['values']['name'] ?? '')) ?>" autocomplete="username" required></label>
                <label>E-mail <input name="email" type="email" value="<?= Http::escape((string) ($data['values']['email'] ?? '')) ?>" autocomplete="email" required></label>
            </div>
            <label>Heslo <input name="password" type="password" autocomplete="new-password" required></label>
            <button class="button button-ink button-wide" type="submit">Dokončit nastavení</button>
        </form>
    </div>
</section>
