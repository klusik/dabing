<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/join.php
 * Module Type: View
 * Purpose: Let an invited collaborator join a private audiobook project.
 * Responsibilities: Explain the invitation and submit its token securely.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="join-layout">
    <div class="join-mark" aria-hidden="true"><span class="brand-mark"><i></i><i></i><i></i><i></i></span></div>
    <p class="eyebrow"><span class="eyebrow-dot"></span> Pozvánka do studia</p>
    <h1>Přijmout pozvánku</h1>
    <p class="lede">Po přijetí získáte přístup k soukromému projektu.</p>
    <?php if (empty($data['valid'])): ?><p class="form-error" role="alert">Tato pozvánka je neplatná nebo neúplná.</p><a class="button button-soft" href="<?= Http::escape(Http::url($user ? 'projects' : 'login')) ?>">Zpět do studia</a><?php elseif (!empty($user)): ?>
        <form method="post" action="<?= Http::escape(Http::url('join', ['token' => (string) ($data['token'] ?? '')])) ?>"><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>"><button class="button button-ink" type="submit">Přijmout pozvánku <span aria-hidden="true">↗</span></button></form>
    <?php else: ?>
        <div class="join-actions"><a class="button button-ink" href="<?= Http::escape(Http::url('login', ['return' => 'join', 'token' => (string) ($data['token'] ?? '')])) ?>">Přihlásit se a připojit <span aria-hidden="true">↗</span></a><a class="text-link" href="<?= Http::escape(Http::url('register', ['return' => 'join', 'token' => (string) ($data['token'] ?? '')])) ?>">Nejdřív vytvořit účet</a></div>
    <?php endif; ?>
</section>