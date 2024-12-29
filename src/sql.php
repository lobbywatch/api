<?php
declare(strict_types=1);

namespace App\Sql;

use App\Constants;
use function App\Lib\String\{clean_db_value};
use const App\Lib\String\{SUPPORTED_DB_CHARS};

function select_fields_SQL(string $table): string {
  $matches = [];
  if (isset($_GET['select_fields']) && $_GET['select_fields'] != '*' && preg_match_all('/([A-Za-z0-9_.,*\-]+)/', $_GET['select_fields'], $matches)) {
    return " $table.id, " . implode(', ', explode(', ', $matches[1][0])) . " ";
  } else {
    return "$table.*";
  }
}

/** Filters out unpublished data if not privileged or includeUnpublished == 0 */
function filter_unpublished_SQL(string $table): string {
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
