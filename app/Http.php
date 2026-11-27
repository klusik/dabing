<?php

declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/Http.php
 * Module Type: HTTP infrastructure
 * Purpose: Provide safe request, response, session, and rendering helpers.
 * Responsibilities: Normalize input, enforce CSRF, render fixed templates, and issue responses.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Centralizes transport helpers without resolving user input to file paths. */
final class Http
{
    /** Maximum accepted uploaded file size before service-level validation.
     * Type: int; Units: bytes; Scope: one normalized PHP upload; Consumers: file();
     * Rationale: keep request metadata bounded at the HTTP boundary.
     */
    private const MAX_NORMALIZED_FILE_BYTES = 52428800;

    /** Start a hardened session and apply response security headers.
     * @return void No return value.
     */
    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
            $name = 'dabing_' . substr(hash('sha256', $root), 0, 16);
            $https = (($_SERVER['HTTPS'] ?? '') !== ''
                && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            session_name($name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        self::securityHeaders();
    }

    /** Apply the fixed application security headers once per response.
     * @return void No return value.
     */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Permissions-Policy: microphone=(self), camera=()');
        header('Cache-Control: no-store');
    }

    /** Return the normalized request method.
     * @return string Uppercase method, or GET when absent.
     */
    public static function method(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return is_string($method) ? strtoupper($method) : 'GET';
    }

    /** Read one string POST field, optionally trimming surrounding whitespace.
     * @param string $key Expected form key.
     * @param bool $trim Whether to trim string values.
     * @return string Submitted string or an empty string for invalid/missing values.
     */
    public static function post(string $key, bool $trim = true): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return $trim ? trim($value) : $value;
    }

    /** Read one string query value.
     * @param string $key Expected query key.
     * @return string Submitted string or an empty string for invalid/missing values.
     */
    public static function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** Read a positive integer query value.
     * @param string $key Expected query key.
     * @return int|null Positive integer or null when invalid/missing.
     */
    public static function queryId(string $key): ?int
    {
        $value = self::query($key);
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $id === false ? null : $id;
    }

    /** Read a positive integer POST value.
     * @param string $key Expected form key.
     * @return int|null Positive integer or null when invalid/missing.
     */
    public static function postId(string $key): ?int
    {
        $value = self::post($key);
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $id === false ? null : $id;
    }

    /** Require a matching session CSRF token for a state-changing request.
     * @return void No return value.
     * @throws HttpError When request method or token is invalid.
     */
    public static function requireCsrf(): void
    {
        if (self::method() !== 'POST') {
            throw new HttpError(405, 'Metoda není povolena.');
        }
        $submitted = self::post('csrf', false);
        $expected = $_SESSION['csrf'] ?? null;
        if (!is_string($expected) || $expected === ''
            || $submitted === '' || !hash_equals($expected, $submitted)) {
            throw new HttpError(403, 'Formulář vypršel. Obnovte stránku a zkuste to znovu.');
        }
    }

    /** Return or create the session CSRF token.
     * @return string Random session token.
     */
    public static function csrf(): string
    {
        $token = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $token;
        }
        return $token;
    }

    /** Normalize one PHP upload record into a concrete field shape.
     * @param string $key Expected file input name.
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null Upload metadata or null when absent/malformed.
     */
    public static function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file)
            || !isset($file['name'], $file['type'], $file['tmp_name'], $file['error'], $file['size'])
            || !is_string($file['name']) || !is_string($file['type'])
            || !is_string($file['tmp_name']) || !is_numeric($file['error'])
            || !is_numeric($file['size'])) {
            return null;
        }
        $error = (int) $file['error'];
        $size = (int) $file['size'];
        if ($size < 0 || $size > self::MAX_NORMALIZED_FILE_BYTES) {
            return null;
        }
        return [
            'name' => $file['name'], 'type' => $file['type'],
            'tmp_name' => $file['tmp_name'], 'error' => $error, 'size' => $size,
        ];
    }

    /** Escape untrusted text for HTML output.
     * @param string $value Untrusted text.
     * @return string HTML-safe UTF-8 text.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build a query-only URL for a known route and scalar parameters.
     * @param string $route Allowlisted route name.
     * @param array<string,int|string> $params Scalar query parameters.
     * @return string Relative query URL.
     */
    public static function url(string $route, array $params = []): string
    {
        $query = ['route' => $route];
        foreach ($params as $key => $value) {
            if ((is_int($value) || is_string($value))
                && preg_match('/\A[a-z][a-z0-9_]*\z/i', $key) === 1) {
                $query[$key] = $value;
            }
        }
        return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Build a safe static asset URL.
     * @param string $filename Simple public asset filename.
     * @return string Relative URL to an asset or an empty asset URL on invalid input.
     */
    public static function asset(string $filename): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/', $filename) !== 1
            || str_contains($filename, '..')) {
            return './public/assets/';
        }
        return './public/assets/' . rawurlencode($filename);
    }

    /** Store one typed flash notice for the next rendered page.
     * @param string $type One of success, error, info, or warning.
     * @param string $message Safe user-facing notice text.
     * @return void No return value.
     */
    public static function notice(string $type, string $message): void
    {
        $allowed = ['success', 'error', 'info', 'warning'];
        $_SESSION['notice'] = [
            'type' => in_array($type, $allowed, true) ? $type : 'info',
            'message' => $message,
        ];
    }

    /** Render one fixed application view inside the shared layout.
     * @param string $view Fixed view key, never a path.
     * @param string $title Page title.
     * @param array{errors?:list<string>,values?:array{db_host?:string,db_port?:string,db_name?:string,db_user?:string,name?:string,email?:string},keyReady?:bool,db_host?:string,db_port?:string,db_name?:string,db_user?:string,name?:string,email?:string,projects?:list<array{id:int,title:string,role:string,created_at:string,line_count:int,recording_count:int}>,max_script_bytes?:int,max_script_paste_bytes?:int,project?:array{id:int,title:string,role:string},chapters?:list<array{id:int,title:string,lines:list<array{id:int,position:int,character:string,text:string,selected_recording_id:?int,selected_recording_user_id:?int,number:int,can_record:bool,assigned_name:?string,takes:list<array{id:int,user_id:int,user_name:string,created_at:string,is_selected:bool,can_delete:bool,attempt_no:int,media_type:string,file_name:string,audio_url:string,download_url:string}>}>}>,members?:list<array{id:int,name:string,role:string}>,voices?:list<array{character:string,assigned_user_id:?int,assigned_name:?string}>,pagination?:array{page:int,pages:int,total:int},playlist?:list<array{recording_id:int,line_number:int,character:string,chapter_title:string,attempt_no:int,media_type:string,file_name:string,audio_url:string,download_url:string}>,can_invite?:bool,invite_url?:string,token?:string,valid?:bool,invite_valid?:bool,existing_account?:bool,recipient_email?:string,project_title?:string,code?:string,status?:int,message?:string} $data Prepared view data.
     * @param array{id:int,name:string,email:string,is_admin:bool}|null $user Authenticated user or null.
     * @param int $status HTTP response status.
     * @return void No return value.
     */
    public static function render(
        string $view,
        string $title,
        array $data = [],
        ?array $user = null,
        int $status = 200
    ): void {
        $templates = [
            'install' => 'install.php', 'login' => 'login.php',
            'account' => 'account.php',
            'register' => 'register.php', 'projects' => 'projects.php',
            'project' => 'project.php', 'join' => 'join.php', 'email-join' => 'email-invite-join.php',
            'error' => 'error.php',
        ];
        if (!isset($templates[$view])) {
            throw new HttpError(500, 'Šablona stránky není dostupná.');
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
        }
        if (isset($data['values']) && is_array($data['values'])) {
            $data = array_merge($data, $data['values']);
        }
        $notice = $_SESSION['notice'] ?? null;
        unset($_SESSION['notice']);
        $template = dirname(__DIR__) . '/app/views/' . $templates[$view];
        require dirname(__DIR__) . '/app/views/layout.php';
    }

    /** Send a relative 303 redirect to an allowlisted route.
     * @param string $route Allowlisted route name.
     * @param array<string,int|string> $params Scalar query parameters.
     * @return never Does not return.
     */
    public static function redirect(string $route, array $params = []): never
    {
        if (headers_sent()) {
            exit;
        }
        $requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $path = null;
        if (is_string($requestUri)
            && str_starts_with($requestUri, '/')
            && !str_starts_with($requestUri, '//')
            && preg_match('/[\x00-\x20\x7f\\\\]/', $requestUri) !== 1
            && preg_match('/%(?![A-Fa-f0-9]{2})/', $requestUri) !== 1) {
            $parsedPath = parse_url($requestUri, PHP_URL_PATH);
            if (is_string($parsedPath) && str_starts_with($parsedPath, '/')
                && !str_starts_with($parsedPath, '//')) {
                $path = $parsedPath;
            }
        }
        $base = $path ?? './';
        header('Location: ' . $base . self::url($route, $params), true, 303);
        exit;
    }

    /** Consume a pending invitation after authentication.
     * @return never Redirects to a pending invitation or project list.
     */
    public static function redirectAfterLogin(): never
    {
        $emailCode = $_SESSION['pending_email_invite_code'] ?? null;
        unset($_SESSION['pending_email_invite_code']);
        if (is_string($emailCode)
            && preg_match('/\\A[a-f0-9]{32}\\z/D', $emailCode) === 1) {
            self::redirect('email-join', ['code' => $emailCode]);
        }
        $token = $_SESSION['pending_invite'] ?? null;
        unset($_SESSION['pending_invite']);
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            self::redirect('join', ['token' => $token]);
        }
        self::redirect('projects');
    }

    /** Send a structured JSON response and terminate the request.
     * @param array{ok:bool,message:string,recording_id?:int,attempt_no?:int,file_name?:string,audio_url?:string,download_url?:string} $payload Upload response payload.
     * @param int $status HTTP status code.
     * @return never Does not return.
     */
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}