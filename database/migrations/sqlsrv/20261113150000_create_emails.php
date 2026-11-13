<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE emails (
            id BIGINT IDENTITY(1,1) PRIMARY KEY,
            direction VARCHAR(10) NOT NULL,
            status VARCHAR(16) NOT NULL,
            from_address NVARCHAR(MAX) NOT NULL,
            to_addresses NVARCHAR(MAX) NOT NULL,
            subject NVARCHAR(MAX) NOT NULL,
            text_body NVARCHAR(MAX) NOT NULL,
            html_body NVARCHAR(MAX) NOT NULL,
            message_id NVARCHAR(MAX) NOT NULL,
            headers_json NVARCHAR(MAX) NOT NULL,
            raw_message NVARCHAR(MAX) NOT NULL,
            source_key CHAR(64) COLLATE Latin1_General_100_BIN2 NULL,
            mailbox NVARCHAR(255) NULL,
            imap_uid VARCHAR(20) NULL,
            imap_uidvalidity VARCHAR(20) NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error NVARCHAR(MAX) NULL,
            created_at VARCHAR(25) NOT NULL,
            updated_at VARCHAR(25) NOT NULL,
            sent_at VARCHAR(25) NULL
        )');
        $db->exec('CREATE INDEX emails_queue ON emails (direction, status, id)');
        $db->exec('CREATE UNIQUE INDEX emails_source_key ON emails (source_key) WHERE source_key IS NOT NULL');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE emails');
    }
};
