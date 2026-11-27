<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: index.php
 * Module Type: Application entry point
 * Purpose: Bootstrap sessions, validate routes, and dispatch HTTP requests.
 * Responsibilities: Load fixed classes, enforce route policy, and contain safe errors.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

use Dabing\AccountController;
use Dabing\AuthService;
use Dabing\FormError;
use Dabing\Http;
use Dabing\HttpError;
use Dabing\InstallService;
use Dabing\StudioController;

$root = __DIR__;
$classes = [
    'app/HttpError.php', 'app/FormError.php', 'app/Http.php',
    'app/models/AccountModel.php', 'app/models/InstallModel.php',
    'app/services/InstallService.php', 'app/services/AuthService.php',
    'app/services/ScriptImport.php', 'app/services/RecordingStorage.php',
    'app/models/StudioModel.php', 'app/services/StudioService.php',
    'app/controllers/AccountController.php', 'app/controllers/StudioController.php',
];
foreach ($classes as $classFile) {
    require_once $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $classFile);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
Http::boot();
$routes = [
    'install' => ['GET', 'POST'], 'login' => ['GET', 'POST'],
    'register' => ['GET', 'POST'], 'logout' => ['POST'],
    'projects' => ['GET', 'POST'], 'project' => ['GET'],
    'invite' => ['POST'], 'join' => ['GET', 'POST'],
    'select-take' => ['POST'], 'upload' => ['POST'],
    'audio' => ['GET', 'HEAD'],
];
$route = Http::query('route');
$route = $route === '' ? 'projects' : $route;
$method = Http::method();

try {
    if (!isset($routes[$route])) {
        throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
    }
    if (!in_array($method, $routes[$route], true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $routes[$route]));
        }
        throw new HttpError(405, 'Tato metoda není pro stránku povolena.');
    }

    $storage = $root . DIRECTORY_SEPARATOR . 'storage';
    $install = new InstallService($storage, $root . DIRECTORY_SEPARATOR . 'database');
    $account = new AccountController($install);
    $installed = $install->isInstalled();

    if (!$installed && $route !== 'install') {
        Http::redirect('install');
    }
    if ($route === 'install') {
        if ($installed) {
            $auth = new AuthService($install->database());
            Http::redirect($auth->currentUser() === null ? 'login' : 'projects');
        }
        $account->install();
        exit;
    }

    $database = $install->database();
    $auth = new AuthService($database);
    $user = $auth->currentUser();
    if (($route === 'join' || $route === 'login' || $route === 'register')
        && Http::query('return') === 'join') {
        $token = Http::query('token');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            $_SESSION['pending_invite'] = $token;
        }
    }
    if ($route === 'login') {
        $account->login($auth);
        exit;
    }
    if ($route === 'register') {
        $account->register($auth);
        exit;
    }
    if ($route === 'logout') {
        $account->logout($auth);
        exit;
    }
    if ($route === 'join' && $user === null) {
        $token = Http::query('token');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            $_SESSION['pending_invite'] = $token;
            Http::redirect('login');
        }
        Http::render('join', 'Pozvánka do knihy', [
            'token' => '', 'valid' => false,
        ]);
        exit;
    }
    if ($user === null) {
        if ($route === 'audio') {
            throw new HttpError(404, 'Nahrávka nebyla nalezena.');
        }
        if ($route === 'upload') {
            Http::json(['ok' => false, 'message' => 'Přihlášení vypršelo.'], 401);
        }
        Http::redirect('login');
    }

    $studio = new StudioController($database, $storage, $user);
    switch ($route) {
        case 'projects':
            $studio->projects();
            break;
        case 'project':
            $studio->project();
            break;
        case 'invite':
            $studio->invite();
            break;
        case 'join':
            $studio->join();
            break;
        case 'select-take':
            $studio->selectTake();
            break;
        case 'upload':
            $studio->upload();
            break;
        case 'audio':
            $studio->audio();
            break;
        default:
            throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
    }
} catch (HttpError $error) {
    if ($route === 'upload') {
        Http::json(['ok' => false, 'message' => $error->getMessage()], $error->status());
    }
    Http::render('error', 'Chyba', [
        'status' => $error->status(), 'message' => $error->getMessage(),
    ], isset($user) && is_array($user) ? $user : null, $error->status());
} catch (Throwable $error) {
    error_log(sprintf(
        'Dabing request failed: %s at %s:%d',
        get_class($error), $error->getFile(), $error->getLine()
    ));
    if ($route === 'upload') {
        Http::json(['ok' => false, 'message' => 'Požadavek se nepodařilo zpracovat.'], 500);
    }
    Http::render('error', 'Chyba', [
        'status' => 500, 'message' => 'Požadavek se nepodařilo zpracovat.',
    ], isset($user) && is_array($user) ? $user : null, 500);
}