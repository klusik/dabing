<?php

declare(strict_types=1);

use Dabing\Http;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/users.php
 * Module Type: View
 * Purpose: Present a compact administrator account and role overview.
 * Responsibilities: Show account status, book roles and anchored actions.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Prepare rows for the user administration table. */
$users = is_array($data['users'] ?? null) ? $data['users'] : [];
$projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];
?>
<section class="users-heading"><h1>Uživatelé</h1></section>
<?php foreach (($data['errors'] ?? []) as $error): ?>
    <p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p>
<?php endforeach; ?>
<div class="users-table-scroll">
    <table class="users-table">
        <thead>
            <tr><th scope="col">Účet</th><th scope="col">Stav</th><th scope="col">Knihy a obsazení</th><th scope="col"><span class="sr-only">Akce</span></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $account): ?>
            <?php
            $id = (int) $account['id'];
            $isCurrentUser = $id === (int) ($user['id'] ?? 0);
            $panelKey = 'user-' . $id;
            ?>
            <tr>
                <th scope="row" class="users-account">
                    <strong><?= Http::escape((string) $account['name']) ?><?= !empty($account['is_admin']) ? ' <span class="users-admin-mark">správce</span>' : '' ?></strong>
                    <span><?= Http::escape((string) $account['email']) ?></span>
                </th>
                <td class="users-status-cell">
                    <span class="users-status<?= !empty($account['is_active']) ? ' is-active' : ' is-inactive' ?>">
                        <?= !empty($account['is_active']) ? 'Aktivní' : 'Deaktivovaný' ?>
                    </span>
                </td>
                <td class="users-books-cell">
                    <?php if (($account['books'] ?? []) === []): ?>
                        <span class="users-no-books">Bez přiřazených postav</span>
                    <?php else: ?>
                        <?php foreach ($account['books'] as $book): ?>
                            <span class="users-book-line"><strong><?= Http::escape((string) $book['title']) ?></strong><span><?= Http::escape(implode(', ', $book['roles'])) ?></span></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td class="users-actions-cell">
                    <div class="users-actions" aria-label="Akce pro <?= Http::escape((string) $account['email']) ?>">
                        <div class="anchored-control">
                            <button class="icon-control users-action" type="button" data-panel-trigger="<?= Http::escape($panelKey) ?>-cast" aria-controls="<?= Http::escape($panelKey) ?>-cast" aria-expanded="false" title="Upravit obsazení knihy pro <?= Http::escape((string) $account['email']) ?>" aria-label="Upravit obsazení knihy pro <?= Http::escape((string) $account['email']) ?>"><span aria-hidden="true">♫</span></button>
                            <div class="popover users-popover" id="<?= Http::escape($panelKey) ?>-cast" data-panel hidden>
                                <h2>Obsazení · <?= Http::escape((string) $account['email']) ?></h2>
                                <?php if ($projects === []): ?>
                                    <p class="users-empty-note">Zatím nejsou vytvořené žádné knihy.</p>
                                <?php else: ?>
                                    <div class="users-popover-scroll">
                                        <?php foreach ($projects as $project): ?>
                                            <?php
                                            $assigned = [];
                                            foreach ($account['books'] as $book) {
                                                if ((int) $book['id'] === (int) $project['id']) {
                                                    $assigned = $book['roles'];
                                                    break;
                                                }
                                            }
                                            ?>
                                            <form method="post" action="<?= Http::escape(Http::url('users')) ?>" class="users-role-form">
                                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                <input type="hidden" name="action" value="roles">
                                                <input type="hidden" name="user_id" value="<?= $id ?>">
                                                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                                <fieldset>
                                                    <legend><?= Http::escape((string) $project['title']) ?></legend>
                                                    <?php foreach ($project['roles'] as $role): ?>
                                                        <label><input type="checkbox" name="roles[]" value="<?= Http::escape($role) ?>"<?= in_array($role, $assigned, true) ? ' checked' : '' ?>> <?= Http::escape($role) ?></label>
                                                    <?php endforeach; ?>
                                                    <input type="hidden" name="roles_complete" value="1">
                                                </fieldset>
                                                <button class="users-panel-submit" type="submit">Uložit obsazení</button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($isCurrentUser): ?>
                            <span class="icon-control users-action is-disabled" title="Vlastní účet nelze deaktivovat" aria-label="Vlastní účet nelze deaktivovat" aria-disabled="true"><span aria-hidden="true">⏻</span></span>
                        <?php else: ?>
                            <form method="post" action="<?= Http::escape(Http::url('users')) ?>" class="users-icon-form">
                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                <input type="hidden" name="user_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="<?= !empty($account['is_active']) ? 'inactive' : 'active' ?>">
                                <button class="icon-control users-action" type="submit" title="<?= !empty($account['is_active']) ? 'Deaktivovat účet ' : 'Znovu aktivovat účet ' ?><?= Http::escape((string) $account['email']) ?>" aria-label="<?= !empty($account['is_active']) ? 'Deaktivovat účet ' : 'Znovu aktivovat účet ' ?><?= Http::escape((string) $account['email']) ?>"><span aria-hidden="true">⏻</span></button>
                            </form>
                        <?php endif; ?>

                        <div class="anchored-control">
                            <button class="icon-control users-action users-password-trigger" type="button" data-panel-trigger="<?= Http::escape($panelKey) ?>-password" aria-controls="<?= Http::escape($panelKey) ?>-password" aria-expanded="false" title="Resetovat heslo pro <?= Http::escape((string) $account['email']) ?>" aria-label="Resetovat heslo pro <?= Http::escape((string) $account['email']) ?>"><span aria-hidden="true">⚿</span></button>
                            <div class="popover users-popover users-password-popover" id="<?= Http::escape($panelKey) ?>-password" data-panel hidden>
                                <h2>Nové heslo</h2>
                                <form method="post" action="<?= Http::escape(Http::url('users')) ?>" class="users-password-form">
                                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                    <input type="hidden" name="user_id" value="<?= $id ?>">
                                    <input type="hidden" name="action" value="password">
                                    <label>Nové heslo <input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
                                    <label>Potvrzení <input type="password" name="password_confirm" minlength="12" required autocomplete="new-password"></label>
                                    <button class="users-panel-submit" type="submit">Nastavit heslo</button>
                                </form>
                            </div>
                        </div>

                        <?php if (!$isCurrentUser): ?>
                            <div class="anchored-control">
                                <button class="icon-control users-action users-delete-trigger" type="button" data-panel-trigger="<?= Http::escape($panelKey) ?>-delete" aria-controls="<?= Http::escape($panelKey) ?>-delete" aria-expanded="false" title="Odstranit účet <?= Http::escape((string) $account['email']) ?>" aria-label="Odstranit účet <?= Http::escape((string) $account['email']) ?>"><span class="trash-icon" aria-hidden="true"></span></button>
                                <div class="popover users-popover users-delete-popover" id="<?= Http::escape($panelKey) ?>-delete" data-panel hidden>
                                    <h2>Odstranit účet?</h2>
                                    <p><?= Http::escape((string) $account['email']) ?> bude trvale odstraněn. Knihy ani soubory nahrávek se nemažou.</p>
                                    <form method="post" action="<?= Http::escape(Http::url('users')) ?>" class="users-delete-form">
                                        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                        <input type="hidden" name="user_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <label><input type="checkbox" name="confirm_delete" value="yes" required> Potvrzuji odstranění</label>
                                        <button class="icon-control users-action users-delete-confirm" type="submit" title="Potvrdit odstranění účtu" aria-label="Potvrdit odstranění účtu"><span class="trash-icon" aria-hidden="true"></span></button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
