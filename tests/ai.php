<?php
declare(strict_types=1);

use PBreeze\Ai;
use PBreeze\Application;

/** @return array<string, mixed> */
function aiSettings(array $overrides = []): array
{
    return array_replace_recursive([
        'provider' => 'openai', 'timeout' => 5, 'max_tokens' => 1024,
        'openai' => ['key' => 'test-openai-key', 'model' => 'test-openai-model'],
        'anthropic' => ['key' => 'test-anthropic-key', 'model' => 'test-claude-model'],
    ], $overrides);
}

/** @return array<string, mixed> */
function aiResponse(string $provider, string $text = 'Hello!'): array
{
    $usage = ['input_tokens' => 12, 'output_tokens' => 3];
    return $provider === 'openai'
        ? ['id' => 'resp_test', 'model' => 'test-openai-model', 'status' => 'completed', 'usage' => $usage,
            'output' => [['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]]
        : ['id' => 'msg_test', 'model' => 'test-claude-model', 'type' => 'message', 'role' => 'assistant',
            'stop_reason' => 'end_turn', 'usage' => $usage, 'content' => [['type' => 'text', 'text' => $text]]];
}

/** @param list<string> $chunks */
function aiStub(array $chunks, array $settings = [], ?Closure $inspect = null): Ai
{
    return new Ai(aiSettings($settings), static function (string $url, array $headers, string $body, int $timeout, Closure $receive) use ($chunks, $inspect): void {
        if ($inspect !== null) {
            $inspect($url, $headers, json_decode($body, true, 128, JSON_THROW_ON_ERROR), $timeout);
        }
        foreach ($chunks as $chunk) {
            $receive($chunk);
        }
    });
}

function aiEvent(array $event, string $newline = "\n"): string
{
    return 'event: ' . $event['type'] . $newline . 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        . $newline . $newline;
}

/** @return list<array<string, mixed>> */
function aiClaudeEvents(string $text = 'Hello!'): array
{
    $start = aiResponse('anthropic', '');
    $start['content'] = [];
    $start['stop_reason'] = null;
    $start['usage']['output_tokens'] = 1;
    return [
        ['type' => 'message_start', 'message' => $start],
        ['type' => 'ping'],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    ];
}

test('AI maps native provider authentication, system prompts, history, and output limits', static function (): void {
    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi!'],
        ['role' => 'user', 'content' => 'Explain PHP.'],
    ];
    foreach (['openai', 'anthropic'] as $provider) {
        $inspected = false;
        $ai = aiStub([json_encode(aiResponse($provider), JSON_THROW_ON_ERROR)], [], static function ($url, $headers, $payload, $timeout) use ($provider, $messages, &$inspected): void {
            $inspected = true;
            same($provider === 'openai' ? 'https://api.openai.com/v1/responses' : 'https://api.anthropic.com/v1/messages', $url);
            check(in_array('Content-Type: application/json', $headers, true));
            check(in_array('Accept: application/json', $headers, true));
            check(in_array($provider === 'openai' ? 'Authorization: Bearer test-openai-key' : 'x-api-key: test-anthropic-key', $headers, true));
            if ($provider === 'anthropic') {
                check(in_array('anthropic-version: 2023-06-01', $headers, true));
            }
            same('model-override', $payload['model']);
            same($messages, $payload[$provider === 'openai' ? 'input' : 'messages']);
            same('Be concise.', $payload[$provider === 'openai' ? 'instructions' : 'system']);
            same(512, $payload[$provider === 'openai' ? 'max_output_tokens' : 'max_tokens']);
            same(false, $payload['stream']);
            if ($provider === 'openai') {
                same(false, $payload['store']);
            }
            same(5, $timeout);
        });
        $result = $ai->chat($messages, $provider, 'Be concise.', 'model-override', 512);
        check($inspected);
        same(['provider', 'id', 'model', 'text', 'finish_reason', 'input_tokens', 'output_tokens'], array_keys($result));
        same($provider, $result['provider']);
        same('Hello!', $result['text']);
        same(12, $result['input_tokens']);
        same(3, $result['output_tokens']);
        same($provider === 'openai' ? 'completed' : 'end_turn', $result['finish_reason']);
    }
});

test('AI uses configured defaults without connecting at construction', static function () use ($temp, $config): void {
    $settings = $config;
    $settings['ai'] = aiSettings(['provider' => 'anthropic']);
    $application = new Application($temp, $settings);
    check($application->ai instanceof Ai);
    new Ai();
    $ai = aiStub([json_encode(aiResponse('anthropic'), JSON_THROW_ON_ERROR)], ['provider' => 'anthropic'],
        static function ($url, $headers, $payload): void {
            same('test-claude-model', $payload['model']);
            same(1024, $payload['max_tokens']);
            check(!array_key_exists('system', $payload));
        });
    same('anthropic', $ai->chat([['role' => 'user', 'content' => 'Hello']])['provider']);
});

test('AI environment configuration validates bounds and maps both providers without exposing secrets', static function () use ($root): void {
    $values = ['AI_PROVIDER' => 'anthropic', 'AI_TIMEOUT' => '12', 'AI_MAX_TOKENS' => '2048',
        'AI_CA_FILE' => '', 'OPENAI_API_KEY' => 'test-openai-key', 'OPENAI_MODEL' => 'openai-test',
        'ANTHROPIC_API_KEY' => 'test-anthropic-key', 'ANTHROPIC_MODEL' => 'claude-test'];
    $previous = [];
    foreach ($values as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("$key=$value");
    }
    try {
        $settings = require $root . '/config/ai.php';
        same('anthropic', $settings['provider']);
        same(12, $settings['timeout']);
        same(2048, $settings['max_tokens']);
        same(['key' => 'test-openai-key', 'model' => 'openai-test'], $settings['openai']);
        same(['key' => 'test-anthropic-key', 'model' => 'claude-test'], $settings['anthropic']);
        foreach (['0', '-1', '301', 'invalid', ''] as $value) {
            putenv("AI_TIMEOUT=$value");
            raises(static fn () => require $root . '/config/ai.php', RuntimeException::class);
        }
        putenv('AI_TIMEOUT=300');
        foreach (['0', '-1', '128001', '1.5', ''] as $value) {
            putenv("AI_MAX_TOKENS=$value");
            raises(static fn () => require $root . '/config/ai.php', RuntimeException::class);
        }
        putenv('AI_MAX_TOKENS=128000');
        new Ai(require $root . '/config/ai.php');
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "$key=$value");
        }
    }
});

test('AI rejects invalid configuration and request input before any transport call', static function (): void {
    foreach ([['provider' => 'unknown'], ['timeout' => 0], ['timeout' => 301], ['max_tokens' => 0], ['max_tokens' => 128001]] as $settings) {
        raises(static fn () => new Ai($settings), InvalidArgumentException::class);
    }
    $never = static function (): never { throw new LogicException('Transport must not be called.'); };
    $ai = new Ai(aiSettings(), $never);
    foreach ([[], ['named' => ['role' => 'user', 'content' => 'Hi']], [null],
        [['role' => 'system', 'content' => 'No']], [['role' => 'user', 'content' => []]],
        [['role' => 'user', 'content' => '']], [['role' => 'user', 'content' => " \n"]],
        [['role' => 'user', 'content' => 'Hi', 'extra' => true]],
        [['role' => 'user', 'content' => "\xff"]], [['role' => 'user', 'content' => str_repeat('x', 4194304)]],
    ] as $messages) {
        raises(static fn () => $ai->chat($messages), InvalidArgumentException::class);
    }
    $messages = [['role' => 'user', 'content' => 'Hi']];
    raises(static fn () => $ai->chat($messages, 'bad'), InvalidArgumentException::class);
    raises(static fn () => $ai->chat($messages, maxTokens: 0), InvalidArgumentException::class);
    raises(static fn () => $ai->chat($messages, maxTokens: 128001), InvalidArgumentException::class);
    raises(static fn () => $ai->chat($messages, system: "\xff"), InvalidArgumentException::class);
    raises(static fn () => $ai->chat($messages, model: "model\r\nInjected: true"), InvalidArgumentException::class);
    foreach (['', "key\r\nInjected: true", 'key with spaces'] as $key) {
        $client = new Ai(aiSettings(['openai' => ['key' => $key]]), $never);
        raises(static fn () => $client->chat($messages), InvalidArgumentException::class);
    }
    raises(static fn () => (new Ai([], $never))->chat($messages), InvalidArgumentException::class);
    raises(static fn () => (new Ai(aiSettings(['openai' => ['model' => '']]), $never))->chat($messages), InvalidArgumentException::class);
});

test('AI preserves truncation and refusals rather than disguising them as normal completion', static function (): void {
    foreach (['openai', 'anthropic'] as $provider) {
        $response = aiResponse($provider, 'Partial');
        if ($provider === 'openai') {
            $response['status'] = 'incomplete';
            $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
        } else {
            $response['stop_reason'] = 'max_tokens';
        }
        $result = aiStub([json_encode($response, JSON_THROW_ON_ERROR)])->chat([['role' => 'user', 'content' => 'Hi']], $provider);
        same('Partial', $result['text']);
        same($provider === 'openai' ? 'max_output_tokens' : 'max_tokens', $result['finish_reason']);
    }
    $response = aiResponse('openai');
    $response['output'] = [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]];
    $result = aiStub([json_encode($response, JSON_THROW_ON_ERROR)])->chat([['role' => 'user', 'content' => 'Hi']]);
    same('I cannot help with that.', $result['text']);
    same('refusal', $result['finish_reason']);
});

test('AI rejects malformed, failed, empty, unsupported, and oversized provider responses', static function (): void {
    $responses = ['not JSON', '{}', '[]', 'null', '{"error":{"message":"private-provider-details"}}'];
    foreach (['output', 'usage', 'id', 'model'] as $field) {
        $response = aiResponse('openai');
        unset($response[$field]);
        $responses[] = json_encode($response, JSON_THROW_ON_ERROR);
    }
    foreach (['failed', 'queued', 'incomplete'] as $status) {
        $response = aiResponse('openai');
        $response['status'] = $status;
        $responses[] = json_encode($response, JSON_THROW_ON_ERROR);
    }
    $response = aiResponse('openai');
    $response['output'] = [['type' => 'function_call', 'name' => 'unexpected']];
    $responses[] = json_encode($response, JSON_THROW_ON_ERROR);
    $response = aiResponse('openai');
    $response['usage']['input_tokens'] = -1;
    $responses[] = json_encode($response, JSON_THROW_ON_ERROR);
    $response = aiResponse('openai');
    $response['output'][1]['content'] = ['invalid' => ['type' => 'output_text', 'text' => 'bad']];
    $responses[] = json_encode($response, JSON_THROW_ON_ERROR);
    $responses[] = json_encode(aiResponse('openai', ''), JSON_THROW_ON_ERROR);
    $responses[] = str_repeat('x', 16777217);
    foreach ($responses as $response) {
        raises(static fn () => aiStub([$response])->chat([['role' => 'user', 'content' => 'Hi']]), RuntimeException::class);
    }
    $response = aiResponse('anthropic');
    $response['stop_reason'] = 'tool_use';
    raises(static fn () => aiStub([json_encode($response, JSON_THROW_ON_ERROR)])
        ->chat([['role' => 'user', 'content' => 'Hi']], 'anthropic'), RuntimeException::class);
});

test('AI enforces exact request, response, and SSE event byte limits independently of fragmentation', static function (): void {
    $messages = [['role' => 'user', 'content' => 'x']];
    $payload = ['model' => 'test-openai-model', 'input' => $messages, 'max_output_tokens' => 1024, 'stream' => false, 'store' => false];
    $overhead = strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) - 1;
    $messages[0]['content'] = str_repeat('x', 4194304 - $overhead);
    $client = aiStub([json_encode(aiResponse('openai'), JSON_THROW_ON_ERROR)], [], static function ($url, $headers, $payload): void {
        same(4194304, strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    });
    same('Hello!', $client->chat($messages)['text']);
    $messages[0]['content'] .= 'x';
    raises(static fn () => $client->chat($messages), InvalidArgumentException::class);
    unset($messages, $client);
    $response = json_encode(aiResponse('openai'), JSON_THROW_ON_ERROR);
    $padded = $response . str_repeat(' ', 16777216 - strlen($response));
    same('Hello!', aiStub([$padded])->chat([['role' => 'user', 'content' => 'Hi']])['text']);
    unset($padded);
    $terminal = aiEvent(['type' => 'response.output_text.delta', 'delta' => 'Hello!'])
        . aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai')]);
    $padding = ':' . str_repeat('x', 4194304 - 3) . "\n\n";
    foreach ([[$padding, $terminal], [substr($padding, 0, -2), "\n", "\n", $terminal]] as $chunks) {
        same('Hello!', aiStub($chunks)->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {})['text']);
    }
    $padding = ':' . str_repeat('x', 4194304 - 2) . "\n\n";
    foreach ([[$padding, $terminal], [substr($padding, 0, -2), "\n", "\n", $terminal]] as $chunks) {
        raises(static fn () => aiStub($chunks)->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {}), RuntimeException::class);
    }
});

test('AI streams fragmented OpenAI and Claude SSE text immediately with final metadata', static function (): void {
    $text = "Hello, caf\u{00e9}!";
    foreach (['openai', 'anthropic'] as $provider) {
        $events = $provider === 'openai'
            ? [['type' => 'response.created', 'response' => ['id' => 'resp_test']],
                ['type' => 'response.output_text.delta', 'delta' => $text],
                ['type' => 'response.completed', 'response' => aiResponse('openai', $text)]]
            : aiClaudeEvents($text);
        foreach (["\n", "\r\n", "\r"] as $newline) {
            $wire = ': keepalive' . $newline . $newline . implode('', array_map(static fn ($event) => aiEvent($event, $newline), $events));
            $received = '';
            $chunksBeforeCompletion = 0;
            $client = new Ai(aiSettings(), static function ($url, $headers, $body, $timeout, $receive) use ($wire, &$received, &$chunksBeforeCompletion): void {
                check(in_array('Accept: text/event-stream', $headers, true));
                same(true, json_decode($body, true, 128, JSON_THROW_ON_ERROR)['stream']);
                foreach (str_split($wire, 1) as $byte) {
                    $receive($byte);
                    if ($received !== '') {
                        $chunksBeforeCompletion++;
                    }
                }
            });
            $result = $client->stream([['role' => 'user', 'content' => 'Hello']], static function ($delta) use (&$received): void {
                $received .= $delta;
            }, $provider);
            same($text, $received);
            same($text, $result['text']);
            same(12, $result['input_tokens']);
            same(3, $result['output_tokens']);
            check($chunksBeforeCompletion > 20);
        }
    }
});

test('AI SSE handles multiline data, forward-compatible events, and multiple content blocks', static function (): void {
    $events = ": comment\n\nid: 42\nretry: 1000\nevent: future.event\ndata: {\"type\":\"future.event\"}\n\n"
        . "data: {\"type\":\"response.output_text.delta\",\ndata: \"delta\":\"Hello!\"}\n\n"
        . aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai')]) . "data: [DONE]\n\n";
    $text = '';
    $result = aiStub([$events])->stream([['role' => 'user', 'content' => 'Hi']], static function ($delta) use (&$text): void { $text .= $delta; });
    same('Hello!', $text);
    same('Hello!', $result['text']);
    $events = aiClaudeEvents('llo!');
    $events[2]['content_block']['text'] = 'He';
    $wire = implode('', array_map(aiEvent(...), $events));
    $text = '';
    $result = aiStub([$wire])->stream([['role' => 'user', 'content' => 'Hi']], static function ($delta) use (&$text): void { $text .= $delta; }, 'anthropic');
    same('Hello!', $text);
    same('Hello!', $result['text']);
});

test('AI streaming failures, truncation, incomplete events, and callback cancellation propagate', static function (): void {
    $partial = aiEvent(['type' => 'response.output_text.delta', 'delta' => 'Partial']);
    foreach ([
        '', $partial, 'data: {broken}' . "\n\n", "data: [DONE]\n\n",
        $partial . aiEvent(['type' => 'error', 'message' => 'private-provider-details']),
        $partial . aiEvent(['type' => 'response.failed', 'response' => ['error' => ['message' => 'private-details']]]),
        $partial . aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai', 'Mismatch')]),
        $partial . rtrim(aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai', 'Partial')])),
        'data: ' . str_repeat('x', 4194305),
        "data: {\"type\":\"response.output_text.delta\",\"delta\":false}\n\n",
    ] as $wire) {
        raises(static fn () => aiStub([$wire])->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {}), RuntimeException::class);
    }
    foreach ([
        [aiEvent(['type' => 'message_stop'])],
        [aiEvent(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'No start']])],
        array_map(aiEvent(...), array_slice(aiClaudeEvents(), 0, -1)),
        [aiEvent(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'private-details']])],
    ] as $chunks) {
        raises(static fn () => aiStub($chunks)->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {}, 'anthropic'), RuntimeException::class);
    }
    $error = new LogicException('Cancel generation');
    try {
        aiStub([$partial])->stream([['role' => 'user', 'content' => 'Hi']], static function () use ($error): never { throw $error; });
        throw new RuntimeException('Expected callback cancellation.');
    } catch (LogicException $caught) {
        check($caught === $error);
    }
    $response = aiResponse('openai', 'Partial');
    $response['status'] = 'incomplete';
    $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
    $result = aiStub([$partial . aiEvent(['type' => 'response.incomplete', 'response' => $response])])
        ->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {});
    same('max_output_tokens', $result['finish_reason']);
});

test('AI streams refusals and Claude stop reasons while rejecting unsupported tool output', static function (): void {
    $response = aiResponse('openai');
    $response['output'] = [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]];
    $wire = aiEvent(['type' => 'response.refusal.delta', 'delta' => 'No.'])
        . aiEvent(['type' => 'response.completed', 'response' => $response]);
    same('refusal', aiStub([$wire])->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {})['finish_reason']);
    foreach (['max_tokens', 'refusal', 'stop_sequence', 'model_context_window_exceeded'] as $reason) {
        $events = aiClaudeEvents();
        $events[5]['delta']['stop_reason'] = $reason;
        $wire = implode('', array_map(aiEvent(...), $events));
        same($reason, aiStub([$wire])->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {}, 'anthropic')['finish_reason']);
    }
    $events = aiClaudeEvents();
    $events[2]['content_block'] = ['type' => 'tool_use', 'id' => 'tool_test', 'name' => 'unexpected', 'input' => []];
    raises(static fn () => aiStub(array_map(aiEvent(...), $events))
        ->stream([['role' => 'user', 'content' => 'Hi']], static function (): void {}, 'anthropic'), RuntimeException::class);
});

/** @param array<string, mixed> $options */
function aiHttpFixture(array $options, callable $work): array
{
    global $temp, $root;
    $name = $temp . '/ai-fixture-' . bin2hex(random_bytes(5));
    $options['certificate'] = mailCertificate();
    $options['transcript'] = $name . '.transcript';
    file_put_contents($name . '.json', json_encode($options, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/ai-server.php', $name . '.json'], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'], 2 => ['file', $name . '.log', 'a'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the AI HTTPS fixture.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        stream_set_timeout($pipes[1], 10);
    }
    try {
        $address = fgets($pipes[1]);
        if ($address === false) {
            throw new RuntimeException('AI HTTPS fixture did not start: ' . file_get_contents($name . '.log'));
        }
        $work('https://' . trim($address) . '/v1/test', $options['certificate']);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $process = null;
        same(0, $exit);
        same('', file_get_contents($name . '.log'));
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

test('AI native HTTPS transport verifies TLS, sends JSON, and streams chunked HTTP bodies', static function (): void {
    $wire = aiEvent(['type' => 'response.output_text.delta', 'delta' => 'Hello!'])
        . aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai')]);
    $transcript = aiHttpFixture(['chunks' => str_split($wire, 17)], static function ($url, $certificate) use ($wire): void {
        $ai = new Ai(aiSettings(['ca_file' => $certificate]));
        $request = new ReflectionMethod(Ai::class, 'request');
        $received = '';
        $request->invoke($ai, $url, ['Content-Type: application/json', 'Authorization: Bearer test-local-key'], '{"test":true}', 3,
            static function ($chunk) use (&$received): void { $received .= $chunk; });
        same($wire, $received);
    });
    check(str_contains($transcript['headers'], 'POST /v1/test HTTP/'));
    check(str_contains($transcript['headers'], 'Authorization: Bearer test-local-key'));
    same('{"test":true}', $transcript['body']);
});

test('AI provider stream parsers operate end to end through native HTTPS and propagate callback exceptions', static function (): void {
    foreach (['openai', 'anthropic'] as $provider) {
        $wire = $provider === 'openai'
            ? aiEvent(['type' => 'response.output_text.delta', 'delta' => 'Hello!'])
                . aiEvent(['type' => 'response.completed', 'response' => aiResponse('openai')])
            : implode('', array_map(aiEvent(...), aiClaudeEvents()));
        aiHttpFixture(['chunks' => str_split($wire, 23)], static function ($url, $certificate) use ($provider): void {
            $network = new Ai(aiSettings(['ca_file' => $certificate]));
            $method = new ReflectionMethod(Ai::class, 'request');
            $client = new Ai(aiSettings(), static function ($unusedUrl, $headers, $body, $timeout, $receive) use ($network, $method, $url): void {
                $method->invoke($network, $url, $headers, $body, $timeout, $receive);
            });
            $text = '';
            $reply = $client->stream([['role' => 'user', 'content' => 'Hi']], static function ($delta) use (&$text): void { $text .= $delta; }, $provider);
            same('Hello!', $text);
            same('Hello!', $reply['text']);
            same($provider, $reply['provider']);
        });
    }
    aiHttpFixture(['body' => 'Hello!'], static function ($url, $certificate): void {
        $network = new Ai(aiSettings(['ca_file' => $certificate]));
        $error = new LogicException('Cancel native transfer');
        try {
            (new ReflectionMethod(Ai::class, 'request'))->invoke($network, $url, [], '{}', 3, static function () use ($error): never { throw $error; });
            throw new RuntimeException('Expected callback cancellation.');
        } catch (LogicException $caught) {
            check($caught === $error);
        }
    });
});

test('AI native HTTPS rejects untrusted certificates, redirects, HTTP errors, timeouts, and partial transfers', static function (): void {
    foreach ([
        ['status' => 429, 'body' => 'private-provider-details'],
        ['status' => 302, 'location' => 'https://127.0.0.1:1/leaked-key'],
        ['delay' => 2],
        ['truncate' => true],
        ['untrusted' => true],
    ] as $options) {
        aiHttpFixture($options, static function ($url, $certificate) use ($options): void {
            $ai = new Ai(aiSettings(['ca_file' => isset($options['untrusted']) ? '' : $certificate]));
            $request = new ReflectionMethod(Ai::class, 'request');
            try {
                $request->invoke($ai, $url, ['Authorization: Bearer test-local-key'], '{}', 1, static function (): void {});
                throw new LogicException('Expected a failed HTTPS request.');
            } catch (RuntimeException $error) {
                if (isset($options['status'])) {
                    same($options['status'], $error->getCode());
                }
                check(!str_contains($error->getMessage(), 'private-provider-details'));
                check(!str_contains((string) $error, 'test-local-key'));
            }
        });
    }
});
