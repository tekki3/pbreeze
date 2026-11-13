<?php
declare(strict_types=1);

namespace PBreeze\Mail;

use InvalidArgumentException;
use RuntimeException;

final class MailConsole
{
    public function __construct(private readonly Mailer $mail)
    {
    }

    public function run(string $command, ?string $argument, bool $force): void
    {
        switch ($command) {
            case 'mail:queue':
                if ($argument === null || !is_file($argument) || !is_readable($argument)
                    || filesize($argument) > 6 * 1024 * 1024) {
                    throw new InvalidArgumentException('Provide a readable JSON message file no larger than 6 MiB.');
                }
                $json = file_get_contents($argument);
                if ($json === false) {
                    throw new RuntimeException('Cannot read the message file.');
                }
                $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data) || array_is_list($data)) {
                    throw new InvalidArgumentException('The message file must contain a JSON object.');
                }
                $id = $this->mail->queue($this->mail->compose($data));
                echo "Queued: $id\n";
                break;
            case 'mail:send':
                $id = self::number($argument, null, PHP_INT_MAX);
                $this->mail->sendQueued($id);
                echo "SMTP accepted email $id.\n";
                break;
            case 'mail:work':
                $ids = $this->mail->store->pending(self::number($argument, 50, 1000));
                foreach ($ids as $id) {
                    $this->mail->sendQueued($id);
                    echo "SMTP accepted email $id.\n";
                }
                echo 'Processed: ' . count($ids) . "\n";
                break;
            case 'mail:list':
                self::json($this->mail->store->listing(self::number($argument, 50, 1000)));
                break;
            case 'mail:show':
                self::json($this->mail->store->find(self::number($argument, null, PHP_INT_MAX)));
                break;
            case 'mail:retry':
                $id = self::number($argument, null, PHP_INT_MAX);
                if ($force) {
                    fwrite(STDERR, "Warning: recovering uncertain/sending messages can cause duplicate delivery. Stop active workers first.\n");
                }
                $this->mail->store->retry($id, $force);
                echo "Requeued: $id\n";
                break;
            case 'mail:fetch':
                $messages = $this->mail->fetch(self::number($argument, 50, 1000));
                self::json(array_map(static fn (array $message): array => [
                    'id' => $message['id'],
                    'uid' => $message['uid'],
                    'subject' => $message['message']['subject'],
                    'stored' => $message['id'] !== null,
                ], $messages));
                break;
            default:
                throw new InvalidArgumentException('Unknown email command.');
        }
    }

    private static function number(?string $value, ?int $default, int $max): int
    {
        if ($value === null && $default !== null) {
            return $default;
        }
        if ($value === null || !ctype_digit($value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]) === false) {
            throw new InvalidArgumentException("Use an integer between 1 and $max.");
        }
        return (int) $value;
    }

    /** @param array<mixed> $data */
    private static function json(array $data): void
    {
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
