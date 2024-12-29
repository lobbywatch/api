<?php
declare(strict_types=1);

namespace App\Application;

use Exception;
use function App\Lib\Http\{add_exception};
use function App\Lib\Metrics\{page_build_secs};
use function App\Sql\{clean_records, data_transformation, filter_fields_SQL, filter_unpublished_SQL, select_fields_SQL};
use function App\Store\{db_query};

function table_by_id($table, $id, $show_sql = false, $show_stacktrace = false) {
  $success = false;
  $count = 0;
  $items = null;
  $message = '';

  try {
    $fields = select_fields_SQL($table);
    $filter_unpublished = (in_array($table, array('parlamentarier', 'zutrittsberechtigung'))
      ? ''
      : filter_unpublished_SQL($table));
    $filter_fields = filter_fields_SQL($table);

    $sql = <<<SQL
      SELECT $fields
      FROM v_$table $table
      WHERE $table.id=:id
      $filter_unpublished
      $filter_fields
      SQL;

    $result = db_query($sql, array(':id' => $id));

    $items = clean_records($result);

    data_transformation($table, $items);

    $count = count($items);
    $success = $count == 1;
    $message .= count($items) . " record(s) found";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
  } finally {
    $response = array(
      'success' => $success,
      'count' => $count,
      'message' => $message,
      'sql' => $show_sql ? preg_replace('/\s+/', ' ', $sql) : '',
      'source' => $table,
      'build secs' => page_build_secs(),
      'data' => $success ? $items[0] : null,
    );

    return $response;
  }
}
