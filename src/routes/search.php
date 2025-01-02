<?php
declare(strict_types=1);

namespace App\Routes;

use Exception;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\json_response;
use function App\Lib\Localization\get_lang_suffix;
use function App\Sql\clean_records;
use function App\Sql\filter_limit_SQL;
use function App\Store\db_query;

function route_search(string $search_str, bool $filter_unpublished = true): never {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';

  $table = 'search_table';

  try {
    $lang_suffix = get_lang_suffix();

    $has_query = mb_strlen($search_str) > 0;

    $conditions = array_filter([isset($_GET['tables']) ? 'table_name IN (' . implode(',', array_map(function ($table) {
        return "'" . preg_replace('/[^A-Za-z0-9_.]+/', '', $table) . "'";
      }, explode(',', $_GET['tables']))) . ')' : '', $has_query ? "search_keywords$lang_suffix LIKE :str" : '', $filter_unpublished ? '(table_name=\'parlamentarier\' OR table_name=\'zutrittsberechtigung\' OR freigabe_datum <= NOW())' : '',]);

    // Show all parlamentarier in search, even if not freigegeben, RKU 22.01.2015
    $sql = "
    SELECT id, page, table_name, name_de, name_fr, table_weight, weight
    -- , freigabe_datum, bis
    FROM v_search_table
    WHERE " . implode($conditions, ' AND ') . "ORDER BY table_weight, weight";

    $sql .= filter_limit_SQL() . ';';

    $result = db_query($sql, array(':str' => search_keyword_processing($search_str)));

    $items = clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found ";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
    json_response($response);
  }
}

// Duplicated from lobbywatch_autocomplete_json.php
function search_keyword_processing($str) {
  $search_str = preg_replace('!\*+!', '%', $str);
  //     $search_str = '%' . db_like($keys) . '%'
  if (!preg_match('/[%_]/', $search_str)) {
    $search_str = "%$search_str%";
  }
  return $search_str;
}
