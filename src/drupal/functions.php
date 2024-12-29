<?php
declare(strict_types=1);

namespace App\Drupal;

function drupal_add_http_header(string $key, string $value): void {
  header("$key: $value");
}
