<?php

declare(strict_types=1);

namespace Dabing;

use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/InviteMailer.php
 * Module Type: Service
 * Purpose: Send addressed Dabing invitation mail as Czech multipart content.
 * Responsibilities: Validate static mail config and build same-origin public links.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Sends no email until an owner explicitly requests an invitation. */
final class InviteMailer
{
    private readonly string $configPath;

    /** Use the ignored mail configuration under protected storage.
     * @param string $storageDirectory Absolute private storage directory.
     * @return void No return value.
     */
    public function __construct(string $storageDirectory)
    {
        $this->configPath = rtrim($storageDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'mail.php';
    }

    /** Send a recipient link with a Czech plain-text and HTML message.
     * @param string $recipient Validated email address.
     * @param string $code Lowercase 32-character one-time code.
     * @param list<string> $roles Optional voice roles described in the message.
     * @param string $expiresAt Prague-local expiry date and time with zone.
     * @return bool True when PHP mail accepted the message.
     * @throws FormError When configuration or same-origin request URL is invalid.
     */
    public function sendInvite(
        string $recipient,
        string $code,
        array $roles,
        string $expiresAt
    ): bool
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\A[a-f0-9]{32}\z/D', $code) !== 1) {
            throw new FormError('E-mailovou pozvánku nelze odeslat.');
        }
        $config = $this->mailConfig();
        $baseUrl = $this->requestPublicBase();
        $link = rtrim($baseUrl, '/') . '/' . Http::routeQuery('email-join', ['code' => $code]);
        $roleText = $roles === [] ? 'Bez přiřazené postavy.' : 'Přiřazené postavy: ' . implode(', ', $roles);
        $expiryText = 'Platnost pozvánky do: ' . $expiresAt . '.';
        $subject = 'Pozvánka do Dabingu';
        $plain = "Dobrý den,\r\n\r\nByli jste pozváni do knihy Dabing.\r\n"
            . $roleText . "\r\n{$expiryText}\r\n\r\nPřijmout pozvánku: {$link}\r\n\r\n"
            . "Pokud jste o pozvánku nežádali, tento e-mail ignorujte.\r\n";
        $boundary = 'dabing_' . bin2hex(random_bytes(16));
        $htmlLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlRoles = htmlspecialchars($roleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlExpiry = htmlspecialchars($expiryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
            . '<title>Pozvánka do Dabingu</title></head><body>'
            . '<p>Dobrý den,</p><p>Byli jste pozváni do knihy Dabing.</p><p>'
            . $htmlRoles . '</p><p>' . $htmlExpiry . '</p><p><a href="' . $htmlLink . '" style="display:inline-block;'
            . 'padding:12px 20px;background:#26344a;color:#fff;text-decoration:none;'
            . 'border-radius:8px">Přijmout pozvánku</a></p><p>Pokud jste o pozvánku nežádali, '
            . 'tento e-mail ignorujte.</p></body></html>';
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($plain) . "\r\n--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n--{$boundary}--\r\n";
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = 'From: ' . $config['from'] . "\r\nMIME-Version: 1.0\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return $this->sendComposed($recipient, $encodedSubject, $body, $headers, $config);
    }

    /** Send an informational message after an existing account receives roles.
     * @param string $recipient Existing account email address.
     * @param int $projectId Target book identifier.
     * @param string $projectTitle Book title from the grant transaction.
     * @param list<string> $roles Newly assigned exact roles.
     * @return bool True when the configured transport accepts the message.
     * @throws FormError When recipient, book, roles, or mail configuration is invalid.
     */
    public function sendRoleAssignmentNotice(
        string $recipient,
        int $projectId,
        string $projectTitle,
        array $roles
    ): bool {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || $projectId < 1 || $roles === []) {
            throw new FormError('Informační e-mail o přiřazení nelze odeslat.');
        }
        $config = $this->mailConfig();
        $baseUrl = $this->requestPublicBase();
        $link = rtrim($baseUrl, '/') . '/' . Http::routeQuery(
            'project', ['id' => $projectId]
        );
        $safeTitle = htmlspecialchars(
            $projectTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
        );
        $roleText = 'Přiřazené postavy: ' . implode(', ', $roles);
        $plain = "Dobrý den,\r\n\r\nByly vám přiřazeny nové postavy v knize „"
            . $projectTitle . "“: " . implode(', ', $roles)
            . "\r\n\r\nOtevřít knihu: {$link}\r\n";
        $boundary = 'dabing_' . bin2hex(random_bytes(16));
        $htmlLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
            . '<title>Přiřazení postav v Dabingu</title></head><body>'
            . '<p>Dobrý den,</p><p>Byly vám přiřazeny nové postavy v knize „'
            . $safeTitle . '“.</p><p>' . htmlspecialchars(
                $roleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
            ) . '</p><p><a href="' . $htmlLink . '" style="display:inline-block;'
            . 'padding:12px 20px;background:#26344a;color:#fff;text-decoration:none;'
            . 'border-radius:8px">Otevřít knihu</a></p></body></html>';
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($plain) . "\r\n--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n--{$boundary}--\r\n";
        $subject = '=?UTF-8?B?' . base64_encode('Nové přiřazení postav v Dabingu') . '?=';
        $headers = 'From: ' . $config['from'] . "\r\nMIME-Version: 1.0\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return $this->sendComposed($recipient, $subject, $body, $headers, $config);
    }

    /** Deliver a composed message through the configured platform transport.
     * @param string $recipient Validated recipient address.
     * @param string $subject Encoded subject.
     * @param string $body Complete multipart body.
     * @param string $headers Safe sender and MIME headers.
     * @param array{from:string,smtp_host:?string,smtp_port:?int} $config Validated mail settings.
     * @return bool True when transport accepts the message.
     */
    private function sendComposed(
        string $recipient,
        string $subject,
        string $body,
        string $headers,
        array $config
    ): bool {
        $send = fn (): bool => $this->sendTransport(
            $recipient, $subject, $body, $headers, $config['from']
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $send();
        }
        $sendmailPath = ini_get('sendmail_path');
        if (is_string($sendmailPath) && trim($sendmailPath) !== '') {
            return $this->sendWithConfiguredSendmail(
                $sendmailPath, $recipient, $subject, $body, $headers
            );
        }
        $settings = ['sendmail_from' => $config['from']];
        if ($config['smtp_host'] !== null && $config['smtp_port'] !== null) {
            $settings['SMTP'] = $config['smtp_host'];
            $settings['smtp_port'] = (string) $config['smtp_port'];
        }
        $previous = [];
        foreach ($settings as $key => $value) {
            $current = ini_get($key);
            $previous[$key] = is_string($current) ? $current : '';
            ini_set($key, $value);
        }
        try {
            return $send();
        } finally {
            foreach ($previous as $key => $value) {
                ini_set($key, $value);
            }
        }
    }

    /** Send through the trusted PHP sendmail_path and honor its exit status.
     * @param string $sendmailPath Trusted PHP runtime command configuration.
     * @param string $recipient Validated recipient placed only in message headers.
     * @param string $subject Encoded subject placed only in message headers.
     * @param string $body Multipart message body written to standard input.
     * @param string $headers MIME and sender headers written to standard input.
     * @return bool True only when the configured process exits successfully.
     */
    private function sendWithConfiguredSendmail(
        string $sendmailPath,
        string $recipient,
        string $subject,
        string $body,
        string $headers
    ): bool {
        if (!function_exists('proc_open')) {
            return false;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];
        $process = proc_open($sendmailPath, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        if (!isset($pipes[0], $pipes[1])) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            return false;
        }
        $payload = 'To: ' . $recipient . "\r\nSubject: " . $subject . "\r\n"
            . $headers . "\r\n\r\n" . $body;
        $complete = false;
        try {
            $offset = 0;
            $payloadLength = strlen($payload);
            while ($offset < $payloadLength) {
                $written = fwrite($pipes[0], substr($payload, $offset));
                if (!is_int($written) || $written < 1) {
                    break;
                }
                $offset += $written;
            }
            $complete = $offset === $payloadLength;
            fclose($pipes[0]);
            unset($pipes[0]);
            // Read and discard process output so SMTP diagnostics never reach app output or logs.
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            unset($pipes[1]);
            return proc_close($process) === 0 && $complete;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    /** Convert transport warnings into safe failures handled by invitation policy.
     * @param string $recipient Validated recipient address.
     * @param string $subject Encoded message subject.
     * @param string $body Multipart message body.
     * @param string $headers MIME and sender headers.
     * @param string $sender Validated sender address.
     * @return bool True when PHP mail accepts the message.
     */
    private function sendTransport(
        string $recipient,
        string $subject,
        string $body,
        string $headers,
        string $sender
    ): bool {
        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line
        ): bool {
            throw new \ErrorException(
                'Mail transport warning.', $severity, $severity, $file, $line
            );
        });
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                return mail($recipient, $subject, $body, $headers);
            }
            return mail($recipient, $subject, $body, $headers, '-f ' . $sender);
        } finally {
            restore_error_handler();
        }
    }

    /** Build a validated absolute base from the browser-visible request origin and path.
     * @return string Safe public application base.
     * @throws FormError When request origin/path is malformed or indicates an internal rewrite path.
     */
    private function requestPublicBase(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($host) || !is_string($uri)
            || preg_match('/\A[A-Za-z0-9.\-\[\]:]+\z/D', $host) !== 1
            || !str_starts_with($uri, '/') || str_starts_with($uri, '//')
            || preg_match('/[\x00-\x20\\\\]/', $uri) === 1) {
            throw new FormError('Veřejná adresa pozvánky není platná.');
        }
        $scheme = (($_SERVER['HTTPS'] ?? '') !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $candidate = Http::normalizePublicBaseUrl($scheme . '://' . $host);
        if ($candidate === null) {
            throw new FormError('Veřejná adresa pozvánky není platná.');
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains(rawurldecode($path), '/subdom/')) {
            throw new FormError('Veřejná cesta pozvánky není platná.');
        }
        if (str_ends_with(strtolower($path), '/index.php')) {
            $path = substr($path, 0, -strlen('/index.php'));
        }
        $base = Http::normalizePublicBaseUrl($candidate . $path);
        if ($base === null) {
            throw new FormError('Veřejná cesta pozvánky není platná.');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && strtolower($origin) !== 'null') {
            $originShape = parse_url($origin);
            $normalizedOrigin = Http::normalizePublicBaseUrl($origin);
            $baseParts = parse_url($base);
            $originParts = is_string($normalizedOrigin) ? parse_url($normalizedOrigin) : false;
            if (!is_array($originShape) || isset($originShape['path'])
                || isset($originShape['query']) || isset($originShape['fragment'])
                || !is_array($baseParts) || !is_array($originParts)
                || strtolower((string) $baseParts['scheme']) !== strtolower((string) $originParts['scheme'])
                || strtolower((string) $baseParts['host']) !== strtolower((string) $originParts['host'])
                || ($baseParts['port'] ?? null) !== ($originParts['port'] ?? null)) {
                throw new FormError('Veřejná adresa pozvánky není platná.');
            }
        }
        return $base;
    }

    /** Read static sender settings from protected storage.
     * @return array{from:string,smtp_host:?string,smtp_port:?int} Valid sender settings.
     * @throws FormError When mail setup is missing or invalid.
     */
    private function mailConfig(): array
    {
        if (!is_file($this->configPath)) {
            throw new FormError('E-mailové pozvánky zatím nejsou nakonfigurované.');
        }
        try {
            $config = require $this->configPath;
        } catch (Throwable) {
            throw new FormError('Poštovní nastavení není platné.');
        }
        if (!is_array($config)) {
            throw new FormError('Poštovní nastavení není platné.');
        }
        $from = isset($config['from']) && is_string($config['from'])
            ? trim($config['from']) : '';
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\A[A-Za-z0-9.!#$%&\'*+\/= ?^_`{|}~-]+@[A-Za-z0-9.-]+\z/D', str_replace(' ', '', $from)) !== 1) {
            throw new FormError('Nastavte bezpečnou odesílací e-mailovou adresu.');
        }
        $smtpHost = $config['smtp_host'] ?? null;
        $smtpPortValue = $config['smtp_port'] ?? null;
        if (($smtpHost === null) !== ($smtpPortValue === null)) {
            throw new FormError('Vyplňte oba údaje místního SMTP serveru.');
        }
        $smtpPort = null;
        if ($smtpHost !== null) {
            if (!is_string($smtpHost)
                || preg_match('/\A[A-Za-z0-9.:-]{1,253}\z/', $smtpHost) !== 1) {
                throw new FormError('Nastavení místního SMTP serveru není platné.');
            }
            $smtpPort = filter_var($smtpPortValue, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($smtpPort === false) {
                throw new FormError('Port místního SMTP serveru není platný.');
            }
        }
        return ['from' => $from, 'smtp_host' => $smtpHost, 'smtp_port' => $smtpPort];
    }
}
