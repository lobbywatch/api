<?php

use App\Constants;
use function App\Lib\String\{clean_db_value};
use const App\Lib\String\{SUPPORTED_DB_CHARS};

function get_lang() {
//    global $language;
//    $langcode = isset($language->language) ? $language->language : 'de';
//    return $langcode;
  return 'de';
}

function translate_record_field($record, $basefield_name, $hide_german = false, $try_lt_if_empty = false, $langcode = null) {
//    global $language;
//
//    // Merge in default.
//    if (!isset($langcode)) {
//        $langcode = isset($language->language) ? $language->language : 'de';
//    }
//
//    $locale_field_name = preg_replace('/_de$/u', '', $basefield_name) . "_$langcode";
//
//    if ($langcode == 'de') {
//        return $record[$basefield_name];
//    } else {
//        if ($hide_german) {
//            $replacement_text = textOnlyOneLanguage($record[$basefield_name], 'de');
//        }
//        // if translation is missing, fallback to default ('de')
//        return !empty($record[$locale_field_name]) ? $record[$locale_field_name] : ($hide_german && isset($record[$basefield_name]) ? $replacement_text : ($try_lt_if_empty && isset($record[$basefield_name]) ? lt($record[$basefield_name]) : $record[$basefield_name]));
//    }
  return $record[$basefield_name];
}

function _lobbywatch_data_get_lang() {
  if (isset($_GET['lang']) && $_GET['lang'] == 'fr') {
    return 'fr';
  } else {
    return 'de';
  }
}

function lobbywatch_set_lang($lang) {
  global $language;
  $old_lang = $language;
  $langs = array("de" => "de", "fr" => "fr");
  $language = $langs[$lang];
  return $old_lang;
}

function _lobbywatch_data_select_fields_SQL($table) {
  $matches = [];
  if (isset($_GET['select_fields']) && $_GET['select_fields'] != '*' && preg_match_all('/([A-Za-z0-9_.,*\-]+)/', $_GET['select_fields'], $matches)) {
    return " $table.id, " . implode(', ', explode(', ', $matches[1][0])) . " ";
  } else {
    return "$table.*";
  }
}

/** Filters out unpublished data if not privileged or includeUnpublished == 0 */
function _lobbywatch_data_filter_unpublished_SQL($table) {
//    return ((isset($_GET['includeUnpublished']) && $_GET['includeUnpublished'] != 1) || !user_access('access lobbywatch data unpublished content') ? " AND $table.freigabe_datum < NOW()" : '');
  return ((isset($_GET['includeUnpublished']) && $_GET['includeUnpublished'] != 1) ? " AND $table.freigabe_datum < NOW()" : '');
}

function filter_fields_SQL(string $table): string {
  $sql = '';
  // TODO filter fields not allowed
  foreach ($_GET as $key => $value) {
    $matches = [];
    if (preg_match('/^filter_([a-z0-9_]+?)(_list|_like)?$/', $key, $matches) && !is_internal_field($matches[1])) {
      $sql .= filter_field_SQL($table, $matches[1]);
    }
  }
  return $sql;
}

function filter_field_SQL(string $table, string $field): string {
  $paramSingle = "filter_{$field}";
  $paramList = "filter_{$field}_list";
  $paramLike = "filter_{$field}_like";
  $matches = [];
  if (isset($_GET[$paramSingle]) && is_numeric($_GET[$paramSingle])) {
    return " AND $table.{$field} = " . intval($_GET[$paramSingle]);
  } else if (isset($_GET[$paramSingle])) {
    return " AND $table.{$field} = '" . clean_db_value($_GET[$paramSingle]) . "'";
  } else if (isset($_GET[$paramList]) && preg_match_all('/([' . SUPPORTED_DB_CHARS . ']+)/', $_GET[$paramList], $matches)) {
    return " AND $table.{$field} IN ( " . implode(',', $matches[1]) . ')';
  } else if (isset($_GET[$paramLike])) {
    return " AND $table.{$field} LIKE '" . clean_db_value($_GET[$paramLike]) . "'";
  } else {
    return '';
  }
}

function is_internal_field(string $field): bool {
//    return !user_access('access lobbywatch data confidential content') && in_array($field, Constants::$intern_fields);
  return in_array($field, Constants::$internal_fields);
}
