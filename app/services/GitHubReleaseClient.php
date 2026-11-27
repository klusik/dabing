<?php
declare(strict_types=1);

namespace Dabing;

/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: app/services/GitHubReleaseClient.php
 * Module Type: Service
 * Purpose: Fetch and verify the latest published Dabing release package.
 * Responsibilities: Restrict GitHub transport, validate stable assets, and hash downloads.
 * Author: Rudolf Klusal
 * License: All rights reserved.
 */

/** Provides a fixed-source, HTTPS-only client for GitHub release assets. */
final class GitHubReleaseClient
{
    /**
     * Type: int; Units: bytes; Scope: one GitHub API JSON response;
     * Consumers: latest(); Rationale: release metadata remains small and bounded.
     */
    private const MAX_API_BODY_BYTES = 1048576;

    /**
     * Type: int; Units: bytes; Scope: one release asset; Consumers: latest(), download();
     * Rationale: reject oversized packages before or during transfer.
     */
    private const MAX_RELEASE_BYTES = 209715200;

    /**
     * Type: int; Units: seconds; Scope: one HTTPS connection;
     * Consumers: performRequest(); Rationale: bound time spent connecting.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Type: int; Units: seconds; Scope: one HTTPS request;
     * Consumers: performRequest(); Rationale: bound stalled API and asset requests.
     */
    private const REQUEST_TIMEOUT_SECONDS = 45;

    /**
     * Type: int; Units: redirects; Scope: one asset download;
     * Consumers: download(); Rationale: allow signed GitHub asset handoffs only.
     */
    private const MAX_ASSET_REDIRECTS = 5;

    /**
     * Type: int; Units: JSON nesting levels; Scope: GitHub API response;
     * Consumers: latest(); Rationale: release metadata has a shallow fixed shape.
     */
    private const JSON_MAX_DEPTH = 16;

    private const API_URL = 'https://api.github.com/repos/klusik/dabing/releases/latest';
    private const REPOSITORY_RELEASE_PREFIX = 'https://github.com/klusik/dabing/releases/download/';

    /** Fetch and validate the latest non-draft stable release asset.
     *
     * @return array{version:string,url:string,sha256:string,size:int}|null Stable release or null when none exists.
     * @throws HttpError When GitHub is unavailable or returns invalid metadata.
     */
    public function latest(): ?array
    {
        $body = '';
        $overflow = false;
        $write = static function (\CurlHandle $handle, string $chunk) use (
            &$body,
            &$overflow
        ): int {
            $length = strlen($chunk);
            if (strlen($body) + $length > self::MAX_API_BODY_BYTES) {
                $overflow = true;
                return 0;
            }
            $body .= $chunk;
            return $length;
        };
        $response = $this->performRequest(self::API_URL, $write);
        if ($overflow) {
            throw new HttpError(503, 'Odpověď GitHubu je příliš velká.');
        }
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw new HttpError(503, 'Vydání aplikace z GitHubu nelze načíst.');
        }
        try {
            $release = json_decode(
                $body,
                true,
                self::JSON_MAX_DEPTH,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $error) {
            throw new HttpError(503, 'Odpověď GitHubu nemá platný formát.');
        }
        if (!is_array($release) || ($release['draft'] ?? true) !== false
            || ($release['prerelease'] ?? true) !== false) {
            return null;
        }
        $tag = $release['tag_name'] ?? null;
        if (!is_string($tag)
            || preg_match('/\Av(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $tag) !== 1) {
            throw new HttpError(503, 'Vydání aplikace nemá platné číslo verze.');
        }
        $version = substr($tag, 1);
        $assetName = 'dabing-' . $version . '.zip';
        $assets = $release['assets'] ?? null;
        if (!is_array($assets)) {
            throw new HttpError(503, 'Vydání neobsahuje očekávaný ZIP balíček.');
        }
        $matchingAssets = [];
        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $assetName) {
                $matchingAssets[] = $asset;
            }
        }
        if (count($matchingAssets) !== 1) {
            throw new HttpError(503, 'Vydání neobsahuje právě jeden očekávaný ZIP balíček.');
        }
        $asset = $matchingAssets[0];
        $digest = $asset['digest'] ?? null;
        $size = $asset['size'] ?? null;
        $url = $asset['browser_download_url'] ?? null;
        if (!is_string($digest)
            || preg_match('/\Asha256:([a-f0-9]{64})\z/D', $digest, $matches) !== 1
            || !is_int($size) || $size < 1 || $size > self::MAX_RELEASE_BYTES
            || !is_string($url)
            || $url !== self::REPOSITORY_RELEASE_PREFIX . $tag . '/' . $assetName) {
            throw new HttpError(503, 'Metadata ZIP balíčku nejsou platná.');
        }
        return [
            'version' => $version,
            'url' => $url,
            'sha256' => $matches[1],
            'size' => $size,
        ];
    }

    /** Download the exact validated release asset and verify size and SHA-256.
     *
     * @param array{version:string,url:string,sha256:string,size:int} $release Validated release metadata.
     * @param string $stageZipPath Private temporary destination path.
     * @return void No value is returned.
     * @throws HttpError When transport, redirects, size, or digest validation fails.
     */
    public function download(array $release, string $stageZipPath): void
    {
        if (!array_key_exists('version', $release) || !is_string($release['version'])
            || !array_key_exists('url', $release) || !is_string($release['url'])
            || !array_key_exists('sha256', $release) || !is_string($release['sha256'])
            || !array_key_exists('size', $release) || !is_int($release['size'])) {
            throw new HttpError(400, 'Údaje balíčku aktualizace nejsou platné.');
        }
        $expectedUrl = self::REPOSITORY_RELEASE_PREFIX
            . 'v' . $release['version'] . '/dabing-' . $release['version'] . '.zip';
        if (!$this->isValidVersion($release['version'])
            || $release['url'] !== $expectedUrl
            || preg_match('/\A[a-f0-9]{64}\z/D', $release['sha256']) !== 1
            || $release['size'] < 1
            || $release['size'] > self::MAX_RELEASE_BYTES
            || file_exists($stageZipPath)
            || is_link($stageZipPath)) {
            throw new HttpError(400, 'Údaje balíčku aktualizace nejsou platné.');
        }
        $output = @fopen($stageZipPath, 'x+b');
        if (!is_resource($output)) {
            throw new HttpError(500, 'Dočasný ZIP balíček nelze vytvořit.');
        }
        $currentUrl = $release['url'];
        $redirects = 0;
        $downloadedBytes = 0;
        $hashContext = hash_init('sha256');
        try {
            while (true) {
                $location = null;
                $write = static function (\CurlHandle $handle, string $chunk) use (
                    $output,
                    &$downloadedBytes,
                    &$hashContext,
                    $release
                ): int {
                    $length = strlen($chunk);
                    if ($downloadedBytes + $length > $release['size']) {
                        return 0;
                    }
                    $written = fwrite($output, $chunk);
                    if ($written !== $length) {
                        return 0;
                    }
                    hash_update($hashContext, $chunk);
                    $downloadedBytes += $length;
                    return $length;
                };
                $header = static function (\CurlHandle $handle, string $line) use (
                    &$location
                ): int {
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, strlen('Location:')));
                    }
                    return strlen($line);
                };
                $response = $this->performRequest($currentUrl, $write, $header);
                if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                    if (++$redirects > self::MAX_ASSET_REDIRECTS
                        || !is_string($location)
                        || !$this->isAllowedAssetRedirect($location, $release['url'])) {
                        throw new HttpError(503, 'Přesměrování ZIP balíčku není povolené.');
                    }
                    $currentUrl = $location;
                    if (!ftruncate($output, 0) || fseek($output, 0) !== 0) {
                        throw new HttpError(500, 'Dočasný ZIP balíček nelze obnovit.');
                    }
                    $downloadedBytes = 0;
                    $hashContext = hash_init('sha256');
                    continue;
                }
                if ($response['status'] !== 200) {
                    throw new HttpError(503, 'ZIP balíček z GitHubu nelze stáhnout.');
                }
                if ($downloadedBytes !== $release['size'] || !fflush($output)) {
                    throw new HttpError(503, 'Velikost staženého ZIP balíčku nesouhlasí.');
                }
                $actualDigest = hash_final($hashContext);
                if (!hash_equals($release['sha256'], $actualDigest)) {
                    throw new HttpError(503, 'SHA-256 ZIP balíčku nesouhlasí.');
                }
                if (!chmod($stageZipPath, 0600)) {
                    throw new HttpError(500, 'Stažený ZIP balíček nelze zabezpečit.');
                }
                fclose($output);
                return;
            }
        } catch (\Throwable $error) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($stageZipPath);
            throw $error;
        }
    }

    /** Check whether a version is a canonical semantic X.Y.Z string.
     *
     * @param string $version Version string without the release tag prefix.
     * @return bool True when the version is canonical.
     */
    private function isValidVersion(string $version): bool
    {
        return preg_match(
            '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D',
            $version
        ) === 1;
    }

    /** Check a release redirect against the fixed GitHub asset-host allowlist.
     *
     * @param string $url Redirect location from a GitHub asset response.
     * @param string $releaseUrl The exact initial release URL.
     * @return bool True when HTTPS and hosted by an approved GitHub asset domain.
     */
    private function isAllowedAssetRedirect(string $url, string $releaseUrl): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === 'github.com') {
            return $url === $releaseUrl;
        }
        return in_array($host, [
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
            'github-releases.githubusercontent.com',
        ], true);
    }

    /** Perform one HTTPS request without following redirects.
     *
     * @param string $url Exact URL selected by the release policy.
     * @param callable(\CurlHandle,string):int $writeHandler Bounded response writer.
     * @param callable(\CurlHandle,string):int|null $headerHandler Optional response header handler.
     * @return array{status:int} HTTP status summary.
     * @throws HttpError When cURL is missing or transport fails.
     */
    private function performRequest(
        string $url,
        callable $writeHandler,
        ?callable $headerHandler = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new HttpError(503, 'Rozšíření cURL není dostupné.');
        }
        $handle = curl_init($url);
        if (!$handle instanceof \CurlHandle) {
            throw new HttpError(503, 'Spojení s GitHubem nelze vytvořit.');
        }
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Dabing-Updater/1.0 (+https://github.com/klusik/dabing)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => $writeHandler,
        ];
        if ($headerHandler !== null) {
            $options[CURLOPT_HEADERFUNCTION] = $headerHandler;
        }
        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);
            throw new HttpError(503, 'Spojení s GitHubem nelze nastavit.');
        }
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false) {
            throw new HttpError(503, 'Přenos z GitHubu selhal.');
        }
        return ['status' => $status];
    }
}
