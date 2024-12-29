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

/**
 * Returns the equivalent of Apache's $_SERVER['REQUEST_URI'] variable.
 *
 * Because $_SERVER['REQUEST_URI'] is only available on Apache, we generate an equivalent using
 * other environment variables.
 *
 * @see https://api.drupal.org/api/drupal/includes%21bootstrap.inc/function/request_uri/7.x
 */
function request_uri(): string {
  if (isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
  } else {
    if (isset($_SERVER['argv'])) {
      $uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['argv'][0];
    } elseif (isset($_SERVER['QUERY_STRING'])) {
      $uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['QUERY_STRING'];
    } else {
      $uri = $_SERVER['SCRIPT_NAME'];
    }
  }
  // Prevent multiple slashes to avoid cross site requests via the Form API.
  $uri = '/' . ltrim($uri, '/');
  return $uri;
}

/**
 * Encodes special characters in a plain-text string for display as HTML.
 *
 * Also validates strings as UTF-8 to prevent cross site scripting attacks on Internet Explorer 6.
 *
 * @see https://api.drupal.org/api/drupal/includes%21bootstrap.inc/function/check_plain/7.x
 */
function check_plain(mixed $text): string {
  return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function json_response(mixed $response = null, int $response_code = 200, bool $cors = true): never {
  global $no_cors; // Global scope is only within one request
  if ($cors && empty($no_cors)) { // Should we disable cors?
    header('Access-Control-Allow-Origin: *');
  }
  header("Content-Type: application/json; charset=UTF-8");
  http_response_code($response_code);
  echo json_encode($response);
  exit();
}

function add_exception($e, $show_stacktrace = false) {
  return $show_stacktrace ? $e->getMessage() . "\n------\n" . $e->getTraceAsString() : $e->getMessage();
}
