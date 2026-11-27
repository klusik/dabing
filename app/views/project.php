<?php
declare(strict_types=1);

use Dabing\Http;
/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/views/project.php
 * Module Type: View
 * Purpose: Provide the collaborative reading and recording workspace.
 * Responsibilities: Present script lines, takes, invite controls and playlist.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
$project = is_array($data['project'] ?? null) ? $data['project'] : [];
$chapters = is_array($data['chapters'] ?? null) ? $data['chapters'] : [];
$members = is_array($data['members'] ?? null) ? $data['members'] : [];
$canInvite = (bool) ($data['can_invite'] ?? false);
?>
<section class="workspace-heading">
    <div class="workspace-title"><a class="back-link" href="<?= Http::escape(Http::url('projects')) ?>">← Všechny projekty</a><p class="eyebrow"><span class="eyebrow-dot"></span> <?= (($project['role'] ?? '') === 'owner' ? 'Autor' : 'Spolupracovník') ?> · soukromé studio</p><h1><?= Http::escape((string) ($project['title'] ?? 'Audiokniha')) ?></h1></div>
    <div class="workspace-actions">
        <?php if ($canInvite): ?><div class="anchored-control"><button class="button button-soft" type="button" data-panel-trigger="invite-panel" data-open="<?= !empty($data['invite_url']) ? 'true' : 'false' ?>" aria-expanded="false" aria-controls="invite-panel">Pozvat hlas <span aria-hidden="true">＋</span></button><div class="popover" id="invite-panel" data-panel hidden><h2>Pozvěte další hlas</h2><p>Vygenerujte pozvánku pro člověka, který bude číst s vámi.</p><form method="post" action="<?= Http::escape(Http::url('invite', ['id' => (string) $project['id']])) ?>"><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>"><button class="button button-ink button-wide" type="submit">Vytvořit pozvánku</button></form><?php if (!empty($data['invite_url'])): ?><label class="invite-copy-label">Odkaz k pozvání<input readonly value="<?= Http::escape((string) $data['invite_url']) ?>" data-copy-value></label><button type="button" class="text-button" data-copy-trigger>Kopírovat odkaz</button><?php endif; ?></div></div><?php endif; ?>
        <div class="member-stack" aria-label="Členové projektu"><?php foreach (array_slice($members, 0, 4) as $member): ?><span class="member-avatar" title="<?= Http::escape((string) $member['name']) ?>"><?= Http::escape((preg_match('/\\A./us', (string) $member['name'], $initial) === 1 ? strtoupper($initial[0]) : 'D')) ?></span><?php endforeach; ?><span class="member-count"><?= count($members) ?> <?= count($members) === 1 ? 'hlas' : (count($members) >= 2 && count($members) < 5 ? 'hlasy' : 'hlasů') ?></span></div>
    </div>
</section>
<section class="studio-toolbar" data-project-id="<?= Http::escape((string) $project['id']) ?>"><div class="studio-summary"><span class="live-dot"></span><span><?= count($chapters) ?> <?= count($chapters) === 1 ? 'kapitola' : (count($chapters) >= 2 && count($chapters) < 5 ? 'kapitoly' : 'kapitol') ?></span><span class="toolbar-sep">·</span><span>Nahrávání po replikách</span><span class="toolbar-sep">·</span><span id="recording-help">Mikrofon se spustí až po vašem kliknutí.</span></div><a class="text-link" href="#poslech">Přejít k poslechu <span aria-hidden="true">↓</span></a></section>
<?php if ($chapters === []): ?><section class="empty-projects compact-empty"><div class="empty-art" aria-hidden="true"><span class="empty-disc"></span><span class="empty-wave"></span></div><div><h2>Projekt zatím neobsahuje kapitoly.</h2><p>Importujte scénář při vytvoření projektu.</p></div></section><?php endif; ?>
<div class="studio-layout">
    <div class="script-column">
        <?php foreach ($chapters as $chapter): ?>
            <section class="chapter-block" aria-labelledby="chapter-<?= Http::escape((string) $chapter['id']) ?>">
                <div class="chapter-heading"><span class="chapter-index">Kapitola</span><h2 id="chapter-<?= Http::escape((string) $chapter['id']) ?>"><?= Http::escape((string) $chapter['title']) ?></h2><span class="chapter-rule"></span></div>
                <div class="line-list">
                    <?php foreach ($chapter['lines'] as $line): ?>
                        <article class="line-card" data-line-card data-line-id="<?= Http::escape((string) $line['id']) ?>">
                            <div class="line-identity"><span class="line-number"><?= Http::escape((string) $line['position']) ?></span><span class="character-name"><?= Http::escape((string) $line['character']) ?></span></div>
                            <p class="line-text"><?= Http::escape((string) $line['text']) ?></p>
                            <div class="take-list">
                                <?php foreach ($line['takes'] as $take): ?>
                                    <div class="take-row<?= !empty($take['is_selected']) ? ' take-selected' : '' ?>">
                                        <audio preload="none" controls src="<?= Http::escape(Http::url('audio', ['id' => (string) $take['id']])) ?>" aria-label="Nahrávka od <?= Http::escape((string) $take['user_name']) ?>"></audio>
                                        <span class="take-byline"><?= Http::escape((string) $take['user_name']) ?><?= !empty($take['is_selected']) ? ' · vybráno' : '' ?></span>
                                        <?php if (empty($take['is_selected']) && ($project['role'] ?? '') === 'owner'): ?><form method="post" action="<?= Http::escape(Http::url('select-take', ['id' => (string) $project['id']])) ?>" class="inline-form"><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>"><input type="hidden" name="line_id" value="<?= Http::escape((string) $line['id']) ?>"><input type="hidden" name="recording_id" value="<?= Http::escape((string) $take['id']) ?>"><button class="text-button" type="submit">Vybrat</button></form><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="record-controls" data-recorder><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                <div class="record-actions"><button type="button" class="record-button" data-record-start aria-describedby="recording-help"><span class="record-dot"></span><span>Nahrát repliku</span></button><button type="button" class="record-button record-stop" data-record-stop hidden><span class="stop-square"></span><span>Ukončit</span></button><label class="file-pick">Vybrat zvuk<input type="file" accept="audio/*" data-audio-file aria-label="Vybrat zvukový soubor" aria-describedby="recording-help"></label></div>
                                <p class="record-status" data-record-status aria-live="polite">Připraveno.</p>
                                <div class="record-preview" data-record-preview hidden></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <aside class="playlist-card" id="poslech">
        <div class="playlist-top"><span class="playlist-icon" aria-hidden="true">♫</span><span class="card-kicker">Vybrané repliky</span></div>
        <h2>Poslech</h2><p class="muted">Vybrané nahrávky v pořadí scénáře.</p>
        <div class="master-player">
            <audio controls preload="none" data-story-player aria-label="Souvislý poslech vybraných replik"></audio>
            <div class="master-controls"><button type="button" class="text-button" data-play-previous aria-label="Předchozí replika">← Předchozí</button><button type="button" class="text-button" data-play-next>Další →</button></div>
            <p class="playlist-current" data-playlist-status aria-live="polite">Vyberte repliku v seznamu nebo stiskněte Přehrát.</p>
        </div>
        <div class="playlist-items" data-playlist>
            <?php $hasSelected = false; foreach ($chapters as $chapter): ?><div class="playlist-chapter"><span><?= Http::escape((string) $chapter['title']) ?></span><?php foreach ($chapter['lines'] as $line): foreach ($line['takes'] as $take): if (!empty($take['is_selected'])): $hasSelected = true; ?><div class="playlist-line"><span><?= Http::escape((string) $line['character']) ?></span><button type="button" class="playlist-play" data-play-item data-src="<?= Http::escape(Http::url('audio', ['id' => (string) $take['id']])) ?>" data-label="<?= Http::escape((string) $line['character']) ?> — <?= Http::escape((string) $chapter['title']) ?>">Přehrát repliku <span aria-hidden="true">▶</span></button></div><?php endif; endforeach; endforeach; ?></div><?php endforeach; ?>
            <?php if (!$hasSelected): ?><p class="playlist-empty">Až vyberete nahrávky, objeví se tady v pořadí scénáře.</p><?php endif; ?>
        </div>
    </aside>
</div>