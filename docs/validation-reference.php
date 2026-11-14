<?php
declare(strict_types=1);

return [
    'PBreeze\\ValidationResult' => [
        'description' => 'HTTP-independent lazy validation operation returned by Validator::make(). Normalizes rules once and caches one evaluation. Validated output contains only covered leaves; arrays are containers, not permission to copy arbitrary children. Does not coerce, sanitize, escape, authorize or automatically persist values.',
        'setup' => '$validator = \\PBreeze\\Validator::make($data, ["name" => "required|string|max:100"]);',
        'methods' => [
            '__construct' => ['Normalize array input, field rules, message overrides and attribute labels. Prefer Validator::make(). The legacy flag is internal compatibility support for Validator::errors(), not the modern API. Invalid rules/configuration throw before evaluating data; at most 1000 field definitions, paths up to 32 segments/512 bytes and 100000 wildcard expansion steps.', '$validator = new \\PBreeze\\ValidationResult($data, ["name" => ["required", "string"]]);'],
            'passes' => ['Evaluate at most once and return true when no field errors exist. Custom rule infrastructure failures and invalid configuration propagate, not validation success.', 'if ($validator->passes()) { $data = $validator->validated(); }'],
            'fails' => ['Return true when errors exist; reuses the same cached operation as passes()/errors()/validate().', 'if ($validator->fails()) { $errors = $validator->errors()->toArray(); }'],
            'errors' => ['Return an immutable ValidationErrors object with all()/first()/get()/has()/toArray() and JSON serialization. Keys are concrete dot paths, including wildcard indexes.', '$message = $validator->errors()->first("users.1.email");'],
            'validated' => ['Return filtered nested arrays with original scalar types, present nulls and file objects accepted by explicit file rules; no unrelated keys. All array containers start empty and only explicitly covered descendants are copied. Missing optional fields are omitted. On any failure throw ValidationException instead of returning partial data.', '$data = $validator->validated();'],
            'validate' => ['Alias for validated(): filtered output on success, ValidationException on any field error. Suitable for controller, CLI, imported-array and mapped DTO/model input.', '$data = \\PBreeze\\Validator::make($input, $rules)->validate();'],
        ],
    ],
    'PBreeze\\ValidationErrors' => [
        'description' => 'Immutable field-to-message-list error bag, independent of HTTP. Messages are plain text and still require escaping in HTML. JSON serialization always uses an object, including an empty bag.',
        'setup' => '$errors = $validator->errors();',
        'methods' => [
            '__construct' => ['Wrap a field => list-of-strings map. Normally obtained from ValidationResult rather than manually constructed.', '$errors = new \\PBreeze\\ValidationErrors(["email" => ["Enter a valid email address."]]);'],
            'all' => ['Return a flat list of all messages, preserving validation order.', '$messages = $errors->all();'],
            'first' => ['Return the first message for an exact concrete field, or the first message overall when no field is supplied. Return null if no matching message exists.', '$message = $errors->first("email");'],
            'get' => ['Return the message list for an exact concrete field, or [] when absent. Does not expand wildcard queries.', '$messages = $errors->get("users.1.email");'],
            'has' => ['Return whether an exact concrete field has messages.', '$invalid = $errors->has("email");'],
            'toArray' => ['Return the field => message-list map for existing forms, logs with an appropriate disclosure policy, or API integration.', '$map = $errors->toArray();'],
            'jsonSerialize' => ['Return an object representation for json_encode(), keeping {} for an empty bag. Do not include original input automatically.', '$json = json_encode($errors, JSON_THROW_ON_ERROR);'],
        ],
    ],
    'PBreeze\\ValidationException' => [
        'description' => 'A core validation failure carries readonly ValidationErrors, never original input. The existing API adapter renders status/message/details JSON with HTTP 422; the web adapter uses a generic plain-text 422. Catch it in form controllers only when custom field rendering is needed.',
        'setup' => '',
        'methods' => [
            '__construct' => ['Construct a validation failure with the safe generic message Check your input. Normally thrown by validated()/validate().', 'throw new \\PBreeze\\ValidationException($validator->errors());'],
        ],
    ],
];
