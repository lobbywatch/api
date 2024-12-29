<?php

require "../bootstrap.php";
require "../src/data.php";

use function App\Routes\route_table_flat_id;

route_table_flat_id('branche', $_GET['id']);
