<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $payload = match ($driver) {
            'mysql' => 'LONGTEXT',
            'sqlsrv' => 'VARCHAR(MAX)',
            default => 'TEXT',
        };
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE sessions (
            id CHAR(64) PRIMARY KEY,
            payload $payload NOT NULL,
            last_activity BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )$suffix");
        $db->exec('CREATE INDEX sessions_expiry ON sessions (expires_at)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE sessions');
    }
};
