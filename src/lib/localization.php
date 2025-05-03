<?php
declare(strict_types=1);

namespace App\Lib\Localization;

function get_current_lang(): string {
  return match ($_GET['lang'] ?? null) {
    'fr' => 'fr',
    default => 'de',
  };
}

function get_lang_suffix(string|null $lang = null): string {
  $suffix = $lang ?? get_current_lang();
  return "_$suffix";
}

function translate_record_field($record, $default_key) {
  $locale_key = preg_replace('/_de$/u', '', $default_key) . get_lang_suffix();
  return !empty($record[$locale_key]) ? $record[$locale_key] : $record[$default_key];
}
