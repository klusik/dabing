<?php
declare(strict_types=1);

namespace Dabing;

use PDO;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/controllers/StudioController.php
 * Module Type: Controller
 * Purpose: Handle authenticated studio project HTTP actions.
 * Responsibilities: Enforce request methods, authorization, CSRF, and view models.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Dispatches user-facing studio project operations. */
final class StudioController
{
    /**
     * Type: int; Units: bytes; Scope: audio response; Consumers: audio().
     * Rationale: bounds output buffering at 8 KiB for streaming playback.
     */
    private const AUDIO_CHUNK_BYTES = 8192;

    private readonly StudioModel $model;
    private readonly StudioService $service;

    /**
     * Build the controller with configured persistence and private storage.
     *
     * @param PDO $database Shared database connection.
     * @param string $storageDirectory Absolute private storage root.
     * @param array<string,mixed> $user Authenticated user row containing id/name.
     * @return void
     */
    public function __construct(
        PDO $database,
        string $storageDirectory,
        private readonly array $user
    ) {
        $this->model = new StudioModel($database);
        $this->service = new StudioService(
            $this->model,
            new ScriptImport(),
            new RecordingStorage($storageDirectory)
        );
    }

    /**
     * Show or create the user's private project list.
     *
     * @return void
     */
    public function projects(): void
    {
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            try {
                $projectId = $this->service->createProject(
                    $this->userId(),
                    $this->scriptPayload()
                );
            } catch (FormError $error) {
                Http::notice('error', $error->getMessage());
                Http::redirect('projects');
            }
            Http::redirect('project', ['id' => $projectId]);
        }
        $projects = $this->model->projectsForUser($this->userId());
        Http::render('projects', 'Moje knihy', ['projects' => $projects], $this->user);
    }

    /**
     * Show an authorized project and its takes.
     *
     * @return void
     */
    public function project(): void
    {
        if (Http::method() !== 'GET') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $view = $this->model->projectView($projectId, $this->userId());
        if ($view === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $view['can_invite'] = $view['project']['role'] === 'owner';
        $view['invite_url'] = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $inviteUrl = $_SESSION['studio_invite_url'] ?? null;
            unset($_SESSION['studio_invite_url']);
            $view['invite_url'] = is_string($inviteUrl) ? $inviteUrl : null;
        }
        Http::render('project', $view['project']['title'], $view, $this->user);
    }

    /**
     * Accept an authenticated audio take upload and return JSON status.
     *
     * @return void
     */
    public function upload(): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        $lineId = Http::postId('line_id');
        $file = Http::file('audio');
        if ($projectId === null || $lineId === null || $file === null) {
            Http::json(['ok' => false, 'message' => 'Chybí kniha, replika nebo nahrávka.'], 422);
            return;
        }
        try {
            $result = $this->service->replaceTake(
                $projectId,
                $lineId,
                $this->userId(),
                $file
            );
        } catch (FormError $error) {
            Http::json(['ok' => false, 'message' => $error->getMessage()], 422);
            return;
        }
        Http::json([
            'ok' => true,
            'message' => 'Nahrávka byla uložena.',
            'recording_id' => $result['recording_id'],
        ]);
    }

    /**
     * Create an owner-only, single-use collaborator invitation.
     *
     * @return void
     */
    public function invite(): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        try {
            $token = $this->service->createInvite($projectId, $this->userId());
        } catch (FormError $error) {
            throw new HttpError(403, $error->getMessage());
        }
        $url = Http::url('join', ['token' => $token]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['studio_invite_url'] = $url;
        }
        Http::notice('success', 'Jednorázový odkaz k pozvánce je připraven.');
        Http::redirect('project', ['id' => $projectId]);
    }

    /**
     * Preview and claim a one-time invitation for the signed-in user.
     *
     * @return void
     */
    public function join(): void
    {
        $token = (string) Http::query('token');
        if (Http::method() === 'POST') {
            Http::requireCsrf();
            try {
                $projectId = $this->service->claimInvite($token, $this->userId());
            } catch (FormError $error) {
                Http::notice('error', $error->getMessage());
                Http::redirect('projects');
            }
            Http::redirect('project', ['id' => $projectId]);
        }
        if (Http::method() !== 'GET') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        $validToken = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
        Http::render('join', 'Pozvánka do knihy', [
            'token' => $validToken ? $token : '',
            'valid' => $validToken,
        ], $this->user);
    }

    /**
     * Let project owners select one collaborator take for a line.
     *
     * @return void
     */
    public function selectTake(): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        $lineId = Http::postId('line_id');
        $recordingId = Http::postId('recording_id');
        if ($projectId === null || $lineId === null || $recordingId === null
            || !$this->model->selectTake(
                $projectId,
                $lineId,
                $recordingId,
                $this->userId()
            )) {
            throw new HttpError(403, 'Tento záznam nelze vybrat.');
        }
        Http::redirect('project', ['id' => $projectId]);
    }

    /**
     * Stream authorized private audio with HEAD and single byte-range support.
     *
     * @return void
     */
    public function audio(): void
    {
        $method = Http::method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        $recordingId = Http::queryId('id');
        $opened = $recordingId === null
            ? null
            : $this->service->openRecording($recordingId, $this->userId());
        if ($opened === null) {
            throw new HttpError(404, 'Nahrávka nebyla nalezena.');
        }
        $recording = $opened['recording'];
        $stream = $opened['stream'];
        $size = (int) $recording['byte_size'];
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (is_string($range) && $range !== '') {
            if (preg_match('/\Abytes=(\d*)-(\d*)\z/D', trim($range), $matches) !== 1
                || $size < 1) {
                $this->service->closeRecording($stream);
                header('Content-Range: bytes */' . $size);
                throw new HttpError(416, 'Požadovaný rozsah není podporován.');
            }
            if ($matches[1] === '' && $matches[2] === '') {
                $this->service->closeRecording($stream);
                header('Content-Range: bytes */' . $size);
                throw new HttpError(416, 'Požadovaný rozsah není podporován.');
            }
            if ($matches[1] === '') {
                $suffix = (int) $matches[2];
                if ($suffix < 1) {
                    $this->service->closeRecording($stream);
                    header('Content-Range: bytes */' . $size);
                    throw new HttpError(416, 'Požadovaný rozsah není podporován.');
                }
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : (int) $matches[2];
            }
            if ($start >= $size || $end < $start) {
                $this->service->closeRecording($stream);
                header('Content-Range: bytes */' . $size);
                throw new HttpError(416, 'Požadovaný rozsah je mimo nahrávku.');
            }
            $end = min($end, $size - 1);
            $status = 206;
        }
        $length = $end - $start + 1;
        if (fseek($stream, $start) !== 0) {
            $this->service->closeRecording($stream);
            throw new HttpError(500, 'Nahrávku nelze přečíst.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code($status);
        header('Content-Type: ' . $recording['media_type']);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        if ($status === 206) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        if ($method === 'GET') {
            $remaining = $length;
            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, min(self::AUDIO_CHUNK_BYTES, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        }
        $this->service->closeRecording($stream);
    }

    /**
     * Read JSON from the selected import file or text fallback field.
     *
     * @return string Raw JSON payload within the import byte limit.
     * @throws FormError When the selected upload cannot be read safely.
     */
    private function scriptPayload(): string
    {
        $file = Http::file('script_file');
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return (string) Http::post('script', false);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'Soubor scénáře přesahuje limit serveru nebo 2 MiB.',
                UPLOAD_ERR_PARTIAL => 'Soubor scénáře se nahrál pouze částečně.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server nemá dočasné úložiště uploadů.',
                UPLOAD_ERR_CANT_WRITE => 'Server nemohl soubor scénáře uložit.',
                UPLOAD_ERR_EXTENSION => 'Nahrání souboru zastavilo rozšíření serveru.',
                default => 'Soubor scénáře se nepodařilo nahrát.',
            };
            throw new FormError($message);
        }
        if ($file['size'] < 1
            || $file['size'] > ScriptImport::MAX_BYTES
            || !is_uploaded_file($file['tmp_name'])) {
            throw new FormError('Soubor scénáře je neplatný nebo přesahuje 2 MiB.');
        }
        $contents = file_get_contents(
            $file['tmp_name'],
            false,
            null,
            0,
            ScriptImport::MAX_BYTES + 1
        );
        if (!is_string($contents)
            || strlen($contents) !== $file['size']
            || strlen($contents) > ScriptImport::MAX_BYTES) {
            throw new FormError('Soubor scénáře se nepodařilo přečíst.');
        }
        return $contents;
    }
    /**
     * Return the authenticated user's numeric identifier.
     *
     * @return int Authenticated user id.
     * @throws HttpError If the user row has no valid id.
     */
    private function userId(): int
    {
        $id = $this->user['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new HttpError(401, 'Přihlášení vypršelo.');
        }
        return (int) $id;
    }
}