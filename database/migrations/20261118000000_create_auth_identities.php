<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $foreign = $driver === 'mysql' ? 'BIGINT UNSIGNED' : ($driver === 'sqlite' ? 'INTEGER' : 'BIGINT');
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE auth_identities (
            identity_hash CHAR(64) PRIMARY KEY,
            issuer VARCHAR(2048) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            user_id $foreign NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$suffix");
        $db->exec('CREATE INDEX auth_identities_user ON auth_identities (user_id)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE auth_identities');
    }
};
