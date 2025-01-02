<?php

require "../bootstrap.php";
require "../src/data.php";

use App\Constants;
use function App\domain\ApiResponse\not_found_response;
use function App\Lib\Http\json_response;
use function App\Routes\{route_table_flat_id, route_table_flat_list_search};

// Parse the URL in the same way as Drupal v7
$segments = explode("/", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (sizeof($segments) !== 10) json_response(not_found_response());
[, , $path, $version, $data_type, $call_type, $object, $response_type, $response_object, $parameter] = $segments;

if ($version !== 'v1' || $data_type !== 'json') {
  json_response(not_found_response());
}

// TODO
//lobbywatch_set_lang(get_lang());

if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $response_object === 'id' && $parameter) {
  route_table_flat_id($object, $parameter);
} else if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $response_object === 'list' && $parameter) {
  route_table_flat_list_search($object, $parameter);
}

json_response(not_found_response());
