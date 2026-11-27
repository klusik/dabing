<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/account.php
 * Module Type: View
 * Purpose: Let a signed-in user change their account password.
 * Responsibilities: Show account email and collect current and replacement passwords.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="auth-layout auth-centered">
    <div class="auth-card account-card">
        <div class="card-kicker">Účet</div>
        <h1>Nastavení účtu</h1>
        <p class="account-email"><?= Http::escape((string) ($user['email'] ?? '')) ?></p>
        <form method="post" action="<?= Http::escape(Http::url('account')) ?>" class="stack-form">
            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <label>Současné heslo <input name="current_password" type="password" autocomplete="current-password" required></label>
            <label>Nové heslo <input name="password" type="password" autocomplete="new-password" required></label>
            <label>Potvrzení nového hesla <input name="password_confirm" type="password" autocomplete="new-password" required></label>
            <button class="button button-ink button-wide" type="submit">Změnit heslo</button>
        </form>
    </div>
</section>