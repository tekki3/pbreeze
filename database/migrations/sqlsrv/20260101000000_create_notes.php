<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE notes (
            id BIGINT IDENTITY(1,1) PRIMARY KEY,
            title NVARCHAR(120) NOT NULL,
            created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
        )');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE notes');
    }
};
