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

    /**
     * Type: int; Units: bytes; Scope: multipart form overhead; Consumers:
     * scriptLimits(); Rationale: reserve 1 MiB for boundaries and form fields.
     */
    private const SCRIPT_MULTIPART_HEADROOM_BYTES = 1048576;

    private readonly StudioModel $model;
    private readonly StudioService $service;

    /**
     * Build the controller with configured persistence and private storage.
     *
     * @param PDO $database Shared database connection.
     * @param string $storageDirectory Absolute private storage root.
     * @param array{id:int,name:string,email:string,is_admin:bool} $user Authenticated account data.
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
            if ($this->requestBodyExceedsPostLimit()) {
                Http::notice(
                    'error',
                    'Požadavek přesahuje limit post_max_size. Zvyšte limit nebo zvolte menší soubor.'
                );
                Http::redirect('projects');
            }
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
        $limits = $this->scriptLimits();
        Http::render('projects', 'Moje knihy', [
            'projects' => $projects,
            'max_script_bytes' => $limits['file'],
            'max_script_paste_bytes' => $limits['paste'],
        ], $this->user);
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
        $view = $this->model->projectView(
            $projectId,
            $this->userId(),
            Http::queryId('page') ?? 1
        );
        if ($view === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        foreach ($view['chapters'] as &$chapter) {
            foreach ($chapter['lines'] as &$line) {
                foreach ($line['takes'] as &$take) {
                    $take['file_name'] = TakeNaming::fileName(
                        $view['project']['title'],
                        $line['number'],
                        $take['attempt_no'],
                        $take['media_type']
                    );
                    $take['audio_url'] = Http::url(
                        'audio', ['id' => $take['id']]
                    );
                    $take['download_url'] = Http::url('audio', [
                        'id' => $take['id'],
                        'download' => 1,
                    ]);
                }
                unset($take);
            }
            unset($line);
        }
        unset($chapter);
        foreach ($view['playlist'] as &$track) {
            $track['file_name'] = TakeNaming::fileName(
                $view['project']['title'],
                $track['line_number'],
                $track['attempt_no'],
                $track['media_type']
            );
            $track['audio_url'] = Http::url(
                'audio', ['id' => $track['recording_id']]
            );
            $track['download_url'] = Http::url('audio', [
                'id' => $track['recording_id'],
                'download' => 1,
            ]);
        }
        unset($track);
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
        $tokenValue = Http::post('take_token');
        $takeToken = $tokenValue === '' ? null : $tokenValue;
        try {
            $result = $this->service->addTake(
                $projectId,
                $lineId,
                $this->userId(),
                $file,
                $takeToken
            );
        } catch (FormError $error) {
            Http::json(['ok' => false, 'message' => $error->getMessage()], 422);
            return;
        }
        Http::json([
            'ok' => true,
            'message' => $result['reused']
                ? 'Pokus už byl uložen.' : 'Nový pokus byl uložen.',
            'recording_id' => $result['recording_id'],
            'attempt_no' => $result['attempt_no'],
            'reused' => $result['reused'],
            'file_name' => $result['file_name'],
            'audio_url' => Http::url('audio', ['id' => $result['recording_id']]),
            'download_url' => Http::url('audio', [
                'id' => $result['recording_id'],
                'download' => 1,
            ]),
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
        Http::redirect('project', [
            'id' => $projectId,
            'page' => Http::postId('page') ?? 1,
        ]);
    }

    /**
     * Assign or clear one script character for a project member.
     *
     * @return void No value is returned.
     */
    public function assignVoice(): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        if ($projectId === null) {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        $page = Http::postId('page') ?? 1;
        $userId = $this->userId();
        if (!$this->model->isOwner($projectId, $userId)) {
            throw new HttpError(403, 'Obsazení může upravit pouze autor knihy.');
        }
        $character = Http::post('character');
        $targetValue = Http::post('user_id');
        $targetUserId = null;
        if ($targetValue !== '' && $targetValue !== '0') {
            $targetUserId = Http::postId('user_id');
            if ($targetUserId === null) {
                Http::notice('error', 'Vyberte platného člena knihy.');
                Http::redirect('project', ['id' => $projectId, 'page' => $page]);
            }
        }
        try {
            $this->service->assignVoice(
                $projectId,
                $userId,
                $character,
                $targetUserId
            );
        } catch (FormError $error) {
            Http::notice('error', $error->getMessage());
            Http::redirect('project', ['id' => $projectId, 'page' => $page]);
        }
        Http::notice('success', 'Obsazení postavy bylo aktualizováno.');
        Http::redirect('project', [
            'id' => $projectId,
            'page' => $page,
        ]);
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
        $page = Http::postId('page') ?? 1;
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
        Http::redirect('project', ['id' => $projectId, 'page' => $page]);
    }

    /**
     * Delete an owner-authorized or self-created take and return to its page.
     *
     * @return void No value is returned.
     */
    public function deleteTake(): void
    {
        if (Http::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není podporována.');
        }
        Http::requireCsrf();
        $projectId = Http::queryId('id');
        $lineId = Http::postId('line_id');
        $recordingId = Http::postId('recording_id');
        $page = Http::postId('page') ?? 1;
        if ($projectId === null || $lineId === null || $recordingId === null) {
            Http::notice('error', 'Chybí kniha, replika nebo pokus.');
            Http::redirect('projects');
        }
        try {
            $this->service->deleteTake(
                $projectId,
                $lineId,
                $recordingId,
                $this->userId()
            );
        } catch (FormError $error) {
            Http::notice('error', $error->getMessage());
            Http::redirect('project', ['id' => $projectId, 'page' => $page]);
        }
        Http::notice('success', 'Pokus byl odstraněn.');
        Http::redirect('project', ['id' => $projectId, 'page' => $page]);
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
        $download = Http::query('download') === '1';
        $fileName = TakeNaming::fileName(
            $recording['project_title'],
            $recording['line_number'],
            $recording['attempt_no'],
            $recording['media_type']
        );
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
        if ($download) {
            header(
                'Content-Disposition: attachment; filename="' . $fileName
                . '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
            );
        }
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
     * Return effective file-import and paste limits after PHP configuration.
     *
     * @return array{file:int,paste:int} Byte caps for the two script entry paths.
     */
    private function scriptLimits(): array
    {
        $postLimit = $this->iniByteLimit('post_max_size');
        $usablePostLimit = max(
            0,
            $postLimit - self::SCRIPT_MULTIPART_HEADROOM_BYTES
        );
        return [
            'file' => min(
                ScriptImport::MAX_BYTES,
                $this->iniByteLimit('upload_max_filesize'),
                $usablePostLimit
            ),
            'paste' => min(ScriptImport::MAX_BYTES, $usablePostLimit),
        ];
    }

    /**
     * Parse a PHP INI size quantity without PHP 8.2-only helpers.
     *
     * @param string $directive Name of a byte-size PHP INI setting.
     * @return int Parsed byte limit, or PHP_INT_MAX for an unlimited setting.
     */
    private function iniByteLimit(string $directive): int
    {
        $value = ini_get($directive);
        if (!is_string($value)) {
            return PHP_INT_MAX;
        }
        if (preg_match('/\\A(-?\\d+)\\s*([KMG]?)B?\\z/i', trim($value), $matches) !== 1) {
            return 0;
        }
        $amount = (int) $matches[1];
        if ($amount <= 0) {
            return PHP_INT_MAX;
        }
        $scale = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
        if ($amount > intdiv(PHP_INT_MAX, $scale)) {
            return PHP_INT_MAX;
        }
        return $amount * $scale;
    }

    /**
     * Detect a request PHP discarded because its content length exceeds post_max_size.
     *
     * @return bool True when the declared request body exceeds the configured cap.
     */
    private function requestBodyExceedsPostLimit(): bool
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (!is_string($contentLength) || !ctype_digit($contentLength)) {
            return false;
        }
        $requestBytes = filter_var($contentLength, FILTER_VALIDATE_INT);
        return $requestBytes !== false
            && $requestBytes > $this->iniByteLimit('post_max_size');
    }

    /**
     * Read bounded JSON from the selected file or paste fallback.
     *
     * @return string Raw JSON bytes within the effective input limit.
     * @throws FormError When upload, size, or request data is invalid.
     */
    private function scriptPayload(): string
    {
        $limits = $this->scriptLimits();
        $file = Http::file('script_file');
        if ($file === null) {
            if ($this->requestBodyExceedsPostLimit()) {
                throw new FormError('Požadavek překračuje limit post_max_size.');
            }
            if (array_key_exists('script_file', $_FILES)) {
                $rawFile = $_FILES['script_file'];
                if (is_array($rawFile) && is_numeric($rawFile['size'] ?? null)
                    && (int) $rawFile['size'] > $limits['file']) {
                    throw new FormError(
                        'Soubor scénáře překračuje aktuální limit '
                        . $limits['file'] . ' bajtů.'
                    );
                }
                throw new FormError('Nahrané metadata souboru scénáře nejsou platná.');
            }
            $script = (string) Http::post('script', false);
            if (strlen($script) > $limits['paste']) {
                throw new FormError(
                    'Vložený scénář překračuje aktuální limit '
                    . $limits['paste'] . ' bajtů.'
                );
            }
            return $script;
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $script = (string) Http::post('script', false);
            if (strlen($script) > $limits['paste']) {
                throw new FormError(
                    'Vložený scénář překračuje aktuální limit '
                    . $limits['paste'] . ' bajtů.'
                );
            }
            return $script;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'Soubor scénáře překračuje aktuální limit '
                    . $limits['file'] . ' bajtů.',
                UPLOAD_ERR_PARTIAL => 'Soubor scénáře se nahrál pouze částečně.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server nemá dočasné úložiště uploadů.',
                UPLOAD_ERR_CANT_WRITE => 'Server nemohl soubor scénáře uložit.',
                UPLOAD_ERR_EXTENSION => 'Nahrání souboru zastavilo rozšíření serveru.',
                default => 'Soubor scénáře se nepodařilo nahrát.',
            };
            throw new FormError($message);
        }
        if ($file['size'] < 1
            || $file['size'] > $limits['file']
            || !is_uploaded_file($file['tmp_name'])) {
            throw new FormError(
                'Soubor scénáře překračuje aktuální limit '
                . $limits['file'] . ' bajtů nebo není platným uploadem.'
            );
        }
        $contents = file_get_contents(
            $file['tmp_name'],
            false,
            null,
            0,
            $limits['file'] + 1
        );
        if (!is_string($contents)
            || strlen($contents) !== $file['size']
            || strlen($contents) > $limits['file']) {
            throw new FormError('Soubor scénáře se nepodařilo přečíst v celém rozsahu.');
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
