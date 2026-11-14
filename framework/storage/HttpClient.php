<?php
declare(strict_types=1);

namespace PBreeze\Storage;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class HttpClient
{
    /** @param Closure(string, string, array<string, string>, string, int, int): array{status: int, body: string}|null $transport */
    public function __construct(
        private readonly int $timeout,
        private readonly int $maxBytes,
        private readonly string $caFile = '',
        private readonly ?Closure $transport = null,
    ) {
        if ($timeout < 1 || $timeout > 300 || $maxBytes < 1 || $maxBytes > 268435456) {
            throw new InvalidArgumentException('Invalid storage HTTPS timeout or maximum byte size.');
        }
    }

    public static function endpoint(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('Storage endpoints must be trusted HTTPS origins without credentials, paths, query, or fragment.');
        }
        return rtrim($url, '/');
    }

    /** @param array<string, string> $headers @return array{status: int, body: string} */
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $content = ''): array
    {
        $parts = parse_url($url);
        if (!in_array($method, ['PUT', 'GET', 'HEAD', 'DELETE'], true) || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)
            || strlen($content) > $this->maxBytes) {
            throw new InvalidArgumentException('Use a bounded storage HTTPS request without URL credentials, query, or fragment.');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || !preg_match('/^[A-Za-z0-9-]+$/D', $name)
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Invalid storage HTTPS header.');
            }
        }
        if ($this->transport !== null) {
            $result = ($this->transport)($method, $url, $headers, $content, $this->maxBytes, $this->timeout);
            if (!is_array($result) || !isset($result['status'], $result['body']) || !is_int($result['status']) || !is_string($result['body'])
                || $result['status'] < 100 || $result['status'] > 599 || strlen($result['body']) > $this->maxBytes) {
                throw new RuntimeException('Invalid or oversized storage transport response.');
            }
            return $result;
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Remote object storage requires the native curl extension.');
        }
        if ($this->caFile !== '' && (!is_file($this->caFile) || !is_readable($this->caFile))) {
            throw new RuntimeException('Storage CA file must be a readable local file.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize the storage HTTPS request.');
        }
        $body = '';
        $oversized = false;
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $handle, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $content;
        }
        if ($this->caFile !== '') {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        try {
            if (!curl_setopt_array($curl, $options)) {
                throw new RuntimeException('Cannot configure the storage HTTPS request.');
            }
            if (curl_exec($curl) === false) {
                throw new RuntimeException($oversized ? 'Storage response exceeds the configured maximum byte size.'
                    : 'Storage HTTPS request failed (curl code ' . curl_errno($curl) . ').');
            }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally {
            unset($curl);
        }
    }
}
