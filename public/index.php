<?php
require "../bootstrap.php";

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

echo json_encode(array(["a" => 1, "b" => 2, "c" => 3]));
