<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/layout.php
 * Module Type: View layout
 * Purpose: Provide the shared accessible shell for the Dabing studio.
 * Responsibilities: Render navigation, notices, page content and shared assets.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f1e8">
    <title><?= Http::escape($title ?? 'Dabing') ?> · Dabing</title>
    <link rel="stylesheet" href="<?= Http::escape(Http::asset('site.css')) ?>">
    <script src="<?= Http::escape(Http::asset('site.js')) ?>" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Přejít k obsahu</a>
    <header class="site-header">
        <a class="brand" href="<?= Http::escape(Http::url($user ? 'projects' : 'login')) ?>" aria-label="Dabing — domů">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span>Dabing<span class="brand-period">.</span></span>
        </a>
        <nav class="top-nav" aria-label="Hlavní navigace">
            <?php if ($user): ?>
                <a class="nav-link<?= ($view ?? '') === 'projects' ? ' is-current' : '' ?>" href="<?= Http::escape(Http::url('projects')) ?>">Moje projekty</a>
                <span class="user-chip"><span class="avatar" aria-hidden="true"><?= Http::escape((preg_match('/\\A./us', (string) $user['name'], $initial) === 1 ? strtoupper($initial[0]) : 'D')) ?></span><span><?= Http::escape((string) $user['name']) ?></span></span>
                <form method="post" action="<?= Http::escape(Http::url('logout')) ?>" class="nav-form">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <button class="nav-link nav-button" type="submit">Odhlásit</button>
                </form>
            <?php else: ?>
                <a class="nav-link" href="<?= Http::escape(Http::url('login')) ?>">Přihlásit se</a>
                <a class="button button-small button-ink" href="<?= Http::escape(Http::url('register')) ?>">Vytvořit účet <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </nav>
    </header>
    <?php if (isset($notice) && is_array($notice)): ?>
        <div class="notice notice-<?= Http::escape((string) ($notice['type'] ?? 'info')) ?>" role="status">
            <span class="notice-symbol" aria-hidden="true">✳</span>
            <p><?= Http::escape((string) ($notice['message'] ?? '')) ?></p>
        </div>
    <?php endif; ?>
    <main id="main" class="site-main">
        <?php require $template; ?>
    </main>
    <footer class="site-footer"><span>© <?= date('Y') ?> Dabing</span></footer>
</body>
</html>