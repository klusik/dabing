<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/FormError.php
 * Module Type: Domain exception
 * Purpose: Represent safe validation feedback for submitted form data.
 * Responsibilities: Carry user-displayable validation messages without transport details.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Signals a validation failure that may be shown to the user. */
final class FormError extends \RuntimeException
{
    /** Create a safe validation error.
     * @param string $message Czech message suitable for the user.
     * @return void No return value.
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}