<?php

declare(strict_types=1);

namespace Dabing;

use Throwable;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/InviteMailer.php
 * Module Type: Service
 * Purpose: Send project invitation email using configured PHP mail transport.
 * Responsibilities: Validate static mail config, build canonical links, and send text mail.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Sends no email until a project owner explicitly requests an invite. */
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

    /** Send the code and canonical claim link to one validated recipient.
     * @param string $recipient Validated recipient email address.
     * @param string $code One-time lower-case hexadecimal code.
     * @return bool True when PHP mail transport accepted the message.
     * @throws FormError When mail configuration is missing or invalid.
     */
    public function sendInvite(string $recipient, string $code): bool
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\A[a-f0-9]{32}\z/D', $code) !== 1) {
            throw new FormError('E-mailovou pozvánku nelze odeslat.');
        }
        $config = $this->mailConfig();
        $baseUrl = Http::publicBaseUrl() ?? $config['base_url'];
        $link = rtrim($baseUrl, '/') . '/' . Http::routeQuery(
            'email-join',
            ['code' => $code]
        );
        $subject = 'Pozvanka do Dabing';
        $message = "Dobrý den,\r\n\r\n"
            . "Byli jste pozváni do soukromého projektu Dabing.\r\n"
            . "Odkaz pro připojení: {$link}\r\n\r\n"
            . "Jednorázový kód: {$code}\r\n\r\n"
            . "Pokud jste o pozvánku nežádali, tento e-mail ignorujte.\r\n";
        $headers = 'From: ' . $config['from'] . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . 'X-Content-Type-Options: nosniff';

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->sendWindows($recipient, $subject, $message, $headers, $config);
        }
        return mail($recipient, $subject, $message, $headers, '-f ' . $config['from']);
    }

    /** Read and validate canonical, non-secret mail configuration.
     * @return array{base_url:string,from:string,smtp_host:?string,smtp_port:?int} Validated config.
     * @throws FormError When the operator has not configured mail delivery.
     */
    private function mailConfig(): array
    {
        if (!is_file($this->configPath)) {
            throw new FormError('E-mailové pozvánky zatím nejsou nakonfigurované.');
        }
        try {
            $config = require $this->configPath;
        } catch (Throwable $error) {
            throw new FormError('Poštovní nastavení není platné.');
        }
        if (!is_array($config) || !isset($config['from'])
            || !is_string($config['from'])) {
            throw new FormError('Poštovní nastavení není platné.');
        }
        $configuredBase = Http::publicBaseUrl();
        $legacyBase = $config['base_url'] ?? null;
        $baseUrl = $configuredBase;
        if ($baseUrl === null && is_string($legacyBase)) {
            $baseUrl = Http::normalizePublicBaseUrl($legacyBase);
        }
        if ($baseUrl === null) {
            throw new FormError('Nastavte platnou veřejnou adresu webu v poštovním nastavení.');
        }
        $sender = trim($config['from']);
        $senderPattern = '/\A[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+\z/D';
        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || preg_match($senderPattern, $sender) !== 1) {
            throw new FormError('Nastavte bezpečnou odesílací e-mailovou adresu.');
        }
        $smtpHost = $config['smtp_host'] ?? null;
        $smtpPortValue = $config['smtp_port'] ?? null;
        if (($smtpHost === null) !== ($smtpPortValue === null)) {
            throw new FormError('Vyplňte oba údaje místního SMTP serveru.');
        }
        $smtpPort = null;
        if ($smtpHost !== null) {
            if (!is_string($smtpHost) || $smtpHost === ''
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
        return [
            'base_url' => $baseUrl,
            'from' => $sender,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
        ];
    }

    /** Send mail on Windows while restoring per-request INI settings.
     * @param string $recipient Recipient email.
     * @param string $subject Fixed ASCII subject.
     * @param string $message Plain-text message.
     * @param string $headers Safe configured headers.
     * @param array{base_url:string,from:string,smtp_host:?string,smtp_port:?int} $config Validated mail config.
     * @return bool True when PHP mail transport accepted the message.
     */
    private function sendWindows(
        string $recipient,
        string $subject,
        string $message,
        string $headers,
        array $config
    ): bool {
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
            return mail($recipient, $subject, $message, $headers);
        } finally {
            foreach ($previous as $key => $value) {
                ini_set($key, $value);
            }
        }
    }
}
