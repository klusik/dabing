<?php

declare(strict_types=1);

use Dabing\Http;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/project-invitations.php
 * Module Type: View partial
 * Purpose: Let book owners manage addressed email invitations.
 * Responsibilities: Render role selection and invitation state actions.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Project invitation rows provided by the authorized owner view model. */
$emailInvites = $data['email_invites'] ?? [];
$inviteVoices = $data['voices'] ?? [];
$inviteUsers = $data['role_users'] ?? [];
$canChooseAccount = !empty($data['is_admin']);
$inviteProjectId = (int) ($project['id'] ?? 0);
?>
<div class="invite-email-tools">
    <form method="post" action="?route=email-invite&amp;id=<?= $inviteProjectId ?>" class="stack-form" data-project-async>
        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
        <input type="hidden" name="action" value="send">
        <?php if ($canChooseAccount): ?>
            <label>Účet příjemce
                <select name="recipient_user_id">
                    <option value="">Nová e-mailová pozvánka</option>
                    <?php foreach ($inviteUsers as $inviteUser): ?>
                        <option value="<?= (int) $inviteUser['id'] ?>">
                            <?= Http::escape((string) $inviteUser['name']) ?> ·
                            <?= Http::escape((string) $inviteUser['email']) ?><?= empty($inviteUser['is_active']) ? ' · neaktivní' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>Nový e-mail (nechte prázdný pro vybraný účet)
            <input type="email" name="email" autocomplete="email" maxlength="254">
        </label>
        <?php if ($inviteVoices !== []): ?>
            <fieldset class="invite-role-list">
                <legend>Přiřadit postavy (volitelné)</legend>
                <?php foreach ($inviteVoices as $voice): ?>
                    <?php $character = (string) ($voice['character'] ?? ''); ?>
                    <label class="invite-role-option">
                        <input type="checkbox" name="roles[]" value="<?= Http::escape($character) ?>">
                        <span><?= Http::escape($character) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <input type="hidden" name="roles_end" value="1">
        <button class="button button-ink" type="submit">Odeslat</button>
    </form>
</div>
<?php if ($emailInvites !== []): ?>
    <div class="invite-email-list" aria-label="E-mailové pozvánky">
        <?php foreach ($emailInvites as $invite): ?>
            <?php $inviteStateLabels = [
                'pending' => 'Odesílá se', 'sent' => 'Odesláno',
                'claimed' => 'Přijato', 'failed' => 'Chyba doručení',
                'expired' => 'Vypršelo', 'cancelled' => 'Zrušeno',
            ]; ?>
            <article class="invite-email-row">
                <div class="invite-email-details">
                    <strong><?= Http::escape((string) $invite['email']) ?></strong>
                    <span class="muted">
                        <?= Http::escape($inviteStateLabels[$invite['state']] ?? 'Neznámý stav') ?>
                        · <?= Http::escape((string) $invite['created_at_local']) ?>
                        <?php if ($invite['expires_at_local'] !== null): ?>
                            · do <?= Http::escape((string) $invite['expires_at_local']) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($invite['roles'] !== []): ?>
                        <span><?= Http::escape(implode(', ', $invite['roles'])) ?></span>
                    <?php endif; ?>
                    <?php if ($invite['delivery_error'] !== null): ?>
                        <span class="form-error" role="status"><?= Http::escape((string) $invite['delivery_error']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (in_array($invite['state'], ['sent', 'pending', 'failed', 'expired'], true)): ?>
                    <div class="invite-email-actions">
                        <form method="post" action="?route=email-invite&amp;id=<?= $inviteProjectId ?>" data-project-async>
                            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                            <button class="button button-quiet" type="submit">Poslat znovu</button>
                        </form>
                        <?php if (in_array($invite['state'], ['sent', 'pending'], true)): ?>
                            <form method="post" action="?route=email-invite&amp;id=<?= $inviteProjectId ?>" data-project-async>
                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                                <button class="button button-quiet" type="submit">Zrušit</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
