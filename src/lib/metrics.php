<?php
declare(strict_types=1);

namespace App\Lib\Metrics;

function page_build_secs(): string {
  $elapsed_s = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
  return number_format($elapsed_s, 2);
}
