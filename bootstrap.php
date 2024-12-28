<?php
require 'vendor/autoload.php';

use PhpDevCommunity\DotEnv;

(new DotEnv(__DIR__ . '/.env'))->load();
