<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Database;
use PBreeze\Mail\DeliveryException;
use PBreeze\Mail\Imap;
use PBreeze\Mail\Mailer;
use PBreeze\Mail\MailSocket;
use PBreeze\Mail\Message;
use PBreeze\Mail\Mime;
use PBreeze\Mail\Smtp;

/** @param array<string, mixed> $options */
function mailFixture(array $options, callable $work): array
{
    global $temp, $root;
    $name = $temp . '/mail-fixture-' . bin2hex(random_bytes(5));
    $options['transcript'] = $name . '.transcript';
    file_put_contents($name . '.json', json_encode($options, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/mail-server.php', $name . '.json'], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $name . '.log', 'a'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Kan mailfixture niet starten.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        stream_set_timeout($pipes[1], 10);
    }
    try {
        $address = fgets($pipes[1]);
        if ($address === false) {
            throw new RuntimeException('Mailfixture startte niet: ' . file_get_contents($name . '.log'));
        }
        $port = explode(':', trim($address))[1];
        $work([
            'host' => '127.0.0.1', 'port' => $port, 'timeout' => '3',
            'encryption' => $options['encryption'] ?? 'none',
            'username' => '', 'password' => '',
            'ca_file' => $options['certificate'] ?? '',
        ]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $process = null;
        $log = file_get_contents($name . '.log');
        check($exit === 0, "Mailfixture stopte met $exit: $log");
        same('', $log);
        return json_decode((string) file_get_contents($options['transcript']), true, 32, JSON_THROW_ON_ERROR);
    } finally {
        if (is_resource($process)) {
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
}

function mailCertificate(): string
{
    global $temp;
    $pem = $temp . '/mail-certificate.pem';
    if (is_file($pem)) {
        return $pem;
    }
    $config = $temp . '/openssl.cnf';
    file_put_contents($config, <<<'CONFIG'
[req]
distinguished_name = dn
[dn]
[server]
subjectAltName = IP:127.0.0.1
basicConstraints = critical,CA:TRUE
keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
extendedKeyUsage = serverAuth
CONFIG);
    $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'server'];
    $key = openssl_pkey_new($options);
    if ($key === false) {
        throw new RuntimeException('Kan testsleutel niet maken.');
    }
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    if ($csr === false) {
        throw new RuntimeException('Kan testcertificaataanvraag niet maken.');
    }
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    if ($certificate === false || !openssl_x509_export($certificate, $cert) || !openssl_pkey_export($key, $private, null, $options)) {
        throw new RuntimeException('Kan testcertificaat niet maken.');
    }
    file_put_contents($pem, $cert . $private);
    \PBreeze\Filesystem::permissions($pem, 0600);
    return $pem;
}

function mailApp(array $mail = []): Application
{
    global $temp, $config;
    $settings = $config;
    $settings['mail'] = $mail + ['from' => 'sender@example.test'];
    $app = new Application($temp, $settings);
    $app->db->pdo();
    global $root;
    $migration = require $root . '/database/migrations/20261113150000_create_emails.php';
    $migration->up($app->db->pdo());
    return $app;
}

test('Mailmigratie maakt tabel en index en rolt volledig terug', static function (): void {
    global $temp, $root;
    $db = new Database($temp, ['driver' => 'sqlite', 'database' => ':memory:']);
    $migration = require $root . '/database/migrations/20261113150000_create_emails.php';
    $migration->up($db->pdo());
    same(1, (int) $db->execute("SELECT COUNT(*) FROM sqlite_master WHERE name = 'emails_queue'")->fetchColumn());
    $migration->down($db->pdo());
    same(0, (int) $db->execute("SELECT COUNT(*) FROM sqlite_master WHERE name = 'emails'")->fetchColumn());
});

test('Message valideert adressen, headerinjectie, lege body en ontvangers', static function (): void {
    foreach ([
        ['from' => "sender@example.test\r\nBcc: other@example.test", 'to' => ['a@example.test'], 'subject' => 'ok', 'text' => 'ok'],
        ['from' => 'sender@example.test', 'to' => [], 'subject' => 'ok', 'text' => 'ok'],
        ['from' => 'sender@example.test', 'to' => ['a@example.test'], 'subject' => "bad\r\nX: bad", 'text' => 'ok'],
        ['from' => 'sender@example.test', 'to' => ['bad address'], 'subject' => 'ok', 'text' => 'ok'],
        ['from' => 'sender@example.test', 'to' => ['a@example.test'], 'subject' => 'ok'],
    ] as $arguments) {
        raises(static fn () => new Message(...$arguments), InvalidArgumentException::class);
    }
});

test('MIME roundtrip bewaart Unicode, lange onderwerpregels en tekst/HTML', static function (): void {
    $subject = str_repeat('Hallo Zoë ', 20);
    $message = new Message('sender@example.test', ['a@example.test', 'b@example.test'], $subject, ".test\nHallo Zoë", '<p>Hallo Zoë</p>');
    $wire = $message->wire();
    foreach (explode("\r\n", $wire) as $line) {
        check(strlen($line) < 998, 'MIME-regel te lang.');
    }
    $parsed = Mime::parse($wire);
    same($subject, $parsed['subject']);
    same(".test\nHallo Zoë", $parsed['text']);
    same('<p>Hallo Zoë</p>', $parsed['html']);
    same($message->messageId, $parsed['message_id']);
});

test('MIME decodeert quoted-printable en negeert bijlagen', static function (): void {
    $raw = "From: Sender <sender@example.test>\r\nSubject: =?ISO-8859-1?Q?Caf=E9?=\r\n"
        . "Content-Type: multipart/mixed; boundary=x\r\n\r\n"
        . "--x\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9\r\n"
        . "--x\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=test.txt\r\n\r\nNiet importeren\r\n--x--\r\n";
    $parsed = Mime::parse($raw);
    same('Café', $parsed['subject']);
    same('Café', $parsed['text']);
    raises(static fn () => Mime::parse("Subject: bad\r\n\r\n\xff"), RuntimeException::class);
    raises(static fn () => Mime::parse("Content-Transfer-Encoding: base64\r\n\r\n***"), RuntimeException::class);
    raises(static fn () => Mime::parse("Content-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: text/plain\r\n\r\none\r\n--x\r\nContent-Type: text/plain\r\n\r\ntwo"), RuntimeException::class);
});

test('MailSocket weigert onveilige authenticatie en ongeldige configuratie', static function (): void {
    foreach ([
        ['host' => 'external.example', 'port' => '25', 'encryption' => 'none'],
        ['host' => '127.0.0.1', 'port' => '25', 'encryption' => 'none', 'username' => 'user'],
        ['host' => 'host;evil', 'port' => '25'],
        ['host' => 'localhost', 'port' => '65536'],
        ['host' => 'localhost', 'port' => '25', 'timeout' => '0'],
    ] as $settings) {
        raises(static fn () => new MailSocket($settings), InvalidArgumentException::class);
    }
});

test('SMTP verstuurt alle ontvangers en MIME via echte lokale socket', static function (): void {
    $message = new Message('sender@example.test', ['a@example.test', 'b@example.test'], 'Onderwerp', 'Hallo');
    $transcript = mailFixture(['protocol' => 'smtp'], static function (array $settings) use ($message): void {
        (new Smtp($settings))->send($message);
    });
    check(in_array('RCPT TO:<a@example.test>', $transcript['commands'], true));
    check(in_array('RCPT TO:<b@example.test>', $transcript['commands'], true));
    same('Hallo', Mime::parse($transcript['data'])['text']);
});

test('SMTP ondersteunt geverifieerde impliciete TLS en AUTH PLAIN', static function (): void {
    $transcript = mailFixture(['protocol' => 'smtp', 'encryption' => 'tls', 'certificate' => mailCertificate()], static function (array $settings): void {
        $settings['username'] = 'test-user';
        $settings['password'] = 'test-password';
        (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test'], 'TLS', 'Veilig'));
    });
    same("\0test-user\0test-password", $transcript['auth']);
});

test('SMTP ondersteunt STARTTLS met nieuwe EHLO en AUTH LOGIN', static function (): void {
    $transcript = mailFixture([
        'protocol' => 'smtp', 'encryption' => 'starttls', 'certificate' => mailCertificate(), 'auth' => 'LOGIN',
    ], static function (array $settings): void {
        $settings['username'] = 'test-user';
        $settings['password'] = 'test-password';
        (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test'], 'TLS', 'Veilig'));
    });
    same(2, count(array_filter($transcript['commands'], static fn (string $command): bool => $command === 'EHLO localhost')));
    same('test-user:test-password', $transcript['auth']);
});

test('SMTP weigert onbetrouwbare TLS-certificaten', static function (): void {
    $transcript = mailFixture(['protocol' => 'smtp', 'encryption' => 'tls', 'certificate' => mailCertificate()], static function (array $settings): void {
        $settings['ca_file'] = '';
        raises(static fn () => (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test'], 'TLS', 'Veilig')), DeliveryException::class);
    });
    same(true, $transcript['tls_rejected']);
    same([], $transcript['commands']);
});

test('SMTP controleert ook de hostnaam bij een vertrouwd certificaat', static function (): void {
    $transcript = mailFixture([
        'protocol' => 'smtp', 'encryption' => 'tls', 'certificate' => mailCertificate(), 'expect_tls_rejection' => true,
    ], static function (array $settings): void {
        $settings['host'] = 'localhost';
        try {
            (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test'], 'TLS', 'Veilig'));
            throw new RuntimeException('Hostnaammismatch is niet geweigerd.');
        } catch (DeliveryException $error) {
            check(str_contains($error->getMessage(), 'TLS-certificaat'));
            same(false, $error->uncertain);
        }
    });
    // De server kan de handshake afronden voordat de client de hostnaam afwijst.
    same([], $transcript['commands']);
    same('', $transcript['data']);
});

test('SMTP valt zonder STARTTLS-aanbod niet terug en verstuurt geen credentials', static function (): void {
    $transcript = mailFixture(['protocol' => 'smtp'], static function (array $settings): void {
        $settings['encryption'] = 'starttls';
        $settings['username'] = 'test-user';
        $settings['password'] = 'test-password';
        raises(static fn () => (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test'], 'TLS', 'Veilig')), DeliveryException::class);
    });
    same(['EHLO localhost'], $transcript['commands']);
});

test('SMTP voert geen DATA uit als een ontvanger geweigerd is', static function (): void {
    $transcript = mailFixture(['protocol' => 'smtp', 'reject_recipient' => true], static function (array $settings): void {
        try {
            (new Smtp($settings))->send(new Message('sender@example.test', ['a@example.test', 'b@example.test'], 'x', 'x'));
            throw new RuntimeException('Afwijzing ontbreekt.');
        } catch (DeliveryException $error) {
            same(false, $error->uncertain);
        }
    });
    check(!in_array('DATA', $transcript['commands'], true));
});

test('Databasewachtrij en CLI versturen opgeslagen mails en blokkeren herhaling', static function (): void {
    global $temp;
    $transcript = mailFixture(['protocol' => 'smtp'], static function (array $settings) use ($temp): void {
        $app = mailApp(['smtp' => $settings, 'log' => false]);
        $file = $temp . '/outgoing.json';
        file_put_contents($file, json_encode(['to' => ['a@example.test'], 'subject' => 'Wachtrij', 'text' => 'Bericht'], JSON_THROW_ON_ERROR));
        check(str_contains(console($app, ['mail:queue', $file]), 'In wachtrij: 1'));
        same('queued', $app->mail->store->find(1)['status']);
        same(1, count(json_decode(console($app, ['mail:list']), true, 32, JSON_THROW_ON_ERROR)));
        check(str_contains(console($app, ['mail:show', '1']), 'Wachtrij'));
        console($app, ['mail:send', '1']);
        $row = $app->mail->store->find(1);
        same('sent', $row['status']);
        same(1, (int) $row['attempts']);
        check($row['sent_at'] !== null);
        raises(static fn () => console($app, ['mail:send', '1']), RuntimeException::class);
        raises(static fn () => console($app, ['mail:retry', '1']), RuntimeException::class);
        raises(static fn () => $app->mail->store->retry(1, true), RuntimeException::class);
    });
    same('Bericht', Mime::parse($transcript['data'])['text']);
});

test('CLI mail:work verwerkt de wachtrij en rapporteert een lege wachtrij', static function (): void {
    mailFixture(['protocol' => 'smtp'], static function (array $settings): void {
        $app = mailApp(['smtp' => $settings]);
        $id = $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'Worker']));
        console($app, ['mail:work', '1']);
        same('sent', $app->mail->store->find($id)['status']);
        check(str_contains(console($app, ['mail:work']), 'Verwerkt: 0'));
    });
});

test('SMTP-afwijzing wordt failed zonder servertekst of credentials in het log', static function (): void {
    mailFixture(['protocol' => 'smtp', 'reject_data' => true], static function (array $settings): void {
        $app = mailApp(['smtp' => $settings]);
        $id = $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'x']));
        raises(static fn () => $app->mail->sendQueued($id), DeliveryException::class);
        $row = $app->mail->store->find($id);
        same('failed', $row['status']);
        check(str_contains($row['last_error'], '554'));
        check(!str_contains($row['last_error'], 'SECRET_FROM_SERVER'));
        same([], $app->mail->store->pending(50));
        console($app, ['mail:retry', (string) $id]);
        same('queued', $app->mail->store->find($id)['status']);
    });
});

test('SMTP-loginfout bewaart geen credentials en worker stopt vóór het volgende bericht', static function (): void {
    mailFixture([
        'protocol' => 'smtp', 'encryption' => 'tls', 'certificate' => mailCertificate(), 'auth_fail' => true,
    ], static function (array $settings): void {
        $settings['username'] = 'test-user';
        $settings['password'] = 'test-password';
        $app = mailApp(['smtp' => $settings]);
        $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'Eerste']));
        $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'Tweede']));
        raises(static fn () => console($app, ['mail:work']), DeliveryException::class);
        $failed = $app->mail->store->find(1);
        same('failed', $failed['status']);
        same('queued', $app->mail->store->find(2)['status']);
        check(!str_contains(json_encode($failed, JSON_THROW_ON_ERROR), 'test-password'));
        check(!str_contains($failed['last_error'], 'SECRET_FROM_SERVER'));
    });
});

test('Een databasefout na SMTP-acceptatie laat sending staan en veroorzaakt geen retry', static function (): void {
    mailFixture(['protocol' => 'smtp'], static function (array $settings): void {
        $app = mailApp(['smtp' => $settings]);
        $id = $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'x']));
        $app->db->execute("CREATE TRIGGER fail_completion BEFORE UPDATE ON emails
            WHEN NEW.status = 'sent' BEGIN SELECT RAISE(FAIL, 'test completion failure'); END");
        raises(static fn () => $app->mail->sendQueued($id), PDOException::class);
        same('sending', $app->mail->store->find($id)['status']);
        same([], $app->mail->store->pending(50));
        raises(static fn () => $app->mail->sendQueued($id), RuntimeException::class);
    });
});

test('Verloren SMTP DATA-bevestiging wordt uncertain en wordt niet automatisch herhaald', static function (): void {
    mailFixture(['protocol' => 'smtp', 'drop_after_data' => true], static function (array $settings): void {
        $app = mailApp(['smtp' => $settings]);
        $id = $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'x']));
        raises(static fn () => $app->mail->sendQueued($id), DeliveryException::class);
        same('uncertain', $app->mail->store->find($id)['status']);
        same([], $app->mail->store->pending(50));
        raises(static fn () => $app->mail->store->retry($id), RuntimeException::class);
        $app->mail->store->retry($id, true);
        same('queued', $app->mail->store->find($id)['status']);
    });
});

test('Een geclaimd bericht kan niet door een tweede worker worden verzonden', static function (): void {
    $app = mailApp();
    $id = $app->mail->queue($app->mail->compose(['to' => ['a@example.test'], 'text' => 'x']));
    $message = $app->mail->store->claim($id);
    same($app->mail->store->find($id)['message_id'], $message->messageId);
    raises(static fn () => $app->mail->store->claim($id), RuntimeException::class);
    raises(static fn () => $app->mail->store->retry($id), RuntimeException::class);
    $app->mail->store->retry($id, true);
    same('queued', $app->mail->store->find($id)['status']);
});

test('MAIL_LOG=false verstuurt zonder e-mailtabel en logging true faalt vóór verzending zonder tabel', static function (): void {
    global $temp;
    mailFixture(['protocol' => 'smtp'], static function (array $settings) use ($temp): void {
        $db = new Database($temp, ['driver' => 'sqlite', 'database' => ':memory:']);
        $mail = new Mailer($db, ['smtp' => $settings, 'log' => false]);
        same(null, $mail->send(new Message('sender@example.test', ['a@example.test'], 'Geen log', 'x')));
        $logged = new Mailer($db, ['smtp' => $settings, 'log' => true]);
        raises(static fn () => $logged->send(new Message('sender@example.test', ['a@example.test'], 'Log', 'x')), PDOException::class);
    });
});

test('MAIL_LOG=true bewaart een direct verzonden bericht met status sent', static function (): void {
    mailFixture(['protocol' => 'smtp'], static function (array $settings): void {
        $app = mailApp(['smtp' => $settings, 'log' => true]);
        $id = $app->mail->send($app->mail->compose(['to' => ['a@example.test'], 'html' => '<p>Hallo</p>']));
        same(1, $id);
        same('sent', $app->mail->store->find($id)['status']);
    });
});

test('IMAP importeert exacte raw mail, Unicode en UID-identiteit zonder berichten te wijzigen', static function (): void {
    $raw = (new Message('sender@example.test', ['a@example.test'], 'Hallo Zoë', 'Tekst', '<p>HTML</p>'))->wire();
    $transcript = mailFixture(['protocol' => 'imap', 'messages' => ['1' => base64_encode($raw)]], static function (array $settings) use ($raw): void {
        $app = mailApp(['imap' => $settings]);
        $rows = $app->mail->fetch(10);
        same(1, count($rows));
        $row = $app->mail->store->find($rows[0]['id']);
        same('incoming', $row['direction']);
        same('received', $row['status']);
        same('Hallo Zoë', $row['subject']);
        same($raw, base64_decode($row['raw_message'], true));
        same('1', $row['imap_uid']);
        same('777', $row['imap_uidvalidity']);
        same($row['id'], $app->mail->store->receive($rows[0]));
        same(1, (int) $app->db->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
        raises(static fn () => $app->mail->sendQueued((int) $row['id']), RuntimeException::class);
        raises(static fn () => $app->mail->store->retry((int) $row['id'], true), RuntimeException::class);
    });
    check(in_array('P1 EXAMINE "INBOX"', $transcript['commands'], true));
    check(in_array('P3 UID FETCH 1 (UID BODY.PEEK[])', $transcript['commands'], true));
});

test('IMAP slaat bekende UIDs over zodat een limiet de import niet blokkeert', static function (): void {
    $raw = (new Message('sender@example.test', ['a@example.test'], 'UID', 'x'))->wire();
    $transcript = mailFixture(['protocol' => 'imap', 'messages' => ['1' => base64_encode($raw), '2' => base64_encode($raw)]], static function (array $settings): void {
        $calls = 0;
        $rows = (new Imap($settings))->fetch(1, static function (string $key) use (&$calls): bool {
            same(64, strlen($key));
            return ++$calls === 1;
        });
        same(2, $calls);
        same('2', $rows[0]['uid']);
    });
    check(!in_array('P3 UID FETCH 1 (UID BODY.PEEK[])', $transcript['commands'], true));
    check(in_array('P3 UID FETCH 2 (UID BODY.PEEK[])', $transcript['commands'], true));
});

test('IMAP ondersteunt TLS-login en importeert zonder logging geen tabel', static function (): void {
    global $temp;
    $raw = (new Message('sender@example.test', ['a@example.test'], 'TLS IMAP', 'x'))->wire();
    $transcript = mailFixture([
        'protocol' => 'imap', 'encryption' => 'tls', 'certificate' => mailCertificate(), 'messages' => ['1' => base64_encode($raw)],
    ], static function (array $settings) use ($temp): void {
        $settings['username'] = 'test"user';
        $settings['password'] = 'test\\password';
        $mail = new Mailer(new Database($temp, ['driver' => 'sqlite', 'database' => ':memory:']), ['log' => false, 'imap' => $settings]);
        $rows = $mail->fetch(10);
        same(1, count($rows));
        same(null, $rows[0]['id']);
    });
    same('P1 LOGIN "test\\"user" "test\\\\password"', $transcript['commands'][0]);
});

test('IMAP ondersteunt STARTTLS en mailboxnamen kunnen geen commando injecteren', static function (): void {
    $transcript = mailFixture([
        'protocol' => 'imap', 'encryption' => 'starttls', 'certificate' => mailCertificate(), 'messages' => [],
    ], static function (array $settings): void {
        $settings['username'] = 'user';
        $settings['password'] = 'password';
        same([], (new Imap($settings))->fetch());
    });
    same('P1 STARTTLS', $transcript['commands'][0]);
    raises(static fn () => (new Imap(['mailbox' => "INBOX\r\nDELETE X"]))->fetch(), InvalidArgumentException::class);
});

test('IMAP-loginfouten lekken geen credentials via exceptiontraces', static function (): void {
    mailFixture([
        'protocol' => 'imap', 'encryption' => 'tls', 'certificate' => mailCertificate(), 'auth_fail' => true,
    ], static function (array $settings): void {
        $settings['username'] = 'test-user';
        $settings['password'] = 'PRIVATE_TEST_PASSWORD';
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new Imap($settings))->fetch();
                throw new LogicException('Loginfout ontbreekt.');
            } catch (RuntimeException $error) {
                same('IMAP-server weigerde de opdracht.', $error->getMessage());
                check(!str_contains((string) $error, 'PRIVATE_TEST_PASSWORD'));
                check(!str_contains((string) $error, 'SECRET_FROM_SERVER'));
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    });
});

test('IMAP weigert onbetrouwbare TLS en lege mailboxen geven een lege lijst', static function (): void {
    $transcript = mailFixture(['protocol' => 'imap', 'encryption' => 'tls', 'certificate' => mailCertificate()], static function (array $settings): void {
        $settings['ca_file'] = '';
        raises(static fn () => (new Imap($settings))->fetch(), RuntimeException::class);
    });
    same(true, $transcript['tls_rejected']);
    mailFixture(['protocol' => 'imap', 'messages' => []], static function (array $settings): void {
        same([], (new Imap($settings))->fetch());
    });
});

test('IMAP weigert te grote literals voordat de berichtbody wordt gelezen', static function (): void {
    mailFixture(['protocol' => 'imap', 'oversize' => true, 'messages' => ['1' => base64_encode('x')]], static function (array $settings): void {
        raises(static fn () => (new Imap($settings))->fetch(), RuntimeException::class);
    });
});

test('CLI mail:fetch bewaart binnenkomende mail en mailcommands valideren invoer', static function (): void {
    $raw = (new Message('sender@example.test', ['a@example.test'], 'CLI IMAP', 'x'))->wire();
    mailFixture(['protocol' => 'imap', 'messages' => ['1' => base64_encode($raw)]], static function (array $settings): void {
        $app = mailApp(['imap' => $settings]);
        $rows = json_decode(console($app, ['mail:fetch', '1']), true, 32, JSON_THROW_ON_ERROR);
        same(true, $rows[0]['stored']);
        same(1, $rows[0]['id']);
        foreach ([['mail:send'], ['mail:send', '0'], ['mail:list', '1001'], ['mail:fetch', '-1'], ['mail:queue', '/not/a/file']] as $arguments) {
            raises(static fn () => console($app, $arguments), InvalidArgumentException::class);
        }
        raises(static fn () => $app->mail->compose(['to' => ['a@example.test'], 'text' => 'x', 'bcc' => 'b@example.test']), InvalidArgumentException::class);
        raises(static fn () => $app->mail->compose(['to' => [123], 'text' => 'x']), InvalidArgumentException::class);
    });
});
