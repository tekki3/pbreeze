<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use RuntimeException;

final class Mime
{
    /** @return array{headers: array<string, string>, subject: string, from: string, to: string, text: string, html: string, message_id: string} */
    public static function parse(string $raw): array
    {
        [$headers] = self::split($raw);
        $parts = 0;
        [$text, $html] = self::body($raw, 0, $parts);
        return [
            'headers' => $headers,
            'subject' => self::decodeHeader($headers['subject'] ?? ''),
            'from' => self::decodeHeader($headers['from'] ?? ''),
            'to' => self::decodeHeader($headers['to'] ?? ''),
            'text' => $text,
            'html' => $html,
            'message_id' => $headers['message-id'] ?? '',
        ];
    }

    /** @return array{array<string, string>, string} */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $offset = strpos($raw, "\n\n");
        if ($offset === false || $offset > 65536) {
            throw new RuntimeException('Ongeldige of te grote MIME-headers.');
        }
        $head = substr($raw, 0, $offset);
        $body = substr($raw, $offset + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (!preg_match('/^([!-9;-~]+):[ \t]*(.*)$/D', $line, $match)) {
                throw new RuntimeException('Ongeldige MIME-header.');
            }
            $name = strtolower($match[1]);
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $match[2] : $match[2];
        }
        return [$headers, $body];
    }

    /** @return array{string, string} */
    private static function body(string $raw, int $depth, int &$parts): array
    {
        if ($depth > 10 || ++$parts > 100) {
            throw new RuntimeException('MIME-bericht bevat te veel of te diep geneste onderdelen.');
        }
        [$headers, $body] = self::split($raw);
        $type = $headers['content-type'] ?? 'text/plain; charset=US-ASCII';
        $media = strtolower(trim(explode(';', $type, 2)[0]));
        if (preg_match('/^attachment\b/i', $headers['content-disposition'] ?? '')) {
            return ['', ''];
        }
        if (str_starts_with($media, 'multipart/')) {
            $boundary = self::parameter($type, 'boundary');
            if ($boundary === null || $boundary === '' || strlen($boundary) > 200) {
                throw new RuntimeException('Multipart MIME-boundary ontbreekt of is ongeldig.');
            }
            preg_match_all('/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\n|$)/m', $body, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if (count($markers) < 2) {
                throw new RuntimeException('Onvolledig multipart MIME-bericht.');
            }
            $text = '';
            $html = '';
            $start = null;
            $closed = false;
            foreach ($markers as $marker) {
                if ($start !== null) {
                    $chunk = substr($body, $start, $marker[0][1] - $start);
                    if (str_ends_with($chunk, "\n")) {
                        $chunk = substr($chunk, 0, -1);
                    }
                    [$partText, $partHtml] = self::body($chunk, $depth + 1, $parts);
                    $text .= $partText;
                    $html .= $partHtml;
                }
                if (($marker[1][0] ?? '') === '--') {
                    $closed = $start !== null;
                    break;
                }
                $start = $marker[0][1] + strlen($marker[0][0]);
            }
            if (!$closed) {
                throw new RuntimeException('Afsluitende multipart MIME-boundary ontbreekt.');
            }
            return [$text, $html];
        }
        if (!in_array($media, ['text/plain', 'text/html'], true)) {
            return ['', ''];
        }
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $decoded = match ($encoding) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body), true),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary' => $body,
            default => throw new RuntimeException('Niet-ondersteunde MIME-transferencoding.'),
        };
        if ($decoded === false) {
            throw new RuntimeException('Ongeldige MIME-base64.');
        }
        $charset = self::parameter($type, 'charset') ?? 'US-ASCII';
        $decoded = self::utf8($decoded, $charset);
        return $media === 'text/html' ? ['', $decoded] : [$decoded, ''];
    }

    private static function parameter(string $value, string $name): ?string
    {
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $value, $match)) {
            return $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        }
        return null;
    }

    private static function decodeHeader(string $value): string
    {
        if (!function_exists('iconv_mime_decode')) {
            throw new RuntimeException('De native iconv-extensie is nodig voor inkomende e-mail.');
        }
        return self::convert(static fn () => iconv_mime_decode($value, ICONV_MIME_DECODE_STRICT, 'UTF-8'));
    }

    private static function utf8(string $body, string $charset): string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,60}$/D', $charset) || !function_exists('iconv')) {
            throw new RuntimeException('Ongeldige charset of ontbrekende native iconv-extensie.');
        }
        return self::convert(static fn () => iconv($charset, 'UTF-8', $body));
    }

    private static function convert(callable $operation): string
    {
        set_error_handler(static function (): never {
            throw new RuntimeException('Kan MIME-header of berichttekst niet naar UTF-8 omzetten.');
        });
        try {
            $converted = $operation();
            if ($converted === false) {
                throw new RuntimeException('Kan MIME-header of berichttekst niet naar UTF-8 omzetten.');
            }
            return $converted;
        } finally {
            restore_error_handler();
        }
    }
}
