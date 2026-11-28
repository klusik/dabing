<?php
declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/AudioProfileController.php
 * Module Type: Controller
 * Purpose: Handle administrator audio profile and project assignment routes.
 * Responsibilities: Enforce HTTP methods, authorization, CSRF, and responses.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Dispatches administrator profile editing and scoped profile assignment. */
final class AudioProfileController
{
    private readonly AudioProfileModel $model;
    private readonly AudioProfileService $service;

    /** Build the profile controller.
     * @param PDO $database Configured application database.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Authenticated user.
     * @return void No value is returned.
     */
    public function __construct(
        private readonly PDO $database,
        private readonly array $user
    ) {
        $this->model = new AudioProfileModel($database);
        $this->service = new AudioProfileService($this->model);
    }

    /** Show the global profile editor or save one profile.
     * @return void Sends a rendered page or redirects after a successful save.
     */
    public function handle(): void
    {
        $this->requireAdmin();
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            $id = Http::postId('profile_id');
            $raw = Http::post('chain');
            try {
                $chain = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->renderEditor(
                    $id, Http::postId('sample'),
                    'Řetězec zvukových modulů není platný.',
                    Http::post('name'), $raw
                );
                return;
            }
            try {
                $savedId = $this->service->save($id, Http::post('name'), $chain);
            } catch (FormError $error) {
                $this->renderEditor(
                    $id, Http::postId('sample'), $error->getMessage(),
                    Http::post('name'), $raw
                );
                return;
            }
            Http::notice('success', 'Zvukový profil byl uložen.');
            $params = ['id' => $savedId];
            $sampleId = Http::postId('sample');
            if ($sampleId !== null) {
                $params['sample'] = $sampleId;
            }
            Http::redirect('audio-profiles', $params);
        }
        if (Http::method() !== 'GET') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        $this->renderEditor(Http::queryId('id'), Http::queryId('sample'));
    }

    /** Render editor data and validate the requested sample's project access.
     * @param int|null $profileId Profile identifier being edited.
     * @param int|null $sampleId Recording identifier for an optional preview.
     * @param string|null $formError Validation message to display.
     * @param string|null $submittedName Submitted name preserved after validation.
     * @param string|null $submittedChainJson Submitted JSON preserved after validation.
     * @return void Renders the profile editor.
     */
    private function renderEditor(
        ?int $profileId,
        ?int $sampleId,
        ?string $formError = null,
        ?string $submittedName = null,
        ?string $submittedChainJson = null
    ): void {
        $profile = $profileId === null ? null : $this->model->profile($profileId);
        if ($profileId !== null && $profile === null) {
            throw new HttpError(404, 'Zvukový profil nebyl nalezen.');
        }
        $sample = null;
        if ($sampleId !== null) {
            $recording = (new StudioModel($this->database))->recordingForUser(
                $sampleId, (int) $this->user['id'], true
            );
            if ($recording === null) {
                throw new HttpError(404, 'Nahrávka nebyla nalezena.');
            }
            $sample = [
                'id' => $sampleId,
                'url' => Http::url('audio', ['id' => $sampleId]),
                'label' => TakeNaming::fileName(
                    $recording['project_title'], $recording['line_number'],
                    $recording['attempt_no'], $recording['media_type']
                ),
            ];
        }
        Http::render('audio-profiles', 'Zvukové profily', [
            'profiles' => $this->model->profiles(),
            'profile' => $profile,
            'default_chain' => $this->service->defaultChain(),
            'sample' => $sample,
            'form_error' => $formError,
            'submitted_name' => $submittedName,
            'submitted_chain_json' => $submittedChainJson,
            'profile_id' => $profileId,
        ], $this->user);
    }

    /** Assign, clear, or suppress a profile at a project scope.
     * @return void Sends JSON for fetch clients or redirects for form posts.
     */
    public function assign(): void
    {
        $this->requireAdmin();
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        $scope = Http::post('scope');
        $targetId = Http::postId('target_id');
        $character = Http::post('character', false);
        $userId = Http::postId('user_id');
        $choice = Http::post('profile');
        if ($projectId === null || !in_array($scope, ['voice', 'line', 'take'], true)
            || !in_array($choice, ['inherit', 'none'], true)
                && preg_match('/\A[1-9][0-9]*\z/D', $choice) !== 1) {
            throw new HttpError(422, 'Přiřazení zvukového profilu není platné.');
        }
        $profileId = $choice === 'inherit' ? null
            : ($choice === 'none' ? null : (int) $choice);
        $existing = $this->model->profiles();
        if ($profileId !== null && !in_array(
            $profileId, array_column($existing, 'id'), true
        )) {
            throw new HttpError(422, 'Zvukový profil nebyl nalezen.');
        }
        if (($scope === 'voice' && ($character === '' || $userId === null || $targetId !== null))
            || ($scope !== 'voice' && ($targetId === null || $character !== '' || $userId !== null))) {
            throw new HttpError(422, 'Cíl přiřazení není platný.');
        }
        $studio = new StudioModel($this->database);
        if ($studio->projectRevision($projectId, (int) $this->user['id'], true) === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        if ($choice === 'inherit') {
            $this->model->remove($projectId, $scope, $targetId,
                $scope === 'voice' ? $character : null,
                $scope === 'voice' ? $userId : null);
        } else {
            $this->model->assign($projectId, $scope, $targetId,
                $scope === 'voice' ? $character : null,
                $scope === 'voice' ? $userId : null,
                $profileId);
        }
        if (Http::wantsJson()) {
            Http::json([
                'ok' => true,
                'message' => 'Přiřazení zvukového profilu bylo uloženo.',
            ]);
        }
        Http::notice('success', 'Přiřazení zvukového profilu bylo uloženo.');
        Http::redirect('project', ['id' => $projectId]);
    }

    /** Send the profile manifest for every book take, with private audio URLs.
     * @return void Sends a no-store JSON response or an HTTP access error.
     */
    public function exportManifest(): void
    {
        if (Http::method() !== 'GET') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        if (empty($this->user['is_admin'])) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $book = $this->model->manifestRows($projectId);
        if ($book === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $profiles = $this->model->profiles($projectId);
        $profileIds = array_fill_keys(array_column($profiles, 'id'), true);
        $service = new AudioProfileService($this->model);
        $assignments = $service->indexAssignments(
            $this->model->assignments($projectId)
        );
        $takes = [];
        foreach ($book['takes'] as $take) {
            $resolved = $service->resolveAssignment(
                $assignments, $take['line_id'], $take['id'],
                $take['character'], $take['user_id']
            );
            $profileId = $resolved['profile_id'] !== null
                && isset($profileIds[$resolved['profile_id']])
                ? $resolved['profile_id'] : null;
            $takes[] = [
                'id' => $take['id'],
                'file_name' => TakeNaming::fileName(
                    $book['title'], $take['line_number'],
                    $take['attempt_no'], $take['media_type']
                ),
                'audio_url' => Http::url('audio', ['id' => $take['id']]),
                'profile_id' => $profileId,
            ];
        }
        header('Cache-Control: private, no-store');
        Http::json(['ok' => true, 'file_name' => $book['title'],
            'takes' => $takes, 'profiles' => $profiles]);
    }

    /** Reject profile editing or assignment by non-administrators.
     * @return void Returns only for global administrators.
     * @throws HttpError When the current account is not an administrator.
     */
    private function requireAdmin(): void
    {
        if (empty($this->user['is_admin'])) {
            throw new HttpError(403, 'Zvukové profily spravuje pouze administrátor.');
        }
    }

}
