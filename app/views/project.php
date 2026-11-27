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
$exportUrl = (string) ($data['export_url']
    ?? Http::url('export-book', ['id' => (int) ($project['id'] ?? 0)]));
$hasRecordings = (bool) ($data['has_recordings'] ?? false);
$filter = (string) ($data['filter'] ?? 'all');
?>
<section class="workspace-heading">
    <div class="workspace-title"><a class="back-link" href="<?= Http::escape(Http::url('projects')) ?>" title="Moje knihy" aria-label="Zpět na moje knihy">←<span class="sr-only">Zpět na moje knihy</span></a><h1><?= Http::escape((string) ($project['title'] ?? 'Audiokniha')) ?></h1></div>
    <div class="workspace-actions">
        <?php if ($canInvite): ?>
            <div class="anchored-control">
                <button class="icon-control" type="button" data-panel-trigger="invite-panel" title="Spravovat pozvánky" aria-label="Spravovat pozvánky" aria-expanded="false" aria-controls="invite-panel"><span aria-hidden="true">✉</span></button>
                <div class="popover invite-popover" id="invite-panel" data-panel hidden>
                    <h2>Pozvat spolupracovníka</h2>
                    <?php require __DIR__ . '/project-invitations.php'; ?>
                </div>
            </div>
            <div class="anchored-control">
                <button class="icon-control" type="button" data-panel-trigger="cast-panel" title="Přiřadit hlasy" aria-label="Přiřadit hlasy" aria-expanded="<?= Http::query('cast') === '1' ? 'true' : 'false' ?>" aria-controls="cast-panel"><span aria-hidden="true">♬</span></button>
                <div class="popover cast-popover" id="cast-panel" data-panel<?= Http::query('cast') === '1' ? '' : ' hidden' ?>>
                    <h2>Obsazení</h2>
                    <div class="cast-matrix-scroll">
                        <table class="cast-matrix" data-cast-matrix>
                            <caption class="sr-only">Přiřazení účtů k postavám. Kliknutím na buňku změníte přiřazení.</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Postava</th>
                                    <?php foreach (($data['role_users'] ?? []) as $userIndex => $roleUser): ?>
                                        <th scope="col" data-cast-column="<?= (int) $userIndex + 1 ?>" title="<?= Http::escape((string) $roleUser['name']) ?><?= empty($roleUser['is_active']) ? ' · neaktivní účet' : '' ?>">
                                            <span class="cast-matrix-actor"><?= Http::escape((string) $roleUser['name']) ?><?= empty($roleUser['is_active']) ? ' · neaktivní' : '' ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($voices as $voice): ?>
                                    <tr data-cast-role-row>
                                        <th scope="row" title="<?= Http::escape((string) $voice['character']) ?>">
                                            <?= Http::escape((string) $voice['character']) ?>
                                        </th>
                                        <?php foreach (($data['role_users'] ?? []) as $userIndex => $roleUser): ?>
                                            <?php $isAssigned = in_array((int) $roleUser['id'], $voice['assigned_users'] ?? [], true); ?>
                                            <td data-cast-column="<?= (int) $userIndex + 1 ?>">
                                                <form method="post" action="<?= Http::escape(Http::url('assign-voice', ['id' => (string) $project['id']])) ?>" data-cast-cell>
                                                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                    <input type="hidden" name="character" value="<?= Http::escape((string) $voice['character']) ?>">
                                                    <input type="hidden" name="user_id" value="<?= Http::escape((string) $roleUser['id']) ?>">
                                                    <input type="hidden" name="assigned" value="<?= $isAssigned ? '0' : '1' ?>">
                                                    <input type="hidden" name="page" value="<?= Http::escape((string) ($pagination['page'] ?? 1)) ?>">
                                                    <input type="hidden" name="filter" value="<?= Http::escape($filter) ?>">
                                                    <button class="cast-matrix-toggle<?= $isAssigned ? ' is-assigned' : '' ?>" type="submit" data-actor-label="<?= Http::escape((string) $roleUser['name']) ?>" data-role-label="<?= Http::escape((string) $voice['character']) ?>" data-active="<?= !empty($roleUser['is_active']) ? 'true' : 'false' ?>" aria-pressed="<?= $isAssigned ? 'true' : 'false' ?>" aria-label="<?= $isAssigned ? 'Odebrat' : 'Přiřadit' ?> <?= Http::escape((string) $roleUser['name']) ?> jako <?= Http::escape((string) $voice['character']) ?>" title="<?= $isAssigned ? 'Odebrat přiřazení' : 'Přiřadit' ?><?= empty($roleUser['is_active']) ? ' · neaktivní účet' : '' ?>">
                                                        <span aria-hidden="true"><?= $isAssigned ? '✓' : '' ?></span>
                                                    </button>
                                                </form>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="cast-matrix-status" data-cast-status aria-live="polite"></p>
                </div>
            </div>
        <?php endif; ?>
        <div class="member-stack" aria-label="Členové projektu"><?php foreach (array_slice($members, 0, 4) as $member): ?><span class="member-avatar" title="<?= Http::escape((string) $member['name']) ?>"><?= Http::escape((preg_match('/\\A./us', (string) $member['name'], $initial) === 1 ? strtoupper($initial[0]) : 'D')) ?></span><?php endforeach; ?><span class="member-count"><?= count($members) ?> <?= count($members) === 1 ? 'hlas' : (count($members) >= 2 && count($members) < 5 ? 'hlasy' : 'hlasů') ?></span></div>
    </div>
</section>
<section
    class="studio-toolbar"
    data-project-id="<?= Http::escape((string) $project['id']) ?>"
>
    <div class="studio-summary">
        <span class="live-dot"></span>
        <span>
            <?= count($chapters) ?>
            <?= count($chapters) === 1
                ? 'kapitola'
                : (count($chapters) >= 2 && count($chapters) < 5
                    ? 'kapitoly'
                    : 'kapitol') ?>
        </span>
        <span id="recording-help" class="sr-only">
            Mikrofon bude vyžádán po stisknutí tlačítka pro nahrávání.
        </span>
    </div>
    <nav class="script-filter" aria-label="Filtr replik">
        <a class="<?= $filter === 'all' ? 'is-current' : '' ?>" href="<?= Http::escape(Http::url('project', ['id' => $project['id'], 'filter' => 'all'])) ?>"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>Všechny</a>
        <a class="<?= $filter === 'mine' ? 'is-current' : '' ?>" href="<?= Http::escape(Http::url('project', ['id' => $project['id'], 'filter' => 'mine'])) ?>"<?= $filter === 'mine' ? ' aria-current="page"' : '' ?>>Moje repliky</a>
    </nav>
    <div class="studio-toolbar-actions">
        <a
            class="icon-control"
            href="#poslech"
            title="Přejít k poslechu"
            aria-label="Přejít k poslechu"
        >
            <span aria-hidden="true">↓</span>
        </a>
        <?php if ($hasRecordings && (!empty($data['is_admin']) || ($project['role'] ?? '') === 'owner')): ?>
            <a
                class="icon-control zip-export-control"
                href="<?= Http::escape($exportUrl) ?>"
                title="Stáhnout všechny nahrávky jako ZIP"
                aria-label="Stáhnout všechny nahrávky jako ZIP"
            >
                <span class="zip-export-glyph" aria-hidden="true">ZIP</span>
            </a>
        <?php endif; ?>
    </div>
</section>
<?php if ((int) ($pagination['pages'] ?? 1) > 1): ?>
    <nav class="script-pagination" aria-label="Stránkování scénáře">
        <?php if ((int) ($pagination['page'] ?? 1) > 1): ?>
            <a class="icon-control" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id'], 'page' => (int) $pagination['page'] - 1, 'filter' => $filter])) ?>" title="Předchozí stránka" aria-label="Předchozí stránka">←</a>
        <?php else: ?>
            <span class="icon-control is-disabled" aria-hidden="true">←</span>
        <?php endif; ?>
        <span>Strana <?= Http::escape((string) $pagination['page']) ?> / <?= Http::escape((string) $pagination['pages']) ?> · <?= Http::escape(number_format((int) $pagination['total'], 0, ',', ' ')) ?> replik</span>
        <?php if ((int) ($pagination['page'] ?? 1) < (int) ($pagination['pages'] ?? 1)): ?>
            <a class="icon-control" href="<?= Http::escape(Http::url('project', ['id' => (string) $project['id'], 'page' => (int) $pagination['page'] + 1, 'filter' => $filter])) ?>" title="Další stránka" aria-label="Další stránka">→</a>
        <?php else: ?>
            <span class="icon-control is-disabled" aria-hidden="true">→</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php if ($chapters === []): ?><section class="empty-projects compact-empty"><div class="empty-art" aria-hidden="true"><span class="empty-disc"></span><span class="empty-wave"></span></div><div><h2>Projekt zatím neobsahuje kapitoly.</h2><p>Importujte scénář při vytvoření projektu.</p></div></section><?php endif; ?>
<?php if ($chapters === [] && $filter === 'mine'): ?><p class="muted">Zatím nemáte uloženou žádnou repliku.</p><?php endif; ?>
<div class="studio-layout">
    <div class="script-column">
        <?php foreach ($chapters as $chapter): ?>
            <section class="chapter-block" aria-labelledby="chapter-<?= Http::escape((string) $chapter['id']) ?>">
                <div class="chapter-heading"><span class="chapter-index">Kapitola</span><h2 id="chapter-<?= Http::escape((string) $chapter['id']) ?>"><?= Http::escape((string) $chapter['title']) ?></h2><span class="chapter-rule"></span></div>
                <div class="line-list">
                    <?php foreach ($chapter['lines'] as $line): ?>
                        <article class="line-card" id="line-<?= Http::escape((string) $line['id']) ?>" data-line-card data-line-id="<?= Http::escape((string) $line['id']) ?>">
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
                                    <div class="take-row<?= !empty($take['is_selected']) ? ' take-selected' : '' ?><?= !empty($take['can_delete']) ? ' take-row-has-delete' : '' ?>">
                                        <div class="take-meta">
                                            <span class="attempt-badge" title="Číslo pokusu"><?= sprintf('%03d', $attemptNo) ?></span>
                                            <span class="take-name" title="<?= Http::escape((string) $take['user_name']) ?>">
                                                <span class="take-byline"><?= Http::escape((string) $take['user_name']) ?><?= !empty($take['is_selected']) ? ' · vybráno' : '' ?></span>
                                            </span>
                                        </div>
                                        <audio preload="none" controls src="<?= Http::escape($audioUrl) ?>" aria-label="Přehrát pokus <?= sprintf('%03d', $attemptNo) ?> od <?= Http::escape((string) $take['user_name']) ?>"></audio>
                                        <a class="icon-control take-download" href="<?= Http::escape($downloadUrl) ?>"<?= $fileName !== '' ? ' download="' . Http::escape($fileName) . '"' : '' ?> title="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>" aria-label="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>"><span aria-hidden="true">↓</span></a>
                                        <?php if (!empty($take['can_delete'])): ?>
                                            <?php $deletePanelId = 'delete-take-' . (int) $take['id']; ?>
                                            <div class="anchored-control take-delete-control">
                                                        <button class="icon-control take-delete-trigger" type="button" data-panel-trigger="<?= Http::escape($deletePanelId) ?>" title="Smazat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-label="Smazat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-expanded="false" aria-controls="<?= Http::escape($deletePanelId) ?>"><span class="trash-icon" aria-hidden="true"></span></button>
                                                <div class="popover take-delete-panel" id="<?= Http::escape($deletePanelId) ?>" data-panel hidden>
                                                    <h2>Smazat nahrávku?</h2>
                                                    <form method="post" action="<?= Http::escape(Http::url('delete-take', ['id' => (string) $project['id']])) ?>" class="delete-take-form">
                                                        <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                        <input type="hidden" name="line_id" value="<?= Http::escape((string) $line['id']) ?>">
                                                        <input type="hidden" name="recording_id" value="<?= Http::escape((string) $take['id']) ?>">
                                                        <input type="hidden" name="page" value="<?= Http::escape((string) ($pagination['page'] ?? 1)) ?>">
                                                        <input type="hidden" name="filter" value="<?= Http::escape($filter) ?>">
                                                        <button class="icon-control delete-confirm" type="submit" title="Potvrdit smazání" aria-label="Potvrdit smazání"><span class="trash-icon" aria-hidden="true"></span><span>Smazat</span></button>
                                                        <button class="icon-control" type="button" data-panel-close title="Zrušit mazání" aria-label="Zrušit mazání"><span aria-hidden="true">×</span></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($take['is_selected']) && (!empty($data['is_admin']) || ($project['role'] ?? '') === 'owner')): ?>
                                            <form method="post" action="<?= Http::escape(Http::url('select-take', ['id' => (string) $project['id']])) ?>" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                <input type="hidden" name="line_id" value="<?= Http::escape((string) $line['id']) ?>">
                                                <input type="hidden" name="recording_id" value="<?= Http::escape((string) $take['id']) ?>"><input type="hidden" name="page" value="<?= Http::escape((string) ($pagination['page'] ?? 1)) ?>"><input type="hidden" name="filter" value="<?= Http::escape($filter) ?>">
                                                <button class="icon-control take-select" type="submit" title="Vybrat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-label="Vybrat pokus <?= sprintf('%03d', $attemptNo) ?>"><span aria-hidden="true">✓</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (($line['can_record'] ?? true) === true): ?>
                                <div class="record-controls" data-recorder><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                    <div class="record-actions"><button type="button" class="icon-control record-button" data-record-start aria-describedby="recording-help" title="Nahrát repliku; mikrofon povolíte až po kliknutí" aria-label="Nahrát repliku <?= sprintf('%04d', (int) ($line['number'] ?? $line['position'])) ?>"><span class="record-dot" aria-hidden="true"></span></button><button type="button" class="icon-control record-button record-stop" data-record-stop title="Ukončit nahrávání" aria-label="Ukončit nahrávání" hidden><span class="stop-square" aria-hidden="true"></span></button><label class="icon-control file-pick" title="Vybrat zvukový soubor"><span aria-hidden="true">↥</span><span class="sr-only">Vybrat zvukový soubor</span><input type="file" accept="audio/*" data-audio-file aria-label="Vybrat zvukový soubor" aria-describedby="recording-help"></label><span class="record-live" data-record-live hidden><span class="record-live-label" aria-hidden="true">REC</span><span class="record-meter" data-record-meter role="meter" aria-label="Úroveň mikrofonu" aria-valuemin="-60" aria-valuemax="0" aria-valuenow="-60" aria-valuetext="-60 dBFS"><span data-record-meter-fill></span></span><span class="record-db" data-record-db aria-hidden="true">−60 dBFS</span></span></div>
                                    <p class="record-status" data-record-status aria-live="polite"></p>
                                    <div class="record-retry-area" data-record-retry-area hidden></div>
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
        <h2>Poslech</h2>
        <div class="master-player">
            <div class="master-controls">
                <button type="button" class="icon-control" data-play-previous aria-label="Předchozí replika" title="Předchozí replika">←</button>
                <audio controls preload="none" data-story-player aria-label="Souvislý poslech vybraných replik"></audio>
                <button type="button" class="icon-control" data-play-next aria-label="Další replika" title="Další replika">→</button>
            </div>
            <p class="playlist-current" data-playlist-status aria-live="polite">Vyberte repliku v seznamu nebo stiskněte Přehrát.</p>
        </div>
        <div class="playlist-items" data-playlist tabindex="0" role="region" aria-label="Seznam všech vybraných nahrávek">
            <?php if ($playlist === []): ?>
                <p class="playlist-empty">Nejsou vybrané žádné nahrávky.</p>
            <?php else: ?>
                <?php foreach ($playlist as $clip): ?>
                    <div class="playlist-line">
                        <button type="button" class="playlist-play" data-play-item aria-label="Přehrát repliku <?= sprintf('%04d', (int) $clip['line_number']) ?> — <?= Http::escape((string) $clip['character']) ?>" title="<?= Http::escape((string) $clip['file_name']) ?>" data-src="<?= Http::escape((string) $clip['audio_url']) ?>" data-label="<?= Http::escape((string) $clip['character']) ?> — <?= Http::escape((string) $clip['chapter_title']) ?>"><span aria-hidden="true">▶</span><span><?= sprintf('%04d', (int) $clip['line_number']) ?> · <?= Http::escape((string) $clip['character']) ?></span></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
