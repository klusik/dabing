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
        $view = $this->model->projectView($projectId, $ownerId);
        if ($view === null || $view['project']['role'] !== 'owner') {
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
     * Replace the current user's take for an authorized script line.
     *
     * @param int $projectId Requested project.
     * @param int $lineId Requested script line.
     * @param int $userId Authenticated recorder.
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file PHP upload metadata.
     * @return array{recording_id:int} Saved take identifier.
     * @throws FormError When file or project access is invalid.
     */
    public function replaceTake(
        int $projectId,
        int $lineId,
        int $userId,
        array $file
    ): array {
        $staged = $this->storage->stage($file);
        try {
            $saved = $this->model->replaceTake(
                $projectId,
                $lineId,
                $userId,
                $staged['key'],
                $staged['mime'],
                $staged['size']
            );
        } catch (\Throwable $exception) {
            $this->storage->delete($staged['key']);
            throw $exception;
        }
        if ($saved['old_key'] !== null && $saved['old_key'] !== $saved['new_key']) {
            $this->storage->delete($saved['old_key']);
        }
        return ['recording_id' => $saved['recording_id']];
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