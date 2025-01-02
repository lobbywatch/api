<?php
declare (strict_types=1);

namespace App\Routes;

use App\Constants;
use Exception;
use function App\domain\ApiResponse\api_response;
use function App\Domain\IdentityAccess\user_access;
use function App\Lib\Http\{add_exception, json_response};
use function App\Sql\{clean_records, filter_fields_SQL, filter_unpublished_SQL, select_fields_SQL};
use function App\Store\db_query;

function route_table_flat_list_search(string $table, string $search_str): never {
  global $show_sql, $show_stacktrace;

  $success = true;
  $count = 0;
  $items = null;
  $message = '';

  try {
    if ($table === 'parlamentarier') {
      //TODO test check permissions
      $sql = "SELECT " . select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . (!(isset($_GET['includeInactive']) && $_GET['includeInactive'] != 0 && user_access('access lobbywatch unpublished content')) ? ' AND (im_rat_bis IS NULL OR im_rat_bis > NOW())' : '') . filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else if ($table === 'zutrittsberechtigung') {
      $sql = "SELECT " . select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . (!(isset($_GET['includeInactive']) && $_GET['includeInactive'] != 0 && !user_access('access lobbywatch unpublished content')) ? ' AND (bis IS NULL OR bis > NOW())' : '') . filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else if (in_array($table, Constants::$entities_web)) {
      $sql = "SELECT " . select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else {
      throw new Exception("Table $table does not exist");
    }
    $sql .= _lobbywatch_data_filter_limit_SQL() . ';';
    $result = db_query($sql, array(':str' => "%$search_str%"));

    $items = clean_records($result);
    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
    json_response($response);
  }
}
