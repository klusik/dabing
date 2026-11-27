<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/ApplicationUpdateLock.php
 * Module Type: Service
 * Purpose: Coordinate normal requests with exclusive application replacement.
 * Responsibilities: Hold shared or exclusive filesystem locks and report recovery state.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Owns one request-scoped lock for application files. */
final class ApplicationUpdateLock
{
    /** Recovery marker filename.
     * Type: string; Units: filename; Scope: protected storage directory;
     * Consumers: recoveryRequired(); Rationale: fail closed after interrupted deployment.
     */
    private const RECOVERY_MARKER = 'update-recovery.json';

    /** Lock filename.
     * Type: string; Units: filename; Scope: protected storage directory;
     * Consumers: lock handle; Rationale: share one lock across application requests.
     */
    private const LOCK_FILE = 'update.lock';

    /** Lock-open mode.
     * Type: string; Units: fopen mode; Scope: application update lock;
     * Consumers: acquireShared(), acquireExclusive(); Rationale: retain a stable lock file.
     */
    private const LOCK_OPEN_MODE = 'c';

    /** Lock acquisition flags.
     * Type: int; Units: flock flags; Scope: request lock calls;
     * Consumers: shared/exclusive acquisition; Rationale: never queue web requests behind an update.
     */
    private const NONBLOCKING_LOCK = LOCK_NB;

    /** @var resource|null Lock file handle retained until object destruction. */
    private $handle = null;

    /** Held lock level: none, shared, or exclusive. */
    private string $level = 'none';

    /** Create the application lock using its protected storage directory.
     * @param string $storageDirectory Absolute path to application storage.
     * @return void No return value.
     */
    public function __construct(private readonly string $storageDirectory)
    {
    }

    /** Acquire a nonblocking shared lock for a normal request.
     * @return bool True when the request may safely use installed application files.
     */
    public function acquireShared(): bool
    {
        if ($this->level !== 'none') {
            return true;
        }
        if (!$this->openHandle()) {
            return false;
        }
        if (!flock($this->handle, LOCK_SH | self::NONBLOCKING_LOCK)) {
            $this->closeHandle();
            return false;
        }
        $this->level = 'shared';
        return true;
    }

    /** Upgrade to a nonblocking exclusive lock after update package staging.
     * @return bool True when this request exclusively owns application replacement.
     */
    public function acquireExclusive(): bool
    {
        if ($this->level === 'exclusive') {
            return true;
        }
        if ($this->level === 'none' && !$this->openHandle()) {
            return false;
        }
        if ($this->level === 'shared') {
            flock($this->handle, LOCK_UN);
            $this->level = 'none';
        }
        if (!flock($this->handle, LOCK_EX | self::NONBLOCKING_LOCK)) {
            if (flock($this->handle, LOCK_SH | self::NONBLOCKING_LOCK)) {
                $this->level = 'shared';
            } else {
                $this->closeHandle();
            }
            return false;
        }
        $this->level = 'exclusive';
        return true;
    }

    /** Check for a marker left when an update needs manual recovery.
     * @return bool True when the application must remain in maintenance mode.
     */
    public function recoveryRequired(): bool
    {
        $marker = $this->storageDirectory . DIRECTORY_SEPARATOR
            . self::RECOVERY_MARKER;
        return file_exists($marker) || is_link($marker);
    }

    /** Release the retained lock, safely allowing repeated calls.
     * @return void No return value.
     */
    public function release(): void
    {
        $this->closeHandle();
    }

    /** Release the retained lock when the request object is destroyed.
     * @return void No return value.
     */
    public function __destruct()
    {
        $this->release();
    }

    /** Open the fixed protected lock path once.
     * @return bool True when an open handle is ready.
     */
    private function openHandle(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $handle = @fopen($path, self::LOCK_OPEN_MODE);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    /** Unlock and close this object's handle, if one is open.
     * @return void No return value.
     */
    private function closeHandle(): void
    {
        if (is_resource($this->handle)) {
            if ($this->level !== 'none') {
                flock($this->handle, LOCK_UN);
            }
            fclose($this->handle);
        }
        $this->handle = null;
        $this->level = 'none';
    }
}
