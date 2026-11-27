<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/StudioService.php
 * Module Type: Service
 * Purpose: Apply studio project, invite, take, and playback policies.
 * Responsibilities: Coordinate script validation, persistence, storage, and authorization.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

use DateTimeImmutable;
use DateTimeZone;

/** Coordinates private studio workflows without HTTP concerns. */
final class StudioService
{
    /**
     * Type: int; Units: pending files; Scope: one post-delete cleanup pass;
     * Consumers: cleanupPendingFiles(); Rationale: bounds stale-file retries.
     */
    private const PENDING_DELETE_CLEANUP_BATCH_SIZE = 20;

    /**
     * Integer; units: seconds; scope: collaborator invitation; consumers:
     * createInvite(); rationale: invitation links expire after seven days.
     */
    private const INVITE_TTL_SECONDS = 604800;


    /**
     * Create the studio service dependencies.
     *
     * @param StudioModel $model Persistence owner.
     * @param ScriptImport $scriptImport JSON import validator.
     * @param RecordingStorage $storage Private audio storage.
     * @return void
     */
    public function __construct(
        private readonly StudioModel $model,
        private readonly ScriptImport $scriptImport,
        private readonly RecordingStorage $storage
    ) {
    }

    /**
     * Validate imported JSON and persist a private project.
     *
     * @param int $ownerId Authenticated project owner.
     * @param string $json Uploaded script JSON.
     * @return int New project identifier.
     */
    public function createProject(int $ownerId, string $json): int
    {
        return $this->model->createProject(
            $ownerId,
            $this->scriptImport->validate($json)
        );
    }

    /**
     * Create an expiring one-use collaborator invite.
     *
     * @param int $projectId Project receiving a collaborator.
     * @param int $ownerId Authenticated owner requesting invite.
     * @return string Raw URL-safe token, returned once to the controller.
     * @throws FormError When the user is not the project owner.
     */
    public function createInvite(int $projectId, int $ownerId): string
    {
        if (!$this->model->isOwner($projectId, $ownerId)) {
            throw new FormError('Pozvánku může vytvořit pouze autor projektu.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::INVITE_TTL_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');
        $this->model->createInvite($projectId, hash('sha256', $token), $expires);
        return $token;
    }

    /**
     * Apply an owner-selected character assignment or clear it.
     *
     * @param int $projectId Project receiving the voice assignment.
     * @param int $ownerId Authenticated project owner.
     * @param string $character Exact character label in the script.
     * @param int|null $userId Member receiving the role, or null to clear.
     * @return void No value is returned.
     * @throws FormError When the character, member, or owner is invalid.
     */
    public function assignVoice(
        int $projectId,
        int $ownerId,
        string $character,
        ?int $userId
    ): void {
        if ($character === '') {
            throw new FormError('Vyberte postavu ze scénáře.');
        }
        $this->model->assignVoice($projectId, $ownerId, $character, $userId);
    }

    /**
     * Claim a valid invitation for the authenticated user.
     *
     * @param string $token Presented raw invitation token.
     * @param int $userId Authenticated user claiming access.
     * @return int Joined project identifier.
     * @throws FormError When token is malformed, expired, or already consumed.
     */
    public function claimInvite(string $token, int $userId): int
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new FormError('Pozvánka je neplatná nebo expirovaná.');
        }
        return $this->model->claimInvite(hash('sha256', $token), $userId);
    }

    /**
     * Store one new attempt for an authorized script line.
     *
     * @param int $projectId Requested project.
     * @param int $lineId Requested script line.
     * @param int $userId Authenticated recorder.
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file PHP upload metadata.
     * @param string|null $takeToken Optional stable client token for safe retries.
     * @return array{recording_id:int,attempt_no:int,file_name:string,reused:bool} Saved or replayed take metadata.
     * @throws FormError When the token, file, or take authorization is invalid.
     */
    public function addTake(
        int $projectId,
        int $lineId,
        int $userId,
        array $file,
        ?string $takeToken = null
    ): array {
        $idempotencyPrefix = null;
        if ($takeToken !== null) {
            if (strlen($takeToken) !== RecordingStorage::IDEMPOTENCY_PREFIX_HEX_CHARS
                || preg_match('/\A[a-f0-9]+\z/D', $takeToken) !== 1) {
                throw new FormError('Identifikátor nahrávky je neplatný.');
            }
            $digest = hash('sha256', implode("\0", [
                'dabing:take-token:v1',
                (string) $projectId,
                (string) $lineId,
                (string) $userId,
                $takeToken,
            ]));
            $idempotencyPrefix = substr(
                $digest,
                0,
                RecordingStorage::IDEMPOTENCY_PREFIX_HEX_CHARS
            );
        }
        if (!$this->model->canRecord($projectId, $lineId, $userId)) {
            throw new FormError('Tato replika není přiřazena vašemu hlasu.');
        }
        // A token replay may return an existing take after a later role change.
        $staged = $this->storage->stage($file, $idempotencyPrefix);
        try {
            $saved = $this->model->addRecording(
                $projectId,
                $lineId,
                $userId,
                $staged['key'],
                $staged['mime'],
                $staged['size'],
                $idempotencyPrefix
            );
        } catch (\Throwable $exception) {
            if ($idempotencyPrefix === null) {
                $this->storage->delete($staged['key']);
            } else {
                try {
                    if (!$this->model->hasStorageKey($staged['key'])) {
                        $this->storage->delete($staged['key']);
                    }
                } catch (\Throwable) {
                    // Preserve the file if the transaction outcome is unknown.
                }
            }
            throw $exception;
        }
        if ($saved['storage_key'] !== $staged['key']) {
            $this->storage->delete($staged['key']);
        }
        return [
            'recording_id' => $saved['recording_id'],
            'attempt_no' => $saved['attempt_no'],
            'file_name' => TakeNaming::fileName(
                $saved['project_title'],
                $saved['line_number'],
                $saved['attempt_no'],
                $saved['media_type']
            ),
            'reused' => $saved['reused'],
        ];
    }

    /**
     * Delete a take with recoverable private-file staging around the SQL commit.
     *
     * @param int $projectId Project containing the take.
     * @param int $lineId Script line containing the take.
     * @param int $recordingId Take selected for removal.
     * @param int $userId Authenticated owner or take creator.
     * @return void No value is returned.
     * @throws FormError When the take is missing or the requester cannot delete it.
     */
    public function deleteTake(
        int $projectId,
        int $lineId,
        int $recordingId,
        int $userId
    ): void {
        $take = $this->model->beginTakeDeletion(
            $projectId, $lineId, $recordingId, $userId
        );
        if ($take === null) {
            throw new FormError('Tento pokus nelze odstranit.');
        }
        try {
            if (!$this->storage->moveToPending($take['storage_key'])) {
                throw new FormError('Soubor pokusu není dostupný k odstranění.');
            }
            $this->model->finishTakeDeletion($take);
        } catch (\Throwable $exception) {
            // Pending storage stays playable and retryable after transaction rollback.
            $this->model->cancelTakeDeletion();
            throw $exception;
        }
        try {
            $this->storage->deletePending($take['storage_key']);
            $this->cleanupPendingFiles();
        } catch (\Throwable) {
            // A committed deletion stays successful; retained pending files are retryable.
        }
    }

    /**
     * Retry a bounded number of stale pending-file removals after commit.
     *
     * @return void No value is returned.
     */
    private function cleanupPendingFiles(): void
    {
        foreach ($this->storage->pendingKeys(
            self::PENDING_DELETE_CLEANUP_BATCH_SIZE
        ) as $key) {
            if (!$this->model->hasStorageKey($key)) {
                $this->storage->deletePending($key);
            }
        }
    }

    /**
     * Build one private ZIP containing all saved takes in global script order.
     *
     * @param int $projectId Project requested for export.
     * @param int $userId Authenticated project owner or member.
     * @return array{stream:resource,size:int,file_name:string,key:string}|null Ready archive, or null when access is denied.
     * @throws FormError When the authorized book has no saved takes.
     * @throws HttpError When ZIP or archive storage fails.
     */
    public function createBookArchive(int $projectId, int $userId): ?array
    {
        $book = $this->model->exportTakes($projectId, $userId);
        if ($book === null) {
            return null;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpError(503, 'Export ZIP není na serveru dostupný.');
        }

        $temporary = $this->storage->createExportArchive();
        $key = $temporary['key'];
        $archive = new \ZipArchive();
        $archiveOpen = false;
        $stream = null;
        register_shutdown_function(function () use ($key): void {
            $this->storage->removeExportArchive($key);
        });
        try {
            $opened = $archive->open(
                $temporary['path'],
                \ZipArchive::CREATE | \ZipArchive::OVERWRITE
            );
            if ($opened !== true) {
                throw new HttpError(500, 'Archiv knihy nelze připravit.');
            }
            $archiveOpen = true;
            $entryCount = 0;
            foreach ($book['takes'] as $take) {
                try {
                    $entryName = TakeNaming::fileName(
                        $book['title'],
                        $take['line_number'],
                        $take['attempt_no'],
                        $take['media_type']
                    );
                } catch (FormError) {
                    throw new HttpError(500, 'Název některé nahrávky není platný.');
                }
                $sourcePath = $this->storage->readablePath(
                    $take['storage_key'],
                    $take['byte_size']
                );
                if ($sourcePath === null) {
                    throw new HttpError(
                        409,
                        'Některá nahrávka se během přípravy změnila. Zkuste export znovu.'
                    );
                }
                if (!$archive->addFile($sourcePath, $entryName)) {
                    throw new HttpError(
                        409,
                        'Některá nahrávka se během přípravy změnila. Zkuste export znovu.'
                    );
                }
                if (!$archive->setCompressionName(
                    $entryName,
                    \ZipArchive::CM_STORE
                )) {
                    throw new HttpError(500, 'Archiv knihy nelze připravit.');
                }
                ++$entryCount;
            }
            if ($entryCount === 0) {
                throw new FormError('Kniha zatím neobsahuje žádné nahrávky.');
            }
            $closed = $archive->close();
            $archiveOpen = false;
            if (!$closed) {
                throw new HttpError(
                    500,
                    'ZIP archiv se nepodařilo dokončit. Zkuste export znovu.'
                );
            }
            $size = filesize($temporary['path']);
            if (!is_int($size) || $size < 1 || !chmod($temporary['path'], 0600)) {
                throw new HttpError(500, 'Archiv knihy nelze dokončit.');
            }
            $stream = @fopen($temporary['path'], 'rb');
            if (!is_resource($stream)) {
                throw new HttpError(500, 'Archiv knihy nelze přečíst.');
            }
            return [
                'stream' => $stream,
                'size' => $size,
                'file_name' => TakeNaming::archiveFileName($book['title']),
                'key' => $key,
            ];
        } catch (\Throwable $exception) {
            unset($book);
            if ($archiveOpen) {
                $archive->unchangeAll();
                $archive->close();
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->storage->removeExportArchive($key);
            throw $exception;
        }
    }

    /**
     * Close the ZIP stream and remove its private temporary file.
     *
     * @param resource $stream Completed ZIP file stream.
     * @param string $key Random archive key.
     * @return void No value is returned.
     */
    public function closeBookArchive($stream, string $key): void
    {
        try {
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            $this->storage->removeExportArchive($key);
        }
    }

    /**
     * Prepare authorized audio file metadata and open the private stream.
     *
     * @param int $recordingId Requested recording.
     * @param int $userId Authenticated listener.
     * @return array{recording:array{id:int,project_id:int,storage_key:string,media_type:string,byte_size:int},stream:resource}|null Authorized stream or null.
     */
    public function openRecording(int $recordingId, int $userId): ?array
    {
        $recording = $this->model->recordingForUser($recordingId, $userId);
        if ($recording === null) {
            return null;
        }
        $stream = $this->storage->open($recording['storage_key']);
        if (!is_resource($stream)) {
            return null;
        }
        return ['recording' => $recording, 'stream' => $stream];
    }

    /**
     * Close a stream opened by this service.
     *
     * @param resource $stream Open recording handle.
     * @return void
     */
    public function closeRecording($stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
