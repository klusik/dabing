<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: scripts/create-install-key.php
 * Module Type: CLI utility
 * Purpose: Create the one-time first-install setup key.
 * Responsibilities: Exclusively create a high-entropy key in protected storage.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Generate a protected setup key when none exists.
 * @return int Process exit status: 0 on success, 1 when creation fails.
 */
function createInstallKey(): int
{
    $storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storage) && !mkdir($storage, 0700, true)
        && !is_dir($storage)) {
        fwrite(STDERR, "Nelze vytvořit instalační úložiště.\n");
        return 1;
    }
    $path = $storage . DIRECTORY_SEPARATOR . 'install.key';
    $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        fwrite(STDERR, "Instalační klíč již existuje nebo jej nelze vytvořit.\n");
        return 1;
    }
    try {
        if (fwrite($handle, $key . PHP_EOL) === false) {
            fwrite(STDERR, "Instalační klíč nelze zapsat.\n");
            return 1;
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
    @chmod($path, 0600);
    fwrite(STDOUT, "Instalační klíč byl vytvořen. Uložte jej z tohoto terminálu.\n");
    fwrite(STDOUT, $key . PHP_EOL);
    return 0;
}

exit(createInstallKey());