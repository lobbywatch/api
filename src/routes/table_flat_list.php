<?php
declare (strict_types=1);

namespace App\Routes;

use function App\Application\table_list;
use function App\Lib\Http\{json_response};

function route_table_flat_list(string $table): never {
  json_response(table_list($table));
}
