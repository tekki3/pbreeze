<?php
declare(strict_types=1);

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(\PBreeze\Session $session): string
{
    return '<input type="hidden" name="_token" value="' . e($session->token()) . '">';
}
