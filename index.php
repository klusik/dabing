<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: index.php
 * Module Type: Stable application entry point
 * Purpose: Load the updateable application bootstrap.
 * Responsibilities: Keep the Apache entry file small and stable across updates.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Bootstrap.php';