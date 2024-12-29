<?php
declare(strict_types=1);

namespace App\Lib\Http;

function json_response(mixed $response = null, bool $cors = true): never {
  global $no_cors; // Global scope is only within one request
  if ($cors && empty($no_cors)) { // Should we disable cors?
    header('Access-Control-Allow-Origin: *');
  }
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($response);
  exit();
}
