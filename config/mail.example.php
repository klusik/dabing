<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: config/mail.example.php
 * Module Type: Example configuration
 * Purpose: Show optional sender and SMTP overrides for invitation email.
 * Responsibilities: Document settings for protected storage/mail.php when needed.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

return [
    // This file is optional; without it PHP's configured mail transport is used.
    // Set a domain sender to improve deliverability on hosting providers.
    'from' => 'noreply@example.com',

    // Optional Windows-only local Mailpit relay; Linux/WEDOS uses PHP mail().
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 1025,
];
