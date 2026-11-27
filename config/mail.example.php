<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: config/mail.example.php
 * Module Type: Example configuration
 * Purpose: Show the non-secret settings needed for email invitations.
 * Responsibilities: Document canonical URL, sender, and optional local SMTP relay keys.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

return [
    // Set this to the canonical public installation URL; never derive links from request Host.
    'base_url' => 'https://studio.example.com',
    'from' => 'noreply@example.com',

    // Optional Windows development values for a local Mailpit relay.
    // Change the port to match the disposable QA Mailpit if needed.
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 1025,
];