<?php
declare(strict_types=1);

namespace App\Lib\Http;

/**
 * The root URL of the host, excluding the path.
 */
function base_root(): string {
  $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'];
  return "$scheme://$host";
}

function json_response(mixed $response = null, bool $cors = true): never {
  global $no_cors; // Global scope is only within one request
  if ($cors && empty($no_cors)) { // Should we disable cors?
    header('Access-Control-Allow-Origin: *');
  }
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($response);
  exit();
}

