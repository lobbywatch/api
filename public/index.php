<?php

require "../bootstrap.php";
require "../src/data.php";

use App\Constants;
use function App\domain\ApiResponse\not_found_response;
use function App\Lib\Http\json_response;
use function App\Lib\Localization\get_lang;
use function App\Lib\Localization\lobbywatch_set_lang;
use function App\Routes\{route_data_table_organisation_aggregated_id,
  route_query_parlament_partei_aggregated_list,
  route_relation_flat_list,
  route_search,
  route_table_branche_aggregated_id,
  route_table_flat_id,
  route_table_flat_list,
  route_table_flat_list_search,
  route_table_interessengruppe_aggregated_id,
  route_table_parlamentarier_aggregated_id,
  route_ws_uid,
  route_zutrittsberechtigte_aggregated};

// Parse the URL in the same way as Drupal v7
$segments = explode("/", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (sizeof($segments) < 9) json_response(not_found_response());
[, , $path, $version, $data_type, $call_type, $object, $response_type, $response_object, $parameter] = array_merge($segments, [10 => null /* Fallback for $parameter */]);

if ($version !== 'v1' || $data_type !== 'json') {
  json_response(not_found_response());
}

lobbywatch_set_lang(get_lang());

if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $response_object === 'id' && $parameter) {
  route_table_flat_id($object, $parameter);
} else if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $response_object === 'list' && $parameter) {
  route_table_flat_list_search($object, $parameter);
} else if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $response_object === 'list') {
  route_table_flat_list($object, 1);
} else if ($call_type === 'ws' && (in_array($object, ['uid', 'zefix-soap', 'zefix-rest', 'uid-bfs'])) && $response_type === 'flat' && $response_object === 'uid' && $parameter) {
  route_ws_uid($object, $parameter);
} else if ($call_type === 'relation' && array_key_exists($object, Constants::getAllEnrichedRelations()) && $response_type === 'flat' && $response_object === 'list') {
  route_relation_flat_list($object);
} else if ($call_type === 'table' && $object === 'zutrittsberechtigung' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
  route_zutrittsberechtigte_aggregated($parameter);
} else if ($call_type === 'table' && $object === 'parlamentarier' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
  route_table_parlamentarier_aggregated_id($parameter);
} else if ($call_type === 'table' && $object === 'organisation' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
  route_data_table_organisation_aggregated_id($parameter);
} else if ($call_type === 'table' && $object === 'interessengruppe' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
  route_table_interessengruppe_aggregated_id($parameter);
} else if ($call_type === 'table' && $object === 'branche' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
  route_table_branche_aggregated_id($parameter);
} else if ($call_type === 'query' && $object === 'parlament-partei' && $response_type === 'aggregated' && $response_object === 'list') {
  route_query_parlament_partei_aggregated_list(1);
} else if ($call_type === 'search' && $object === 'default' /*&& $response_type === 'aggregated' && $respone_object === 'list'*/ /*&& $parameter*/) {
  route_search($response_type);
}

json_response(not_found_response());
