<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $db->exec("CREATE TABLE emails (
            id $id,
            direction VARCHAR(10) NOT NULL,
            status VARCHAR(16) NOT NULL,
            from_address TEXT NOT NULL,
            to_addresses TEXT NOT NULL,
            subject TEXT NOT NULL,
            text_body $text NOT NULL,
            html_body $text NOT NULL,
            message_id TEXT NOT NULL,
            headers_json $text NOT NULL,
            raw_message $text NOT NULL,
            source_key CHAR(64) NULL UNIQUE,
            mailbox VARCHAR(255) NULL,
            imap_uid VARCHAR(20) NULL,
            imap_uidvalidity VARCHAR(20) NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at VARCHAR(25) NOT NULL,
            updated_at VARCHAR(25) NOT NULL,
            sent_at VARCHAR(25) NULL
        )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        $db->exec('CREATE INDEX emails_queue ON emails (direction, status, id)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE emails');
    }
};
