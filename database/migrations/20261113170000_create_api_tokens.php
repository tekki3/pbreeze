<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreign = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE api_tokens (
            id $id,
            user_id $foreign NOT NULL,
            name VARCHAR(80) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            session_version INTEGER NOT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$suffix");
        $db->exec('CREATE INDEX api_tokens_user ON api_tokens (user_id, id)');
        $db->exec("CREATE TABLE api_limits (
            bucket CHAR(64) PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL
        )$suffix");
        $db->exec('CREATE INDEX api_limits_expiry ON api_limits (expires_at)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE api_limits');
        $db->exec('DROP TABLE api_tokens');
    }
};
