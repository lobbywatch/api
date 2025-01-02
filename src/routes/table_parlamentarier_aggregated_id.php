<?php
declare (strict_types=1);

namespace App\Routes;

use function App\Application\{table_by_id, table_list, zutrittsberechtigte_aggregated_by_id};
use function App\domain\ApiResponse\api_response;
use function App\Lib\{Array\max_attribute_in_array, Http\add_exception, Http\json_response};
use function App\Sql\add_verguetungen;

function route_table_parlamentarier_aggregated_id(string $id): array {
  global $show_sql, $show_stacktrace;
  global $env;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'parlamentarier';

  try {
    $message .= "$env";
    $parlamentarier = table_by_id('parlamentarier', $id);
    $aggregated = $parlamentarier['data'];
    $message .= ' | ' . $parlamentarier['message'];
    $sql .= ' | ' . $parlamentarier['sql'];
    $success = $success && $parlamentarier['success'];

    $last_modified_date = $aggregated['updated_date'];
    $last_modified_date_unix = $aggregated['updated_date_unix'];

    // load aggregated data only if main object is there
    if ($success) {
      $in_kommissionen = table_list('in_kommission_liste', "in_kommission_liste.parlamentarier_id = $id");
      $aggregated['in_kommission'] = $in_kommissionen['data'];
      $message .= ' | ' . $in_kommissionen['message'];
      $sql .= ' | ' . $in_kommissionen['sql'];

      $interessenbindungen = table_list('interessenbindung_liste', "interessenbindung_liste.parlamentarier_id = $id");
      $aggregated['interessenbindungen'] = $interessenbindungen['data'];
      $message .= ' | ' . $interessenbindungen['message'];
      $sql .= ' | ' . $interessenbindungen['sql'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date_unix'));

      foreach ($aggregated['interessenbindungen'] as $key => $value) {
        $verguetungen = table_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        add_verguetungen($aggregated['interessenbindungen'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      $verguetungs_tranparenz = table_list('parlamentarier_transparenz', "parlamentarier_transparenz.parlamentarier_id = $id");
      $aggregated['verguetungs_tranparenz'] = $verguetungs_tranparenz['data'];
      $message .= ' | ' . $verguetungs_tranparenz['message'];
      $sql .= ' | ' . $verguetungs_tranparenz['sql'];

      $zutrittsberechtigungen = table_list('zutrittsberechtigung', "zutrittsberechtigung.parlamentarier_id = $id");
      $aggregated['zutrittsberechtigungen'] = $zutrittsberechtigungen['data'];
      $message .= ' | ' . $zutrittsberechtigungen['message'];
      $sql .= ' | ' . $zutrittsberechtigungen['sql'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['zutrittsberechtigungen'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['zutrittsberechtigungen'], 'updated_date_unix'));

      foreach ($aggregated['zutrittsberechtigungen'] as $key => $value) {
        $mandate = zutrittsberechtigte_aggregated_by_id($value['id']);
        $aggregated['zutrittsberechtigungen'][$key]['mandate'] = $mandate['data']['mandate'];
        $message .= ' | ' . $mandate['message'];
        $sql .= ' | ' . $mandate['sql'];
      }

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);

      if (!empty($last_modified_date)) {
        $aggregated['last_modified_date'] = $last_modified_date;
        $aggregated['last_modified_date_unix'] = $last_modified_date_unix;
      }
    }

    $count = $parlamentarier['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    json_response($response);
  }
}
