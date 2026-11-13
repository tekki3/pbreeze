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
            throw new InvalidArgumentException('Onbekend e-mailveld; toegestaan: from, to, subject, text, html.');
        }
        foreach (['from', 'subject', 'text', 'html'] as $field) {
            if (array_key_exists($field, $data) && !is_string($data[$field])) {
                throw new InvalidArgumentException("E-mailveld $field moet tekst zijn.");
            }
        }
        if (!isset($data['to']) || !is_array($data['to'])) {
            throw new InvalidArgumentException('E-mailveld to moet een lijst e-mailadressen zijn.');
        }
        foreach ($data['to'] as $recipient) {
            if (!is_string($recipient)) {
                throw new InvalidArgumentException('Elke ontvanger moet een e-mailadres als tekst zijn.');
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

    /** Geeft bij ingeschakelde logging het opgeslagen e-mail-ID terug. */
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
        // Niet in de catch: een DB-fout na SMTP-acceptatie mag geen veilige retry suggereren.
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
