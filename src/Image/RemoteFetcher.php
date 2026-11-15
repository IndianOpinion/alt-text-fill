<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

/**
 * Fetches offloaded media over HTTP (case 25): allowed hosts only, at most
 * three redirects each checked against the same list, a 10 second timeout,
 * and the byte limit enforced while reading.
 */
class RemoteFetcher
{
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 10;

    /**
     * @param list<string> $extraHosts
     */
    public function __construct(private array $extraHosts = [])
    {
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = $this->extraHosts;
        $uploads = wp_upload_dir(null, false);
        $base = is_array($uploads) ? (string) ($uploads['baseurl'] ?? '') : '';
        $host = wp_parse_url($base, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $hosts[] = $host;
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    public function isAllowed(string $url): bool
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host)) {
            return false;
        }

        return in_array(strtolower($host), $this->allowedHosts(), true);
    }

    /**
     * @return array{ok: bool, bytes: string, reason: string}
     */
    public function fetch(string $url, int $maxBytes): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->isAllowed($url)) {
                return ['ok' => false, 'bytes' => '', 'reason' => 'host not allowed'];
            }
            $response = wp_safe_remote_get($url, [
                'timeout' => self::TIMEOUT,
                'redirection' => 0,
                'limit_response_size' => $maxBytes + 1,
            ]);
            if (is_wp_error($response)) {
                return ['ok' => false, 'bytes' => '', 'reason' => 'file missing'];
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 300 && $status < 400) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if ($location === '') {
                    return ['ok' => false, 'bytes' => '', 'reason' => 'file missing'];
                }
                $url = str_starts_with($location, '/') ? self::origin($url) . $location : $location;
                continue;
            }
            if ($status !== 200) {
                return ['ok' => false, 'bytes' => '', 'reason' => 'file missing'];
            }
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > $maxBytes) {
                return ['ok' => false, 'bytes' => '', 'reason' => 'too large'];
            }

            return ['ok' => true, 'bytes' => $body, 'reason' => ''];
        }

        return ['ok' => false, 'bytes' => '', 'reason' => 'too many redirects'];
    }

    /** A HEAD request that must answer 200 (case 24). */
    public function headOk(string $url): bool
    {
        if (!$this->isAllowed($url)) {
            return false;
        }
        $response = wp_safe_remote_head($url, ['timeout' => self::TIMEOUT, 'redirection' => 0]);

        return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
    }

    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
