<?php
declare(strict_types=1);

// Linux integration launcher; IIS uses web-server-iis.ps1 on Windows.
if (PHP_OS_FAMILY !== 'Linux' || !in_array($argv[1] ?? '', ['apache', 'nginx', 'frankenphp'], true)) {
    throw new RuntimeException('Usage on Linux: php tests/web-server-integration.php apache|nginx|frankenphp');
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$server = $argv[1];
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/pbreeze-server-' . $server . '-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
$fixture = $temp . '/application';
$processes = [];
$start = static function (array $command, string $name, ?array $environment = null) use ($temp, &$processes) {
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $temp . '/' . $name . '.log', 'a'],
        2 => ['file', $temp . '/' . $name . '.log', 'a'],
    ], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ' . $name);
    }
    $processes[] = $process;
    return $process;
};
$port = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate a test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($address, strrpos($address, ':') + 1);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            $remove($path . '/' . $name);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};
try {
    $prepare = $start([PHP_BINARY, __DIR__ . '/web-server-prepare.php', $fixture], 'prepare');
    if (proc_close($prepare) !== 0) {
        throw new RuntimeException('Fixture preparation failed.');
    }
    $listen = $port();
    $public = $fixture . '/public';
    mkdir($temp . '/sessions', 0700);
    if ($server === 'frankenphp') {
        putenv('PBREEZE_SERVER_NAME=http://127.0.0.1:' . $listen);
        putenv('PBREEZE_PUBLIC_ROOT=' . $public);
        putenv('XDG_DATA_HOME=' . $temp . '/data');
        putenv('XDG_CONFIG_HOME=' . $temp . '/config');
        mkdir($temp . '/ini', 0700);
        file_put_contents($temp . '/ini/test.ini', "session.save_path=\"$temp/sessions\"\nupload_max_filesize=3M\npost_max_size=8M\n");
        $environment = getenv();
        $environment['PHP_INI_SCAN_DIR'] = $temp . '/ini';
        $process = $start([getenv('FRANKENPHP_BINARY') ?: 'frankenphp', 'run', '--config', $root . '/deploy/Caddyfile'], 'server', $environment);
    } else {
        do {
            $fpmPort = $port();
        } while ($fpmPort === $listen);
        $user = posix_getpwuid(posix_geteuid())['name'];
        $group = posix_getgrgid(posix_getegid())['name'];
        file_put_contents($temp . '/fpm.conf', "[global]\nerror_log = $temp/fpm-error.log\ndaemonize = no\n"
            . "[pbreeze]\nuser = $user\ngroup = $group\nlisten = 127.0.0.1:$fpmPort\n"
            . "pm = static\npm.max_children = 2\nclear_env = no\ncatch_workers_output = yes\n"
            . "php_admin_value[session.save_path] = $temp/sessions\n"
            . "php_admin_value[upload_max_filesize] = 3M\nphp_admin_value[post_max_size] = 8M\n"
            . "php_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\n");
        $start([getenv('PHP_FPM_BINARY') ?: 'php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            '--nodaemonize', '--fpm-config', $temp . '/fpm.conf'], 'fpm');
        if ($server === 'apache') {
            $modules = getenv('APACHE_MODULE_DIR') ?: '/usr/lib/apache2/modules';
            $preamble = "ServerRoot \"$temp\"\nListen 127.0.0.1:$listen\nServerName localhost\n"
                . "PidFile \"$temp/apache.pid\"\nErrorLog \"$temp/apache-error.log\"\n";
            foreach (['mpm_event', 'authz_core', 'authz_host', 'dir', 'mime', 'rewrite', 'proxy', 'proxy_fcgi', 'env'] as $module) {
                $preamble .= "LoadModule {$module}_module $modules/mod_$module.so\n";
            }
            $preamble .= "TypesConfig /etc/mime.types\n";
            $config = str_replace(
                ['*:80', '/srv/pbreeze/public', 'proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/'],
                ['127.0.0.1:' . $listen, $public, 'proxy:fcgi://127.0.0.1:' . $fpmPort],
                file_get_contents($root . '/deploy/apache.conf'),
            );
            file_put_contents($temp . '/apache.conf', $preamble . $config);
            $process = $start([getenv('APACHE_BINARY') ?: 'apache2', '-f', $temp . '/apache.conf', '-DFOREGROUND'], 'server');
        } else {
            $config = str_replace(
                ['listen 80;', '/srv/pbreeze/public', 'unix:/run/php/php8.3-fpm.sock'],
                ['listen 127.0.0.1:' . $listen . ';', $public, '127.0.0.1:' . $fpmPort],
                file_get_contents($root . '/deploy/nginx.conf'),
            );
            $config = str_replace('include fastcgi_params;', 'include ' . (getenv('NGINX_FASTCGI_PARAMS') ?: '/etc/nginx/fastcgi_params') . ';', $config);
            $mime = getenv('NGINX_MIME_TYPES') ?: '/etc/nginx/mime.types';
            file_put_contents($temp . '/nginx.conf', "daemon off;\nerror_log $temp/nginx-error.log;\npid $temp/nginx.pid;\n"
                . "events {}\nhttp {\ninclude $mime;\naccess_log off;\nclient_body_temp_path $temp;\nfastcgi_temp_path $temp;\n"
                . "proxy_temp_path $temp;\nuwsgi_temp_path $temp;\nscgi_temp_path $temp;\n$config\n}\n");
            $process = $start([getenv('NGINX_BINARY') ?: 'nginx', '-p', $temp . '/', '-c', $temp . '/nginx.conf'], 'server');
        }
    }
    $base = 'http://127.0.0.1:' . $listen;
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        if (!proc_get_status($process)['running']) {
            throw new RuntimeException('The web server exited before becoming ready.');
        }
        try {
            $response = file_get_contents($base . '/health', false, stream_context_create(['http' => ['timeout' => 1]]));
            if ($response !== false) {
                $ready = true;
                break;
            }
        } catch (ErrorException) {
            // Startup races are expected; failures remain visible in server logs.
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('The web server did not become ready.');
    }
    $smoke = $start([PHP_BINARY, __DIR__ . '/web-server-smoke.php', $base, $fixture], 'smoke');
    if (proc_close($smoke) !== 0) {
        throw new RuntimeException('Web server smoke tests failed.');
    }
    echo strtoupper($server) . ': ' . file_get_contents($temp . '/smoke.log');
} catch (Throwable $error) {
    foreach (glob($temp . '/*.log') as $file) {
        fwrite(STDERR, basename($file) . ":\n" . implode("\n", array_slice(explode("\n", file_get_contents($file)), -25)) . "\n");
    }
    throw $error;
} finally {
    foreach (array_reverse($processes) as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    $remove($temp);
}
