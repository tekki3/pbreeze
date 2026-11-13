<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE notes (id $id, title VARCHAR(120) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE notes');
    }
};
