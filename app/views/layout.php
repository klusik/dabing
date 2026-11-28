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
    <?php if (($view ?? '') === 'users'): ?>
        <link rel="stylesheet" href="<?= Http::escape(Http::asset('users.css')) ?>">
    <?php endif; ?>
    <?php if (($view ?? '') === 'audio-profiles'): ?>
        <link rel="stylesheet" href="<?= Http::escape(Http::asset('audio-profile-editor.css')) ?>">
    <?php elseif (($view ?? '') === 'project'): ?>
        <link rel="stylesheet" href="<?= Http::escape(Http::asset('studio-audio-profiles.css')) ?>">
    <?php endif; ?>
    <script src="<?= Http::escape(Http::asset('site.js')) ?>" defer></script>
    <?php if (($view ?? '') === 'audio-profiles'): ?>
        <script type="module" src="<?= Http::escape(Http::asset('audio-profile-editor.js')) ?>"></script>
    <?php elseif (($view ?? '') === 'project'): ?>
        <script type="module" src="<?= Http::escape(Http::asset('studio-audio-profiles.js')) ?>"></script>
    <?php endif; ?>
</head>
<body>
    <a class="skip-link" href="#main">Přejít k obsahu</a>
    <header class="site-header">
        <a class="brand" href="<?= Http::escape(Http::url($user ? 'projects' : 'login')) ?>" aria-label="Dabing — domů">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span>Dabing<span class="brand-period">.</span></span>
        </a>
        <nav class="top-nav" aria-label="Hlavní navigace"<?= !empty($user['is_admin']) ? ' data-admin-audio-nav' : '' ?>>
            <?php if ($user): ?>
                <a class="icon-control" href="<?= Http::escape(Http::url('projects')) ?>" aria-label="Moje knihy" title="Moje knihy"><span aria-hidden="true">▦</span></a>
                <a class="icon-control" href="<?= Http::escape(Http::url('account')) ?>" aria-label="Účet <?= Http::escape((string) $user['name']) ?>" title="Účet"><span aria-hidden="true">♙</span></a>
                <?php if (!empty($user['is_admin'])): ?>
                    <a class="icon-control" href="<?= Http::escape(Http::url('users')) ?>" aria-label="Uživatelé" title="Uživatelé"><span aria-hidden="true">♧</span></a>
                    <a class="icon-control" href="<?= Http::escape(Http::url('audio-profiles')) ?>" aria-label="Zvukové profily" title="Zvukové profily"><span aria-hidden="true">♫</span></a>
                    <a class="icon-control" href="<?= Http::escape(Http::url('update')) ?>" aria-label="Aktualizace" title="Aktualizace"><span aria-hidden="true">⟳</span></a>
                <?php endif; ?>
                <form method="post" action="<?= Http::escape(Http::url('logout')) ?>" class="nav-form">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <button class="icon-control" type="submit" aria-label="Odhlásit se" title="Odhlásit se"><span aria-hidden="true">↪</span></button>
                </form>
            <?php else: ?>
                <?php if (($view ?? '') !== 'login'): ?><a class="icon-control" href="<?= Http::escape(Http::url('login')) ?>" aria-label="Přihlásit se" title="Přihlásit se"><span aria-hidden="true">↪</span></a><?php endif; ?>
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
