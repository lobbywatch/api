<?php
declare (strict_types=1);

namespace App\Routes;

use Exception;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\{add_exception, json_response};
use function App\Sql\{clean_records, filter_fields_SQL, filter_unpublished_SQL, select_fields_SQL};
use function App\Store\db_query;

function route_relation_flat_list(string $table, string $condition = '1'): never {
  global $show_sql, $show_stacktrace;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;


  try {
    $sql = "
    SELECT " . select_fields_SQL($table) . "
    FROM v_$table $table
    WHERE $condition " . filter_unpublished_SQL($table) . filter_fields_SQL($table) . _lobbywatch_data_filter_limit_SQL() . ';';

    $result = db_query($sql, []);

    $items = clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message = $count . " record(s) found";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    json_response(api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items));
  }
}
