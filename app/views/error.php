<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/error.php
 * Module Type: View
 * Purpose: Explain an unavailable or unauthorized studio action.
 * Responsibilities: Show a clear status and a safe route back.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<section class="empty-page">
    <div class="error-orb" aria-hidden="true">!</div>
    <p class="eyebrow">Chyba <span class="status-code"><?= Http::escape((string) ($data['status'] ?? '')) ?></span></p>
    <h1><?= Http::escape((string) ($data['message'] ?? 'Tuhle stránku se nepodařilo otevřít.')) ?></h1>
    <p class="muted">Zkontrolujte odkaz nebo se vraťte na seznam knih.</p>
    <a class="button button-ink" href="<?= Http::escape(Http::url($user ? 'projects' : 'login')) ?>">Zpět na knihy <span aria-hidden="true">↗</span></a>
</section>