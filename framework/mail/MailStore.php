<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;
use PBreeze\Database;
use RuntimeException;

final class MailStore
{
    public function __construct(private readonly Database $db)
    {
    }

    public function queue(Message $message): int
    {
        $this->db->execute(
            "INSERT INTO emails (direction, status, from_address, to_addresses, subject, text_body, html_body,
                message_id, headers_json, raw_message, created_at, updated_at)
             VALUES ('outgoing', 'queued', ?, ?, ?, ?, ?, ?, '{}', '', ?, ?)",
            [
                $message->from, json_encode($message->to, JSON_THROW_ON_ERROR), $message->subject,
                $message->text, $message->html, $message->messageId, self::now(), self::now(),
            ],
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{source_key: string, uid: string, uidvalidity: string, mailbox: string, raw: string, message: array<string, mixed>} $incoming */
    public function receive(array $incoming): int
    {
        $message = $incoming['message'];
        try {
            $this->db->execute(
                "INSERT INTO emails (direction, status, from_address, to_addresses, subject, text_body, html_body,
                    message_id, headers_json, raw_message, source_key, mailbox, imap_uid, imap_uidvalidity, created_at, updated_at)
                 VALUES ('incoming', 'received', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $message['from'], json_encode([$message['to']], JSON_THROW_ON_ERROR), $message['subject'],
                    $message['text'], $message['html'], $message['message_id'],
                    json_encode($message['headers'], JSON_THROW_ON_ERROR), base64_encode($incoming['raw']),
                    $incoming['source_key'], $incoming['mailbox'], $incoming['uid'], $incoming['uidvalidity'],
                    self::now(), self::now(),
                ],
            );
        } catch (\PDOException $error) {
            // Concurrent imports of the same UID are allowed; other database errors remain errors.
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                $existing = $this->db->execute('SELECT id FROM emails WHERE source_key = ?', [$incoming['source_key']])->fetchColumn();
                if ($existing !== false) {
                    return (int) $existing;
                }
            }
            throw $error;
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function known(string $sourceKey): bool
    {
        return $this->db->execute('SELECT id FROM emails WHERE source_key = ?', [$sourceKey])->fetchColumn() !== false;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Email ID must be positive.');
        }
        $row = $this->db->execute('SELECT * FROM emails WHERE id = ?', [$id])->fetch();
        if ($row === false) {
            throw new RuntimeException("Email does not exist: $id");
        }
        return $row;
    }

    public function claim(int $id): Message
    {
        $row = $this->find($id);
        if ($row['direction'] !== 'outgoing' || $row['status'] !== 'queued') {
            throw new RuntimeException('Only outgoing emails with queued status can be sent.');
        }
        $message = new Message(
            $row['from_address'], json_decode($row['to_addresses'], true, 32, JSON_THROW_ON_ERROR),
            $row['subject'], $row['text_body'], $row['html_body'], $row['message_id'],
        );
        $changed = $this->db->execute(
            "UPDATE emails SET status = 'sending', attempts = attempts + 1, last_error = NULL, updated_at = ?
             WHERE id = ? AND direction = 'outgoing' AND status = 'queued'",
            [self::now(), $id],
        )->rowCount();
        if ($changed !== 1) {
            throw new RuntimeException('Another process has already claimed this email.');
        }
        return $message;
    }

    public function complete(int $id): void
    {
        $this->transition($id, 'sent', null, self::now());
    }

    public function fail(int $id, DeliveryException $error): void
    {
        $this->transition($id, $error->uncertain ? 'uncertain' : 'failed', $error->getMessage(), null);
    }

    public function retry(int $id, bool $force = false): void
    {
        $row = $this->find($id);
        $allowed = $force ? ['failed', 'uncertain', 'sending'] : ['failed'];
        if ($row['direction'] !== 'outgoing' || !in_array($row['status'], $allowed, true)) {
            throw new RuntimeException('Only failed messages can be requeued; uncertain/sending requires explicit --force.');
        }
        if ($this->db->execute(
            "UPDATE emails SET status = 'queued', updated_at = ? WHERE id = ? AND status = ? AND direction = 'outgoing'",
            [self::now(), $id, $row['status']],
        )->rowCount() !== 1) {
            throw new RuntimeException('The email status was changed concurrently.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function listing(int $limit = 50): array
    {
        self::limit($limit);
        return $this->db->execute(
            'SELECT id, direction, status, subject, attempts, last_error, created_at, sent_at FROM emails ORDER BY id DESC LIMIT ' . $limit,
        )->fetchAll();
    }

    /** @return list<int> */
    public function pending(int $limit): array
    {
        self::limit($limit);
        $rows = $this->db->execute(
            "SELECT id FROM emails WHERE direction = 'outgoing' AND status = 'queued' ORDER BY id LIMIT " . $limit,
        )->fetchAll();
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function transition(int $id, string $status, ?string $error, ?string $sentAt): void
    {
        if ($this->db->execute(
            "UPDATE emails SET status = ?, last_error = ?, sent_at = ?, updated_at = ? WHERE id = ? AND status = 'sending'",
            [$status, $error, $sentAt, self::now(), $id],
        )->rowCount() !== 1) {
            throw new RuntimeException('Cannot save the delivery status; check the SMTP server before resending.');
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Use a limit between 1 and 1000.');
        }
    }
}
