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
$isAdmin = (bool) ($data['is_admin'] ?? false);
/**
 * Type: Integer; Units: bytes; Scope: script file import; Consumers: help copy,
 * file input limit, and client preflight; Rationale: reflect app and host limits.
 * Value: Controller-provided cap; default matches the application ceiling.
 */
$maxScriptBytes = (int) ($data['max_script_bytes'] ?? 20_000_000);
/**
 * Type: Integer; Units: bytes; Scope: pasted script; Consumers: text input guard;
 * Rationale: request body headroom may lower the safe pasted payload size.
 * Value: Controller-provided cap; default follows the file cap.
 */
$maxScriptPasteBytes = (int) ($data['max_script_paste_bytes'] ?? $maxScriptBytes);
/**
 * Type: Integer; Units: bytes per megabyte; Scope: limit labels; Consumers: UI.
 * Rationale: express transfer limits in decimal MB as requested.
 * Value: 1,000,000 bytes per MB.
 */
$bytesPerMegabyte = 1_000_000;
/**
 * Type: Integer; Units: tenths per megabyte; Scope: displayed limits; Consumers: labels.
 * Rationale: round down to one decimal so the visible cap never overstates the limit.
 * Value: 10 tenths per decimal megabyte.
 */
$tenthsPerMegabyte = 10;
$bytesPerTenthMegabyte = $bytesPerMegabyte / $tenthsPerMegabyte;
$maxScriptLabel = $maxScriptBytes >= $bytesPerMegabyte
    ? rtrim(rtrim(number_format(
        floor($maxScriptBytes / $bytesPerTenthMegabyte)
            / $tenthsPerMegabyte,
        1,
        '.',
        ''
    ), '0'), '.') . ' MB'
    : number_format($maxScriptBytes, 0, '.', ',') . ' bytes';
$maxScriptPasteLabel = $maxScriptPasteBytes >= $bytesPerMegabyte
    ? rtrim(rtrim(number_format(
        floor($maxScriptPasteBytes / $bytesPerTenthMegabyte)
            / $tenthsPerMegabyte,
        1,
        '.',
        ''
    ), '0'), '.') . ' MB'
    : number_format($maxScriptPasteBytes, 0, '.', ',') . ' bytes';
?>
<section class="page-heading projects-heading">
    <div>
        <h1>Moje knihy</h1>
        <p class="lede">Otevřete knihu nebo importujte scénář.</p>
    </div>
    <a class="icon-control" href="#novy-projekt" title="Nový projekt" aria-label="Nový projekt"><span aria-hidden="true">＋</span></a>
</section>
<?php if ($projects !== []): ?>
    <section class="project-grid" aria-label="Moje projekty">
        <?php foreach ($projects as $project): ?>
            <a class="project-card" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id']])) ?>">
                <div class="project-card-top"><span class="project-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="role-pill"><?= $isAdmin ? 'Správce' : 'Spolupracovník' ?></span></div>
                <h2><?= Http::escape((string) $project['title']) ?></h2>
                <p class="project-meta"><span><?= Http::escape((string) $project['line_count']) ?> replik</span><span class="meta-dot"></span><span><?= Http::escape((string) $project['recording_count']) ?> nahrávek</span></p>
                <div class="project-card-foot"><span>Otevřít studio</span><span class="arrow-circle" aria-hidden="true">↗</span></div>
            </a>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="empty-projects">
        <div class="empty-art" aria-hidden="true"><span class="empty-disc"></span><span class="empty-wave"></span><span class="empty-spark">✳</span></div>
        <div><h2>Zatím tu nejsou žádné knihy.</h2><p>Importujte scénář JSON a začněte nahrávat repliky.</p></div>
        <a class="text-link" href="#novy-projekt">Nahrát scénář <span aria-hidden="true">↓</span></a>
    </section>
<?php endif; ?>
<section id="novy-projekt" class="create-section">
    <div class="create-intro">
        <p class="eyebrow">Nový projekt</p>
        <h2>Importovat scénář</h2>

        <details class="json-guidance" data-json-guide>
            <summary class="icon-control help-toggle" title="Pokyny ke scénáři JSON v2" aria-label="Pokyny ke scénáři JSON v2"><span aria-hidden="true">?</span></summary>
            <div class="guidance-panel">
                <header class="guidance-header">
                    <div><span class="card-kicker">Import</span><h3>Scénář JSON v2</h3></div>
                    <div class="guidance-actions">
                        <button type="button" class="icon-control" data-fill-example title="Vložit ukázkový JSON" aria-label="Vložit ukázkový JSON"><span aria-hidden="true">↙</span></button>
                        <button type="button" class="icon-control" data-download-example title="Stáhnout ukázkový JSON" aria-label="Stáhnout ukázkový JSON"><span aria-hidden="true">↓</span></button>
                        <button type="button" class="icon-control" data-close-guide title="Zavřít pokyny" aria-label="Zavřít pokyny"><span aria-hidden="true">×</span></button>
                    </div>
                </header>
                <div class="guidance-grid">
                    <section class="guidance-structure" aria-labelledby="json-structure-title">
                        <h4 id="json-structure-title">Struktura a číslování</h4>
                        <ul>
                            <li>Čísla replik jsou souvislá od 1 do N napříč celou knihou.</li>
                            <li>Každá replika má <code>number</code>, <code>character</code> a <code>text</code>.</li>
                            <li>Text může obsahovat celý odstavec, nesmí být prázdný. Číslování v další kapitole pokračuje.</li>
                            <li>Limit: 1–9 999 replik, nejvýše 200 kapitol, UTF-8 JSON do <?= Http::escape($maxScriptLabel) ?>. Každá replika má nejvýše 999 pokusů.</li>
                        </ul>
                        <pre class="json-example"><code data-script-example>{
  "version": 2,
  "title": "O pejskovi",
  "chapters": [
    { "title": "První kapitola", "lines": [
      { "number": 1, "character": "Vypravěč", "text": "Pejsek se probudil. Otevřel dveře a vyšel na zahradu." },
      { "number": 2, "character": "Pejsek", "text": "Dobrý den, zahrado!" }
    ]},
    { "title": "Druhá kapitola", "lines": [
      { "number": 3, "character": "Vypravěč", "text": "Za plotem čekal jeho kamarád." }
    ]}
  ]
}</code></pre>
                    </section>
                    <section class="guidance-ai" aria-labelledby="json-ai-title">
                        <h4 id="json-ai-title">Pokyny pro AI</h4>
                        <pre data-ai-instructions>Z textu, který přikládám, vytvoř platný JSON v2 pro Dabing. Zachovej znění replik a rozděl je do kapitol podle nadpisů. Každou repliku očísluj souvisle celou knihou od 1 do N; v další kapitole pokračuj. Každý záznam musí mít number, character a text. Souvislý odstavec může zůstat jednou replikou. Text nesmí být prázdný. Použij UTF-8. Dodrž limit 9 999 replik, 200 kapitol, nejvýše 999 pokusů na repliku a velikost <?= Http::escape($maxScriptLabel) ?>. Vrať pouze JSON bez vysvětlení.</pre>
                        <button type="button" class="icon-control copy-prompt" data-copy-instructions title="Zkopírovat formát a pokyny" aria-label="Zkopírovat formát a pokyny"><span aria-hidden="true">⧉</span></button>
                        <span class="copy-status" data-copy-status aria-live="polite"></span>
                    </section>
                </div>
                <p class="filename-help"><code>o_pejskovi_0001_001.mp3</code> — replika 0001, pokus 001. Další pokus: <code>o_pejskovi_0001_002.mp3</code>. Repliky mají čtyři číslice; pokusy tři (001–999), každý samostatně.</p>
            </div>
        </details>
    </div>
    <form method="post" enctype="multipart/form-data" action="<?= Http::escape(Http::url('projects')) ?>" class="create-card" data-script-form>
        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
        <?php foreach (($data['errors'] ?? []) as $error): ?><p class="form-error" role="alert"><?= Http::escape((string) $error) ?></p><?php endforeach; ?>
        <label class="json-file-label"><span class="file-action-icon" aria-hidden="true">↥</span><span class="sr-only">Scénář JSON</span><input type="file" name="script_file" accept=".json,application/json" data-script-file data-max-bytes="<?= Http::escape((string) $maxScriptBytes) ?>" data-max-label="<?= Http::escape($maxScriptLabel) ?>" aria-label="Vybrat soubor JSON, nejvýše <?= Http::escape($maxScriptLabel) ?>"></label>
        <div class="form-divider"><span>nebo</span></div>
        <label>Scénář JSON <textarea name="script" rows="8" data-script-editor data-max-bytes="<?= Http::escape((string) $maxScriptPasteBytes) ?>" data-max-label="<?= Http::escape($maxScriptPasteLabel) ?>" title="Vložit JSON, nejvýše <?= Http::escape($maxScriptPasteLabel) ?>" spellcheck="false" placeholder="Vložit JSON"></textarea></label>
        <p class="form-hint" data-script-hint aria-live="polite"></p>

        <button class="icon-control create-submit" type="submit" title="Vytvořit projekt" aria-label="Vytvořit projekt"><span aria-hidden="true">＋</span></button>
    </form>
</section>
