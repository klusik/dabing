<?php

declare(strict_types=1);

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/Bootstrap.php
 * Module Type: Application bootstrap
 * Purpose: Bootstrap sessions, validate routes, and dispatch HTTP requests.
 * Responsibilities: Load fixed classes, enforce route policy, and contain safe errors.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

use Dabing\AccountController;
use Dabing\AuthService;
use Dabing\FormError;
use Dabing\Http;
use Dabing\HttpError;
use Dabing\EmailInviteController;
use Dabing\EmailInviteService;
use Dabing\InstallService;
use Dabing\StudioController;
use Dabing\UserAdminController;

$root = dirname(__DIR__);
$storage = $root . DIRECTORY_SEPARATOR . 'storage';
require_once $root . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'ApplicationUpdateLock.php';
$applicationLock = new Dabing\ApplicationUpdateLock($storage);
if (is_file($storage . DIRECTORY_SEPARATOR . 'config.php')) {
    if (!$applicationLock->acquireShared()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Aplikace se právě aktualizuje. Zkuste to prosím za chvíli.';
        exit;
    }
    if ($applicationLock->recoveryRequired()) {
        $applicationLock->release();
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Aplikace vyžaduje obnovu. Obnovte soubory ze zálohy podle README a poté odstraňte značku údržby.';
        exit;
    }
}
$classes = [
    'app/HttpError.php', 'app/FormError.php', 'app/Http.php',
    'app/models/AccountModel.php', 'app/models/InstallModel.php',
    'app/models/UserAdminModel.php',
    'app/models/EmailInviteModel.php',
    'app/models/UpdateMigrationModel.php',
    'app/services/UpdateMigrationService.php',
    'app/services/InstallService.php', 'app/services/AuthService.php',
    'app/services/UserAdminService.php',
    'app/services/GitHubReleaseClient.php', 'app/services/UpdatePackage.php',
    'app/services/UpdateService.php',
    'app/services/TakeNaming.php', 'app/services/ScriptImport.php',
    'app/services/RecordingStorage.php',
    'app/models/StudioModel.php', 'app/services/StudioService.php',
    'app/services/InviteMailer.php', 'app/services/EmailInviteService.php',
    'app/controllers/AccountController.php', 'app/controllers/UpdateController.php',
    'app/controllers/UserAdminController.php',
    'app/controllers/StudioController.php',
    'app/controllers/EmailInviteController.php',
];
foreach ($classes as $classFile) {
    require_once $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $classFile);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
Http::boot();
$routes = [
    'install' => ['GET', 'POST'], 'login' => ['GET', 'POST'],
    'account' => ['GET', 'POST'],
    'logout' => ['POST'], 'users' => ['GET', 'POST'],
    'projects' => ['GET', 'POST'], 'project' => ['GET'],
    'assign-voice' => ['POST'],
    'email-invite' => ['POST'], 'email-join' => ['GET', 'POST'],
    'update' => ['GET', 'POST'],
    'select-take' => ['POST'], 'upload' => ['POST'],
    'delete-take' => ['POST'], 'hide-take' => ['POST'],
    'audio' => ['GET', 'HEAD'],
    'export-book' => ['GET'],
];
$route = Http::query('route');
$route = $route === '' ? 'projects' : $route;
$method = Http::method();
$jsonEndpoint = Http::wantsJson()
    || ($route === 'project' && Http::query('view') === 'revision');
$jsonResponse = $jsonEndpoint && in_array($route, [
    'project', 'assign-voice', 'email-invite', 'select-take',
    'delete-take', 'hide-take',
], true);

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
    $emailInviteController = new EmailInviteController(
        new EmailInviteService($database, $storage)
    );
    if ($route === 'login') {
        $account->login($auth);
        exit;
    }
    if ($route === 'logout') {
        $account->logout($auth);
        exit;
    }
    if ($route === 'email-join') {
        $emailInviteController->join($auth);
        exit;
    }
    if ($route === 'update') {
        if ($user === null || !$user['is_admin']) {
            throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
        }
        $updates = new Dabing\UpdateService($root, $install, $applicationLock);
        (new Dabing\UpdateController($updates))->handle($user);
        exit;
    }
    if ($user === null) {
        if ($route === 'audio') {
            throw new HttpError(404, 'Nahrávka nebyla nalezena.');
        }
        if ($route === 'export-book') {
            throw new HttpError(404, 'Kniha nebyla nalezena.');
        }
        if ($route === 'upload') {
            Http::json(['ok' => false, 'message' => 'Přihlášení vypršelo.'], 401);
        }
        if ($jsonResponse) {
            Http::json(['ok' => false, 'message' => 'Přihlášení vypršelo.'], 401);
        }
        Http::redirect('login');
    }

    if ($route === 'email-invite') {
        $emailInviteController->send($user['id'], $user['email']);
        exit;
    }

    if ($route === 'account') {
        $account->changePassword($auth);
        exit;
    }

    if ($route === 'users') {
        (new UserAdminController($database))->handle($user);
        exit;
    }

    $studio = new StudioController($database, $storage, $user);
    switch ($route) {
        case 'projects':
            $studio->projects();
            break;
        case 'project':
            $studio->project();
            break;
        case 'assign-voice':
            $studio->assignVoice();
            break;
        case 'select-take':
            $studio->selectTake();
            break;
        case 'upload':
            $studio->upload();
            break;
        case 'delete-take':
            $studio->deleteTake();
            break;
        case 'hide-take':
            $studio->hideTake();
            break;
        case 'audio':
            $studio->audio();
            break;
        case 'export-book':
            $studio->exportBook();
            break;
        default:
            throw new HttpError(404, 'Požadovaná stránka nebyla nalezena.');
    }
} catch (HttpError $error) {
    if ($route === 'upload') {
        Http::json(['ok' => false, 'message' => $error->getMessage()], $error->status());
    }
    if ($jsonResponse) {
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
    if ($jsonResponse) {
        Http::json([
            'ok' => false,
            'message' => 'Požadavek se nepodařilo zpracovat.',
        ], 500);
    }
    Http::render('error', 'Chyba', [
        'status' => 500, 'message' => 'Požadavek se nepodařilo zpracovat.',
    ], isset($user) && is_array($user) ? $user : null, 500);
}
