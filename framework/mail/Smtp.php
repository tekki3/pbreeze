<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use RuntimeException;
use Throwable;

final class DeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain)
    {
        parent::__construct($message);
    }
}

final class Smtp
{
    /** @param array<string, string> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config)
    {
    }

    public function send(Message $message): void
    {
        $socket = null;
        $uncertain = false;
        try {
            $socket = new MailSocket($this->config);
            $this->reply($socket, [220]);
            $capabilities = $this->command($socket, 'EHLO localhost', [250]);
            if ($socket->encryption === 'starttls') {
                if (!preg_match('/^250[ -]STARTTLS\b/im', $capabilities)) {
                    throw new RuntimeException('SMTP-server biedt geen STARTTLS aan.');
                }
                $this->command($socket, 'STARTTLS', [220]);
                $socket->startTls();
                $capabilities = $this->command($socket, 'EHLO localhost', [250]);
            }
            if ($socket->username !== '' || $socket->password !== '') {
                if ($socket->username === '' || $socket->password === '') {
                    throw new RuntimeException('SMTP-gebruikersnaam en wachtwoord zijn beide vereist.');
                }
                if (preg_match('/^250[ -]AUTH[ =].*\bPLAIN\b/im', $capabilities)) {
                    $this->command($socket, 'AUTH PLAIN', [334]);
                    $this->command($socket, base64_encode("\0" . $socket->username . "\0" . $socket->password), [235]);
                } elseif (preg_match('/^250[ -]AUTH[ =].*\bLOGIN\b/im', $capabilities)) {
                    $this->command($socket, 'AUTH LOGIN', [334]);
                    $this->command($socket, base64_encode($socket->username), [334]);
                    $this->command($socket, base64_encode($socket->password), [235]);
                } else {
                    throw new RuntimeException('SMTP vereist AUTH PLAIN of LOGIN over TLS.');
                }
            }
            $this->command($socket, 'MAIL FROM:<' . $message->from . '>', [250]);
            foreach ($message->to as $recipient) {
                $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);
            $wire = preg_replace('/^\./m', '..', $message->wire());
            $uncertain = true;
            $socket->write(rtrim($wire, "\r\n") . "\r\n.\r\n");
            $this->reply($socket, [250], $uncertain);
            // De 250 na DATA is doorslaggevend; een verloren QUIT mag geen retry veroorzaken.
        } catch (Throwable $error) {
            throw new DeliveryException($error->getMessage(), $uncertain);
        } finally {
            $socket?->close();
        }
    }

    /** @param list<int> $expected */
    private function command(MailSocket $socket, #[\SensitiveParameter] string $command, array $expected): string
    {
        $socket->write($command . "\r\n");
        return $this->reply($socket, $expected);
    }

    /** @param list<int> $expected */
    private function reply(MailSocket $socket, array $expected, ?bool &$uncertain = null): string
    {
        $lines = [];
        $code = null;
        for ($count = 0; $count < 100; $count++) {
            $line = $socket->line();
            if (!preg_match('/^([0-9]{3})([ -])(.*)$/D', $line, $match)
                || ($code !== null && $code !== (int) $match[1])) {
                throw new RuntimeException('Ongeldig SMTP-antwoord.');
            }
            $code = (int) $match[1];
            $lines[] = $line;
            if ($match[2] === ' ') {
                if (!in_array($code, $expected, true)) {
                    if ($code >= 400 && $code <= 599) {
                        $uncertain = false;
                    }
                    throw new RuntimeException("SMTP-server weigerde de opdracht (status $code).");
                }
                $uncertain = false;
                return implode("\r\n", $lines);
            }
        }
        throw new RuntimeException('Te veel SMTP-antwoordregels.');
    }
}
