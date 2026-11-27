<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/register.php
 * Module Type: View
 * Purpose: Create an account for a private audiobook collaborator.
 * Responsibilities: Collect the member name, email and password.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="auth-layout auth-centered">
    <div class="auth-card">
        <div class="card-kicker">Údaje k účtu</div>
        <h2>Vytvořit účet</h2>
        <p class="muted">Vyplňte své jméno, e-mail a heslo.</p>
        <form method="post" action="<?= Http::escape(Http::url('register')) ?>" class="stack-form">
            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <label>Jméno <input name="name" autocomplete="name" value="<?= Http::escape((string) ($data['values']['name'] ?? '')) ?>" required autofocus></label>
            <label>E-mail <input name="email" type="email" autocomplete="email" value="<?= Http::escape((string) ($data['values']['email'] ?? '')) ?>" required></label>
            <label>Heslo <input name="password" type="password" autocomplete="new-password" required></label>
            <label>Potvrzení hesla <input name="password_confirm" type="password" autocomplete="new-password" required></label>
            <button class="button button-ink button-wide" type="submit">Vytvořit účet</button>
        </form>
        <p class="auth-switch">Účet už máte? <a href="<?= Http::escape(Http::url('login')) ?>">Přihlásit se</a></p>
    </div>
</section>