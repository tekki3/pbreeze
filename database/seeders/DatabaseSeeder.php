<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $title = 'Welcome to PBreeze';
    $query = $db->prepare('SELECT id FROM notes WHERE title = ?');
    $query->execute([$title]);
    if ($query->fetchColumn() === false) {
        $db->prepare('INSERT INTO notes (title) VALUES (?)')->execute([$title]);
    }
};
