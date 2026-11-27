<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/install.php
 * Module Type: View
 * Purpose: Guide an operator through first-time database setup.
 * Responsibilities: Collect setup key, database credentials and first account.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="auth-layout auth-centered">
    <div class="auth-card install-card">
        <?php if (array_key_exists('keyReady', $data) && !$data['keyReady']): ?><div class="setup-hint" role="note">Instalační klíč zatím není připraven. Požádejte správce serveru o jeho nastavení a vraťte se sem.</div><?php endif; ?>
        <div class="card-kicker">Nastavení instalace</div>
        <h2>Databáze a účet</h2>
        <p class="muted">Hesla po odeslání znovu nezobrazujeme.</p>
        <form method="post" action="<?= Http::escape(Http::url('install')) ?>" class="stack-form">
            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <label>Instalační klíč <input name="install_key" type="password" autocomplete="off" required></label>
            <div class="form-divider"><span>Databáze</span></div>
            <label>Veřejná adresa (volitelné) <input name="base_url" type="url" value="<?= Http::escape((string) ($data['values']['base_url'] ?? '')) ?>" autocomplete="url"><small>Používá se pro odkazy a přesměrování. Ponechte prázdné, pokud adresu ještě neznáte.</small></label>
            <div class="field-grid">
                <label>Server <input name="db_host" value="<?= Http::escape((string) ($data['values']['db_host'] ?? 'localhost')) ?>" autocomplete="off" required></label>
                <label>Port <input name="db_port" inputmode="numeric" value="<?= Http::escape((string) ($data['values']['db_port'] ?? '3306')) ?>" required></label>
            </div>
            <label>Název databáze <input name="db_name" value="<?= Http::escape((string) ($data['values']['db_name'] ?? '')) ?>" autocomplete="off" required></label>
            <div class="field-grid">
                <label>Uživatel <input name="db_user" value="<?= Http::escape((string) ($data['values']['db_user'] ?? '')) ?>" autocomplete="username" required></label>
                <label>Heslo <input name="db_password" type="password" autocomplete="new-password"></label>
            </div>
            <details class="optional-setup"><summary>Volitelné přihlašovací údaje hostingu</summary><div class="field-grid"><label>Uživatel hostingu <input name="setup_user" autocomplete="off"></label><label>Heslo hostingu <input name="setup_password" type="password" autocomplete="new-password"></label></div><p>Vyplňte pouze tehdy, pokud to vyžaduje vaše instalace.</p></details>
            <div class="form-divider"><span>První účet</span></div>
            <label>Jméno <input name="name" value="<?= Http::escape((string) ($data['values']['name'] ?? '')) ?>" autocomplete="name" required></label>
            <div class="field-grid">
                <label>E-mail <input name="email" type="email" value="<?= Http::escape((string) ($data['values']['email'] ?? '')) ?>" autocomplete="email" required></label>
                <label>Heslo <input name="password" type="password" autocomplete="new-password" required></label>
            </div>
            <label>Potvrzení hesla <input name="password_confirm" type="password" autocomplete="new-password" required></label>
            <button class="button button-ink button-wide" type="submit">Dokončit nastavení</button>
        </form>
    </div>
</section>
