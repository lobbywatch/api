<?php
declare(strict_types=1);

namespace App\Settings;

use function App\Store\db_query;

function getRechercheJahrFromSettings(): int {
  static $year_raw = null;

  if ($year_raw === null) {
    $year_raw = db_query('SELECT value FROM settings WHERE key_name="rechercheJahr"')[0]['value'];
  }

  $cur_year = (int)date("Y");
  if ($year_raw <= $cur_year + 1 && $year_raw >= $cur_year - 1) {
    $year = (int)$year_raw;
  } else {
    $year = $cur_year;
  }
  return $year;
}
