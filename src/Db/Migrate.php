<?php

declare(strict_types=1);

namespace App\Db;

use PDO;

final class Migrate
{
  public static function run(PDO $pdo, string $schemaFile): void
  {
    // 1) Read the SQL schema file as text.
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
      throw new \RuntimeException("Cannot read schema file: " . $schemaFile);
    }

    // 2) Execute the SQL.
    // This creates all tables.
    $pdo->exec($sql);
  }
}
