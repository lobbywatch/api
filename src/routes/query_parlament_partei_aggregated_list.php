<?php
declare (strict_types=1);

namespace App\Routes;

use Exception;
use function App\Application\table_list;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\json_response;
use function App\Lib\Localization\get_lang_suffix;
use function App\Sql\clean_records;
use function App\Sql\filter_fields_SQL;
use function App\Store\db_query;

function route_query_parlament_partei_aggregated_list($condition = '1') {
  global $show_sql, $show_stacktrace;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;

  try {
    $lang_suffix = get_lang_suffix();

    $table = 'parlamentarier';
    $sql = "
select count(*) as anzahl, partei.id, $table.partei$lang_suffix as partei_short, partei.anzeige_name$lang_suffix as partei_name, $table.fraktion
from v_parlamentarier $table
inner join v_partei partei
on partei.id = $table.partei_id
WHERE $condition " . filter_fields_SQL($table) . "
group by $table.partei
order by count(*) desc, $table.partei asc ";

    $result = db_query($sql, []);

    $items['totalMembers'] = 246;
    $items['parteien'] = clean_records($result);

    $count = count($items['parteien']);
    $message .= $count . " record(s) found";
    $success = $count > 0;

    foreach ($items['parteien'] as &$record) {
      $parlamentarier = table_list('parlamentarier', "parlamentarier.partei_id = {$record['id']}");
      $record['members'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];
      $success = $success && ($parlamentarier['success'] || (!$parlamentarier['success'] && $parlamentarier['data']['members'] == 0));
    }

  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
    json_response($response);
  }
}
