<?php

require "utils.php";

use App\Constants;
use function App\Lib\Http\{base_root, check_plain, json_response, request_uri};
use function App\Lib\Metrics\{page_build_secs};
use function App\Lib\String\{clean_str};
use function App\Store\db_query;

/**
 * This is the original Drupal 7 module that was used for providing the data API.
 * We copy it into this repo to gradually transform into Drupal-free code.
 */

global $show_sql, $show_stacktrace;
$show_sql = true;
$show_stacktrace = true;

function _lobbywatch_data_table_flat_id($table, $id, $json = true) {
  global $show_sql;
  $success = false;
  $count = 0;
  $items = null;
  $message = '';

  try {
    $fields = _lobbywatch_data_select_fields_SQL($table);
    $filter_unpublished = (in_array($table, array('parlamentarier', 'zutrittsberechtigung'))
      ? ''
      : _lobbywatch_data_filter_unpublished_SQL($table));
    $filter_fields = filter_fields_SQL($table);

    $sql = <<<SQL
      SELECT $fields
      FROM v_$table $table
      WHERE $table.id=:id
      $filter_unpublished
      $filter_fields
      SQL;

    $result = db_query($sql, array(':id' => $id));

    $items = _lobbywatch_data_clean_records($result);

    _lobbywatch_data_transformation($table, $items);

    $count = count($items);
    $success = $count == 1;
    $message .= count($items) . " record(s) found";
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
  } finally {
    $response = array(
      'success' => $success,
      'count' => $count,
      'message' => $message,
      'sql' => $show_sql ? preg_replace('/\s+/', ' ', $sql) : '',
      'source' => $table,
      'build secs' => page_build_secs(),
      'data' => $success ? $items[0] : null,
    );

    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_add_exeption($e) {
  global $show_stacktrace;
  return $show_stacktrace ? $e->getMessage() . "\n------\n" . $e->getTraceAsString() : $e->getMessage();
}

function _lobbywatch_data_filter_limit_SQL() {
  if (isset($_GET['limit']) && $_GET['limit'] == 'none') {
    return '';
  } else {
    return " LIMIT " . (isset($_GET['limit']) && is_int($limit = $_GET['limit'] + 0) && $limit > 0 ? $limit : 10) . " ";
  }
}

/**
 * Filter fields and keep only one language and set it in the base field name.
 *
 * Looks for "_fr" since this is consistently used language fields. If found,
 * delete "_fr" and "_de" fields and set value in base field name.
 *
 * E.g
 * If anzeige_name_fr is found, anzeige_name_fr and anzeige_name_de are deleted and the
 * for lang=fr anzeige_name set with the value of anzeige_name_fr.
 *
 * @param unknown $items
 * @return either
 */
function _lobbywatch_data_handle_lang_fields(&$items) {
  $lang = get_lang();
  $fr_suffix = '_fr';
  $de_suffix = '_de';

  $fields = [];

  foreach ($items as &$fields) {
    foreach ($fields as $key => $value) {
      $matches = [];
      if (preg_match('/^(.+)_fr$/i', $key, $matches)) {
        $base_field_name = $matches[1];
        if (isset($fields["{$base_field_name}_de"]) || (array_key_exists("{$base_field_name}_de", $fields) && !array_key_exists($base_field_name, $fields))) {
          $de_field_name = "{$base_field_name}_de";
        } else {
          $de_field_name = $base_field_name;
        }
        $lang_value = translate_record_field($fields, $de_field_name);
        unset($fields["{$base_field_name}_fr"]);
        unset($fields["{$base_field_name}_de"]);
        unset($fields["{$base_field_name}_it"]);
        $fields[$base_field_name] = $lang_value;
      }
    }
  }
  return $fields;
}

function _lobbywatch_data_clean_records($result) {
  $items = [];
//    $includeHistorised = isset($_GET['includeInactive']) && $_GET['includeInactive'] == 1 && user_access('access lobbywatch unpublished content');
  $includeHistorised = false;

  // TODO test exclusion of historised records
  foreach ($result as $record) {
    if (((!array_key_exists('bis_unix', $record) || (is_null($record['bis_unix']) || $record['bis_unix'] > time())) && (!array_key_exists('im_rat_bis_unix', $record) || (is_null($record['im_rat_bis_unix']) || $record['im_rat_bis_unix'] > time())) && (!array_key_exists('zutrittsberechtigung_bis_unix', $record) || (is_null($record['zutrittsberechtigung_bis_unix']) || $record['zutrittsberechtigung_bis_unix'] > time()))) || $includeHistorised) {
      $fields = _lobbywatch_data_clean_fields($record);
      _lobbywatch_data_enrich_fields($fields);
      $items[] = $fields;
    }
  }

  _lobbywatch_data_handle_lang_fields($items);

  return $items;
}

function _lobbywatch_data_enrich_fields(array &$items) {
  if (!empty($items['wikidata_qid'])) {
    $items['wikidata_item_url'] = "https://www.wikidata.org/wiki/" . $items['wikidata_qid'];
  }

  if (!empty($items['parlament_biografie_id'])) {
    $items['parlament_biografie_url'] = "https://www.parlament.ch/de/biografie/name/" . $items['parlament_biografie_id'];
  }

  if (!empty($items['twitter_name'])) {
    $items['twitter_url'] = "https://twitter.com/" . $items['twitter_name'];
  }

  if (!empty($items['facebook_name'])) {
    $items['facebook_url'] = "https://www.facebook.com/" . $items['facebook_name'];
  }

  if (!empty($items['isicv4'])) {
    $items['isicv4_list'] = explode(" ", $items['isicv4']);
  }
}

function _lobbywatch_data_clean_fields($input_record) {

  $record = $input_record;

  $updated_fields = array('updated_date', 'updated_date_unix', 'refreshed_date');
  if (!isset($_GET['includeConfidentialData']) || $_GET['includeConfidentialData'] != 1 /* || !user_access('access lobbywatch data confidential content') */) {
    foreach ($record as $key => $value) {
      // Clean intern fields
      if (is_internal_field($key)) {
        unset($record[$key]);
      }
      if (in_array($key, Constants::$meta_fields) && !in_array($key, $updated_fields)) {
        unset($record[$key]);
      }
    }
  }

  if (!isset($_GET['includeMetaData']) || $_GET['includeMetaData'] != 1) {
    foreach ($record as $key => $value) {
      // Clean intern fields
      if (in_array($key, $updated_fields)) {
        unset($record[$key]);
      }
    }
  }

  foreach ($record as $key => $value) {
    // Clean intern fields
    if (preg_match('/_(BAD|OLD|ALT)$/i', $key)) {
      unset($record[$key]);
    } else if (preg_match('/_json$/i', $key)) {
      $record[$key] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } else if (is_string($value)) {
      $clean = clean_str($value);
      // TODO move list of strip_tags exception to general location
      if (!in_array($key, ['parlament_interessenbindungen'], true)) {
        $clean = strip_tags($clean);
      }
      $record[$key] = $clean;
    }

  }

  global $rel_files_url;
  if (isset($record['symbol_klein_rel'])) {
    $record['symbol_path'] = $rel_files_url . '/' . $record['symbol_klein_rel'];
    $record['symbol_url'] = base_root() . $record['symbol_path'];
  }

  if (isset($record['kleinbild'])) {
    $record['kleinbild_path'] = $rel_files_url . '/../files/parlamentarier_photos/klein/' . $record['kleinbild'];
    $record['kleinbild_url'] = base_root() . $record['kleinbild_path'];
  }

  return $record;
}

function _lobbywatch_data_transformation($table, &$items) {
  if (in_array($table, ['organisation', 'interessenbindung_liste'], true)) {
    $lang = get_lang();

    $pg_prefix = ['de' => 'Parlamentarische Gruppe ', 'fr' => 'Intergroupe parlementaire ', 'it' => 'Intergruppo parlamentare ',];
    $fpg_prefix = ['de' => 'Parlamentarische Freundschaftsgruppe ', 'fr' => 'Intergroupe parlementaire ', 'it' => 'Intergruppo parlamentare ',];

    foreach ($items as $item_key => $fields) {
      foreach ($fields as $key => $value) {
        if (in_array($key, ['name', 'organisation_name'], true) && $fields['rechtsform'] === 'Parlamentarische Gruppe') {
          $items[$item_key][$key] = $pg_prefix[$lang] . ($lang === 'de' ? $items[$item_key][$key] : lcfirst($items[$item_key][$key]));
        } else if (in_array($key, ['name', 'organisation_name'], true) && $fields['rechtsform'] === 'Parlamentarische Freundschaftsgruppe') {
          $items[$item_key][$key] = $fpg_prefix[$lang] . ($lang === 'de' ? $items[$item_key][$key] : lcfirst($items[$item_key][$key]));
        }
      }
    }
  } else if (in_array($table, ['parlamentarier', 'organisation_parlamentarier'], true)) { // quick and dirty hack to replace M with Mitte
    $lang = get_lang();

    foreach ($items as $item_key => $fields) {
      foreach ($fields as $key => $value) {
        if (in_array($key, ['partei'], true) && $fields['partei'] === 'M') {
          $items[$item_key][$key] = 'Mitte';
        } else if (in_array($key, ['partei'], true) && $fields['partei'] === 'C') {
          $items[$item_key][$key] = 'Centre';
        }
      }
    }
  }
}

function _lobbywatch_data_table_flat_list($table, $condition = '1', $json = true, $order_by = '', $join = '', $join_select = '') {
  global $show_sql;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;


  try {
    // TXTTODO _lobbywatch_data_table_flat_list nothing todo
    $sql = "
    SELECT " . _lobbywatch_data_select_fields_SQL($table) . "
    $join_select
    FROM v_$table $table
    $join
    WHERE $condition " . _lobbywatch_data_filter_unpublished_SQL($table) . filter_fields_SQL($table) . " $order_by" . _lobbywatch_data_filter_limit_SQL() . ';';

    $result = db_query($sql, []);

    $items = _lobbywatch_data_clean_records($result);

    _lobbywatch_data_transformation($table, $items);

    $count = count($items);
    $success = $count > 0;
    $message = $count . " record(s) found";
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $items);

    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_relation_flat_list($table, $condition = '1', $json = true) {
  global $show_sql;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;


  try {
    $sql = "
    SELECT " . _lobbywatch_data_select_fields_SQL($table) . "
    FROM v_$table $table
    WHERE $condition " . _lobbywatch_data_filter_unpublished_SQL($table) . filter_fields_SQL($table) . _lobbywatch_data_filter_limit_SQL() . ';';

    $result = db_query($sql, []);

    $items = _lobbywatch_data_clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message = $count . " record(s) found";
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $items);

    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

// Duplicated from lobbywatch_autocomplete_json.php
function _lobbywatch_search_keyword_processing($str) {
  $search_str = preg_replace('!\*+!', '%', $str);
  //     $search_str = '%' . db_like($keys) . '%'
  if (!preg_match('/[%_]/', $search_str)) {
    $search_str = "%$search_str%";
  }
  return $search_str;
}

// Adapted from lobbywatch_autocomplete_json.php
function _lobbywatch_data_search($search_str, $json = true, $filter_unpublished = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';

  $table = 'search_table';

  try {
    $lang_suffix = get_lang_suffix();

    $has_query = mb_strlen($search_str) > 0;

    $conditions = array_filter([isset($_GET['tables']) ? 'table_name IN (' . implode(',', array_map(function ($table) {
        return "'" . db_escape_table($table) . "'";
      }, explode(',', $_GET['tables']))) . ')' : '', $has_query ? "search_keywords$lang_suffix LIKE :str" : '', $filter_unpublished ? '(table_name=\'parlamentarier\' OR table_name=\'zutrittsberechtigung\' OR freigabe_datum <= NOW())' : '',]);

    // Show all parlamentarier in search, even if not freigegeben, RKU 22.01.2015
    $sql = "
    SELECT id, page, table_name, name_de, name_fr, table_weight, weight
    -- , freigabe_datum, bis
    FROM v_search_table
    WHERE " . implode($conditions, ' AND ') . "ORDER BY table_weight, weight";

    $sql .= _lobbywatch_data_filter_limit_SQL() . ';';

    $result = db_query($sql, array(':str' => _lobbywatch_search_keyword_processing($search_str)));

    $items = _lobbywatch_data_clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found ";
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $items);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_table_flat_list_search($table, $search_str, $json = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';


  try {
    if ($table === 'parlamentarier') {
      //TODO test check permissions
      $sql = "SELECT " . _lobbywatch_data_select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . (!(isset($_GET['includeInactive']) && $_GET['includeInactive'] != 0 && user_access('access lobbywatch unpublished content')) ? ' AND (im_rat_bis IS NULL OR im_rat_bis > NOW())' : '') . _lobbywatch_data_filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else if ($table === 'zutrittsberechtigung') {
      $sql = "SELECT " . _lobbywatch_data_select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . (!(isset($_GET['includeInactive']) && $_GET['includeInactive'] != 0 && !user_access('access lobbywatch unpublished content')) ? ' AND (bis IS NULL OR bis > NOW())' : '') . _lobbywatch_data_filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else if (in_array($table, Constants::$entities_web)) {
      $sql = "SELECT " . _lobbywatch_data_select_fields_SQL($table) . " FROM v_$table $table WHERE anzeige_name LIKE :str" . _lobbywatch_data_filter_unpublished_SQL($table) . filter_fields_SQL($table);
    } else {
      throw new Exception("Table $table does not exist");
    }
    $sql .= _lobbywatch_data_filter_limit_SQL() . ';';
    $result = db_query($sql, array(':str' => "%$search_str%"));

    $items = _lobbywatch_data_clean_records($result);
    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found";
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $items);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_table_zutrittsberechtigte_aggregated_id($id, $json = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'zutrittsberechtigung';

  try {
    $zutrittsberechtigung = _lobbywatch_data_table_flat_id('zutrittsberechtigung', $id, false);
    $aggregated = $zutrittsberechtigung['data'];
    $message .= ' | ' . $zutrittsberechtigung['message'];
    $sql .= ' | ' . $zutrittsberechtigung['sql'];
    $success = $success && $zutrittsberechtigung['success'];

    $last_modified_date = $aggregated['updated_date'];
    $last_modified_date_unix = $aggregated['updated_date_unix'];

    if ($success) {
      $person_id = $zutrittsberechtigung['data']['person_id'];
      $mandate = _lobbywatch_data_table_flat_list('person_mandate', "person_mandate.person_id = $person_id", false);
      $aggregated['mandate'] = $mandate['data'];
      $message .= ' | ' . $mandate['message'] . '';
      $sql .= ' | ' . $mandate['sql'] . '';
      // $success = $success && $mandate['success'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['mandate'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['mandate'], 'updated_date_unix'));

      foreach ($aggregated['mandate'] as $key => $value) {
        $verguetungen = _lobbywatch_data_table_flat_list('mandat_jahr', "mandat_jahr.mandat_id = {$value['id']}", false, 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['mandate'][$key], $verguetungen, $value['von']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      //     dpm($zutrittsberechtigung, '$zutrittsberechtigung');
      // TODO handle duplicate zutrittsberechtigung due to historization
      $parlamentarier_id = $zutrittsberechtigung['data']['parlamentarier_id'];
      $parlamentarier = _lobbywatch_data_table_flat_list('parlamentarier', "parlamentarier.id = $parlamentarier_id", false);
      //     dpm($parlamentarier, '$parlamentarier');
      $aggregated['parlamentarier'] = $parlamentarier['data'][0];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];
      $success = $success && $parlamentarier['success'];

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);

      if (!empty($last_modified_date)) {
        $aggregated['last_modified_date'] = $last_modified_date;
        $aggregated['last_modified_date_unix'] = $last_modified_date_unix;
      }
    }

    $count = $zutrittsberechtigung['count'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }

}

function _add_verguetungen(&$arr, $verguetungen, $ib_von, $im_rat_seit = null) {
  $arr['verguetungen_einzeln'] = $verguetungen['data'];
  $verguetungen_jahr = [];
  foreach ($verguetungen['data'] as $jkey => $jval) {
    $verguetungen_jahr[$jval['jahr']] = $jval;
  }

  $this_year = getRechercheJahrFromSettings();
  $start_years = [$this_year - 5];
  if ($ib_von) {
    $start_years[] = date_parse($ib_von)['year'];
  }
  if ($im_rat_seit) {
    $start_years[] = date_parse($im_rat_seit)['year'];
  }
  $start_year = max($start_years);
  for ($year = $start_year; $year <= $this_year; $year++) {
    $message .= " +$year+";
    $arr['verguetungen_jahr'][$year] = $verguetungen_jahr[$year] ?? null;
    $arr['verguetungen_pro_jahr'][] = $verguetungen_jahr[$year] ?? ['jahr' => "$year"];
  }
}

function _lobbywatch_data_table_parlamentarier_aggregated_id($id, $json = true) {
  global $show_sql;
  global $env;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'parlamentarier';


  try {
    $message .= "$env";
    $parlamentarier = _lobbywatch_data_table_flat_id('parlamentarier', $id, false);
    $aggregated = $parlamentarier['data'];
    $message .= ' | ' . $parlamentarier['message'];
    $sql .= ' | ' . $parlamentarier['sql'];
    $success = $success && $parlamentarier['success'];

    $last_modified_date = $aggregated['updated_date'];
    $last_modified_date_unix = $aggregated['updated_date_unix'];

    // load aggregated data only if main object is there
    if ($success) {
      $in_kommissionen = _lobbywatch_data_table_flat_list('in_kommission_liste', "in_kommission_liste.parlamentarier_id = $id", false);
      $aggregated['in_kommission'] = $in_kommissionen['data'];
      $message .= ' | ' . $in_kommissionen['message'];
      $sql .= ' | ' . $in_kommissionen['sql'];

      $interessenbindungen = _lobbywatch_data_table_flat_list('interessenbindung_liste', "interessenbindung_liste.parlamentarier_id = $id", false);
      $aggregated['interessenbindungen'] = $interessenbindungen['data'];
      $message .= ' | ' . $interessenbindungen['message'];
      $sql .= ' | ' . $interessenbindungen['sql'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date_unix'));

      foreach ($aggregated['interessenbindungen'] as $key => $value) {
        $verguetungen = _lobbywatch_data_table_flat_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", false, 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['interessenbindungen'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      $verguetungs_tranparenz = _lobbywatch_data_table_flat_list('parlamentarier_transparenz', "parlamentarier_transparenz.parlamentarier_id = $id", false);
      $aggregated['verguetungs_tranparenz'] = $verguetungs_tranparenz['data'];
      $message .= ' | ' . $verguetungs_tranparenz['message'];
      $sql .= ' | ' . $verguetungs_tranparenz['sql'];

      $zutrittsberechtigungen = _lobbywatch_data_table_flat_list('zutrittsberechtigung', "zutrittsberechtigung.parlamentarier_id = $id", false);
      $aggregated['zutrittsberechtigungen'] = $zutrittsberechtigungen['data'];
      $message .= ' | ' . $zutrittsberechtigungen['message'];
      $sql .= ' | ' . $zutrittsberechtigungen['sql'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['zutrittsberechtigungen'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['zutrittsberechtigungen'], 'updated_date_unix'));

      foreach ($aggregated['zutrittsberechtigungen'] as $key => $value) {
        $mandate = _lobbywatch_data_table_zutrittsberechtigte_aggregated_id($value['id'], false);
        $aggregated['zutrittsberechtigungen'][$key]['mandate'] = $mandate['data']['mandate'];
        $message .= ' | ' . $mandate['message'];
        $sql .= ' | ' . $mandate['sql'];
      }

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);

      if (!empty($last_modified_date)) {
        $aggregated['last_modified_date'] = $last_modified_date;
        $aggregated['last_modified_date_unix'] = $last_modified_date_unix;
      }
    }

    $count = $parlamentarier['count'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_table_organisation_aggregated_id($id, $json = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'organisation';

  try {
    $organsation = _lobbywatch_data_table_flat_id('organisation', $id, false);
    $aggregated = $organsation['data'];
    $message .= ' | ' . $organsation['message'];
    $sql .= ' | ' . $organsation['sql'];
    $success = $success && $organsation['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $beziehungen = _lobbywatch_data_table_flat_list('organisation_beziehung', "organisation_beziehung.organisation_id = $id OR organisation_beziehung.ziel_organisation_id = $id", false);

      $aggregated['beziehungen'] = $beziehungen['data'];
      $message .= ' | ' . $beziehungen['message'];
      $sql .= ' | ' . $beziehungen['sql'];

      $reverse_art = array('arbeitet fuer' => 'auftraggeber fuer', 'mitglied von' => 'mitglieder', 'tochtergesellschaft von' => 'tochtergesellschaften', 'partner von' => 'partner von', // not directional
        'beteiligt an' => 'beteiligungen von',);
      foreach ($aggregated['beziehungen'] as $key => $value) {
        if ($value['ziel_organisation_id'] === $aggregated['id']) {
          $aggregated['beziehungen'][$key]['art'] = $reverse_art[$value['art']];
          $aggregated['beziehungen'][$key]['ziel_organisation_id'] = $value['organisation_id'];
          $aggregated['beziehungen'][$key]['ziel_organisation_name'] = $value['organisation_name'];
          $aggregated['beziehungen'][$key]['organisation_name'] = $aggregated['name'];
          $aggregated['beziehungen'][$key]['organisation_id'] = $aggregated['id'];
        }
      }

      $parlamentarier = _lobbywatch_data_table_flat_list('organisation_parlamentarier', "organisation_parlamentarier.organisation_id = $id", false);
      $aggregated['parlamentarier'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];

      foreach ($aggregated['parlamentarier'] as $key => $value) {
        $verguetungen = _lobbywatch_data_table_flat_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", false, 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['parlamentarier'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit']);
      }

      $zutrittsberechtigung = _lobbywatch_data_table_flat_list('organisation_zutrittsberechtigung', "organisation_zutrittsberechtigung.organisation_id = $id", false);
      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigung['data'];
      $message .= ' | ' . $zutrittsberechtigung['message'];
      $sql .= ' | ' . $zutrittsberechtigung['sql'];

      foreach ($aggregated['zutrittsberechtigte'] as $key => $value) {
        $parlamentarier = _lobbywatch_data_table_flat_id('parlamentarier', $value['parlamentarier_id'], false);
        $aggregated['zutrittsberechtigte'][$key]['parlamentarier'] = $parlamentarier['data'];
        $message .= ' | ' . $parlamentarier['message'];
        $sql .= ' | ' . $parlamentarier['sql'];
      }

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organsation['count'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }

}

/** Add knowledge_articles of current language. The Drupal 7 translation system is used. */
function _lobbywatch_add_wissensartikel($source_table, $id, &$aggregated, &$message, &$sql) {
  $lang = get_lang();
  $knowledge_articles = _lobbywatch_data_table_flat_list('wissensartikel_link', "wissensartikel_link.target_id = $id AND wissensartikel_link.target_table_name = '$source_table'", false, '', 'JOIN v_d7_node node ON node.tnid_nid = (SELECT tnid_nid FROM v_d7_node WHERE nid=wissensartikel_link.node_id) AND node.status=1' . ($lang ? " AND node.language = '$lang'" : ''), ', node.tnid_nid, node.language article_language, node.type article_type, node.status article_status, node.nid article_nid, node.title as article_title');
  $aggregated['knowledge_articles'] = $knowledge_articles['data'];
  $message .= ' | ' . $knowledge_articles['message'];
  $sql .= ' | ' . $knowledge_articles['sql'];
}

function _lobbywatch_data_table_interessengruppe_aggregated_id($id, $json = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'interessengruppe';

  try {
    $interessengruppe = _lobbywatch_data_table_flat_id($table, $id, false);
    $aggregated = $interessengruppe['data'];
    $message .= ' | ' . $interessengruppe['message'];
    $sql .= ' | ' . $interessengruppe['sql'];
    $success = $success && $interessengruppe['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $organisationen = _lobbywatch_data_table_flat_list('organisation', "organisation.interessengruppe_id = $id OR organisation.interessengruppe2_id = $id OR organisation.interessengruppe3_id = $id", false);

      $aggregated['organisationen'] = $organisationen['data'];
      $message .= ' | ' . $organisationen['message'];
      $sql .= ' | ' . $organisationen['sql'];

      $aggregated_parlamentarier = _lobbywatch_data_get_parlamentarier_from_organisation($organisationen['data']);
      $aggregated = array_merge($aggregated, $aggregated_parlamentarier);

      $zwischen_organisationen_conditions = array_map(function ($con) {
        return "organisation.id = " . $con['zwischen_organisation_id'];
      }, array_filter($aggregated['connections'], function ($con) {
        return !empty($con['zwischen_organisation_id']);
      }));
      $zwischen_organisationen_conditions = !empty($zwischen_organisationen_conditions) ? $zwischen_organisationen_conditions : ['1=0'];

      $zwischen_organisationen = _lobbywatch_data_table_flat_list('organisation', "(" . implode(" OR ", $zwischen_organisationen_conditions) . ")", false);

      $aggregated['zwischen_organisationen'] = $zwischen_organisationen['data'];
      $message .= ' | ' . $zwischen_organisationen['message'];
      $sql .= ' | ' . $zwischen_organisationen['sql'];

      $zutrittsberechtigte_conditions = array_map(function ($con) {
        return "zutrittsberechtigung.person_id = " . $con['person_id'];
      }, array_filter($aggregated['connections'], function ($con) {
        return !empty($con['person_id']);
      }));
      $zutrittsberechtigte_conditions = !empty($zutrittsberechtigte_conditions) ? $zutrittsberechtigte_conditions : ['1=0'];

      $zutrittsberechtigte = _lobbywatch_data_table_flat_list('zutrittsberechtigung', "(" . implode(" OR ", $zutrittsberechtigte_conditions) . ")", false);

      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigte['data'];
      $message .= ' | ' . $zutrittsberechtigte['message'];
      $sql .= ' | ' . $zutrittsberechtigte['sql'];

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organsation['count'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

/**
 * @param $orgs array of organisation having 'id'
 */
function _lobbywatch_data_get_parlamentarier_from_organisation($orgs) {

  $aggregated = [];

  $org_conditions = array_map(function ($org) {
    return "organisation_parlamentarier_beide_indirekt.connector_organisation_id = " . $org['id'];
  }, $orgs);

  $connections = _lobbywatch_data_table_flat_list('organisation_parlamentarier_beide_indirekt', "(" . implode(" OR ", $org_conditions) . ")", false);

  $aggregated['connections'] = $connections['data'];
  $message .= ' | ' . $connections['message'];
  $sql .= ' | ' . $connections['sql'];

  $parlamentarier_conditions = array_map(function ($con) {
    return "parlamentarier.id = " . $con['parlamentarier_id'];
  }, $connections['data']);

  $parlamentarier = _lobbywatch_data_table_flat_list('parlamentarier', "(" . implode(" OR ", $parlamentarier_conditions) . ")", false);

  $aggregated['parlamentarier'] = $parlamentarier['data'];
  $message .= ' | ' . $parlamentarier['message'];
  $sql .= ' | ' . $parlamentarier['sql'];

  return $aggregated;
}

function _lobbywatch_data_table_branche_aggregated_id($id, $json = true) {
  global $show_sql;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'branche';

  try {
    $branche = _lobbywatch_data_table_flat_id($table, $id, false);
    $aggregated = $branche['data'];
    $message .= ' | ' . $branche['message'];
    $sql .= ' | ' . $branche['sql'];
    $success = $success && $branche['success'];

    // load aggregated data only if main object is there
    if ($success) {
      // interessengruppe
      $interessengruppe = _lobbywatch_data_table_flat_list('interessengruppe', "interessengruppe.branche_id = $id", false);

      $aggregated['interessengruppe'] = $interessengruppe['data'];
      $message .= ' | ' . $interessengruppe['message'];
      $sql .= ' | ' . $interessengruppe['sql'];
      $success = $success && $interessengruppe['success'];

      // organisations
      $organisationen = _lobbywatch_data_table_flat_list('organisation', "organisation.interessengruppe_branche_id = $id OR organisation.interessengruppe2_branche_id = $id OR organisation.interessengruppe3_branche_id = $id", false);

      $aggregated['organisationen'] = $organisationen['data'];
      $message .= ' | ' . $organisationen['message'];
      $sql .= ' | ' . $organisationen['sql'];
      $success = $success && $organisationen['success'];

      // parlamentarier
      $aggregated_parlamentarier = _lobbywatch_data_get_parlamentarier_from_organisation($organisationen['data']);
      $aggregated = array_merge($aggregated, $aggregated_parlamentarier);

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $branche['count'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_query_parlament_partei_aggregated_list($condition = '1', $json = true) {
  global $show_sql;
  $success = true;
  $message = '';
  $count = 0;
  $items = null;


  try {
    $lang_suffix = get_lang_suffix();

    $table = 'parlamentarier';
    $sql = "
select count(*) as anzahl, partei.id, $table.partei$lang_suffix as partei_short, partei.anzeige_name$lang_suffix as partei_name, $table.fraktion
from v_parlamentarier $table
inner join v_partei partei
on partei.id = $table.partei_id
WHERE $condition " . filter_fields_SQL($table) . "
group by $table.partei
order by count(*) desc, $table.partei asc ";

    $result = db_query($sql, []);

    $items['totalMembers'] = 246;
    $items['parteien'] = _lobbywatch_data_clean_records($result);

    $count = count($items['parteien']);
    $message .= $count . " record(s) found";
    $success = $count > 0;

    foreach ($items['parteien'] as &$record) {
      $parlamentarier = _lobbywatch_data_table_flat_list('parlamentarier', "parlamentarier.partei_id = {$record['id']}", false);
      $record['members'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];
      $success = $success && ($parlamentarier['success'] || (!$parlamentarier['success'] && $parlamentarier['data']['members'] == 0));
    }

  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $items);

    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_router($path = '', $version = '', $data_type = '', $call_type = '', $object = '', $response_type = '', $respone_object = '', $parameter = '', $json_output = false) {
  if ($version !== 'v1' || $data_type !== 'json') {
    json_not_found();
  }

  lobbywatch_set_lang(_lobbywatch_data_get_lang());

  if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_flat_id($object, $parameter, false);
  } else if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $respone_object === 'list' && $parameter) {
    return _lobbywatch_data_table_flat_list_search($object, $parameter, false);
  } else if ($call_type === 'table' && array_key_exists($object, Constants::$workflow_tables) && $response_type === 'flat' && $respone_object === 'list') {
    return _lobbywatch_data_table_flat_list($object, 1, false);
  } else if ($call_type === 'ws' && (in_array($object, ['uid', 'zefix-soap', 'zefix-rest', 'uid-bfs'])) && $response_type === 'flat' && $respone_object === 'uid' && $parameter) {
    return _lobbywatch_data_ws_uid($object, $parameter, false);
  } else if ($call_type === 'relation' && array_key_exists($object, Constants::getAllEnrichedRelations()) && $response_type === 'flat' && $respone_object === 'list') {
    return _lobbywatch_data_relation_flat_list($object, 1, false);
  } else if ($call_type === 'table' && $object === 'zutrittsberechtigung' && $response_type === 'aggregated' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_zutrittsberechtigte_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'parlamentarier' && $response_type === 'aggregated' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_parlamentarier_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'organisation' && $response_type === 'aggregated' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_organisation_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'interessengruppe' && $response_type === 'aggregated' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_interessengruppe_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'branche' && $response_type === 'aggregated' && $respone_object === 'id' && $parameter) {
    return _lobbywatch_data_table_branche_aggregated_id($parameter, false);
  } else if ($call_type === 'query' && $object === 'parlament-partei' && $response_type === 'aggregated' && $respone_object === 'list') {
    return _lobbywatch_data_query_parlament_partei_aggregated_list(1, false);
  } else if ($call_type === 'search' && $object === 'default' /*&& $response_type === 'aggregated' && $respone_object === 'list'*/ /*&& $parameter*/) {
    return _lobbywatch_data_search($response_type, false);
  }

  json_not_found();
}

function _lobbywatch_data_ws_uid($table, $uid, $json = true) {
  global $show_sql;
  global $no_cors;
  global $zefix_ws_login;
  global $allowed_uid_access_keys;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';
  $sql = '';

  // Protect Zefix WS, either with key or from cyon.ch server or localhost
  if (in_array($table, ['uid', 'zefix-rest', 'uid-bfs']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $allowed_uid_access_keys, true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_forbidden();
  } else if (in_array($table, ['zefix-soap']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $zefix_ws_login['keys'], true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_forbidden();
  }


  try {
    if ($table == 'uid-bfs') {
      $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-soap') {
      $items = _lobbywatch_fetch_ws_zefix_soap_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-rest') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'uid') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      if (!$items['success']) {
        $message .= "zefix-rest unsuccessful ({$items['message']}), calling uid@bfs | ";
        $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false, 0);
      }
      $no_cors = true; // Disable CORS since it is a protected service
    } else {
      // Must not happen
      $items = null;
    }

    $count = $items['count']; // already set by fillDataFromUIDResult()
    $success = $items['success'];
    $message .= $items['message'];
    $sql .= $items['sql'];
  } catch (Exception $e) {
    $message .= _lobbywatch_data_add_exeption($e);
    $success = false;
  } finally {
    $response = array('success' => $success, 'count' => $count, 'message' => $message, 'sql' => $show_sql ? $sql : '', 'source' => $table, 'build secs' => '' . page_build_secs(), 'data' => $success ? $items['data'] : null,);

    if ($json) {
      json_response($response, cors: !$no_cors);
    } else {
      return $response;
    }
  }
}

function json_not_found(): never {
  $response = array(
    'success' => false,
    'count' => 0,
    'message' => '404 Not Found. The requested URL "' . check_plain(request_uri()) . '" was not found on this server.',
    'sql' => '',
    'source' => '',
    'build secs' => page_build_secs(),
    'data' => null,
  );
  json_response($response, 404);
}

function json_forbidden(): never {
  $response = array('success' => false,
    'count' => 0,
    'message' => '403 Forbidden. The requested URL "' . check_plain(request_uri()) . '" is protected.',
    'sql' => '',
    'source' => '',
    'build secs' => page_build_secs(),
    'data' => null,
  );
  json_response($response, 403);
}