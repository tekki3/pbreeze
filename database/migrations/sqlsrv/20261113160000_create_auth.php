<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE users (
            id BIGINT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(80) NOT NULL,
            email VARCHAR(254) COLLATE Latin1_General_100_BIN2 NOT NULL UNIQUE,
            password_hash VARCHAR(255) COLLATE Latin1_General_100_BIN2 NOT NULL,
            email_verified_at BIGINT NULL,
            session_version INTEGER NOT NULL DEFAULT 1,
            created_at BIGINT NOT NULL
        )');
        $db->exec('CREATE TABLE auth_tokens (
            token_hash CHAR(64) COLLATE Latin1_General_100_BIN2 PRIMARY KEY,
            user_id BIGINT NOT NULL,
            purpose VARCHAR(12) COLLATE Latin1_General_100_BIN2 NOT NULL,
            expires_at BIGINT NOT NULL,
            UNIQUE (user_id, purpose),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $db->exec('CREATE TABLE auth_limits (
            bucket CHAR(64) COLLATE Latin1_General_100_BIN2 PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL
        )');
        $db->exec('CREATE INDEX auth_limits_expiry ON auth_limits (expires_at)');
        $db->exec('CREATE INDEX auth_tokens_expiry ON auth_tokens (expires_at)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE auth_limits');
        $db->exec('DROP TABLE auth_tokens');
        $db->exec('DROP TABLE users');
    }
};
