<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;

final class Message
{
    public readonly string $messageId;

    /** @param list<string> $to */
    public function __construct(
        public readonly string $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text = '',
        public readonly string $html = '',
        ?string $messageId = null,
    ) {
        self::address($from);
        if ($to === [] || count($to) > 100 || !array_is_list($to)) {
            throw new InvalidArgumentException('Gebruik 1 tot 100 ontvangers.');
        }
        foreach ($to as $recipient) {
            self::address($recipient);
        }
        if (strlen($subject) > 500 || preg_match('/[\x00-\x1f\x7f]/', $subject)
            || preg_match('//u', $subject) !== 1) {
            throw new InvalidArgumentException('Ongeldig onderwerp (maximaal 500 UTF-8-bytes, geen stuurtekens).');
        }
        if (($text === '' && $html === '') || strlen($text) + strlen($html) > 5 * 1024 * 1024
            || preg_match('//u', $text . $html) !== 1 || str_contains($text . $html, "\0")) {
            throw new InvalidArgumentException('Gebruik UTF-8 tekst of HTML, samen maximaal 5 MiB, zonder NUL-bytes.');
        }
        $messageId ??= '<' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>';
        if (!preg_match('/^<[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+>$/D', $messageId) || strlen($messageId) > 250) {
            throw new InvalidArgumentException('Ongeldig Message-ID.');
        }
        $this->messageId = $messageId;
    }

    public static function address(string $address): void
    {
        if (strlen($address) > 254 || !preg_match('/^[\x21-\x7e]+$/D', $address)
            || strpbrk($address, '<>') !== false || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Gebruik een geldig ASCII-e-mailadres zonder weergavenaam.');
        }
    }

    public function wire(): string
    {
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: ' . $this->messageId,
            'From: ' . $this->from,
            'To: ' . implode(",\r\n ", $this->to),
            'Subject: ' . self::encodedSubject($this->subject),
            'MIME-Version: 1.0',
        ];
        if ($this->text !== '' && $this->html !== '') {
            $boundary = 'pbreeze_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--$boundary\r\n" . self::part('plain', $this->text)
                . "\r\n--$boundary\r\n" . self::part('html', $this->html) . "\r\n--$boundary--\r\n";
        } else {
            $type = $this->html !== '' ? 'html' : 'plain';
            $headers[] = "Content-Type: text/$type; charset=UTF-8";
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($this->html !== '' ? $this->html : $this->text), 76, "\r\n");
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodedSubject(string $subject): string
    {
        if ($subject === '') {
            return '';
        }
        // 30 bytes per encoded-word keeps every word below RFC 2047's 75 characters.
        $words = [];
        $chunk = '';
        foreach (preg_split('//u', $subject, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            if (strlen($chunk . $character) > 30) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    private static function part(string $type, string $body): string
    {
        return "Content-Type: text/$type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
    }
}
