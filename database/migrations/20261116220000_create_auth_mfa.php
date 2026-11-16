<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $foreign = $driver === 'mysql' ? 'BIGINT UNSIGNED' : ($driver === 'sqlite' ? 'INTEGER' : 'BIGINT');
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE auth_mfa (
            user_id $foreign PRIMARY KEY,
            secret VARCHAR(255) NOT NULL,
            last_counter BIGINT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$suffix");
        $db->exec("CREATE TABLE auth_mfa_recovery (
            user_id $foreign NOT NULL,
            code_hash CHAR(64) NOT NULL,
            PRIMARY KEY (user_id, code_hash),
            FOREIGN KEY (user_id) REFERENCES auth_mfa(user_id) ON DELETE CASCADE
        )$suffix");
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE auth_mfa_recovery');
        $db->exec('DROP TABLE auth_mfa');
    }
};
