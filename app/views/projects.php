<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/projects.php
 * Module Type: View
 * Purpose: Show a collaborator's private audiobook projects.
 * Responsibilities: Present project cards and create projects from JSON scripts.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
$projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];
?>
<section class="page-heading projects-heading">
    <div>
        <p class="eyebrow">Moje knihy</p>
        <h1>Moje knihy</h1>
        <p class="lede">Otevřete projekt, nebo vytvořte nový z JSON scénáře.</p>
    </div>
    <a class="button button-ink" href="#novy-projekt">Nový projekt <span aria-hidden="true">＋</span></a>
</section>
<?php if ($projects !== []): ?>
    <section class="project-grid" aria-label="Moje projekty">
        <?php foreach ($projects as $project): ?>
            <a class="project-card" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id']])) ?>">
                <div class="project-card-top"><span class="project-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="role-pill"><?= (($project['role'] ?? '') === 'owner' ? 'Autor' : 'Spolupracovník') ?></span></div>
                <h2><?= Http::escape((string) $project['title']) ?></h2>
                <p class="project-meta"><span><?= Http::escape((string) $project['line_count']) ?> replik</span><span class="meta-dot"></span><span><?= Http::escape((string) $project['recording_count']) ?> nahrávek</span></p>
                <div class="project-card-foot"><span>Otevřít studio</span><span class="arrow-circle" aria-hidden="true">↗</span></div>
            </a>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="empty-projects">
        <div class="empty-art" aria-hidden="true"><span class="empty-disc"></span><span class="empty-wave"></span><span class="empty-spark">✳</span></div>
        <div><h2>Zatím tu nejsou žádné knihy.</h2><p>Vytvořte projekt z JSON scénáře nebo otevřete pozvánku.</p></div>
        <a class="text-link" href="#novy-projekt">Nahrát scénář <span aria-hidden="true">↓</span></a>
    </section>
<?php endif; ?>
<section id="novy-projekt" class="create-section">
    <div class="create-intro"><p class="eyebrow">Nový projekt</p><h2>Importovat scénář</h2><p>Nahrajte JSON scénář verze 1, nebo jej vložte do textového pole. Soubor může mít nejvýše 2 MiB.</p></div>
    <form method="post" enctype="multipart/form-data" action="<?= Http::escape(Http::url('projects')) ?>" class="create-card" data-script-form>
        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
        <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
        <label class="json-file-label">Scénář ve formátu JSON <input type="file" name="script_file" accept=".json,application/json" data-script-file><span class="file-hint">Zvolte soubor do 2 MiB. Název knihy je uložen uvnitř JSON.</span></label>
        <div class="form-divider"><span>nebo vložte scénář</span></div>
        <label>Obsah scénáře <textarea name="script" rows="8" data-script-editor spellcheck="false" placeholder="Vložte scénář ve formátu JSON…"></textarea></label>
        <p class="form-hint" data-script-hint aria-live="polite">Vyberte soubor, nebo vložte scénář do textového pole.</p>
        <div class="editor-tools"><button type="button" class="text-button" data-fill-example>Vložit ukázkový scénář</button><span aria-hidden="true">·</span><a class="text-button" href="<?= Http::escape(Http::asset('example.json')) ?>" download="dabing-scenar-ukazka.json">Stáhnout ukázku</a></div>
        <button class="button button-ink" type="submit">Vytvořit projekt <span aria-hidden="true">↗</span></button>
    </form>
</section>