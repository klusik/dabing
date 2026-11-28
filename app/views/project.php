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
$memberNamesById = [];
foreach ($members as $member) {
    $memberNamesById[(int) $member['id']] = (string) $member['name'];
}
$canInvite = (bool) ($data['can_invite'] ?? false);
$voices = is_array($data['voices'] ?? null) ? $data['voices'] : [];
$filterVoices = is_array($data['filter_voices'] ?? null)
    ? $data['filter_voices']
    : $voices;
$pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : ['page' => 1, 'pages' => 1, 'total' => 0];
$playlist = is_array($data['playlist'] ?? null) ? $data['playlist'] : [];
$exportUrl = (string) ($data['export_url']
    ?? Http::url('export-book', ['id' => (int) ($project['id'] ?? 0)]));
$hasRecordings = (bool) ($data['has_recordings'] ?? false);
$filter = (string) ($data['filter'] ?? 'all');
$isAdmin = !empty($data['is_admin']);
$characters = is_array($data['characters'] ?? null) ? $data['characters'] : [];
$pagingEnabled = !empty($pagination['enabled']);
$pagingValue = $pagingEnabled ? 'on' : 'off';
$projectId = (int) ($project['id'] ?? 0);
$accountId = (int) ($user['id'] ?? 0);
$allFilterUrl = Http::url('project', [
    'id' => $projectId, 'filter' => 'all', 'paging' => $pagingValue,
]);
$mineFilterUrl = Http::url('project', [
    'id' => $projectId, 'filter' => 'mine', 'paging' => $pagingValue,
]);
$previousUrl = Http::url('project', [
    'id' => $projectId, 'filter' => $filter, 'paging' => $pagingValue,
    'page' => max(1, (int) ($pagination['page'] ?? 1) - 1),
]);
$nextUrl = Http::url('project', [
    'id' => $projectId, 'filter' => $filter, 'paging' => $pagingValue,
    'page' => (int) ($pagination['page'] ?? 1) + 1,
]);
if ($filter === 'characters') {
    foreach ($characters as $character) {
        $previousUrl .= '&characters%5B%5D=' . rawurlencode((string) $character);
        $nextUrl .= '&characters%5B%5D=' . rawurlencode((string) $character);
    }
}
?>
<div
    data-project-live
    data-project-id="<?= Http::escape((string) $projectId) ?>"
    data-account-id="<?= Http::escape((string) $accountId) ?>"
    data-revision="<?= Http::escape((string) ($data['revision'] ?? '')) ?>"
    data-paging-enabled="<?= $pagingEnabled ? 'on' : 'off' ?>"
    data-audio-profiles="<?= Http::escape(json_encode($data['audio_profiles'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?>"
>
<section class="workspace-heading">
    <div class="workspace-title"><a class="back-link" href="<?= Http::escape(Http::url('projects')) ?>" title="Moje knihy" aria-label="Zpět na moje knihy">←<span class="sr-only">Zpět na moje knihy</span></a><h1><?= Http::escape((string) ($project['title'] ?? 'Audiokniha')) ?></h1></div>
    <div class="workspace-actions">
        <?php if ($canInvite): ?>
            <div class="anchored-control">
                <button class="icon-control" type="button" data-panel-trigger="invite-panel" title="Spravovat pozvánky" aria-label="Spravovat pozvánky" aria-expanded="false" aria-controls="invite-panel"><span aria-hidden="true">✉</span></button>
                <div class="popover invite-popover" id="invite-panel" data-panel hidden>
                    <h2>Pozvat spolupracovníka</h2>
                    <?php require __DIR__ . '/project-invitations.php'; ?>
                    <p class="project-action-status" data-panel-status aria-live="polite"></p>
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
        <div class="member-stack" data-project-members aria-label="Členové projektu"><?php foreach (array_slice($members, 0, 4) as $member): ?><span class="member-avatar" title="<?= Http::escape((string) $member['name']) ?>"><?= Http::escape((preg_match('/\\A./us', (string) $member['name'], $initial) === 1 ? strtoupper($initial[0]) : 'D')) ?></span><?php endforeach; ?><span class="member-count"><?= count($members) ?> <?= count($members) === 1 ? 'hlas' : (count($members) >= 2 && count($members) < 5 ? 'hlasy' : 'hlasů') ?></span></div>
    </div>
</section>
<section
    class="studio-toolbar"
    data-project-id="<?= Http::escape((string) $project['id']) ?>"
>
    <?php $audioProfiles = is_array($data['audio_profiles'] ?? null) ? $data['audio_profiles'] : []; ?>
    <?php if ($isAdmin && $audioProfiles !== []): ?>
        <div class="anchored-control audio-profile-bulk">
            <button class="icon-control" type="button" data-panel-trigger="audio-profile-bulk-panel" title="Zvukové profily" aria-label="Zvukové profily" aria-expanded="false" aria-controls="audio-profile-bulk-panel"><span aria-hidden="true">♫</span></button>
            <div class="popover audio-profile-popover" id="audio-profile-bulk-panel" data-panel hidden>
                <h2>Profil hlasu</h2>
                <form data-audio-profile-assignment data-project-async data-scope="voice" action="<?= Http::escape(Http::url('assign-audio-profile', ['id' => (string) $projectId])) ?>">
                    <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                    <input type="hidden" name="scope" value="voice">
                    <label>Postava <select name="character" required><?php foreach ($voices as $voice): ?><option value="<?= Http::escape((string) $voice['character']) ?>"><?= Http::escape((string) $voice['character']) ?></option><?php endforeach; ?></select></label>
                    <label>Hlas <select name="user_id" required><?php foreach (($data['role_users'] ?? []) as $roleUser): ?><option value="<?= (int) $roleUser['id'] ?>"><?= Http::escape((string) $roleUser['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Profil <select name="profile" required><option value="inherit">Dědit</option><option value="none">Bez efektů</option><?php foreach ($audioProfiles as $audioProfile): ?><option value="<?= (int) $audioProfile['id'] ?>"><?= Http::escape((string) $audioProfile['name']) ?></option><?php endforeach; ?></select></label>
                    <button class="icon-control" type="submit">Použít</button>
                    <p data-audio-profile-status aria-live="polite"></p>
                </form>
            </div>
        </div>
    <?php endif; ?>
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
        <a class="<?= $filter === 'all' ? 'is-current' : '' ?>" data-project-nav href="<?= Http::escape($allFilterUrl) ?>"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>Všechny</a>
        <a class="<?= $filter === 'mine' ? 'is-current' : '' ?>" data-project-nav href="<?= Http::escape($mineFilterUrl) ?>"<?= $filter === 'mine' ? ' aria-current="page"' : '' ?>>Moje repliky</a>
        <?php if ($filterVoices !== []): ?>
            <div class="anchored-control character-filter-control">
                <button class="script-filter-trigger<?= $filter === 'characters' ? ' is-current' : '' ?>" type="button" data-panel-trigger="character-filter-panel" aria-controls="character-filter-panel" aria-expanded="false" title="Filtrovat podle postavy">Postavy<?= $filter === 'characters' ? ' · ' . count($characters) : '' ?></button>
                <div class="popover character-filter-popover" id="character-filter-panel" data-panel hidden>
                    <div class="character-filter-cells" role="group" aria-label="Postavy knihy">
                <?php foreach ($filterVoices as $voice): ?>
                    <?php $characterName = (string) $voice['character']; ?>
                    <?php
                    $assignedNames = is_array($voice['assigned_names'] ?? null)
                        ? $voice['assigned_names']
                        : array_values(array_filter(array_map(
                        static fn (int $userId): ?string => $memberNamesById[$userId] ?? null,
                        $voice['assigned_users'] ?? []
                        )));
                    $assignedSummary = implode(', ', $assignedNames);
                    $isCharacterSelected = in_array($characterName, $characters, true);
                    $lineCount = (int) ($voice['line_count'] ?? 0);
                    $dubbedCount = (int) ($voice['dubbed_count'] ?? 0);
                    $missingCount = max(0, $lineCount - $dubbedCount);
                    ?>
                    <button class="character-filter-cell<?= $isCharacterSelected ? ' is-selected' : '' ?>" type="button" data-character-filter value="<?= Http::escape($characterName) ?>" aria-pressed="<?= $isCharacterSelected ? 'true' : 'false' ?>" title="<?= Http::escape($characterName . ($assignedSummary !== '' ? ' · ' . $assignedSummary : '')) ?>">
                        <span class="character-filter-name"><?= Http::escape($characterName) ?></span>
                        <span class="character-filter-assignees"<?= $assignedSummary === '' ? ' aria-hidden="true"' : '' ?>><?= Http::escape($assignedSummary) ?></span>
                        <span class="character-filter-count" aria-label="Replik celkem <?= $lineCount ?>" title="Replik celkem: <?= $lineCount ?>"><span aria-hidden="true">▤</span><?= $lineCount ?></span>
                        <span class="character-filter-count" aria-label="Nadabováno <?= $dubbedCount ?>" title="Nadabováno: <?= $dubbedCount ?>"><span aria-hidden="true">♫</span><?= $dubbedCount ?></span>
                        <span class="character-filter-count" aria-label="Chybí <?= $missingCount ?>" title="Chybí: <?= $missingCount ?>"><span class="character-filter-missing-icon" aria-hidden="true">♫</span><?= $missingCount ?></span>
                    </button>
                <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </nav>
    <div class="studio-toolbar-actions">
        <label class="paging-toggle" title="Zobrazit scénář po stránkách">
            <input type="checkbox" role="switch" data-paging-switch aria-label="Stránkování"<?= $pagingEnabled ? ' checked' : '' ?>>
            <span class="paging-switch-track" aria-hidden="true"></span>
            <span>Stránkování</span>
        </label>
        <a
            class="icon-control"
            href="#poslech"
            title="Přejít k poslechu"
            aria-label="Přejít k poslechu"
        >
            <span aria-hidden="true">↓</span>
        </a>
        <?php if ($hasRecordings && ($isAdmin || ($project['role'] ?? '') === 'owner')): ?>
            <?php if (!empty($data['audio_has_effects'])): ?>
                <button class="icon-control zip-export-control" type="button" data-audio-book-export data-url="<?= Http::escape(Http::url('audio-export', ['id' => (string) $projectId])) ?>" title="Stáhnout originály a kopie s efekty" aria-label="Stáhnout originály a kopie s efekty"><span class="zip-export-glyph" aria-hidden="true">♫ ZIP</span></button>
            <?php endif; ?>
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
    <p class="project-action-status" data-project-status data-audio-feedback aria-live="polite"></p>
</section>
<div data-project-content>
<?php if ((int) ($pagination['pages'] ?? 1) > 1): ?>
    <nav class="script-pagination" aria-label="Stránkování scénáře">
        <?php if ((int) ($pagination['page'] ?? 1) > 1): ?>
            <a class="icon-control" data-project-nav href="<?= Http::escape($previousUrl) ?>" title="Předchozí stránka" aria-label="Předchozí stránka">←</a>
        <?php else: ?>
            <span class="icon-control is-disabled" aria-hidden="true">←</span>
        <?php endif; ?>
        <span>Strana <?= Http::escape((string) $pagination['page']) ?> / <?= Http::escape((string) $pagination['pages']) ?> · <?= Http::escape(number_format((int) $pagination['total'], 0, ',', ' ')) ?> replik</span>
        <?php if ((int) ($pagination['page'] ?? 1) < (int) ($pagination['pages'] ?? 1)): ?>
            <a class="icon-control" data-project-nav href="<?= Http::escape($nextUrl) ?>" title="Další stránka" aria-label="Další stránka">→</a>
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
                            <div class="line-identity"><span class="line-number"><?= sprintf('%04d', (int) ($line['number'] ?? $line['position'])) ?></span><span class="character-name"><?= Http::escape((string) $line['character']) ?></span><?php if (!empty($line['assigned_name'])): ?><span class="assigned-name" title="Přiřazeno: <?= Http::escape((string) $line['assigned_name']) ?>"><?= Http::escape((string) $line['assigned_name']) ?></span><?php endif; ?><?php if ($isAdmin && $audioProfiles !== []): ?><form data-audio-profile-assignment data-project-async data-scope="line" action="<?= Http::escape(Http::url('assign-audio-profile', ['id' => (string) $projectId])) ?>"><input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>"><input type="hidden" name="scope" value="line"><input type="hidden" name="target_id" value="<?= (int) $line['id'] ?>"><select name="profile" aria-label="Zvukový profil repliky <?= sprintf('%04d', (int) ($line['number'] ?? $line['position'])) ?>"><option value="inherit"<?= ($line['audio_profile_override'] ?? null) === null ? ' selected' : '' ?>>Dědit</option><option value="none"<?= (int) ($line['audio_profile_override'] ?? -1) === 0 ? ' selected' : '' ?>>Bez efektů</option><?php foreach ($audioProfiles as $audioProfile): ?><option value="<?= (int) $audioProfile['id'] ?>"<?= (int) ($line['audio_profile_override'] ?? -1) === (int) $audioProfile['id'] ? ' selected' : '' ?>><?= Http::escape((string) $audioProfile['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?></div>
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
                                    <?php $isHidden = !empty($take['hidden']); ?>
                                    <div class="take-row<?= !empty($take['is_selected']) ? ' take-selected' : '' ?><?= !empty($take['can_delete']) ? ' take-row-has-delete' : '' ?><?= $isHidden ? ' take-hidden' : '' ?>" data-recording-id="<?= Http::escape((string) $take['id']) ?>" data-audio-profile-id="<?= Http::escape((string) ($take['audio_profile_id'] ?? '')) ?>">
                                        <div class="take-meta">
                                            <span class="attempt-badge" title="Číslo pokusu"><?= sprintf('%03d', $attemptNo) ?></span>
                                            <span class="take-name" title="<?= Http::escape((string) $take['user_name']) ?>">
                                                <span class="take-byline"><?= Http::escape((string) $take['user_name']) ?><?= !empty($take['is_selected']) ? ' · vybráno' : '' ?></span>
                                                <?php if ($isHidden): ?><span class="take-hidden-label" title="Tato nahrávka je skrytá">Skryto</span><?php endif; ?>
                                            </span>
                                        </div>
                                        <audio data-take-audio="<?= Http::escape((string) $take['id']) ?>" preload="none" controls src="<?= Http::escape($audioUrl) ?>" aria-label="Přehrát pokus <?= sprintf('%03d', $attemptNo) ?> od <?= Http::escape((string) $take['user_name']) ?>"></audio>
                                        <?php if ($isAdmin && $audioProfiles !== []): ?>
                                            <form data-audio-profile-assignment data-project-async data-scope="take" action="<?= Http::escape(Http::url('assign-audio-profile', ['id' => (string) $projectId])) ?>">
                                                <input type="hidden" name="scope" value="take">
                                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>"><input type="hidden" name="target_id" value="<?= (int) $take['id'] ?>">
                                                <select name="profile" aria-label="Zvukový profil pokusu <?= sprintf('%03d', $attemptNo) ?>"><option value="inherit"<?= ($take['audio_profile_override'] ?? null) === null ? ' selected' : '' ?>>Dědit</option><option value="none"<?= (int) ($take['audio_profile_override'] ?? -1) === 0 ? ' selected' : '' ?>>Bez efektů</option><?php foreach ($audioProfiles as $audioProfile): ?><option value="<?= (int) $audioProfile['id'] ?>"<?= (int) ($take['audio_profile_override'] ?? -1) === (int) $audioProfile['id'] ? ' selected' : '' ?>><?= Http::escape((string) $audioProfile['name']) ?></option><?php endforeach; ?></select>
                                            </form>
                                            <a class="icon-control" href="<?= Http::escape(Http::url('audio-profiles', array_filter([
                                                'id' => $take['audio_profile_id'] ?? null,
                                                'sample' => $take['id'],
                                            ], static fn (mixed $value): bool => $value !== null))) ?>" title="Vyzkoušet zvukový profil" aria-label="Vyzkoušet zvukový profil na této nahrávce"><span aria-hidden="true">♫</span></a>
                                        <?php endif; ?>
                                        <a class="icon-control take-download" data-audio-double-download data-file-name="<?= Http::escape($fileName) ?>" href="<?= Http::escape($downloadUrl) ?>"<?= $fileName !== '' ? ' download="' . Http::escape($fileName) . '"' : '' ?> title="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>" aria-label="Stáhnout <?= Http::escape($fileName !== '' ? $fileName : 'nahrávku') ?>"><span aria-hidden="true">↓</span></a>
                                        <?php if (!empty($take['can_hide']) || ($isAdmin && $isHidden)): ?>
                                            <form method="post" action="<?= Http::escape(Http::url('hide-take', ['id' => (string) $project['id']])) ?>" class="take-hide-form" data-project-async>
                                                <input type="hidden" name="csrf" value="<?= Http::escape(Http::csrf()) ?>">
                                                <input type="hidden" name="line_id" value="<?= Http::escape((string) $line['id']) ?>">
                                                <input type="hidden" name="recording_id" value="<?= Http::escape((string) $take['id']) ?>">
                                                <input type="hidden" name="hidden" value="<?= $isHidden ? '0' : '1' ?>">
                                                <button class="icon-control take-hide-trigger" type="submit" title="<?= $isHidden ? 'Zobrazit nahrávku' : 'Skrýt nahrávku' ?>" aria-label="<?= $isHidden ? 'Zobrazit nahrávku' : 'Skrýt nahrávku' ?>"><span aria-hidden="true"><?= $isHidden ? '◉' : '⊘' ?></span></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($take['can_delete'])): ?>
                                            <?php $deletePanelId = 'delete-take-' . (int) $take['id']; ?>
                                            <div class="anchored-control take-delete-control">
                                                        <button class="icon-control take-delete-trigger" type="button" data-panel-trigger="<?= Http::escape($deletePanelId) ?>" title="Smazat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-label="Smazat pokus <?= sprintf('%03d', $attemptNo) ?>" aria-expanded="false" aria-controls="<?= Http::escape($deletePanelId) ?>"><span class="trash-icon" aria-hidden="true"></span></button>
                                                <div class="popover take-delete-panel" id="<?= Http::escape($deletePanelId) ?>" data-panel hidden>
                                                    <h2>Smazat nahrávku?</h2>
                                                    <form method="post" action="<?= Http::escape(Http::url('delete-take', ['id' => (string) $project['id']])) ?>" class="delete-take-form" data-project-async>
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
                                        <?php if (empty($take['is_selected']) && ($isAdmin || ($project['role'] ?? '') === 'owner')): ?>
                                            <form method="post" action="<?= Http::escape(Http::url('select-take', ['id' => (string) $project['id']])) ?>" class="inline-form" data-project-async>
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
                        <button type="button" class="playlist-play" data-play-item data-recording-id="<?= Http::escape((string) $clip['recording_id']) ?>" data-audio-profile-id="<?= Http::escape((string) ($clip['audio_profile_id'] ?? '')) ?>" aria-label="Přehrát repliku <?= sprintf('%04d', (int) $clip['line_number']) ?> — <?= Http::escape((string) $clip['character']) ?>" title="Přehrát · <?= Http::escape((string) $clip['file_name']) ?>" data-src="<?= Http::escape((string) $clip['audio_url']) ?>" data-label="<?= Http::escape((string) $clip['character']) ?> — <?= Http::escape((string) $clip['chapter_title']) ?>"><span aria-hidden="true">▶</span></button>
                        <button type="button" class="playlist-jump" data-play-jump data-play-line-id="<?= Http::escape((string) $clip['line_id']) ?>" data-play-line-page="<?= Http::escape((string) $clip['line_page']) ?>" data-play-in-filter="<?= !empty($clip['in_filter']) ? '1' : '0' ?>" aria-label="Přejít na repliku <?= sprintf('%04d', (int) $clip['line_number']) ?> — <?= Http::escape((string) $clip['character']) ?>" title="Přejít na repliku <?= sprintf('%04d', (int) $clip['line_number']) ?>"><?= sprintf('%04d', (int) $clip['line_number']) ?> · <?= Http::escape((string) $clip['character']) ?></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
</div>
</div>
