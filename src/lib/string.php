<?php
declare(strict_types=1);

namespace App\Lib\String;

function clean_str(?string $str): ?string {
  // MUST NOT BE empty($str) due to usage in forms
  if (!isset($str)) return null;
  $cleaned = $str; // Normalizer::normalize($str, Normalizer::FORM_C);
  // replace typographic chars
  // https://stackoverflow.com/questions/26458654/regular-expressions-for-a-range-of-unicode-points-php
  // https://www.compart.com/en/unicode/block/U+2000
  // https://www.php.net/manual/de/migration70.new-features.php#migration70.new-features.unicode-codepoint-escape-syntax
  // \x{} is part of PCRE
  return trim(
    preg_replace(
      [
        '%[\x{201C}-\x{201F}«»“”„]%ui',
        '%[\x{2018}-\x{201B}`‘’‚‹›]%ui',
        '%[-\x{2010}-\x{2014}\x{202F}]%ui',
        "%[ \x{2000}-\x{200A}]%ui", '%\R%u',
        '%[\x{2028}\x{2029}\x{200B}\x{2063}]%ui'
      ],
      ['"', "'", '-', ' ', "\n", ''],
      $cleaned
    )
  );
}

const SUPPORTED_DB_CHARS = '-a-zA-Z0-9_.äöüÄÖÜéèàÉÈâ%;,"\'';

function clean_db_value(string $value): string {
  return preg_replace(
    '/[^' . SUPPORTED_DB_CHARS . ']/',
    '',
    $value
  );
}
