<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;
use RuntimeException;

final class MailSocket
{
    /** @var resource */
    private $stream;
    public readonly string $encryption;
    public readonly string $username;
    public readonly string $password;

    /** @param array<string, string> $config */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $host = $config['host'] ?? '';
        $port = $config['port'] ?? '';
        $timeout = $config['timeout'] ?? '15';
        $this->encryption = $config['encryption'] ?? 'tls';
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $host)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || !ctype_digit($timeout) || (int) $timeout < 1 || (int) $timeout > 300
            || !in_array($this->encryption, ['tls', 'starttls', 'none'], true)) {
            throw new InvalidArgumentException('Ongeldige mailhost, poort, timeout of versleuteling.');
        }
        if ($this->encryption === 'none' && (!in_array($host, ['127.0.0.1', 'localhost'], true)
            || $this->username !== '' || $this->password !== '')) {
            throw new InvalidArgumentException('Onversleutelde mail is uitsluitend toegestaan zonder login op localhost.');
        }
        if (strpbrk($this->username . $this->password, "\r\n\0") !== false) {
            throw new InvalidArgumentException('Ongeldige mail-inloggegevens.');
        }
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if (($config['ca_file'] ?? '') !== '') {
            if (!is_readable($config['ca_file'])) {
                throw new InvalidArgumentException('Het CA-bestand is niet leesbaar.');
            }
            $ssl['cafile'] = $config['ca_file'];
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $stream = self::network(static fn () => stream_socket_client(
            "tcp://$host:$port", $errno, $error, (int) $timeout, STREAM_CLIENT_CONNECT, $context,
        ));
        if ($stream === false) {
            throw new RuntimeException('Verbinding met de mailserver mislukt.');
        }
        $this->stream = $stream;
        stream_set_timeout($this->stream, (int) $timeout);
        if ($this->encryption === 'tls') {
            try {
                $this->startTls();
            } catch (\Throwable $error) {
                $this->close();
                throw $error;
            }
        }
    }

    public function startTls(): void
    {
        $enabled = self::network(fn () => stream_socket_enable_crypto(
            $this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ));
        if ($enabled !== true) {
            throw new RuntimeException('TLS-onderhandeling met de mailserver mislukt.');
        }
    }

    public function write(#[\SensitiveParameter] string $data): void
    {
        $length = strlen($data);
        for ($offset = 0; $offset < $length;) {
            $written = self::network(fn () => fwrite($this->stream, substr($data, $offset, 65536)));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Schrijven naar de mailserver mislukt.');
            }
            $offset += $written;
        }
    }

    public function line(int $maxBytes = 8192): string
    {
        $line = self::network(fn () => fgets($this->stream, $maxBytes + 2));
        if ($line === false || !str_ends_with($line, "\r\n")) {
            throw new RuntimeException('Mailserververbinding gesloten, timeout of te lange protocolregel.');
        }
        return substr($line, 0, -2);
    }

    public function bytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = self::network(fn () => fread($this->stream, min(65536, $length - strlen($data))));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Onvolledig bericht van de mailserver.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    public function close(): void
    {
        if (isset($this->stream) && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function network(callable $operation): mixed
    {
        // Serverantwoorden en credentials mogen niet in PHP-warnings of logs belanden.
        set_error_handler(static function (): never {
            throw new RuntimeException('Mailnetwerkfout: controleer verbinding, timeout en TLS-certificaat.');
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
