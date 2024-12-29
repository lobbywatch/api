<?php
declare(strict_types=1);

namespace App\Drupal;

function drupal_add_http_header(string $key, string $value): void {
  header("$key: $value");
}

function drupal_exit(): never {
  // https://api.drupal.org/api/drupal/includes%21common.inc/function/drupal_exit/7.x
  exit();
}
