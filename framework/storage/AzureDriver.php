<?php
declare(strict_types=1);

namespace PBreeze\Storage;

use InvalidArgumentException;
use PBreeze\Storage;
use RuntimeException;

final class AzureDriver implements DriverInterface
{
    private readonly string $endpoint;
    private readonly string $accountKey;

    /** @param array<string, mixed> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config, private readonly HttpClient $http)
    {
        if (!is_string($config['account'] ?? null) || !is_string($config['container'] ?? null)
            || !is_string($config['key'] ?? null) || !is_string($config['endpoint'] ?? '')) {
            throw new InvalidArgumentException('Azure storage configuration requires string values.');
        }
        if (!preg_match('/^[a-z0-9]{3,24}$/D', $config['account'])
            || !preg_match('/^[a-z0-9](?:[a-z0-9]|-(?!-)){1,61}[a-z0-9]$/D', $config['container'] ?? '')) {
            throw new InvalidArgumentException('Azure storage needs a valid account name and container.');
        }
        $this->endpoint = HttpClient::endpoint(($config['endpoint'] ?? '') !== '' ? $config['endpoint']
            : 'https://' . $config['account'] . '.blob.core.windows.net');
        $key = base64_decode($config['key'] ?? '', true);
        if ($key === false || strlen($key) !== 64) {
            throw new InvalidArgumentException('Azure storage needs a base64-encoded 64-byte account key.');
        }
        $this->accountKey = $key;
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
        return $this->request('DELETE', $key)['status'] !== 404;
    }

    /** @return array{status: int, body: string} */
    private function request(string $method, string $key, #[\SensitiveParameter] string $content = ''): array
    {
        Storage::key($key);
        $path = '/' . $this->config['container'] . '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $headers = ['x-ms-date' => gmdate('D, d M Y H:i:s') . ' GMT', 'x-ms-version' => '2023-11-03'];
        if ($method === 'PUT') {
            $headers['x-ms-blob-type'] = 'BlockBlob';
        } elseif ($method === 'DELETE') {
            $headers['x-ms-delete-snapshots'] = 'include';
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $length = $method === 'PUT' && $content !== '' ? (string) strlen($content) : '';
        $type = $method === 'PUT' ? 'application/octet-stream' : '';
        $canonical = implode("\n", [$method, '', '', $length, '', $type, '', '', '', '', '', ''])
            . "\n" . $canonicalHeaders . '/' . $this->config['account'] . $path;
        $headers['Authorization'] = 'SharedKey ' . $this->config['account'] . ':'
            . base64_encode(hash_hmac('sha256', $canonical, $this->accountKey, true));
        if ($method === 'PUT') {
            $headers['Content-Type'] = $type;
            $headers['Content-Length'] = (string) strlen($content);
        }
        $result = $this->http->request($method, $this->endpoint . $path, $headers, $content);
        if ($result['status'] === 404 && $method !== 'PUT') {
            return $result;
        }
        $expected = match ($method) {
            'PUT' => [201],
            'GET', 'HEAD' => [200],
            'DELETE' => [202],
        };
        if (!in_array($result['status'], $expected, true)) {
            throw new RuntimeException('Azure storage request failed (HTTP ' . $result['status'] . ').');
        }
        return $result;
    }
}
