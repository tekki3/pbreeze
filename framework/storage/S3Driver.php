<?php
declare(strict_types=1);

namespace PBreeze\Storage;

use InvalidArgumentException;
use PBreeze\Storage;
use RuntimeException;

final class S3Driver implements DriverInterface
{
    private readonly string $endpoint;

    /** @param array<string, mixed> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config, private readonly HttpClient $http)
    {
        if (!is_string($config['endpoint'] ?? null) || !is_string($config['bucket'] ?? null) || !is_string($config['region'] ?? null)) {
            throw new InvalidArgumentException('S3 storage endpoint, bucket, and region must be strings.');
        }
        $this->endpoint = HttpClient::endpoint($config['endpoint']);
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $config['bucket'])
            || !preg_match('/^[a-z0-9-]{1,64}$/D', $config['region'] ?? '')) {
            throw new InvalidArgumentException('S3 storage needs a valid bucket and explicit region.');
        }
        foreach (['access_key', 'secret_key'] as $key) {
            if (!is_string($config[$key] ?? null) || $config[$key] === '' || preg_match('/[\x00-\x20\x7f]/', $config[$key])) {
                throw new InvalidArgumentException('S3 storage credentials must be configured without whitespace or controls.');
            }
        }
        if (!is_string($config['session_token'] ?? '') || preg_match('/[\x00-\x20\x7f]/', $config['session_token'] ?? '')) {
            throw new InvalidArgumentException('Invalid S3 session token.');
        }
    }

    public function put(string $key, #[\SensitiveParameter] string $content): void
    {
        $this->request('PUT', $key, $content);
    }

    public function get(string $key): string
    {
        $result = $this->request('GET', $key);
        if ($result['status'] === 404) {
            throw new RuntimeException('Storage file not found.');
        }
        return $result['body'];
    }

    public function exists(string $key): bool
    {
        return $this->request('HEAD', $key)['status'] !== 404;
    }

    public function delete(string $key): bool
    {
        $existed = $this->exists($key);
        if (!$existed) {
            return false;
        }
        return $this->request('DELETE', $key)['status'] !== 404;
    }

    /** @return array{status: int, body: string} */
    private function request(string $method, string $key, #[\SensitiveParameter] string $content = ''): array
    {
        Storage::key($key);
        $path = '/' . $this->config['bucket'] . '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $url = $this->endpoint . $path;
        $parts = parse_url($url);
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $date = gmdate('Ymd\THis\Z');
        $day = substr($date, 0, 8);
        $hash = hash('sha256', $content);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $date];
        if (($this->config['session_token'] ?? '') !== '') {
            $headers['x-amz-security-token'] = $this->config['session_token'];
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $path . "\n\n" . $canonicalHeaders . "\n" . $signed . "\n" . $hash;
        $scope = $day . '/' . $this->config['region'] . '/s3/aws4_request';
        $signing = hash_hmac('sha256', $day, 'AWS4' . $this->config['secret_key'], true);
        foreach ([$this->config['region'], 's3', 'aws4_request'] as $part) {
            $signing = hash_hmac('sha256', $part, $signing, true);
        }
        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical), $signing);
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->config['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
        if ($method === 'PUT') {
            $headers['Content-Type'] = 'application/octet-stream';
            $headers['Content-Length'] = (string) strlen($content);
        }
        $result = $this->http->request($method, $url, $headers, $content);
        if ($result['status'] === 404 && $method !== 'PUT') {
            return $result;
        }
        $expected = match ($method) {
            'PUT', 'GET', 'HEAD' => [200],
            'DELETE' => [204],
        };
        if (!in_array($result['status'], $expected, true)) {
            throw new RuntimeException('S3 storage request failed (HTTP ' . $result['status'] . ').');
        }
        return $result;
    }
}
