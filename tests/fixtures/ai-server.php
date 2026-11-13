<?php
declare(strict_types=1);

$options = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$context = stream_context_create(['ssl' => ['local_cert' => $options['certificate']]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('Cannot bind the AI fixture.');
}
echo stream_socket_get_name($server, false) . PHP_EOL;
flush();
$client = stream_socket_accept($server, 10);
if ($client === false) {
    throw new RuntimeException('No AI fixture connection.');
}
stream_set_timeout($client, 5);
set_error_handler(static fn (): bool => true);
$tls = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
restore_error_handler();
if ($tls !== true) {
    if (!($options['untrusted'] ?? false)) {
        throw new RuntimeException('Unexpected AI fixture TLS failure.');
    }
    file_put_contents($options['transcript'], json_encode(['headers' => '', 'body' => ''], JSON_THROW_ON_ERROR));
    fclose($client);
    fclose($server);
    exit(0);
}
if ($options['untrusted'] ?? false) {
    set_error_handler(static fn (): bool => true);
    $line = fgets($client);
    restore_error_handler();
    if ($line !== false) {
        throw new RuntimeException('The untrusted AI fixture received an HTTP request.');
    }
    file_put_contents($options['transcript'], json_encode(['headers' => '', 'body' => ''], JSON_THROW_ON_ERROR));
    fclose($client);
    fclose($server);
    exit(0);
}
$headers = '';
while (($line = fgets($client)) !== false && $line !== "\r\n") {
    $headers .= $line;
}
preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $match);
$length = (int) ($match[1] ?? 0);
$body = '';
while (strlen($body) < $length) {
    $chunk = fread($client, $length - strlen($body));
    if ($chunk === false || $chunk === '') {
        throw new RuntimeException('Incomplete AI fixture request.');
    }
    $body .= $chunk;
}
file_put_contents($options['transcript'], json_encode(['headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR));
if (isset($options['delay'])) {
    sleep($options['delay']);
}
$status = $options['status'] ?? 200;
$response = "HTTP/1.1 $status Fixture\r\nConnection: close\r\nContent-Type: text/event-stream\r\n";
if (isset($options['location'])) {
    $response .= 'Location: ' . $options['location'] . "\r\n";
}
set_error_handler(static fn (): bool => true);
if (isset($options['chunks'])) {
    fwrite($client, $response . "Transfer-Encoding: chunked\r\n\r\n");
    foreach ($options['chunks'] as $chunk) {
        fwrite($client, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
        fflush($client);
    }
    fwrite($client, "0\r\n\r\n");
} else {
    $output = $options['body'] ?? '{}';
    $length = strlen($output) + (isset($options['truncate']) ? 100 : 0);
    fwrite($client, $response . "Content-Length: $length\r\n\r\n" . $output);
}
restore_error_handler();
fclose($client);
fclose($server);
