<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;
use PBreeze\Database;

final class Mailer
{
    public readonly MailStore $store;

    /** @param array{log?: bool, from?: string, smtp?: array<string, string>, imap?: array<string, string>} $config */
    public function __construct(Database $db, #[\SensitiveParameter] private readonly array $config)
    {
        $this->store = new MailStore($db);
    }

    /** @param array<string, mixed> $data */
    public function compose(array $data): Message
    {
        if (array_diff(array_keys($data), ['from', 'to', 'subject', 'text', 'html']) !== []) {
            throw new InvalidArgumentException('Unknown email field; allowed: from, to, subject, text, html.');
        }
        foreach (['from', 'subject', 'text', 'html'] as $field) {
            if (array_key_exists($field, $data) && !is_string($data[$field])) {
                throw new InvalidArgumentException("Email field $field must be a string.");
            }
        }
        if (!isset($data['to']) || !is_array($data['to'])) {
            throw new InvalidArgumentException('Email field to must be a list of email addresses.');
        }
        foreach ($data['to'] as $recipient) {
            if (!is_string($recipient)) {
                throw new InvalidArgumentException('Each recipient must be an email address string.');
            }
        }
        return new Message(
            $data['from'] ?? $this->config['from'] ?? '',
            $data['to'], $data['subject'] ?? '', $data['text'] ?? '', $data['html'] ?? '',
        );
    }

    public function queue(Message $message): int
    {
        return $this->store->queue($message);
    }

    /** Returns the stored email ID when logging is enabled. */
    public function send(Message $message): ?int
    {
        if ($this->config['log'] ?? true) {
            $id = $this->queue($message);
            $this->sendQueued($id);
            return $id;
        }
        (new Smtp($this->config['smtp'] ?? []))->send($message);
        return null;
    }

    public function sendQueued(int $id): void
    {
        $message = $this->store->claim($id);
        try {
            (new Smtp($this->config['smtp'] ?? []))->send($message);
        } catch (DeliveryException $error) {
            $this->store->fail($id, $error);
            throw $error;
        }
        // Outside the catch: a database error after SMTP acceptance must not suggest a safe retry.
        $this->store->complete($id);
    }

    /** @return list<array<string, mixed>> */
    public function fetch(int $limit = 50): array
    {
        $log = $this->config['log'] ?? true;
        $messages = (new Imap($this->config['imap'] ?? []))->fetch($limit, $log ? $this->store->known(...) : null);
        foreach ($messages as &$message) {
            $message['id'] = $log ? $this->store->receive($message) : null;
        }
        unset($message);
        return $messages;
    }
}
