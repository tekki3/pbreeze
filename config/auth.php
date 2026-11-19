<?php
declare(strict_types=1);

use PBreeze\Env;

$policy = [];
foreach (['mfa_enrollment_enabled' => 'AUTH_MFA_ENROLLMENT_ENABLED', 'mfa_enforcement_enabled' => 'AUTH_MFA_ENFORCEMENT_ENABLED'] as $setting => $key) {
    $value = strtolower(Env::get($key, 'true'));
    if (!in_array($value, ['true', 'false'], true)) {
        throw new RuntimeException("$key must be true or false.");
    }
    $policy[$setting] = $value === 'true';
}

return [
    'email_composer' => null,
] + $policy;
