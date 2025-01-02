<?php

require "../bootstrap.php";
require "../src/data.php";

use function App\Routes\route_table_flat_id;

// Parse the URL in the same way as Drupal v7
[, , $path, $version, $data_type, $call_type, $object, $response_type, $response_object, $parameter] = explode("/", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

route_table_flat_id($object, $parameter);

