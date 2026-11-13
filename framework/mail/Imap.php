<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;
use RuntimeException;

final class Imap
{
    private int $tag = 0;

    /** @param array<string, string> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config)
    {
    }

    /**
     * @param null|callable(string): bool $known Ontvangt de unieke bronhash.
     * @return list<array{source_key: string, uid: string, uidvalidity: string, mailbox: string, raw: string, message: array<string, mixed>}>
     */
    public function fetch(int $limit = 50, ?callable $known = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Gebruik een IMAP-limiet tussen 1 en 1000.');
        }
        $mailbox = $this->config['mailbox'] ?? 'INBOX';
        if (!preg_match('/^[\x20-\x7e]{1,255}$/D', $mailbox)) {
            throw new InvalidArgumentException('Gebruik een ASCII- of IMAP modified UTF-7-mailboxnaam.');
        }
        $maxBytes = $this->config['max_bytes'] ?? '10485760';
        if (!ctype_digit($maxBytes) || (int) $maxBytes < 1 || (int) $maxBytes > 52428800) {
            throw new InvalidArgumentException('IMAP_MAX_BYTES moet tussen 1 en 52428800 liggen.');
        }
        $socket = new MailSocket($this->config);
        try {
            $greeting = $socket->line();
            if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting, $greet)) {
                throw new RuntimeException('Ongeldige IMAP-begroeting.');
            }
            if ($socket->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS');
                $socket->startTls();
            }
            if (strtoupper($greet[1]) !== 'PREAUTH') {
                if ($socket->username === '' || $socket->password === '' || $socket->encryption === 'none') {
                    throw new RuntimeException('IMAP-login vereist TLS, een gebruikersnaam en wachtwoord.');
                }
                $this->command($socket, 'LOGIN ' . self::quote($socket->username) . ' ' . self::quote($socket->password));
            }
            $selected = $this->command($socket, 'EXAMINE ' . self::quote($mailbox));
            if (!preg_match('/\[UIDVALIDITY ([1-9][0-9]*)\]/i', implode("\n", $selected['lines']), $match)) {
                throw new RuntimeException('IMAP-server geeft geen UIDVALIDITY terug.');
            }
            $validity = $match[1];
            if (strlen($validity) > 10 || (int) $validity > 4294967295) {
                throw new RuntimeException('Ongeldige IMAP UIDVALIDITY.');
            }
            $search = $this->command($socket, 'UID SEARCH ALL');
            $uids = [];
            $searchFound = false;
            foreach ($search['lines'] as $line) {
                if (preg_match('/^\* SEARCH(?: ([0-9 ]+))?$/Di', $line, $match)) {
                    $searchFound = true;
                    $uids = trim($match[1] ?? '') === '' ? [] : explode(' ', trim($match[1]));
                }
            }
            if (!$searchFound) {
                throw new RuntimeException('IMAP-server geeft geen geldige SEARCH-respons terug.');
            }
            foreach ($uids as $uid) {
                if (!preg_match('/^[1-9][0-9]{0,9}$/D', $uid) || (int) $uid > 4294967295) {
                    throw new RuntimeException('Ongeldige IMAP UID-lijst.');
                }
            }
            sort($uids, SORT_NUMERIC);
            if ($known === null) {
                $uids = array_slice($uids, -$limit);
            }
            $result = [];
            $totalBytes = 0;
            foreach ($uids as $uid) {
                $key = hash('sha256', json_encode([
                    strtolower($this->config['host']), $this->config['port'], $socket->username, $mailbox, $validity, $uid,
                ], JSON_THROW_ON_ERROR));
                if ($known !== null && $known($key)) {
                    continue;
                }
                $fetched = $this->command($socket, "UID FETCH $uid (UID BODY.PEEK[])", (int) $maxBytes);
                if (count($fetched['literals']) !== 1
                    || !preg_match('/\bUID ' . preg_quote($uid, '/') . '\b/i', implode("\n", $fetched['lines']))) {
                    throw new RuntimeException("IMAP-bericht met UID $uid is niet volledig ontvangen.");
                }
                $raw = $fetched['literals'][0];
                $totalBytes += strlen($raw);
                if ($totalBytes > 52428800) {
                    throw new RuntimeException('IMAP-batch is groter dan 50 MiB; gebruik een kleinere limiet.');
                }
                $result[] = [
                    'source_key' => $key, 'uid' => $uid, 'uidvalidity' => $validity,
                    'mailbox' => $mailbox, 'raw' => $raw, 'message' => Mime::parse($raw),
                ];
                if (count($result) >= $limit) {
                    break;
                }
            }
            return $result;
        } finally {
            $socket->close();
        }
    }

    private static function quote(#[\SensitiveParameter] string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    /** @return array{lines: list<string>, literals: list<string>} */
    private function command(MailSocket $socket, #[\SensitiveParameter] string $command, int $maxLiteral = 0): array
    {
        $tag = 'P' . ++$this->tag;
        $socket->write("$tag $command\r\n");
        $lines = [];
        $literals = [];
        $bytes = 0;
        for ($count = 0; $count < 10000; $count++) {
            $line = $socket->line(2097152);
            $bytes += strlen($line);
            if ($bytes > 2097152) {
                throw new RuntimeException('IMAP-antwoord bevat te veel metadata.');
            }
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) {
                    throw new RuntimeException('IMAP-server weigerde de opdracht.');
                }
                return ['lines' => $lines, 'literals' => $literals];
            }
            if (preg_match('/^\* BYE\b/i', $line)) {
                throw new RuntimeException('IMAP-server heeft de verbinding gesloten.');
            }
            $lines[] = $line;
            if (preg_match('/\{([0-9]+)\}$/D', $line, $match)) {
                $length = (int) $match[1];
                if ($length > $maxLiteral || $maxLiteral === 0 || $literals !== []) {
                    throw new RuntimeException('IMAP-bericht overschrijdt de ingestelde limiet of bevat onverwachte literals.');
                }
                $literals[] = $socket->bytes($length);
            }
        }
        throw new RuntimeException('IMAP-antwoord bevat te veel regels.');
    }
}
