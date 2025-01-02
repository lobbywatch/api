<?php
declare (strict_types=1);

use function App\Application\zutrittsberechtigte_aggregated_by_id;
use function App\Lib\Http\json_response;

function route_zutrittsberechtigte_aggregated(string $id): never {
  $result = zutrittsberechtigte_aggregated_by_id($id);
  json_response($result);
}
