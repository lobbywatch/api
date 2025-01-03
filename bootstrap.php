<?php
require 'vendor/autoload.php';

use PhpDevCommunity\DotEnv;

(new DotEnv(__DIR__ . '/.env'))->load();

global $show_sql, $show_stacktrace;
$show_sql = true;
$show_stacktrace = true;
