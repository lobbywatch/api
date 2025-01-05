<?php
declare(strict_types=1);

namespace App\Routes;

use Exception;
use function App\Application\table_by_id;
use function App\Application\table_list;
use function App\domain\ApiResponse\api_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\json_response;
use function App\Sql\add_verguetungen;

function route_data_table_organisation_aggregated_id(string $id): never {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'organisation';

  try {
    $organsation = table_by_id('organisation', $id);
    $aggregated = $organsation['data'];
    $message .= ' | ' . $organsation['message'];
    $sql .= ' | ' . $organsation['sql'];
    $success = $success && $organsation['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $beziehungen = table_list('organisation_beziehung', "organisation_beziehung.organisation_id = $id OR organisation_beziehung.ziel_organisation_id = $id");

      $aggregated['beziehungen'] = $beziehungen['data'];
      $message .= ' | ' . $beziehungen['message'];
      $sql .= ' | ' . $beziehungen['sql'];

      $reverse_art = array('arbeitet fuer' => 'auftraggeber fuer', 'mitglied von' => 'mitglieder', 'tochtergesellschaft von' => 'tochtergesellschaften', 'partner von' => 'partner von', // not directional
        'beteiligt an' => 'beteiligungen von',);
      foreach ($aggregated['beziehungen'] as $key => $value) {
        if ($value['ziel_organisation_id'] === $aggregated['id']) {
          $aggregated['beziehungen'][$key]['art'] = $reverse_art[$value['art']];
          $aggregated['beziehungen'][$key]['ziel_organisation_id'] = $value['organisation_id'];
          $aggregated['beziehungen'][$key]['ziel_organisation_name'] = $value['organisation_name'];
          $aggregated['beziehungen'][$key]['organisation_name'] = $aggregated['name'];
          $aggregated['beziehungen'][$key]['organisation_id'] = $aggregated['id'];
        }
      }

      $parlamentarier = table_list('organisation_parlamentarier', "organisation_parlamentarier.organisation_id = $id");
      $aggregated['parlamentarier'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];

      foreach ($aggregated['parlamentarier'] as $key => $value) {
        $verguetungen = table_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        add_verguetungen($aggregated['parlamentarier'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit'] ?? null);
      }

      $zutrittsberechtigung = table_list('organisation_zutrittsberechtigung', "organisation_zutrittsberechtigung.organisation_id = $id");
      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigung['data'];
      $message .= ' | ' . $zutrittsberechtigung['message'];
      $sql .= ' | ' . $zutrittsberechtigung['sql'];

      foreach ($aggregated['zutrittsberechtigte'] as $key => $value) {
        $parlamentarier = table_by_id('parlamentarier', $value['parlamentarier_id']);
        $aggregated['zutrittsberechtigte'][$key]['parlamentarier'] = $parlamentarier['data'];
        $message .= ' | ' . $parlamentarier['message'];
        $sql .= ' | ' . $parlamentarier['sql'];
      }

//      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organsation['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    json_response($response);
  }
}
