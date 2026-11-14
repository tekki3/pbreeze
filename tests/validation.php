<?php
declare(strict_types=1);

use PBreeze\ValidationException;
use PBreeze\RuleInterface;

test('Validation results preserve input, filter unknown fields and cache one operation', static function (): void {
    $data = ['name' => 'John', 'email' => 'john@example.com', 'age' => '30', 'active' => false, 'admin' => true];
    $validator = \PBreeze\Validator::make($data, [
        'name' => 'required|string|max:100', 'email' => 'required|email',
        'age' => 'nullable|integer|min:18', 'active' => 'boolean',
    ]);
    same(true, $validator->passes());
    same(false, $validator->fails());
    same(['name' => 'John', 'email' => 'john@example.com', 'age' => '30', 'active' => false], $validator->validated());
    same($validator->validated(), $validator->validate());
    same(true, $data['admin']);
    same('30', $validator->validated()['age']);
    $rule = new class implements RuleInterface {
        public int $calls = 0;
        public function validate(string $field, mixed $value, array $data): bool { $this->calls++; return true; }
        public function message(): string { return 'Invalid :attribute.'; }
    };
    $validator = \PBreeze\Validator::make(['x' => 1], ['x' => [$rule]]);
    $validator->passes();
    $validator->errors();
    $validator->validated();
    same(1, $rule->calls);
});

test('Validation distinguishes missing, null, empty string, zero, false and empty arrays', static function (): void {
    foreach ([0, '0', false, true, 'text', [1]] as $value) {
        check(\PBreeze\Validator::make(['x' => $value], ['x' => 'required'])->passes());
    }
    foreach ([null, '', " \n", []] as $value) {
        check(\PBreeze\Validator::make(['x' => $value], ['x' => 'required'])->fails());
    }
    check(\PBreeze\Validator::make([], ['x' => 'required'])->fails());
    check(\PBreeze\Validator::make([], ['x' => 'present'])->fails());
    same(['x' => null], \PBreeze\Validator::make(['x' => null], ['x' => 'present'])->validated());
    same([], \PBreeze\Validator::make([], ['x' => 'string'])->validated());
    same(['x' => null], \PBreeze\Validator::make(['x' => null], ['x' => 'nullable|string'])->validated());
    check(\PBreeze\Validator::make(['x' => null], ['x' => 'string'])->fails());
    check(\PBreeze\Validator::make(['x' => ''], ['x' => 'nullable|integer'])->fails());
    check(\PBreeze\Validator::make(['x' => ''], ['x' => 'string'])->passes());
    check(\PBreeze\Validator::make(['x' => null], ['x' => 'nullable|required'])->fails());
    check(\PBreeze\Validator::make([], ['x' => 'sometimes|required|string'])->passes());
    check(\PBreeze\Validator::make(['x' => null], ['x' => 'sometimes|string'])->fails());
});

test('Validation type rules reject coercion traps and preserve finite numeric types', static function (): void {
    foreach ([
        ['string', ['value', ''], [1, false, [], null, "\xff"]],
        ['integer', [0, '0', 18, '-2'], [true, false, 1.5, '1.0', '01', [], '1e3', ' 18', "18\n"]],
        ['numeric', [0, '0', '-2.5', '.5', '1e3', 1.25], [false, null, [], ' 1', '1 ', 'NaN', '1e9999', INF, NAN]],
        ['float', [1.25, '1.25', 0], [false, [], 'x', INF, '1e9999']],
        ['boolean', [true, false, 0, 1, '0', '1'], ['true', 'false', 'yes', 2, null, []]],
        ['array', [[], ['x' => 1]], [null, '', new stdClass()]],
    ] as [$rule, $valid, $invalid]) {
        foreach ($valid as $value) {
            check(\PBreeze\Validator::make(['x' => $value], ['x' => $rule])->passes(), "$rule rejected valid input.");
        }
        foreach ($invalid as $value) {
            check(\PBreeze\Validator::make(['x' => $value], ['x' => $rule])->fails(), "$rule accepted invalid input.");
        }
    }
});

test('Validation sizes use Unicode character length, numeric bounds or array count predictably', static function (): void {
    foreach ([
        ['Zoë', 'string|length:3'], ['😀é', 'string|min:2|max:2'], ['a' . "\n" . 'b', 'length:3'],
        ['18', 'integer|min:18|max:18'], ['18.5', 'numeric|between:18,19'], [-5, 'min:-5|max:-5'],
        [['a', 'b'], 'array|between:2,2'], [[], 'array|min:0|max:0'], ['123', 'string|between:3,3'],
        [PHP_INT_MAX, 'integer|min:' . PHP_INT_MAX . '|max:' . PHP_INT_MAX],
    ] as [$value, $rules]) {
        check(\PBreeze\Validator::make(['x' => $value], ['x' => $rules])->passes(), "Size rule rejected $rules.");
    }
    foreach ([['17', 'integer|min:18'], ['ab', 'string|max:1'], [[], 'array|min:1'], ['é', 'length:2'],
        ['abc', 'numeric|min:1'], ["\xff", 'max:1']] as [$value, $rules]) {
        check(\PBreeze\Validator::make(['x' => $value], ['x' => $rules])->fails());
    }
    check(\PBreeze\Validator::make(['x' => PHP_INT_MAX], ['x' => 'integer|max:' . (PHP_INT_MAX - 1)])->fails());
});

test('Validation formatting rules handle Unicode, email, HTTP URLs, UUID, IP and JSON safely', static function (): void {
    foreach ([
        ['email', ['john@example.com'], ['bad', ['x'], "a\r\n@example.com"]],
        ['url', ['https://example.com/a?b=1', 'http://example.test/'], ['javascript:1', '//example.com', 'ftp://example.com', 'https://u:p@example.com', 'https://a.test/ a']],
        ['uuid', ['550e8400-e29b-41d4-a716-446655440000', '01932c14-06c7-7abc-9def-123456789abc'], ['bad', '550e8400-e29b-01d4-a716-446655440000']],
        ['ip', ['127.0.0.1', '2001:db8::1'], ['999.0.0.1', 'localhost']],
        ['ipv4', ['127.0.0.1'], ['::1', '127.1']],
        ['ipv6', ['::1'], ['127.0.0.1', 'fe80::1%eth0']],
        ['json', ['{"a":[1,true]}', 'null', '[]'], ['{"a":}', '', []]],
        ['alpha', ['Élève', '日本語'], ['abc1', 'a b', "\xff"]],
        ['alpha_num', ['Élève123', '日本語42'], ['a_b', 'a-b', '']],
    ] as [$rule, $valid, $invalid]) {
        foreach ($valid as $value) {
            check(\PBreeze\Validator::make(['x' => $value], ['x' => $rule])->passes(), "$rule rejected valid value.");
        }
        foreach ($invalid as $value) {
            check(\PBreeze\Validator::make(['x' => $value], ['x' => $rule])->fails(), "$rule accepted invalid value.");
        }
    }
});

test('Validation comparisons, membership, confirmations and conditional presence are type-aware', static function (): void {
    check(\PBreeze\Validator::make(['x' => 0], ['x' => 'in:0,1'])->passes());
    check(\PBreeze\Validator::make(['x' => false], ['x' => 'in:0,1'])->fails());
    check(\PBreeze\Validator::make(['x' => false], ['x' => 'in:false'])->passes());
    check(\PBreeze\Validator::make(['x' => 'admin'], ['x' => 'not_in:admin'])->fails());
    check(\PBreeze\Validator::make(['x' => 'reader'], ['x' => 'not_in:admin'])->passes());
    check(\PBreeze\Validator::make(['x' => []], ['x' => 'not_in:admin'])->fails());
    check(\PBreeze\Validator::make(['x' => '1', 'y' => 1], ['x' => 'same:y'])->fails());
    check(\PBreeze\Validator::make(['x' => '1', 'y' => 1], ['x' => 'different:y'])->passes());
    check(\PBreeze\Validator::make(['x' => '1'], ['x' => 'different:y'])->fails());
    check(\PBreeze\Validator::make(['password' => 'value', 'password_confirmation' => 'value'], ['password' => 'confirmed'])->passes());
    foreach (['company', 'nonprofit'] as $type) {
        check(\PBreeze\Validator::make(['type' => $type], ['name' => 'required_if:type,company,nonprofit'])->fails());
    }
    check(\PBreeze\Validator::make(['type' => 'person'], ['name' => 'required_if:type,company'])->passes());
    check(\PBreeze\Validator::make(['type' => 'person'], ['name' => 'required_unless:type,person'])->passes());
    check(\PBreeze\Validator::make([], ['name' => 'required_unless:type,person'])->fails());
    check(\PBreeze\Validator::make(['active' => false], ['name' => 'required_if:active,false'])->fails());
});

test('Validation date rules reject rollover and relative parsing and compare explicit dates and fields', static function (): void {
    foreach (['2024-02-29', '2026-11-14T12:30:45Z', '2026-11-14T12:30:45.123456+01:00'] as $date) {
        check(\PBreeze\Validator::make(['x' => $date], ['x' => 'date'])->passes());
    }
    foreach (['2023-02-29', '2026-02-30', 'tomorrow', 'next week', '2026-11-14T25:30:00Z', '14/11/2026', ''] as $date) {
        check(\PBreeze\Validator::make(['x' => $date], ['x' => 'date'])->fails());
    }
    check(\PBreeze\Validator::make(['x' => '14/11/2026'], ['x' => 'date_format:d/m/Y'])->passes());
    check(\PBreeze\Validator::make(['x' => '31/02/2026'], ['x' => 'date_format:d/m/Y'])->fails());
    check(\PBreeze\Validator::make(['x' => "2026\0"], ['x' => 'date_format:Y'])->fails());
    check(\PBreeze\Validator::make(['start' => '2026-01-01', 'end' => '2026-02-01'], ['end' => 'after:@start|before:2026-03-01'])->passes());
    check(\PBreeze\Validator::make(['x' => '2026-01-01'], ['x' => 'before:2026-01-01'])->fails());
    check(\PBreeze\Validator::make(['x' => '2026-01-01'], ['x' => 'after:@missing'])->fails());
});

test('Validation regex supports array definitions with pipe alternation and explicit regex errors', static function (): void {
    check(\PBreeze\Validator::make(['x' => 'cat'], ['x' => ['string', 'regex:/^(cat|dog)$/u']])->passes());
    check(\PBreeze\Validator::make(['x' => 'bird'], ['x' => ['regex:/^(cat|dog)$/u']])->fails());
    raises(static fn () => \PBreeze\Validator::make([], ['x' => ['regex:/[/']]), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make([], ['x' => 'regex:/cat|dog/']), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make(['x' => str_repeat('a', 100) . '!'], [
        'x' => ['regex:~(*NO_START_OPT)(*LIMIT_MATCH=10)^(a+)+$~'],
    ])->validate(), RuntimeException::class);
});

test('Validation nested wildcard output includes only covered leaves and never leaks parent array siblings', static function (): void {
    $data = ['user' => ['name' => 'John', 'admin' => true], 'users' => [
        ['email' => 'john@example.com', 'admin' => true],
        ['email' => 'jane@example.com', 'admin' => true],
    ], 'tags' => ['one', 'two'], 'extra' => 1];
    $rules = ['user.name' => 'required|string', 'users.*.email' => 'required|email', 'users' => 'required|array',
        'user' => 'array', 'tags' => 'array', 'tags.*' => 'string'];
    same(['users' => [['email' => 'john@example.com'], ['email' => 'jane@example.com']],
        'user' => ['name' => 'John'], 'tags' => ['one', 'two']],
        \PBreeze\Validator::make($data, $rules)->validated());
    same(['user' => []], \PBreeze\Validator::make($data, ['user' => 'array'])->validated());
    $data['users'][1]['email'] = 'bad';
    $validator = \PBreeze\Validator::make($data, $rules);
    check($validator->errors()->has('users.1.email'));
    check(!$validator->errors()->has('users.0.email'));
    raises(static fn () => $validator->validated(), ValidationException::class);
    check(\PBreeze\Validator::make(['users' => [[]]], ['users.*.email' => 'required'])->errors()->has('users.0.email'));
    check(\PBreeze\Validator::make(['users' => []], ['users.*.email' => 'required'])->passes());
    check(\PBreeze\Validator::make(['users' => [['password' => 'x', 'password_confirmation' => 'x']]], [
        'users.*.password' => 'confirmed|same:users.*.password_confirmation',
    ])->passes());
    same(['rows' => [3 => ['id' => 1]]], \PBreeze\Validator::make(['rows' => [3 => ['id' => 1, 'secret' => 2]]], [
        'rows.*.id' => 'integer',
    ])->validated());
    same(['one', 'two'], \PBreeze\Validator::make(['one', 'two'], ['*' => 'string'])->validated());
    same([['id' => 1]], \PBreeze\Validator::make([['id' => 1, 'secret' => true]], ['*.id' => 'integer'])->validated());
    check(\PBreeze\Validator::make(['users' => [['type' => 'company']]], [
        'users.*.name' => 'required_if:users.*.type,company',
    ])->errors()->has('users.0.name'));
    same(['matrix' => [[1, 2], [3]]], \PBreeze\Validator::make(['matrix' => [[1, 2], [3]]], [
        'matrix.*.*' => 'integer',
    ])->validated());
});

test('Validation error bags provide stable messages, attribute labels and JSON without input disclosure', static function (): void {
    $validator = \PBreeze\Validator::make(['email' => 'secret-input', 'age' => 17], [
        'email' => 'email', 'age' => 'integer|min:18', 'name' => 'required|string',
    ], ['email.email' => 'Please provide a valid :attribute.', 'min' => ':attribute must be at least :min.'], ['email' => 'email address']);
    same('Please provide a valid email address.', $validator->errors()->first('email'));
    same('age must be at least 18.', $validator->errors()->first('age'));
    same([], $validator->errors()->get('missing'));
    same(null, $validator->errors()->first('missing'));
    same(3, count($validator->errors()->all()));
    same($validator->errors()->toArray(), json_decode(json_encode($validator->errors(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    check(!str_contains(json_encode($validator->errors(), JSON_THROW_ON_ERROR), 'secret-input'));
    same('{}', json_encode(\PBreeze\Validator::make([], [])->errors(), JSON_THROW_ON_ERROR));
    same('The email address field is required.', \PBreeze\Validator::make([], ['email_address' => 'required'], [], [
        'email_address' => 'email address',
    ])->errors()->first());
    same('Row email invalid.', \PBreeze\Validator::make(['users' => [['email' => 'bad']]], ['users.*.email' => 'email'], [
        'users.*.email.email' => 'Row email invalid.',
    ])->errors()->first());
});

test('Validation object rules operate independently, receive full data and propagate infrastructure failures', static function (): void {
    $rule = new class implements RuleInterface {
        public function validate(string $field, mixed $value, array $data): bool
        {
            return $field === 'vat' && $value === 'VAT123' && ($data['country'] ?? '') === 'NL';
        }
        public function message(): string { return 'The :attribute is not a valid VAT number.'; }
    };
    same(['vat' => 'VAT123'], \PBreeze\Validator::make(['vat' => 'VAT123', 'country' => 'NL'], ['vat' => ['required', $rule]])->validated());
    same('The VAT number is not a valid VAT number.', \PBreeze\Validator::make(['vat' => 'bad'], ['vat' => [$rule]], [], [
        'vat' => 'VAT number',
    ])->errors()->first());
    $broken = new class implements RuleInterface {
        public function validate(string $field, mixed $value, array $data): bool { throw new RuntimeException('database unavailable'); }
        public function message(): string { return 'Rejected.'; }
    };
    raises(static fn () => \PBreeze\Validator::make(['x' => 1], ['x' => [$broken]])->validate(), RuntimeException::class);
});

test('Validation rejects bad configuration up front and contains wildcard and object input hazards', static function (): void {
    foreach (['unknown', 'min', 'min:bad', 'min:INF', 'between:2,1', 'length:-1', 'required:yes', 'regex:', 'required_if:type',
        'date_format:', 'before:tomorrow', 'mimes:exe', 'mimetypes:text/*', 'max_size:-1'] as $rule) {
        raises(static fn () => \PBreeze\Validator::make([], ['x' => $rule]), InvalidArgumentException::class);
    }
    raises(static fn () => \PBreeze\Validator::make([], ['a..b' => 'string']), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make([], ['a' => [123]]), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make([], ['a' => []]), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make([], [], ['required' => []]), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make(['rows' => ['a.b' => 1]], ['rows.*' => 'integer'])->validate(), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Validator::make(['x' => new stdClass()], ['x' => 'present'])->validate(), InvalidArgumentException::class);
    $data = ['x' => '<script>alert(1)</script>', 'email' => ['malicious']];
    same(['x' => $data['x']], \PBreeze\Validator::make($data, ['x' => 'string'])->validated());
    check(\PBreeze\Validator::make($data, ['email' => 'email'])->fails());
    $cycle = [];
    $cycle['next'] = &$cycle;
    check(\PBreeze\Validator::make(['x' => $cycle], ['x' => 'array'])->passes());
    same(['x' => []], \PBreeze\Validator::make(['x' => $cycle], ['x' => 'array'])->validated());
    raises(static fn () => \PBreeze\Validator::make([], [implode('.', array_fill(0, 33, 'a')) => 'string']), InvalidArgumentException::class);
    $rules = [];
    for ($index = 0; $index <= 1000; $index++) {
        $rules['field' . $index] = 'string';
    }
    raises(static fn () => \PBreeze\Validator::make([], $rules), InvalidArgumentException::class);
});

test('Validation exception adapters retain the API error envelope and web 422 behavior', static function (): void {
    $request = new \PBreeze\Request('POST', '/api/example', ['email' => 'ignored@example.com'], [
        'user' => ['email' => 'john@example.com', 'admin' => true],
    ]);
    same(['user' => ['email' => 'john@example.com']], $request->validate(['user.email' => 'required|email']));
    same(['user' => ['email' => 'john@example.com']], \PBreeze\Api::validate($request, ['user.email' => 'required|email']));
    try {
        \PBreeze\Validator::make(['email' => 'bad'], ['email' => 'email'])->validate();
        throw new LogicException('Expected validation failure.');
    } catch (ValidationException $error) {
        same('Check your input.', $error->getMessage());
        $response = \PBreeze\Api::errorResponse($error);
        same(422, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        same(422, $body['error']['status']);
        same($error->errors->toArray(), $body['error']['details']);
        same('Check your input.', $body['error']['message']);
        same(422, \PBreeze\Application::errorResponse($error)->status);
    }
    raises(static fn () => \PBreeze\Api::validate($request, ['email' => 'required']), \PBreeze\ApiValidationException::class);
    $application = apiApplication();
    $application->api->add('POST', '/api/validation-check', static function (\PBreeze\Request $request): \PBreeze\Response {
        return \PBreeze\Response::json(['data' => $request->validate(['user.email' => 'required|email'])]);
    }, ['summary' => 'Validation check', 'operationId' => 'validationCheck', 'responses' => ['200' => ['description' => 'OK']]], authenticated: false);
    $response = $application->handle(new \PBreeze\Request('POST', '/api/validation-check', body: ['user' => ['email' => 'bad']]));
    same(422, $response->status);
    check(isset(json_decode($response->body, true, 32, JSON_THROW_ON_ERROR)['error']['details']['user.email']));
});

test('Validation file rules reuse UploadedFile guards and reject spoofed paths and upload failures', static function (): void {
    foreach (['file', 'mimes:txt', 'mimetypes:text/plain', 'max_size:100'] as $rule) {
        check(\PBreeze\Validator::make(['x' => '/etc/passwd'], ['x' => $rule])->fails());
        $file = \PBreeze\UploadedFile::capture(['x' => [
            'name' => 'file.txt', 'type' => 'text/plain', 'tmp_name' => '/etc/passwd', 'size' => 1, 'error' => UPLOAD_ERR_OK,
        ]])['x'][0];
        check(\PBreeze\Validator::make(['x' => $file], ['x' => $rule])->fails());
    }
    $file = \PBreeze\UploadedFile::capture(['x' => [
        'name' => 'file.txt', 'type' => 'text/plain', 'tmp_name' => '', 'size' => 1, 'error' => UPLOAD_ERR_CANT_WRITE,
    ]])['x'][0];
    raises(static fn () => \PBreeze\Validator::make(['x' => $file], ['x' => 'file'])->validate(), RuntimeException::class);
    raises(static fn () => \PBreeze\Validator::make(['x' => $file], ['x' => 'present'])->validate(), InvalidArgumentException::class);
});

test('Validation core loads independently without bootstrap, HTTP or application configuration', static function (): void {
    global $root;
    $source = 'require ' . var_export($root . '/framework/Validator.php', true) . ';'
        . '$result = \\PBreeze\\Validator::make(["age" => "30", "admin" => true], ["age" => "integer|min:18"]);'
        . 'echo json_encode($result->validate(), JSON_THROW_ON_ERROR);'
        . 'if (class_exists("PBreeze\\\\Request", false)) { exit(2); }';
    $process = proc_open([PHP_BINARY, '-r', $source], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    check(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process));
    same('', $errors);
    same('{"age":"30"}', $output);
});

function validationHttp(string $base): void
{
    test('HTTP validation handles real multipart files with trusted MIME/size inspection and safe 422 details', static function () use ($base): void {
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'report.txt', 'contents' => 'Plain text document.', 'type' => 'image/png']]);
        $response = http($base, '/__validation/file', 'POST', $body, $headers);
        same(200, $response['status']);
        same(['name' => 'report.txt'], json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR));
        foreach (['extension', 'mime', 'size'] as $check) {
            $response = http($base, '/__validation/file?check=' . $check, 'POST', $body, $headers);
            same(422, $response['status']);
            $errors = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['error']['details'];
            check(isset($errors['document']));
        }
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'report.txt', 'contents' => '', 'type' => 'text/plain']]);
        same(422, http($base, '/__validation/file', 'POST', $body, $headers)['status']);
    });

    test('HTTP JSON validation converts nested payloads to arrays and prunes unknown wildcard siblings', static function () use ($base): void {
        $headers = ['Content-Type: application/json'];
        $response = http($base, '/__validation/json', 'POST', '{"users":[{"email":"john@example.com","admin":true}],"root_secret":true}', $headers);
        same(200, $response['status']);
        same(['data' => ['users' => [['email' => 'john@example.com']]]], json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR));
        $response = http($base, '/__validation/json', 'POST', '{"users":[{"email":"john@example.com"},{}]}', $headers);
        same(422, $response['status']);
        check(isset(json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['error']['details']['users.1.email']));
    });
}
