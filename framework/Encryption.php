<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class EncryptionException extends RuntimeException
{
}

final class Encryption
{
    private const PREFIX = 'pbreeze:v1:';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(#[\SensitiveParameter] private readonly string $configuredKey = '')
    {
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $key = $this->key();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::PREFIX . $context, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new EncryptionException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(#[\SensitiveParameter] string $payload, string $context = ''): string
    {
        $key = $this->key();
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new EncryptionException('Encrypted data is invalid or could not be authenticated.');
        }
        $encoded = substr($payload, strlen(self::PREFIX));
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) < 28 || base64_encode($bytes) !== $encoded) {
            throw new EncryptionException('Encrypted data is invalid or could not be authenticated.');
        }
        $plaintext = openssl_decrypt(
            substr($bytes, 28), self::CIPHER, $key, OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), self::PREFIX . $context,
        );
        if ($plaintext === false) {
            throw new EncryptionException('Encrypted data is invalid or could not be authenticated.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        if (!function_exists('openssl_encrypt') || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new EncryptionException('Encryption requires the native OpenSSL extension with AES-256-GCM.');
        }
        $encoded = str_starts_with($this->configuredKey, 'base64:') ? substr($this->configuredKey, 7) : '';
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32 || base64_encode($key) !== $encoded) {
            throw new EncryptionException('APP_KEY is missing or invalid. Generate a key with php breeze key:generate.');
        }
        return $key;
    }

    public function __debugInfo(): array
    {
        return ['configuredKey' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('An encryption service containing key material must not be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('An encryption service must not be unserialized.');
    }
}
