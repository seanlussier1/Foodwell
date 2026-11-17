<?php
declare(strict_types=1);

namespace Foodwell\Services;

use JsonException;

final class PostalAreaClient
{
    private const LOOKUP_TIMEOUT_MS = 5000;
    private const CONNECT_TIMEOUT_MS = 2000;
    private const MAX_BODY_BYTES = 65536;
    private const RETRYABLE_STATUSES = [500, 502, 503, 504];

    /** The optional test transport receives the prefix and the remaining timeout in milliseconds. */
    public function __construct(private string $cacheDirectory, private int $cacheSeconds = 86400,
        private string $caBundle = '', private $transport = null) {}

    public function lookup(string $prefix): array
    {
        if (!preg_match('/^[ABCEGHJ-NPRSTVXY][0-9][ABCEGHJ-NPRSTV-Z]$/D', $prefix)) {
            throw new PostalLookupException('invalid-prefix');
        }
        $file = $this->cacheDirectory . '/ca-' . $prefix . '.json';
        if ($this->cacheSeconds > 0 && is_readable($file)) {
            $entry = json_decode((string) @file_get_contents($file), true);
            if (is_array($entry) && isset($entry['fetchedAt'], $entry['payload']) && is_int($entry['fetchedAt'])
                && $entry['fetchedAt'] <= time() && $entry['fetchedAt'] > time() - $this->cacheSeconds) {
                try { return ['places' => $this->places($prefix, $entry['payload']), 'source' => 'cache', 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $entry['fetchedAt'])]; }
                catch (PostalLookupException $ignored) { /* Ignore corrupt cache and fetch again. */ }
            }
        }
        $response = $this->request($prefix);
        if ($response['status'] === 404) { throw new PostalLookupException('not-found'); }
        if ($response['status'] === 429) { throw new PostalLookupException('rate-limited'); }
        if ($response['status'] !== 200) { throw new PostalLookupException('upstream-error'); }
        try { $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new PostalLookupException('invalid-response'); }
        if (!is_array($payload)) { throw new PostalLookupException('invalid-response'); }
        $places = $this->places($prefix, $payload);
        $fetchedAt = time();
        if ($this->cacheSeconds > 0) {
            if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0700, true)) {
                $temporary = @tempnam($this->cacheDirectory, '.postal-');
                if ($temporary !== false) {
                    if (@file_put_contents($temporary, json_encode(['fetchedAt' => $fetchedAt, 'payload' => $payload]), LOCK_EX) !== false) {
                        if (!@rename($temporary, $file)) { @unlink($temporary); }
                    } else { @unlink($temporary); }
                }
            }
        }
        return ['places' => $places, 'source' => $this->transport === null ? 'live' : 'test-transport', 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $fetchedAt)];
    }

    private function request(string $prefix): array
    {
        $deadline = hrtime(true) + self::LOOKUP_TIMEOUT_MS * 1000000;
        for ($attempt = 0; ; $attempt++) {
            $remainingMs = intdiv($deadline - hrtime(true), 1000000);
            if ($remainingMs < 1) {
                throw new PostalLookupException('timeout');
            }

            try {
                $response = $this->transport !== null
                    ? ($this->transport)($prefix, $remainingMs)
                    : $this->fetch($prefix, $remainingMs);
            } catch (PostalLookupException $error) {
                if ($attempt === 0 && $error->reason === 'connection-interrupted') {
                    continue;
                }
                if ($error->reason === 'connection-interrupted') {
                    throw new PostalLookupException('network-error');
                }
                throw $error;
            }

            if (!is_array($response) || !isset($response['status'], $response['body'])
                || !is_int($response['status']) || !is_string($response['body'])
                || strlen($response['body']) > self::MAX_BODY_BYTES) {
                throw new PostalLookupException('invalid-response');
            }
            if ($attempt === 0 && in_array($response['status'], self::RETRYABLE_STATUSES, true)
                && empty($response['retryAfter'])) {
                continue;
            }
            return $response;
        }
    }

    private function places(string $prefix, array $payload): array
    {
        $postCode = $payload['post code'] ?? null;
        if (($payload['country abbreviation'] ?? null) !== 'CA' || !is_string($postCode) || strtoupper($postCode) !== $prefix
            || !isset($payload['places']) || !is_array($payload['places'])
            || $payload['places'] !== array_values($payload['places'])
            || count($payload['places']) < 1 || count($payload['places']) > 50) {
            throw new PostalLookupException('invalid-response');
        }
        $places = [];
        foreach ($payload['places'] as $place) {
            if (!is_array($place)) { throw new PostalLookupException('invalid-response'); }
            foreach (['place name', 'state', 'state abbreviation'] as $key) {
                if (!isset($place[$key]) || !is_string($place[$key]) || trim($place[$key]) === '' || strlen($place[$key]) > 200) {
                    throw new PostalLookupException('invalid-response');
                }
            }
            if (!preg_match('/^[A-Z]{2}$/D', $place['state abbreviation'])) { throw new PostalLookupException('invalid-response'); }
            $places[] = ['name' => $place['place name'], 'province' => $place['state'], 'provinceCode' => $place['state abbreviation']];
        }
        return $places;
    }

    private function fetch(string $prefix, int $timeoutMs): array
    {
        if (!function_exists('curl_init')) { throw new PostalLookupException('configuration'); }
        // Fixed HTTPS host, CA country and validated prefix: no user-supplied URLs.
        $ch = curl_init('https://api.zippopotam.us/ca/' . rawurlencode($prefix));
        $body = '';
        $tooLarge = false;
        $retryAfter = false;
        $options = [CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_MS, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Foodwell/1.0 (educational postal-area lookup)', CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'HTTP/') === 0) {
                    $retryAfter = false;
                } elseif (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = true;
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }];
        if ($this->caBundle !== '') { $options[CURLOPT_CAINFO] = $this->caBundle; }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch); $errno = curl_errno($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($tooLarge) { throw new PostalLookupException('invalid-response'); }
        if ($ok === false) {
            $reason = match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_CONNECT, CURLE_SEND_ERROR, CURLE_RECV_ERROR => 'connection-interrupted',
                default => 'network-error',
            };
            throw new PostalLookupException($reason);
        }
        return ['status' => $status, 'body' => $body, 'retryAfter' => $retryAfter];
    }
}
