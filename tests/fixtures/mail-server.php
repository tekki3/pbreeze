<?php
declare(strict_types=1);

// Uitsluitend een lokale protocolfixture; wordt nooit door de applicatie geladen.
$settings = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$context = stream_context_create(['ssl' => [
    'local_cert' => $settings['certificate'] ?? '',
    'verify_peer' => false,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException($error);
}
echo stream_socket_get_name($server, false) . "\n";
flush();
$client = stream_socket_accept($server, 15);
fclose($server);
if ($client === false) {
    throw new RuntimeException('Geen testclient verbonden.');
}
stream_set_timeout($client, 10);
$transcript = ['commands' => [], 'data' => ''];

final class FixtureDisconnected extends RuntimeException
{
}

function fixtureLine(): ?string
{
    global $client, $transcript;
    $line = fgets($client, 1048576);
    if ($line === false) {
        return null;
    }
    $line = rtrim($line, "\r\n");
    $transcript['commands'][] = $line;
    return $line;
}

function fixtureWrite(string $value): void
{
    global $client;
    set_error_handler(static function (): never {
        throw new FixtureDisconnected('Client heeft de testverbinding gesloten.');
    });
    try {
        for ($offset = 0; $offset < strlen($value);) {
            $count = fwrite($client, substr($value, $offset));
            if ($count === false || $count === 0) {
                throw new FixtureDisconnected('Client heeft de testverbinding gesloten.');
            }
            $offset += $count;
        }
    } finally {
        restore_error_handler();
    }
}

function fixtureTls(): bool
{
    global $client;
    set_error_handler(static fn (): bool => true);
    try {
        return stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
    } finally {
        restore_error_handler();
    }
}

try {
    if (($settings['encryption'] ?? 'none') === 'tls' && !fixtureTls()) {
        $transcript['tls_rejected'] = true;
    } elseif ($settings['protocol'] === 'smtp') {
        fixtureWrite("220 fixture ESMTP\r\n");
        $recipients = 0;
        $tls = ($settings['encryption'] ?? 'none') === 'tls';
        while (($line = fixtureLine()) !== null) {
            if (str_starts_with($line, 'EHLO ')) {
                $extra = !$tls && ($settings['encryption'] ?? '') === 'starttls' ? "250-STARTTLS\r\n" : '';
                $auth = $settings['auth'] ?? 'PLAIN';
                fixtureWrite("250-fixture\r\n" . $extra . "250 AUTH $auth\r\n");
            } elseif ($line === 'STARTTLS') {
                fixtureWrite("220 Begin TLS\r\n");
                if (!fixtureTls()) {
                    $transcript['tls_rejected'] = true;
                    break;
                }
                $tls = true;
            } elseif ($line === 'AUTH PLAIN') {
                fixtureWrite("334 \r\n");
                $credentials = fixtureLine();
                $transcript['auth'] = base64_decode($credentials, true);
                fixtureWrite(($settings['auth_fail'] ?? false) ? "535 Login rejected SECRET_FROM_SERVER\r\n" : "235 Authenticated\r\n");
            } elseif ($line === 'AUTH LOGIN') {
                fixtureWrite("334 VXNlcm5hbWU6\r\n");
                $user = fixtureLine();
                fixtureWrite("334 UGFzc3dvcmQ6\r\n");
                $password = fixtureLine();
                $transcript['auth'] = base64_decode($user, true) . ':' . base64_decode($password, true);
                fixtureWrite("235 Authenticated\r\n");
            } elseif (str_starts_with($line, 'MAIL FROM:')) {
                fixtureWrite("250 Sender OK\r\n");
            } elseif (str_starts_with($line, 'RCPT TO:')) {
                $recipients++;
                fixtureWrite(($settings['reject_recipient'] ?? false) && $recipients === 2 ? "550 Rejected\r\n" : "250 Recipient OK\r\n");
            } elseif ($line === 'DATA') {
                fixtureWrite("354 Send data\r\n");
                while (($data = fgets($client, 1048576)) !== false && $data !== ".\r\n") {
                    $transcript['data'] .= $data;
                }
                if ($settings['drop_after_data'] ?? false) {
                    break;
                }
                fixtureWrite(($settings['reject_data'] ?? false) ? "554 Rejected SECRET_FROM_SERVER\r\n" : "250 Queued\r\n");
                break;
            } else {
                throw new RuntimeException('Onverwacht SMTP-commando.');
            }
        }
    } else {
        $preauth = ($settings['encryption'] ?? 'none') === 'none';
        fixtureWrite($preauth ? "* PREAUTH Fixture ready\r\n" : "* OK Fixture ready\r\n");
        while (($line = fixtureLine()) !== null) {
            [$tag, $command] = explode(' ', $line, 2);
            if ($command === 'STARTTLS') {
                fixtureWrite("$tag OK Begin TLS\r\n");
                if (!fixtureTls()) {
                    break;
                }
            } elseif (str_starts_with($command, 'LOGIN ')) {
                fixtureWrite(($settings['auth_fail'] ?? false) ? "$tag NO SECRET_FROM_SERVER\r\n" : "$tag OK Logged in\r\n");
            } elseif (str_starts_with($command, 'EXAMINE ')) {
                $validity = $settings['uidvalidity'] ?? '777';
                fixtureWrite("* 2 EXISTS\r\n* OK [UIDVALIDITY $validity] UIDs valid\r\n$tag OK [READ-ONLY] Selected\r\n");
            } elseif ($command === 'UID SEARCH ALL') {
                $uids = implode(' ', array_keys($settings['messages'] ?? []));
                fixtureWrite("* SEARCH" . ($uids !== '' ? " $uids" : '') . "\r\n$tag OK Searched\r\n");
            } elseif (preg_match('/^UID FETCH ([0-9]+) \(UID BODY\.PEEK\[\]\)$/D', $command, $match)) {
                $uid = $match[1];
                $raw = base64_decode($settings['messages'][$uid], true);
                $length = ($settings['oversize'] ?? false) ? 999999999 : strlen($raw);
                fixtureWrite("* 1 FETCH (UID $uid BODY[] {" . $length . "}\r\n");
                if ($settings['oversize'] ?? false) {
                    break;
                }
                fixtureWrite($raw . ")\r\n$tag OK Fetched\r\n");
            } else {
                throw new RuntimeException('Onverwacht IMAP-commando.');
            }
        }
    }
} catch (FixtureDisconnected $error) {
    if (!($settings['expect_tls_rejection'] ?? false) || $transcript['commands'] !== []) {
        throw $error;
    }
    $transcript['tls_rejected'] = true;
} finally {
    fclose($client);
    file_put_contents($settings['transcript'], json_encode($transcript, JSON_THROW_ON_ERROR));
}
