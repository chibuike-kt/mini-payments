<?php
declare(strict_types=1);

namespace App\Db;

use PDO;

final class Db
{
    public static function pdo(string $path): PDO
    {
        // 1) Create a new PDO connection to SQLite.
        // SQLite is a file-based database. The database is stored in $path.
        $pdo = new PDO('sqlite:' . $path);

        // 2) Throw exceptions when something goes wrong (so bugs are obvious).
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 3) Return the connection so other code can query the DB.
        return $pdo;
    }
}
