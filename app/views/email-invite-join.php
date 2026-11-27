<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/email-invite-join.php
 * Module Type: View
 * Purpose: Let an invited email recipient join a private audiobook project.
 * Responsibilities: Display invite status and collect a new account or claim action.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
$inviteValid = (bool) ($data['invite_valid'] ?? false);
$existingAccount = (bool) ($data['existing_account'] ?? false);
$recipientEmail = (string) ($data['recipient_email'] ?? '');
$inviteCode = (string) ($data['code'] ?? '');
$wrongAccount = (bool) ($data['wrong_account'] ?? false);
?>
<section class="auth-layout auth-centered">
    <div class="auth-card invite-join-card">
        <div class="card-kicker">E-mailová pozvánka</div>
        <?php if (!$inviteValid): ?>
            <h1>Pozvánka není platná</h1>
            <p class="muted">Požádejte vlastníka knihy o novou pozvánku.</p>
        <?php else: ?>
            <h1>Vstup do knihy</h1>
            <?php if (!empty($data['project_title'])): ?><p class="invite-book-title"><?= Http::escape((string) $data['project_title']) ?></p><?php endif; ?>
            <?php if (!empty($data['roles'])): ?>
                <p class="muted">Přiřazené postavy: <?= Http::escape(implode(', ', $data['roles'])) ?></p>
            <?php endif; ?>
            <p class="invite-recipient"><?= Http::escape($recipientEmail) ?></p>
            <?php if ($wrongAccount): ?>
                <p class="form-error" role="alert">Přihlášený účet nemá pozvanou e-mailovou adresu. Odhlaste se a přihlaste se správným účtem.</p>
                <form method="post" action="?route=logout" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <button class="button button-ink button-wide" type="submit">Přepnout účet</button>
                </form>
            <?php endif; ?>
            <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
            <?php if ($existingAccount): ?>
                <?php if (!$wrongAccount): ?><form method="post" action="?route=email-join" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <input type="hidden" name="code" value="<?= Http::escape($inviteCode) ?>">
                    <button class="button button-ink button-wide" type="submit">Přijmout pozvánku</button>
                </form><?php endif; ?>
            <?php elseif (!$wrongAccount): ?>
                <form method="post" action="?route=email-join" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <input type="hidden" name="code" value="<?= Http::escape($inviteCode) ?>">
                    <label>E-mail <input value="<?= Http::escape($recipientEmail) ?>" readonly></label>
                    <label>Jméno <input name="name" autocomplete="name" required autofocus></label>
                    <label>Heslo <input name="password" type="password" autocomplete="new-password" required></label>
                    <label>Potvrzení hesla <input name="password_confirm" type="password" autocomplete="new-password" required></label>
                    <button class="button button-ink button-wide" type="submit">Vytvořit účet a přijmout</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
