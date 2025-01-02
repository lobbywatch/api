<?php
declare (strict_types=1);

namespace App\Routes;

use Exception;
use function App\Application\table_by_id;
use function App\Application\table_list;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\json_response;

function route_table_branche_aggregated_id(string $id): array {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'branche';

  try {
    $branche = table_by_id($table, $id);
    $aggregated = $branche['data'];
    $message .= ' | ' . $branche['message'];
    $sql .= ' | ' . $branche['sql'];
    $success = $success && $branche['success'];

    // load aggregated data only if main object is there
    if ($success) {
      // interessengruppe
      $interessengruppe = table_list('interessengruppe', "interessengruppe.branche_id = $id");

      $aggregated['interessengruppe'] = $interessengruppe['data'];
      $message .= ' | ' . $interessengruppe['message'];
      $sql .= ' | ' . $interessengruppe['sql'];
      $success = $success && $interessengruppe['success'];

      // organisations
      $organisationen = table_list('organisation', "organisation.interessengruppe_branche_id = $id OR organisation.interessengruppe2_branche_id = $id OR organisation.interessengruppe3_branche_id = $id");

      $aggregated['organisationen'] = $organisationen['data'];
      $message .= ' | ' . $organisationen['message'];
      $sql .= ' | ' . $organisationen['sql'];
      $success = $success && $organisationen['success'];

      // parlamentarier
      $aggregated_parlamentarier = _lobbywatch_data_get_parlamentarier_from_organisation($organisationen['data']);
      $aggregated = array_merge($aggregated, $aggregated_parlamentarier);

//      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $branche['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    json_response($response);
  }
}
