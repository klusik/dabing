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
$voices = is_array($data['voices'] ?? null) ? $data['voices'] : [];
$pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : ['page' => 1, 'pages' => 1, 'total' => 0];
$playlist = is_array($data['playlist'] ?? null) ? $data['playlist'] : [];
?>
<section class="workspace-heading">
    <div class="workspace-title"><a class="back-link" href="<?= Http::escape(Http::url('projects')) ?>" title="Moje knihy" aria-label="Zpět na moje knihy">←<span class="sr-only">Zpět na moje knihy</span></a><p class="eyebrow"><span class="eyebrow-dot"></span> <?= (($project['role'] ?? '') === 'owner' ? 'Autor' : 'Spolupracovník') ?> · soukromé studio</p><h1><?= Http::escape((string) ($project['title'] ?? 'Audiokniha')) ?></h1></div>
    <div class="workspace-actions">
        <?php if ($canInvite): ?>
            <div class="anchored-control">
                <button class="icon-control" type="button" data-panel-trigger="invite-panel" title="Pozvat spolupracovníka" aria-label="Pozvat spolupracovníka" aria-expanded="false" aria-controls="invite-panel"><span aria-hidden="true">＋</span></button>
                <div class="popover" id="invite-panel" data-panel hidden>
                    <h2>E-mailová pozvánka</h2>
                    <form method="post" action="<?= Http::escape(Http::url('email-invite', ['id' => (string) $project['id']])) ?>" class="invite-form">
                        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                        <label class="invite-copy-label">E-mail spolupracovníka<input name="email" type="email" autocomplete="email" required></label>
                        <button class="icon-control" type="submit" title="Odeslat pozvánku" aria-label="Odeslat pozvánku"><span aria-hidden="true">→</span></button>
                    </form>
                </div>
            </div>
            <div class="anchored-control">
                <button class="icon-control" type="button" data-panel-trigger="cast-panel" title="Přiřadit hlasy" aria-label="Přiřadit hlasy" aria-expanded="false" aria-controls="cast-panel"><span aria-hidden="true">♬</span></button>
                <div class="popover cast-popover" id="cast-panel" data-panel hidden>
                    <h2>Obsazení</h2>
                    <?php foreach ($voices as $voice): ?>
                        <form method="post" action="<?= Http::escape(Http::url('assign-voice', ['id' => (string) $project['id']])) ?>" class="cast-row">
                            <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                            <input type="hidden" name="character" value="<?= Http::escape((string) $voice['character']) ?>"><input type="hidden" name="page" value="<?= Http::escape((string) ($pagination['page'] ?? 1)) ?>">
                            <label><?= Http::escape((string) $voice['character']) ?><select name="user_id" aria-label="Hlas pro <?= Http::escape((string) $voice['character']) ?>">
                                <option value="0"<?= empty($voice['assigned_user_id']) ? ' selected' : '' ?>>Nepřiřazeno</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= Http::escape((string) $member['id']) ?>"<?= (int) ($voice['assigned_user_id'] ?? 0) === (int) $member['id'] ? ' selected' : '' ?>><?= Http::escape((string) $member['name']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <button class="icon-control" type="submit" title="Uložit obsazení" aria-label="Uložit hlas pro <?= Http::escape((string) $voice['character']) ?>"><span aria-hidden="true">✓</span></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="member-stack" aria-label="Členové projektu"><?php foreach (array_slice($members, 0, 4) as $member): ?><span class="member-avatar" title="<?= Http::escape((string) $member['name']) ?>"><?= Http::escape((preg_match('/\\A./us', (string) $member['name'], $initial) === 1 ? strtoupper($initial[0]) : 'D')) ?></span><?php endforeach; ?><span class="member-count"><?= count($members) ?> <?= count($members) === 1 ? 'hlas' : (count($members) >= 2 && count($members) < 5 ? 'hlasy' : 'hlasů') ?></span></div>
    </div>
</section>
<section class="studio-toolbar" data-project-id="<?= Http::escape((string) $project['id']) ?>"><div class="studio-summary"><span class="live-dot"></span><span><?= count($chapters) ?> <?= count($chapters) === 1 ? 'kapitola' : (count($chapters) >= 2 && count($chapters) < 5 ? 'kapitoly' : 'kapitol') ?></span><span id="recording-help" class="sr-only">Mikrofon bude vyžádán po stisknutí tlačítka pro nahrávání.</span></div><a class="icon-control" href="#poslech" title="Přejít k poslechu" aria-label="Přejít k poslechu"><span aria-hidden="true">↓</span></a></section>
<?php if ((int) ($pagination['pages'] ?? 1) > 1): ?>
    <nav class="script-pagination" aria-label="Stránkování scénáře">
        <?php if ((int) ($pagination['page'] ?? 1) > 1): ?>
            <a class="icon-control" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id'], 'page' => (int) $pagination['page'] - 1])) ?>" title="Předchozí stránka" aria-label="Předchozí stránka">←</a>
        <?php else: ?>
            <span class="icon-control is-disabled" aria-hidden="true">←</span>
        <?php endif; ?>
        <span>Strana <?= Http::escape((string) $pagination['page']) ?> / <?= Http::escape((string) $pagination['pages']) ?> · <?= Http::escape(number_format((int) $pagination['total'], 0, ',', ' ')) ?> replik</span>
        <?php if ((int) ($pagination['page'] ?? 1) < (int) ($pagination['pages'] ?? 1)): ?>
            <a class="icon-control" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id'], 'page' => (int) $pagination['page'] + 1])) ?>" title="Další stránka" aria-label="Další stránka">→</a>
        <?php else: ?>
            <span class="icon-control is-disabled" aria-hidden="true">→</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php if ($chapters === []): ?><section class="empty-projects compact-empty"><div class="empty-art" aria-hidden="true"><span class="empty-disc"></span><span class="empty-wave"></span></div><div><h2>Projekt zatím neobsahuje kapitoly.</h2><p>Importujte scénář při vytvoření projektu.</p></div></section><?php endif; ?>
<div class="studio-layout">
    <div class="script-column">
        <?php foreach ($chapters as $chapter): ?>
            <section class="chapter-block" aria-labelledby="chapter-<?= Http::escape((string) $chapter['id']) ?>">
                <div class="chapter-heading"><span class="chapter-index">Kapitola</span><h2 id="chapter-<?= Http::escape((string) $chapter['id']) ?>"><?= Http::escape((string) $chapter['title']) ?></h2><span class="chapter-rule"></span></div>
                <div class="line-list">
                    <?php foreach ($chapter['lines'] as $line): ?>
                        <article class="line-card" data-line-card data-line-id="<?= Http::escape((string) $line['id']) ?>">
                            <div class="line-identity"><span class="line-number"><?= sprintf('%04d', (int) ($line['number'] ?? $line['position'])) ?></span><span class="character-name"><?= Http::escape((string) $line['character']) ?></span><?php if (!empty($line['assigned_name'])): ?><span class="assigned-name" title="Přiřazeno: <?= Http::escape((string) $line['assigned_name']) ?>"><?= Http::escape((string) $line['assigned_name']) ?></span><?php endif; ?></div>
                            <p class="line-text"><?= Http::escape((string) $line['text']) ?></p>
                            <div class="take-list">
                                <?php foreach ($line['takes'] as $take): ?>
                                    <?php
                                    $attemptNo = (int) ($take['attempt_no'] ?? 1);
                                    $fileName = (string) ($take['file_name'] ?? '');
                                    $audioUrl = (string) ($take['audio_url']
                                        ?? Http::url('audio', ['id' => (string) $take['id']]));
                                    $downloadUrl = (string) ($take['download_url']
                                        ?? Http::url('audio', [
                                            'id' => (string) $take['id'],
                                            'download' => 1,
                                        ]));
                                    ?>
                                    <div class="take-row<?= !empty($take['is_selected']) ? ' take-selected' : '' ?>">
                                        <div class="take-meta">
                                            <span class="attempt-badge" title="Číslo pokusu"><?= sprintf('%03d', $attemptNo) ?></span>
                                            <?php if ($fileName !== ''): ?><code class="take-filename"><?= Http::escape($fileName) ?></code><?php endif; ?>
                                            <span class="take-byline"><?= Http::escape((string) $take['user_name']) ?><?= !empty($take['is_selected']) ? ' · vybráno' : '' ?></span>
                                        </div>
                                        <audio preload="none" controls src="<?= Http::escape($audioUrl) ?>" aria-label="Přehrát pokus <?= sprintf('%03d', $attemptNo) ?> od <?= Http::escape((string) $take['user_name']) ?>"></audio>
                                        <a class="icon-control take-download" href="<?= Http::escape($downloadUrl) ?>"<?= $fileName !== '' ? ' download="' . Http::escape($fileName) . '"' : '' ?> title="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>" aria-label="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>"><span aria-hidden="true">↓</span></a>
                                        <?php if (empty($take['is_selected']) && ($project['role'] ?? '') === 'owner'): ?>
                                            <form method="post" action="<?= Http::escape(Http::url('select-take', ['id' => (string) $project['id']])) ?>" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                <input type="hidden" name="line_id" value="<?= Http::escape((string) $line['id']) ?>">
                                                <input type="hidden" name="recording_id" value="<?= Http::escape((string) $take['id']) ?>"><input type="hidden" name="page" value="<?= Http::escape((string) ($pagination['page'] ?? 1)) ?>">
                                                <button class="icon-control take-select" type="submit" title="Vybrat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-label="Vybrat pokus <?= sprintf('%03d', $attemptNo) ?>"><span aria-hidden="true">✓</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (($line['can_record'] ?? true) === true): ?>
                                <div class="record-controls" data-recorder><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                    <div class="record-actions"><button type="button" class="icon-control record-button" data-record-start aria-describedby="recording-help" title="Nahrát repliku; mikrofon povolíte až po kliknutí" aria-label="Nahrát repliku <?= sprintf('%04d', (int) ($line['number'] ?? $line['position'])) ?>"><span class="record-dot" aria-hidden="true"></span></button><button type="button" class="icon-control record-button record-stop" data-record-stop title="Ukončit nahrávání" aria-label="Ukončit nahrávání" hidden><span class="stop-square" aria-hidden="true"></span></button><label class="icon-control file-pick" title="Vybrat zvukový soubor"><span aria-hidden="true">↥</span><span class="sr-only">Vybrat zvukový soubor</span><input type="file" accept="audio/*" data-audio-file aria-label="Vybrat zvukový soubor" aria-describedby="recording-help"></label></div>
                                    <p class="record-status" data-record-status aria-live="polite"></p>
                                    <div class="record-preview" data-record-preview hidden></div>
                                </div>
                            <?php else: ?>
                                <p class="record-unavailable" title="Tato replika je přiřazena jinému členu.">Přiřazeno: <?= Http::escape((string) ($line['assigned_name'] ?? '')) ?></p>
                            <?php endif; ?>
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
            <div class="master-controls"><button type="button" class="icon-control" data-play-previous aria-label="Předchozí replika" title="Předchozí replika">←</button><button type="button" class="icon-control" data-play-next aria-label="Další replika" title="Další replika">→</button></div>
            <p class="playlist-current" data-playlist-status aria-live="polite">Vyberte repliku v seznamu nebo stiskněte Přehrát.</p>
        </div>
        <div class="playlist-items" data-playlist tabindex="0" role="region" aria-label="Seznam všech vybraných nahrávek">
            <?php if ($playlist === []): ?>
                <p class="playlist-empty">Nejsou vybrané žádné nahrávky.</p>
            <?php else: ?>
                <?php foreach ($playlist as $clip): ?>
                    <div class="playlist-line">
                        <span><?= sprintf('%04d', (int) $clip['line_number']) ?> · <?= Http::escape((string) $clip['character']) ?></span>
                        <button type="button" class="playlist-play" data-play-item aria-label="Přehrát repliku <?= sprintf('%04d', (int) $clip['line_number']) ?> — <?= Http::escape((string) $clip['character']) ?>" title="<?= Http::escape((string) $clip['file_name']) ?>" data-src="<?= Http::escape((string) $clip['audio_url']) ?>" data-label="<?= Http::escape((string) $clip['character']) ?> — <?= Http::escape((string) $clip['chapter_title']) ?>"><span aria-hidden="true">▶</span></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
