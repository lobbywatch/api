<?php

require "../bootstrap.php";
require "../src/data.php";

use function App\domain\ApiResponse\not_found_response;
use function App\Lib\Http\json_response;
use function App\Routes\route_table_flat_id;

// Parse the URL in the same way as Drupal v7
[, , $path, $version, $data_type, $call_type, $object, $response_type, $response_object, $parameter] = explode("/", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($version !== 'v1' || $data_type !== 'json') {
  json_response(not_found_response());
}

route_table_flat_id($object, $parameter);
