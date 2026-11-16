<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec($chunk)];
        }
        return $secret;
    }

    public static function matchCounter(
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $code,
        int $timestamp,
    ): ?int {
        if (preg_match('/^[A-Z2-7]{32}$/D', $secret) !== 1 || $timestamp < 0) {
            throw new InvalidArgumentException('TOTP requires a 32-character Base32 secret and a nonnegative Unix timestamp.');
        }
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return null;
        }
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            $key .= chr(bindec($chunk));
        }
        $current = intdiv($timestamp, 30);
        for ($counter = $current + 1; $counter >= max(0, $current - 1); $counter--) {
            $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
            $offset = ord($hash[19]) & 15;
            $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
            if (hash_equals(str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT), $code)) {
                return $counter;
            }
        }
        return null;
    }
}
