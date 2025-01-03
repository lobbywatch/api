<?php

use function App\domain\ApiResponse\{api_response, forbidden_response};
use function App\Lib\Http\{add_exception, json_response};

/**
 * This is the original Drupal 7 module that was used for providing the data API.
 * We copy it into this repo to gradually transform into Drupal-free code.
 */

global $show_sql, $show_stacktrace;
$show_sql = true;
$show_stacktrace = true;

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

