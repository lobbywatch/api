<?php
declare(strict_types=1);

namespace App\domain\ApiResponse;

use function App\Lib\Http\check_plain;
use function App\Lib\Http\request_uri;
use function App\Lib\Metrics\page_build_secs;

function api_response(bool $success, int $count, string $message, string $sql, string $source, array|null $data): array {
  return array(
    'success' => $success,
    'count' => $count,
    'message' => "$count record(s) found",
    'sql' => $sql,
    'source' => $source,
    'build secs' => page_build_secs(),
    'data' => $data
  );
}

function failed_api_response(string $message): array {
  return api_response(false, 0, $message, '', '', null);
}

function not_found_response(): array {
  return failed_api_response(
    '404 Not Found. The requested URL "' . check_plain(request_uri()) . '" was not found on this server.',
  );
}

function forbidden_response(): array {
  return failed_api_response(
    '403 Forbidden. The requested URL "' . check_plain(request_uri()) . '" is protected.',
  );
}
