<?php
declare(strict_types=1);

namespace PBreeze;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Ai
{
    private const MAX_REQUEST_BYTES = 4194304;
    private const MAX_RESPONSE_BYTES = 16777216;
    private const MAX_EVENT_BYTES = 4194304;
    private readonly string $provider;
    private readonly int $timeout;
    private readonly int $maxTokens;

    /**
     * @param array{provider?: string, timeout?: int, max_tokens?: int, ca_file?: string, openai?: array{key?: string, model?: string}, anthropic?: array{key?: string, model?: string}} $config
     * @param Closure(string, list<string>, string, int, Closure(string): void): void|null $transport Trusted replacement for native HTTPS, primarily for isolated tests.
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $config = [],
        private readonly ?Closure $transport = null,
    ) {
        $this->provider = $config['provider'] ?? 'openai';
        $this->timeout = $config['timeout'] ?? 60;
        $this->maxTokens = $config['max_tokens'] ?? 1024;
        $this->validateProvider($this->provider);
        if ($this->timeout < 1 || $this->timeout > 300) {
            throw new InvalidArgumentException('AI timeout must be between 1 and 300 seconds.');
        }
        $this->validateTokens($this->maxTokens);
    }

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @return array{provider: string, id: string, model: string, text: string, finish_reason: string, input_tokens: int, output_tokens: int}
     */
    public function chat(
        #[\SensitiveParameter] array $messages,
        ?string $provider = null,
        #[\SensitiveParameter] ?string $system = null,
        ?string $model = null,
        ?int $maxTokens = null,
    ): array {
        return $this->complete($messages, null, $provider, $system, $model, $maxTokens);
    }

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @param callable(string): void $onText Receives unescaped text deltas synchronously; exceptions cancel the request.
     * @return array{provider: string, id: string, model: string, text: string, finish_reason: string, input_tokens: int, output_tokens: int}
     */
    public function stream(
        #[\SensitiveParameter] array $messages,
        callable $onText,
        ?string $provider = null,
        #[\SensitiveParameter] ?string $system = null,
        ?string $model = null,
        ?int $maxTokens = null,
    ): array {
        return $this->complete($messages, $onText, $provider, $system, $model, $maxTokens);
    }

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @return array{provider: string, id: string, model: string, text: string, finish_reason: string, input_tokens: int, output_tokens: int}
     */
    private function complete(
        #[\SensitiveParameter] array $messages,
        ?callable $onText,
        ?string $provider,
        #[\SensitiveParameter] ?string $system,
        ?string $model,
        ?int $maxTokens,
    ): array {
        $provider ??= $this->provider;
        $this->validateProvider($provider);
        $maxTokens ??= $this->maxTokens;
        $this->validateTokens($maxTokens);
        $key = $this->config[$provider]['key'] ?? '';
        $model ??= $this->config[$provider]['model'] ?? '';
        if (!preg_match('/^[\x21-\x7e]{1,8192}$/D', $key)) {
            throw new InvalidArgumentException("Configure a valid API key for AI provider $provider.");
        }
        if (!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]{0,199}$~D', $model)) {
            throw new InvalidArgumentException("Configure a valid model ID for AI provider $provider.");
        }
        if ($messages === [] || !array_is_list($messages)) {
            throw new InvalidArgumentException('AI messages must be a nonempty list.');
        }
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || trim($message['content']) === ''
                || array_diff(array_keys($message), ['role', 'content']) !== []) {
                throw new InvalidArgumentException('Each AI message must contain only a user/assistant role and nonempty text content.');
            }
        }
        $streaming = $onText !== null;
        $headers = ['Content-Type: application/json', 'Accept: ' . ($streaming ? 'text/event-stream' : 'application/json')];
        if ($provider === 'openai') {
            $url = 'https://api.openai.com/v1/responses';
            $headers[] = 'Authorization: Bearer ' . $key;
            $payload = ['model' => $model, 'input' => $messages, 'max_output_tokens' => $maxTokens, 'stream' => $streaming, 'store' => false];
            if ($system !== null) {
                $payload['instructions'] = $system;
            }
        } else {
            $url = 'https://api.anthropic.com/v1/messages';
            $headers[] = 'x-api-key: ' . $key;
            $headers[] = 'anthropic-version: 2023-06-01';
            $payload = ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'stream' => $streaming];
            if ($system !== null) {
                $payload['system'] = $system;
            }
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('AI request text must be valid UTF-8.');
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new InvalidArgumentException('AI request exceeds the 4 MiB limit.');
        }
        $response = '';
        $bytes = 0;
        $parser = $onText === null ? null : $this->streamParser($provider, $onText);
        $receive = static function (#[\SensitiveParameter] string $chunk) use (&$bytes, &$response, $parser): void {
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('AI response exceeds the 16 MiB limit.');
            }
            if ($parser !== null) {
                $parser($chunk);
            } else {
                $response .= $chunk;
            }
        };
        ($this->transport ?? $this->request(...))($url, $headers, $body, $this->timeout, $receive);
        return $parser === null ? $this->normalize($provider, $this->decode($response)) : $parser(null);
    }

    /**
     * @param list<string> $headers
     * @param Closure(string): void $receive
     */
    private function request(
        string $url,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] string $body,
        int $timeout,
        Closure $receive,
    ): void {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('AI requests require the native curl extension.');
        }
        $caFile = $this->config['ca_file'] ?? '';
        if ($caFile !== '' && (!is_file($caFile) || !is_readable($caFile))) {
            throw new InvalidArgumentException('The AI CA file is not readable.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize the AI HTTPS request.');
        }
        $status = 0;
        try {
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$status): int {
                    if (preg_match('~^HTTP/\S+\s+([0-9]{3})\b~', $line, $match)) {
                        $status = (int) $match[1];
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$status, $receive): int {
                    if ($status !== 200) {
                        return 0;
                    }
                    $receive($chunk);
                    return strlen($chunk);
                },
            ];
            if ($caFile !== '') {
                $options[CURLOPT_CAINFO] = $caFile;
            }
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Cannot configure the AI HTTPS request.');
            }
            $ok = curl_exec($handle);
            if ($status !== 0 && $status !== 200) {
                throw new RuntimeException("AI provider returned HTTP $status. Check credentials, model access, quotas, and provider availability.", $status);
            }
            if ($ok === false || $status !== 200) {
                throw new RuntimeException('AI HTTPS request failed or timed out. Check network connectivity and trusted CA certificates.');
            }
        } finally {
            unset($handle);
        }
    }

    /** @return array<string, mixed> */
    private function decode(#[\SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('AI provider returned invalid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('AI provider returned an invalid response object.');
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{provider: string, id: string, model: string, text: string, finish_reason: string, input_tokens: int, output_tokens: int}
     */
    private function normalize(string $provider, #[\SensitiveParameter] array $data): array
    {
        if (isset($data['error']) || ($data['type'] ?? '') === 'error') {
            throw new RuntimeException('AI provider reported an error.');
        }
        $text = '';
        if ($provider === 'openai') {
            $reason = $data['status'] ?? null;
            if ($reason === 'incomplete') {
                $reason = $data['incomplete_details']['reason'] ?? null;
                if ($reason !== 'max_output_tokens') {
                    throw new RuntimeException('AI response was incomplete.');
                }
            } elseif ($reason !== 'completed') {
                throw new RuntimeException('AI response did not complete successfully.');
            }
            $blocks = [];
            if (!is_array($data['output'] ?? null) || !array_is_list($data['output'])) {
                throw new RuntimeException('AI response is missing output.');
            }
            foreach ($data['output'] as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('AI response contains invalid output.');
                }
                if (($item['type'] ?? '') === 'reasoning') {
                    continue;
                }
                if (($item['type'] ?? '') !== 'message' || !is_array($item['content'] ?? null) || !array_is_list($item['content'])) {
                    throw new RuntimeException('AI response contains unsupported non-text output.');
                }
                array_push($blocks, ...$item['content']);
            }
        } else {
            $reason = $data['stop_reason'] ?? null;
            if (!in_array($reason, ['end_turn', 'max_tokens', 'stop_sequence', 'refusal', 'model_context_window_exceeded'], true)
                || !is_array($data['content'] ?? null) || !array_is_list($data['content'])) {
                throw new RuntimeException('AI response did not complete with supported text output.');
            }
            $blocks = $data['content'];
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new RuntimeException('AI response contains invalid text.');
            }
            $type = $block['type'] ?? '';
            if ($provider === 'anthropic' && in_array($type, ['thinking', 'redacted_thinking'], true)) {
                continue;
            }
            if ($provider === 'openai' && $type === 'refusal' && is_string($block['refusal'] ?? null)) {
                $text .= $block['refusal'];
                $reason = 'refusal';
            } elseif ($type === ($provider === 'openai' ? 'output_text' : 'text') && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } else {
                throw new RuntimeException('AI response contains unsupported non-text content.');
            }
        }
        if (!is_string($data['id'] ?? null) || $data['id'] === ''
            || !is_string($data['model'] ?? null) || $data['model'] === ''
            || !is_int($data['usage']['input_tokens'] ?? null) || $data['usage']['input_tokens'] < 0
            || !is_int($data['usage']['output_tokens'] ?? null) || $data['usage']['output_tokens'] < 0
            || $text === '') {
            throw new RuntimeException('AI response is missing text or completion metadata.');
        }
        return ['provider' => $provider, 'id' => $data['id'], 'model' => $data['model'], 'text' => $text,
            'finish_reason' => $reason, 'input_tokens' => $data['usage']['input_tokens'], 'output_tokens' => $data['usage']['output_tokens']];
    }

    /** @return Closure(?string): mixed */
    private function streamParser(string $provider, callable $onText): Closure
    {
        $buffer = '';
        $data = '';
        $text = '';
        $message = null;
        $result = null;
        $eventBytes = 0;
        $emit = static function (#[\SensitiveParameter] string $delta) use (&$text, $onText): void {
            if ($delta !== '') {
                $text .= $delta;
                $onText($delta);
            }
        };
        $event = function () use ($provider, &$data, &$message, &$result, &$text, $emit): void {
            if ($data === '') {
                return;
            }
            $json = substr($data, 0, -1);
            $data = '';
            if ($json === '[DONE]' && $result !== null) {
                return;
            }
            $entry = $this->decode($json);
            $type = $entry['type'] ?? '';
            if (!is_string($type) || $type === '' || $type === 'error' || in_array($type, ['response.failed', 'response.cancelled'], true)) {
                throw new RuntimeException('AI provider reported an invalid or failed stream event.');
            }
            if ($type === 'ping') {
                return;
            }
            if ($result !== null) {
                throw new RuntimeException('AI stream continued after completion.');
            }
            if ($provider === 'openai') {
                if (in_array($type, ['response.output_text.delta', 'response.refusal.delta'], true)) {
                    if (!is_string($entry['delta'] ?? null)) {
                        throw new RuntimeException('AI stream contains an invalid text delta.');
                    }
                    $emit($entry['delta']);
                } elseif (in_array($type, ['response.completed', 'response.incomplete'], true)) {
                    if (!is_array($entry['response'] ?? null)) {
                        throw new RuntimeException('AI stream is missing its final response.');
                    }
                    $result = $this->normalize($provider, $entry['response']);
                    if ($result['text'] !== $text) {
                        throw new RuntimeException('AI stream text does not match the final response.');
                    }
                }
                return;
            }
            if ($type === 'message_start') {
                if ($message !== null || !is_array($entry['message'] ?? null)) {
                    throw new RuntimeException('AI stream contains an invalid message start.');
                }
                $message = $entry['message'];
            } elseif ($type === 'content_block_start') {
                if ($message === null || !is_array($entry['content_block'] ?? null)) {
                    throw new RuntimeException('AI stream contains an invalid content block.');
                }
                $block = $entry['content_block'];
                if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $emit($block['text']);
                } elseif (!in_array($block['type'] ?? '', ['thinking', 'redacted_thinking'], true)) {
                    throw new RuntimeException('AI stream contains unsupported non-text content.');
                }
            } elseif ($type === 'content_block_delta') {
                if ($message === null || !is_array($entry['delta'] ?? null)) {
                    throw new RuntimeException('AI stream contains an invalid content delta.');
                }
                $delta = $entry['delta'];
                if (($delta['type'] ?? '') === 'text_delta' && is_string($delta['text'] ?? null)) {
                    $emit($delta['text']);
                } elseif (!in_array($delta['type'] ?? '', ['thinking_delta', 'signature_delta'], true)) {
                    throw new RuntimeException('AI stream contains an unsupported content delta.');
                }
            } elseif ($type === 'message_delta') {
                if ($message === null || !is_array($entry['delta'] ?? null) || !is_array($entry['usage'] ?? null)) {
                    throw new RuntimeException('AI stream contains invalid completion metadata.');
                }
                $message['stop_reason'] = $entry['delta']['stop_reason'] ?? $message['stop_reason'] ?? null;
                if (!is_array($message['usage'] ?? null)) {
                    throw new RuntimeException('AI stream is missing token usage.');
                }
                $message['usage'] = array_replace($message['usage'], $entry['usage']);
            } elseif ($type === 'message_stop') {
                if ($message === null) {
                    throw new RuntimeException('AI stream stopped without a message.');
                }
                $message['content'] = [['type' => 'text', 'text' => $text]];
                $result = $this->normalize($provider, $message);
            }
        };
        return static function (#[\SensitiveParameter] ?string $chunk) use (&$buffer, &$data, &$result, &$eventBytes, $event): ?array {
            $buffer .= $chunk ?? '';
            while ($buffer !== '') {
                $length = strcspn($buffer, "\r\n");
                if ($length === strlen($buffer) || ($buffer[$length] === "\r" && $length + 1 === strlen($buffer) && $chunk !== null)) {
                    break;
                }
                $line = substr($buffer, 0, $length);
                $separator = substr($buffer, $length, 2) === "\r\n" ? 2 : 1;
                $buffer = substr($buffer, $length + $separator);
                $eventBytes += $length + $separator;
                if ($eventBytes > self::MAX_EVENT_BYTES) {
                    throw new RuntimeException('AI stream event exceeds the 4 MiB limit.');
                }
                if ($line === '') {
                    $event();
                    $eventBytes = 0;
                } elseif ($line === 'data' || str_starts_with($line, 'data:')) {
                    $value = $line === 'data' ? '' : substr($line, 5);
                    $data .= (str_starts_with($value, ' ') ? substr($value, 1) : $value) . "\n";
                }
            }
            if (strlen($buffer) + $eventBytes > self::MAX_EVENT_BYTES) {
                throw new RuntimeException('AI stream event exceeds the 4 MiB limit.');
            }
            if ($chunk === null) {
                if ($buffer !== '' || $data !== '' || $result === null) {
                    throw new RuntimeException('AI stream ended before a complete terminal event.');
                }
                return $result;
            }
            return null;
        };
    }

    private function validateProvider(string $provider): void
    {
        if (!in_array($provider, ['openai', 'anthropic'], true)) {
            throw new InvalidArgumentException('AI provider must be openai or anthropic (Claude).');
        }
    }

    private function validateTokens(int $tokens): void
    {
        if ($tokens < 1 || $tokens > 128000) {
            throw new InvalidArgumentException('AI output token limit must be between 1 and 128000; model limits may be lower.');
        }
    }
}
