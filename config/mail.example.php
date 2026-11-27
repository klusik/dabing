<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: config/mail.example.php
 * Module Type: Example configuration
 * Purpose: Document static sender configuration for invitation email.
 * Responsibilities: Provide the sender setting for protected storage/mail.php.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

return [
    // The public base URL is derived from the validated browser-visible request.
    'from' => 'noreply@example.com',

    // Optional Windows-only local Mailpit relay; Linux/WEDOS uses PHP mail().
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 1025,
];
