<?php
declare(strict_types=1);

use PBreeze\Env;

return [
    'log' => Env::bool('MAIL_LOG', true),
    'from' => Env::get('MAIL_FROM', 'noreply@example.test'),
    'smtp' => [
        'host' => Env::get('SMTP_HOST'),
        'port' => Env::get('SMTP_PORT', '587'),
        'encryption' => Env::get('SMTP_ENCRYPTION', 'starttls'),
        'username' => Env::get('SMTP_USERNAME'),
        'password' => Env::get('SMTP_PASSWORD'),
        'timeout' => Env::get('SMTP_TIMEOUT', '15'),
        'ca_file' => Env::get('SMTP_CA_FILE'),
    ],
    'imap' => [
        'host' => Env::get('IMAP_HOST'),
        'port' => Env::get('IMAP_PORT', '993'),
        'encryption' => Env::get('IMAP_ENCRYPTION', 'tls'),
        'username' => Env::get('IMAP_USERNAME'),
        'password' => Env::get('IMAP_PASSWORD'),
        'timeout' => Env::get('IMAP_TIMEOUT', '15'),
        'ca_file' => Env::get('IMAP_CA_FILE'),
        'mailbox' => Env::get('IMAP_MAILBOX', 'INBOX'),
        'max_bytes' => Env::get('IMAP_MAX_BYTES', '10485760'),
    ],
];
