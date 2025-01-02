<?php
declare(strict_types=1);

namespace App\domain\ApiResponse;

use function App\Lib\Metrics\page_build_secs;

function mkApiResponse(bool $success, int $count, string $message, string $sql, string $source, array|null $data): array {
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

function mkFailedApiResponse(string $message): array {
  return mkApiResponse(false, 0, $message, '', '', null);
}
