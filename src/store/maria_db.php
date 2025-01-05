<?php
declare(strict_types=1);

namespace App\Store;

use PDO;
use PDOException;

function db_query(string $sql, array $args = array()): array {
  static $db = null;

  if ($db === null) {
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $name = getenv('DB_DATABASE');
    $user = getenv('DB_USERNAME');
    $pass = getenv('DB_PASSWORD');

    try {
      $db = new PDO(
        "mysql:host=$host;port=$port;charset=utf8mb4;dbname=$name",
        $user,
        $pass,
        [
          PDO::ATTR_STRINGIFY_FETCHES => true
        ]
      );
    } catch (PDOException $e) {
      exit($e->getMessage());
    }
  }

  $statement = $db->prepare($sql);
  $statement->execute($args);
  return $statement->fetchAll(PDO::FETCH_ASSOC);
}
