<?php
declare(strict_types=1);

namespace App\Application;

use Exception;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Array\max_attribute_in_array;
use function App\Lib\Http\add_exception;
use function App\Sql\add_verguetungen;

function zutrittsberechtigte_aggregated_by_id(string $id): array {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'zutrittsberechtigung';

  try {
    $zutrittsberechtigung = table_by_id('zutrittsberechtigung', $id);
    $aggregated = $zutrittsberechtigung['data'];
    $message .= ' | ' . $zutrittsberechtigung['message'];
    $sql .= ' | ' . $zutrittsberechtigung['sql'];
    $success = $success && $zutrittsberechtigung['success'];


    $last_modified_date = $aggregated['updated_date'] ?? 0;
    $last_modified_date_unix = $aggregated['updated_date_unix'] ?? 0;

    if ($success) {
      $person_id = $zutrittsberechtigung['data']['person_id'];
      $mandate = table_list('person_mandate', "person_mandate.person_id = $person_id");
      $aggregated['mandate'] = $mandate['data'];
      $message .= ' | ' . $mandate['message'] . '';
      $sql .= ' | ' . $mandate['sql'] . '';
      // $success = $success && $mandate['success'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['mandate'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['mandate'], 'updated_date_unix'));

      foreach ($aggregated['mandate'] as $key => $value) {
        $verguetungen = table_list('mandat_jahr', "mandat_jahr.mandat_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        add_verguetungen($aggregated['mandate'][$key], $verguetungen, $value['von']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      // dpm($zutrittsberechtigung, '$zutrittsberechtigung');
      // TODO handle duplicate zutrittsberechtigung due to historization
      $parlamentarier_id = $zutrittsberechtigung['data']['parlamentarier_id'];
      $parlamentarier = table_list('parlamentarier', "parlamentarier.id = $parlamentarier_id");
      //     dpm($parlamentarier, '$parlamentarier');
      $aggregated['parlamentarier'] = $parlamentarier['data'][0];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];
      $success = $success && $parlamentarier['success'];

      // Decision was to remove this
      // _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);

      if (!empty($last_modified_date)) {
        $aggregated['last_modified_date'] = $last_modified_date;
        $aggregated['last_modified_date_unix'] = $last_modified_date_unix;
      }
    }

    $count = $zutrittsberechtigung['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    return api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
  }
}
