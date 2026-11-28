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

    /** Maximum percent-decoding passes used to detect hidden path segments.
     * Type: int; Units: passes; Scope: public base URL path validation;
     * Consumers: normalizePublicBaseUrl(); Rationale: catch nested encoding
     * without unbounded decoding.
     */
    private const MAX_PATH_DECODE_PASSES = 5;

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

    /** Normalize a configured public HTTP(S) base URL.
     * @param string $value Operator-provided public base URL.
     * @return string|null Safe URL without a trailing slash, or null if invalid.
     */
    public static function normalizePublicBaseUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $isIpv6 = str_contains($host, ':');
        if ($isIpv6) {
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $host = '[' . trim($host, '[]') . ']';
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && (strlen($host) > 253
                || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/i', $host) !== 1)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        $authority = $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443))) {
            $authority .= ':' . $port;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && !str_starts_with($path, '/')) {
            return null;
        }
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        $decodedPath = $path;
        for ($pass = 0; $pass < self::MAX_PATH_DECODE_PASSES; $pass++) {
            if (preg_match('/%(?![a-f0-9]{2})/i', $decodedPath) === 1) {
                return null;
            }
            $nextPath = rawurldecode($decodedPath);
            if ($nextPath === $decodedPath) {
                break;
            }
            $decodedPath = $nextPath;
        }
        if (rawurldecode($decodedPath) !== $decodedPath) {
            return null;
        }
        if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x20\x7f]/', $decodedPath) === 1) {
            return null;
        }
        foreach (explode('/', trim($decodedPath, '/')) as $segment) {
            if ($segment === '.' || $segment === '..'
                || strcasecmp($segment, 'subdom') === 0) {
                return null;
            }
        }
        return $scheme . '://' . $authority . $path;
    }

    /** Build a query-only URL for a known route and scalar parameters.
     * @param string $route Allowlisted route name.
     * @param array<string,int|string> $params Scalar query parameters.
     * @return string Query-only URL beginning with route.
     */
    public static function routeQuery(string $route, array $params = []): string
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

    /** Build a same-origin query-only URL for an application route.
     * @param string $route Allowlisted route name.
     * @param array<string,int|string> $params Scalar query parameters.
     * @return string Query-only route URL on the current browser origin.
     */
    public static function url(string $route, array $params = []): string
    {
        $query = self::routeQuery($route, $params);
        return $query;
    }

    /** Build a relative URL for one existing flat public asset with a content digest.
     * @param string $filename Simple flat public asset filename.
     * @return string Relative URL with digest, or base asset path when invalid or missing.
     */
    public static function asset(string $filename): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/', $filename) !== 1
            || str_contains($filename, '..')) {
            return './public/assets/';
        }
        $assetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($assetPath) || !is_readable($assetPath)) {
            return './public/assets/';
        }
        $digest = hash_file('sha256', $assetPath);
        if (!is_string($digest)) {
            return './public/assets/';
        }
        return './public/assets/' . rawurlencode($filename)
            . '?v=' . $digest;
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
     * @param array{
     *     errors?:list<string>,
     *     values?:array{db_host?:string,db_port?:string,db_name?:string,db_user?:string,name?:string,email?:string},
     *     db_host?:string,db_port?:string,db_name?:string,db_user?:string,name?:string,email?:string,
     *     projects?:list<array{id:int,title:string,role?:string,created_at?:string,line_count?:int,recording_count?:int,roles?:list<string>}>,
     *     users?:list<array{id:int,name:string,email:string,is_admin:bool,is_active:bool,books:list<array{id:int,title:string,roles:list<string>}>}>,
     *     max_script_bytes?:int,max_script_paste_bytes?:int,
     *     project?:array{id:int,title:string,role:string},
     *     chapters?:list<array{id:int,title:string,lines:list<array{id:int,position:int,character:string,text:string,selected_recording_id:?int,selected_recording_user_id:?int,number:int,can_record:bool,assigned_name:?string,audio_profile_override?:int|null,takes:list<array{id:int,user_id:?int,user_name:string,created_at:string,is_selected:bool,can_delete:bool,hidden?:bool,can_hide?:bool,attempt_no:int,media_type:string,file_name:string,audio_url:string,download_url:string,audio_profile_override?:int|null,audio_profile_id?:int|null,audio_profile_source?:'take'|'line'|'voice'|null}>}>}>,
     *     members?:list<array{id:int,name:string,is_active:bool,role:string}>,
     *     voices?:list<array{character:string,assigned_user_id:?int,assigned_name:?string,assigned_names:list<string>,assigned_users:list<int>,line_count:int,dubbed_count:int}>,
     *     filter_voices?:list<array{character:string,assigned_user_id:?int,assigned_name:?string,assigned_names:list<string>,assigned_users:list<int>,line_count:int,dubbed_count:int}>,
     *     role_users?:list<array{id:int,name:string,email:string,is_active:bool}>,
     *     email_invites?:list<array{id:int,email:string,roles:list<string>,state:'pending'|'claimed'|'failed'|'cancelled'|'expired'|'sent',expires_at_local:?string,delivery_error:?string,created_at_local:string}>,
     *     filter?:'all'|'mine'|'characters',characters?:list<string>,
     *     revision?:string,pagination?:array{page:int,pages:int,total:int,enabled?:bool},
     *     playlist?:list<array{recording_id:int,line_id:int,line_number:int,line_page:int,in_filter:bool,character:string,chapter_title:string,attempt_no:int,media_type:string,file_name:string,audio_url:string,download_url:string,audio_profile_id:?int,audio_profile_source:?string}>,
     *     current_version?:string,latest_release?:array{version:string,url:string,sha256:string,size:int}|null,
     *     audio_profiles?:list<array{id:int,name:string,chain:list<array<string,mixed>>}>,
     *     audio_voice_rules?:array<string,array<string,int>>,
     *     audio_has_effects?:bool,
     *     profile?:array{id:int,name:string,chain:list<array<string,mixed>>}|null,
     *     default_chain?:list<array{type:string,enabled:bool,settings:array<string,mixed>}>,
     *     sample?:array{id:int,url:string,label:string}|null,
     *     form_error?:string|null,submitted_name?:string|null,
     *     submitted_chain_json?:string|null,profile_id?:int|null,
     *     can_invite?:bool,has_recordings?:bool,is_admin?:bool,export_url?:string,
     *     invite_valid?:bool,existing_account?:bool,wrong_account?:bool,
     *     recipient_email?:string,project_title?:string,code?:string,roles?:list<string>,
     *     status?:int,message?:string
     * } $data Prepared view data.
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
            'projects' => 'projects.php', 'users' => 'users.php',
            'project' => 'project.php', 'email-join' => 'email-invite-join.php',
            'audio-profiles' => 'audio-profiles.php',
            'update' => 'update.php', 'error' => 'error.php',
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

    /** Send a 303 redirect to an allowlisted route.
     * @param string $route Allowlisted route name.
     * @param array<string,int|string> $params Scalar query parameters.
     * @return never Does not return.
     */
    public static function redirect(string $route, array $params = []): never
    {
        if (headers_sent()) {
            exit;
        }
        header('Location: ' . self::url($route, $params), true, 303);
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
        self::redirect('projects');
    }

    /** Detect a caller that explicitly accepts a JSON response.
     * @return bool True when the Accept header includes application/json.
     */
    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return is_string($accept)
            && str_contains(strtolower($accept), 'application/json');
    }

    /** Send a structured upload or role-assignment response and terminate the request.
     * @param array<string,bool|int|string|list<string>> $payload Structured response values.
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
