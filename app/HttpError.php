<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/HttpError.php
 * Module Type: HTTP exception
 * Purpose: Carry a safe HTTP status and message through request dispatch.
 * Responsibilities: Represent expected not-found, authorization, and method errors.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Signals an expected HTTP error with a display-safe message. */
final class HttpError extends \RuntimeException
{
    /** Create an HTTP error.
     * @param int $status HTTP response status code.
     * @param string $message Safe user-facing explanation.
     * @return void No return value.
     */
    public function __construct(int $status, string $message)
    {
        parent::__construct($message, $status);
    }

    /** Return the HTTP response status code.
     * @return int Valid HTTP status code.
     */
    public function status(): int
    {
        return $this->getCode();
    }
}