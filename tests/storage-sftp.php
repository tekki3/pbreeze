<?php
declare(strict_types=1);

/** @param list<string> $arguments */
function storageSftpCommand(array $arguments): string
{
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process));
    same('', $errors);
    return $output;
}

$sshd = getenv('PBREEZE_TEST_SSHD_BINARY');
if ($sshd !== false && $sshd !== '') {
    test('Native SFTP verifies the host, uses key authentication and supports binary overwrite and missing files', static function () use ($sshd): void {
        global $temp;
        check(extension_loaded('ssh2'), 'Native SFTP integration requires ext-ssh2.');
        check(is_file($sshd), 'Native SFTP integration needs an absolute sshd binary.');
        $directory = $temp . '/sftp-native';
        mkdir($directory, 0700);
        mkdir($directory . '/remote', 0700);
        storageSftpCommand(['ssh-keygen', '-q', '-t', 'ecdsa', '-m', 'PEM', '-N', '', '-f', $directory . '/host']);
        storageSftpCommand(['ssh-keygen', '-q', '-t', 'rsa', '-b', '2048', '-m', 'PEM', '-N', '', '-f', $directory . '/client']);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        check(is_resource($socket));
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $configuration = "ListenAddress 127.0.0.1\nPort $port\n"
            . 'HostKey "' . $directory . '/host"' . "\n"
            . 'AuthorizedKeysFile "' . str_replace('%', '%%', $directory) . '/client.pub"' . "\n"
            . "PasswordAuthentication no\nKbdInteractiveAuthentication no\nUsePAM no\nStrictModes no\n"
            . "Subsystem sftp internal-sftp\nLogLevel ERROR\n"
            . 'PidFile "' . $directory . '/sshd.pid"' . "\n";
        foreach (['SshdSessionPath' => 'sshd-session', 'SshdAuthPath' => 'sshd-auth'] as $option => $file) {
            $path = dirname($sshd) . '/../lib/openssh/' . $file;
            if (is_file($path)) {
                $configuration .= $option . ' "' . $path . '"' . "\n";
            }
        }
        file_put_contents($directory . '/sshd.conf', $configuration);
        $server = proc_open([$sshd, '-D', '-e', '-f', $directory . '/sshd.conf'], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'],
            2 => ['file', $directory . '/server.log', 'a'],
        ], $pipes);
        check(is_resource($server));
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                usleep(50000);
                check(proc_get_status($server)['running'], 'sshd stopped: ' . file_get_contents($directory . '/server.log'));
                set_error_handler(static fn (): bool => true);
                try {
                    $probe = stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                } finally {
                    restore_error_handler();
                }
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            check($ready, 'SFTP daemon did not become ready.');
            $parts = explode(' ', trim(file_get_contents($directory . '/host.pub')));
            $fingerprint = sha1(base64_decode($parts[1], true));
            $username = get_current_user();
            $config = storageSettings('sftp', ['sftp' => [
                'host' => '127.0.0.1', 'port' => $port, 'username' => $username,
                'password' => '', 'public_key' => $directory . '/client.pub', 'private_key' => $directory . '/client',
                'fingerprint' => $fingerprint, 'root' => $directory . '/remote',
            ]]);
            $storage = new \PBreeze\Storage($temp, $config);
            same(false, $storage->exists('documents/report.bin'));
            same(false, $storage->delete('documents/report.bin'));
            raises(static fn () => $storage->get('documents/report.bin'), RuntimeException::class);
            $storage->put('documents/report.bin', "\0\xff\r\n");
            same("\0\xff\r\n", $storage->get('documents/report.bin'));
            same(true, $storage->exists('documents/report.bin'));
            $storage->put('documents/report.bin', '');
            same('', $storage->get('documents/report.bin'));
            $storage->put('documents/Café.txt', 'unicode');
            same('unicode', $storage->get('documents/Café.txt'));
            same([], glob($directory . '/remote/documents/write-*'));
            same([], glob($directory . '/remote/documents/backup-*'));
            same(0600, fileperms($directory . '/remote/documents/Café.txt') & 0777);
            same(true, $storage->delete('documents/report.bin'));
            same(false, $storage->delete('documents/report.bin'));
            file_put_contents($directory . '/remote/oversized.bin', str_repeat('x', 1025));
            raises(static fn () => $storage->get('oversized.bin'), RuntimeException::class);
            $bad = new \PBreeze\Storage($temp, array_replace_recursive($config, ['sftp' => ['fingerprint' => str_repeat('0', 40)]]));
            try {
                $bad->exists('documents/Café.txt');
                throw new LogicException('Expected host-key rejection.');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), 'host-key verification'));
            }
            $unsupported = new \PBreeze\Storage($temp, array_replace_recursive($config, ['sftp' => [
                'public_key' => $directory . '/host.pub', 'private_key' => $directory . '/host',
            ]]));
            raises(static fn () => $unsupported->exists('documents/Café.txt'), InvalidArgumentException::class);
            $badPassword = new \PBreeze\Storage($temp, array_replace_recursive($config, ['sftp' => [
                'password' => 'invalid-test-password', 'public_key' => '', 'private_key' => '',
            ]]));
            raises(static fn () => $badPassword->exists('documents/Café.txt'), RuntimeException::class);
            symlink($directory, $directory . '/remote/escape');
            try {
                raises(static fn () => $storage->exists('escape/client'), RuntimeException::class);
                raises(static fn () => $storage->get('escape/client'), RuntimeException::class);
                raises(static fn () => $storage->put('escape/client', 'overwrite'), RuntimeException::class);
                raises(static fn () => $storage->delete('escape/client'), RuntimeException::class);
            } finally {
                unlink($directory . '/remote/escape');
            }
            mkdir($directory . '/remote/denied', 0000);
            try {
                raises(static fn () => $storage->exists('denied/missing.txt'), RuntimeException::class);
            } finally {
                chmod($directory . '/remote/denied', 0700);
            }
        } catch (Throwable $error) {
            throw new RuntimeException($error->getMessage() . ' Server log: ' . file_get_contents($directory . '/server.log'), 0, $error);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    });
}
