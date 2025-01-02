<?php

use function App\Application\table_list;
use function App\domain\ApiResponse\{api_response, forbidden_response};
use function App\Lib\Http\{add_exception, json_response};
use function App\Lib\Localization\{get_lang_suffix};
use function App\Sql\{clean_records, filter_limit_SQL};
use function App\Store\{db_query};

/**
 * This is the original Drupal 7 module that was used for providing the data API.
 * We copy it into this repo to gradually transform into Drupal-free code.
 */

global $show_sql, $show_stacktrace;
$show_sql = true;
$show_stacktrace = true;

// Duplicated from lobbywatch_autocomplete_json.php
function _lobbywatch_search_keyword_processing($str) {
  $search_str = preg_replace('!\*+!', '%', $str);
  //     $search_str = '%' . db_like($keys) . '%'
  if (!preg_match('/[%_]/', $search_str)) {
    $search_str = "%$search_str%";
  }
  return $search_str;
}

// Adapted from lobbywatch_autocomplete_json.php
function _lobbywatch_data_search($search_str, $json = true, $filter_unpublished = true) {
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
        return "'" . db_escape_table($table) . "'";
      }, explode(',', $_GET['tables']))) . ')' : '', $has_query ? "search_keywords$lang_suffix LIKE :str" : '', $filter_unpublished ? '(table_name=\'parlamentarier\' OR table_name=\'zutrittsberechtigung\' OR freigabe_datum <= NOW())' : '',]);

    // Show all parlamentarier in search, even if not freigegeben, RKU 22.01.2015
    $sql = "
    SELECT id, page, table_name, name_de, name_fr, table_weight, weight
    -- , freigabe_datum, bis
    FROM v_search_table
    WHERE " . implode($conditions, ' AND ') . "ORDER BY table_weight, weight";

    $sql .= filter_limit_SQL() . ';';

    $result = db_query($sql, array(':str' => _lobbywatch_search_keyword_processing($search_str)));

    $items = clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found ";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

/**
 * @param $orgs array of organisation having 'id'
 */
function _lobbywatch_data_get_parlamentarier_from_organisation($orgs) {

  $aggregated = [];

  $org_conditions = array_map(function ($org) {
    return "organisation_parlamentarier_beide_indirekt.connector_organisation_id = " . $org['id'];
  }, $orgs);

  $connections = table_list('organisation_parlamentarier_beide_indirekt', "(" . implode(" OR ", $org_conditions) . ")");

  $aggregated['connections'] = $connections['data'];
  $message .= ' | ' . $connections['message'];
  $sql .= ' | ' . $connections['sql'];

  $parlamentarier_conditions = array_map(function ($con) {
    return "parlamentarier.id = " . $con['parlamentarier_id'];
  }, $connections['data']);

  $parlamentarier = table_list('parlamentarier', "(" . implode(" OR ", $parlamentarier_conditions) . ")");

  $aggregated['parlamentarier'] = $parlamentarier['data'];
  $message .= ' | ' . $parlamentarier['message'];
  $sql .= ' | ' . $parlamentarier['sql'];

  return $aggregated;
}


function _lobbywatch_data_router($path = '', $version = '', $data_type = '', $call_type = '', $object = '', $response_type = '', $response_object = '', $parameter = '', $json_output = false) {
  if ($call_type === 'search' && $object === 'default' /*&& $response_type === 'aggregated' && $respone_object === 'list'*/ /*&& $parameter*/) {
    return _lobbywatch_data_search($response_type, false);
  }
}

function _lobbywatch_data_ws_uid($table, $uid, $json = true) {
  global $show_sql, $show_stacktrace;
  global $no_cors;
  global $zefix_ws_login;
  global $allowed_uid_access_keys;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';
  $sql = '';

  // Protect Zefix WS, either with key or from cyon.ch server or localhost
  if (in_array($table, ['uid', 'zefix-rest', 'uid-bfs']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $allowed_uid_access_keys, true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_response(forbidden_response());
  } else if (in_array($table, ['zefix-soap']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $zefix_ws_login['keys'], true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_response(forbidden_response());
  }


  try {
    if ($table == 'uid-bfs') {
      $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-soap') {
      $items = _lobbywatch_fetch_ws_zefix_soap_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-rest') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'uid') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      if (!$items['success']) {
        $message .= "zefix-rest unsuccessful ({$items['message']}), calling uid@bfs | ";
        $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false, 0);
      }
      $no_cors = true; // Disable CORS since it is a protected service
    } else {
      // Must not happen
      $items = null;
    }

    $count = $items['count']; // already set by fillDataFromUIDResult()
    $success = $items['success'];
    $message .= $items['message'];
    $sql .= $items['sql'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $success ? $items['data'] : null);

    if ($json) {
      json_response($response, cors: !$no_cors);
    } else {
      return $response;
    }
  }
}

