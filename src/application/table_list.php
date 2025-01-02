<?php
declare(strict_types=1);

namespace App\Application;

use Exception;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Sql\{clean_records, data_transformation, filter_fields_SQL, filter_unpublished_SQL, select_fields_SQL};
use function App\Store\db_query;

function table_list(string $table, string $condition = '1', string $order_by = '', string $join = '', string $join_select = ''): array {
  global $show_sql, $show_stacktrace;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;

  try {
    $sql = "
    SELECT " . select_fields_SQL($table) . "
    $join_select
    FROM v_$table $table
    $join
    WHERE $condition " . filter_unpublished_SQL($table) . filter_fields_SQL($table) . " $order_by" . _lobbywatch_data_filter_limit_SQL() . ';';

    $result = db_query($sql, []);

    $items = clean_records($result);

    data_transformation($table, $items);

    $count = count($items);
    $success = $count > 0;
    $message = $count . " record(s) found";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    return api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
  }
}
