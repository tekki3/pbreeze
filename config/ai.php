<?php
declare(strict_types=1);

use PBreeze\Env;

$timeout = filter_var(Env::get('AI_TIMEOUT', '60'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 300],
]);
$maxTokens = filter_var(Env::get('AI_MAX_TOKENS', '1024'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 128000],
]);
if ($timeout === false || $maxTokens === false) {
    throw new RuntimeException('AI_TIMEOUT must be 1..300 seconds and AI_MAX_TOKENS must be 1..128000.');
}

return [
    'provider' => Env::get('AI_PROVIDER', 'openai'),
    'timeout' => $timeout,
    'max_tokens' => $maxTokens,
    'ca_file' => Env::get('AI_CA_FILE'),
    'openai' => ['key' => Env::get('OPENAI_API_KEY'), 'model' => Env::get('OPENAI_MODEL')],
    'anthropic' => ['key' => Env::get('ANTHROPIC_API_KEY'), 'model' => Env::get('ANTHROPIC_MODEL')],
];
