<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/login.php
 * Module Type: View
 * Purpose: Let an invited account enter a private studio.
 * Responsibilities: Present sign-in fields for existing accounts.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="auth-layout auth-centered">
    <div class="auth-card">
        <div class="card-kicker">Účet</div>
        <h2>Přihlášení</h2>
        <p class="muted">Zadejte e-mail a heslo.</p>
        <form method="post" action="<?= Http::escape(Http::url('login')) ?>" class="stack-form">
            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <label>E-mail <input name="email" type="email" autocomplete="username" value="<?= Http::escape((string) ($data['values']['email'] ?? '')) ?>" required autofocus></label>
            <label>Heslo <input name="password" type="password" autocomplete="current-password" required></label>
            <button class="icon-control auth-submit" type="submit" title="Přihlásit se" aria-label="Přihlásit se"><span aria-hidden="true">↪</span></button>
        </form>
    </div>
</section>
