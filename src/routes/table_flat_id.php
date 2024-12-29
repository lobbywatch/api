<?php
declare (strict_types=1);

namespace App\Routes;

use function App\Application\table_by_id;
use function App\Lib\Http\json_response;

function route_table_flat_id($table, $id): never {
  global $show_sql, $show_stacktrace;
  $response = table_by_id($table, $id, $show_sql, $show_stacktrace);
  json_response($response);
}
