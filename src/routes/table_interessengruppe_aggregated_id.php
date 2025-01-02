<?php
declare (strict_types=1);

namespace App\Routes;

use Exception;
use function App\Application\table_by_id;
use function App\Application\table_list;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\json_response;

function table_interessengruppe_aggregated_id($id, $json = true) {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'interessengruppe';

  try {
    $interessengruppe = table_by_id($table, $id);
    $aggregated = $interessengruppe['data'];
    $message .= ' | ' . $interessengruppe['message'];
    $sql .= ' | ' . $interessengruppe['sql'];
    $success = $success && $interessengruppe['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $organisationen = table_list('organisation', "organisation.interessengruppe_id = $id OR organisation.interessengruppe2_id = $id OR organisation.interessengruppe3_id = $id");

      $aggregated['organisationen'] = $organisationen['data'];
      $message .= ' | ' . $organisationen['message'];
      $sql .= ' | ' . $organisationen['sql'];

      $aggregated_parlamentarier = _lobbywatch_data_get_parlamentarier_from_organisation($organisationen['data']);
      $aggregated = array_merge($aggregated, $aggregated_parlamentarier);

      $zwischen_organisationen_conditions = array_map(function ($con) {
        return "organisation.id = " . $con['zwischen_organisation_id'];
      }, array_filter($aggregated['connections'], function ($con) {
        return !empty($con['zwischen_organisation_id']);
      }));
      $zwischen_organisationen_conditions = !empty($zwischen_organisationen_conditions) ? $zwischen_organisationen_conditions : ['1=0'];

      $zwischen_organisationen = table_list('organisation', "(" . implode(" OR ", $zwischen_organisationen_conditions) . ")");

      $aggregated['zwischen_organisationen'] = $zwischen_organisationen['data'];
      $message .= ' | ' . $zwischen_organisationen['message'];
      $sql .= ' | ' . $zwischen_organisationen['sql'];

      $zutrittsberechtigte_conditions = array_map(function ($con) {
        return "zutrittsberechtigung.person_id = " . $con['person_id'];
      }, array_filter($aggregated['connections'], function ($con) {
        return !empty($con['person_id']);
      }));
      $zutrittsberechtigte_conditions = !empty($zutrittsberechtigte_conditions) ? $zutrittsberechtigte_conditions : ['1=0'];

      $zutrittsberechtigte = table_list('zutrittsberechtigung', "(" . implode(" OR ", $zutrittsberechtigte_conditions) . ")");

      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigte['data'];
      $message .= ' | ' . $zutrittsberechtigte['message'];
      $sql .= ' | ' . $zutrittsberechtigte['sql'];

//      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organisationen['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    json_response($response);
  }
}
