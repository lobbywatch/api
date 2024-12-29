<?php
require "../bootstrap.php";
require "../src/drupal/data.php";

header("Access-Control-Allow-Origin: *");

echo _lobbywatch_data_table_flat_id('branche', $_GET['id']);