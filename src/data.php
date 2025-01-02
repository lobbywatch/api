<?php

use function App\Application\table_by_id;
use function App\Application\table_list;
use function App\domain\ApiResponse\{api_response, forbidden_response};
use function App\Lib\Array\max_attribute_in_array;
use function App\Lib\Http\{add_exception, json_response};
use function App\Lib\Localization\{get_current_lang, translate_record_field};
use function App\Sql\{clean_records, filter_fields_SQL, filter_limit_SQL};
use function App\Store\{db_query};

/**
 * This is the original Drupal 7 module that was used for providing the data API.
 * We copy it into this repo to gradually transform into Drupal-free code.
 */

global $show_sql, $show_stacktrace;
$show_sql = true;
$show_stacktrace = true;


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
  $lang = get_current_lang();
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
  global $show_sql, $show_stacktrace;
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

    $sql .= filter_limit_SQL() . ';';

    $result = db_query($sql, array(':str' => _lobbywatch_search_keyword_processing($search_str)));

    $items = clean_records($result);

    $count = count($items);
    $success = $count > 0;
    $message .= count($items) . " record(s) found ";
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_table_zutrittsberechtigte_aggregated_id($id, $json = true) {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'zutrittsberechtigung';

  try {
    $zutrittsberechtigung = table_by_id('zutrittsberechtigung', $id);
    $aggregated = $zutrittsberechtigung['data'];
    $message .= ' | ' . $zutrittsberechtigung['message'];
    $sql .= ' | ' . $zutrittsberechtigung['sql'];
    $success = $success && $zutrittsberechtigung['success'];

    $last_modified_date = $aggregated['updated_date'];
    $last_modified_date_unix = $aggregated['updated_date_unix'];

    if ($success) {
      $person_id = $zutrittsberechtigung['data']['person_id'];
      $mandate = table_list('person_mandate', "person_mandate.person_id = $person_id");
      $aggregated['mandate'] = $mandate['data'];
      $message .= ' | ' . $mandate['message'] . '';
      $sql .= ' | ' . $mandate['sql'] . '';
      // $success = $success && $mandate['success'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['mandate'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['mandate'], 'updated_date_unix'));

      foreach ($aggregated['mandate'] as $key => $value) {
        $verguetungen = table_list('mandat_jahr', "mandat_jahr.mandat_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['mandate'][$key], $verguetungen, $value['von']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      //     dpm($zutrittsberechtigung, '$zutrittsberechtigung');
      // TODO handle duplicate zutrittsberechtigung due to historization
      $parlamentarier_id = $zutrittsberechtigung['data']['parlamentarier_id'];
      $parlamentarier = table_list('parlamentarier', "parlamentarier.id = $parlamentarier_id");
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
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
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
  global $show_sql, $show_stacktrace;
  global $env;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'parlamentarier';


  try {
    $message .= "$env";
    $parlamentarier = table_by_id('parlamentarier', $id);
    $aggregated = $parlamentarier['data'];
    $message .= ' | ' . $parlamentarier['message'];
    $sql .= ' | ' . $parlamentarier['sql'];
    $success = $success && $parlamentarier['success'];

    $last_modified_date = $aggregated['updated_date'];
    $last_modified_date_unix = $aggregated['updated_date_unix'];

    // load aggregated data only if main object is there
    if ($success) {
      $in_kommissionen = table_list('in_kommission_liste', "in_kommission_liste.parlamentarier_id = $id");
      $aggregated['in_kommission'] = $in_kommissionen['data'];
      $message .= ' | ' . $in_kommissionen['message'];
      $sql .= ' | ' . $in_kommissionen['sql'];

      $interessenbindungen = table_list('interessenbindung_liste', "interessenbindung_liste.parlamentarier_id = $id");
      $aggregated['interessenbindungen'] = $interessenbindungen['data'];
      $message .= ' | ' . $interessenbindungen['message'];
      $sql .= ' | ' . $interessenbindungen['sql'];
      $last_modified_date = max($last_modified_date, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date'));
      $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($aggregated['interessenbindungen'], 'updated_date_unix'));

      foreach ($aggregated['interessenbindungen'] as $key => $value) {
        $verguetungen = table_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['interessenbindungen'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit']);
        $last_modified_date = max($last_modified_date, max_attribute_in_array($verguetungen['data'], 'updated_date'));
        $last_modified_date_unix = max($last_modified_date_unix, max_attribute_in_array($verguetungen['data'], 'updated_date_unix'));
      }

      $verguetungs_tranparenz = table_list('parlamentarier_transparenz', "parlamentarier_transparenz.parlamentarier_id = $id");
      $aggregated['verguetungs_tranparenz'] = $verguetungs_tranparenz['data'];
      $message .= ' | ' . $verguetungs_tranparenz['message'];
      $sql .= ' | ' . $verguetungs_tranparenz['sql'];

      $zutrittsberechtigungen = table_list('zutrittsberechtigung', "zutrittsberechtigung.parlamentarier_id = $id");
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
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_table_organisation_aggregated_id($id, $json = true) {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'organisation';

  try {
    $organsation = table_by_id('organisation', $id);
    $aggregated = $organsation['data'];
    $message .= ' | ' . $organsation['message'];
    $sql .= ' | ' . $organsation['sql'];
    $success = $success && $organsation['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $beziehungen = table_list('organisation_beziehung', "organisation_beziehung.organisation_id = $id OR organisation_beziehung.ziel_organisation_id = $id");

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

      $parlamentarier = table_list('organisation_parlamentarier', "organisation_parlamentarier.organisation_id = $id");
      $aggregated['parlamentarier'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];

      foreach ($aggregated['parlamentarier'] as $key => $value) {
        $verguetungen = table_list('interessenbindung_jahr', "interessenbindung_jahr.interessenbindung_id = {$value['id']}", 'ORDER BY jahr DESC');
        $message .= ' | ' . $verguetungen['message'];
        $sql .= ' | ' . $verguetungen['sql'];
        _add_verguetungen($aggregated['parlamentarier'][$key], $verguetungen, $value['von'], $parlamentarier['data']['im_rat_seit']);
      }

      $zutrittsberechtigung = table_list('organisation_zutrittsberechtigung', "organisation_zutrittsberechtigung.organisation_id = $id");
      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigung['data'];
      $message .= ' | ' . $zutrittsberechtigung['message'];
      $sql .= ' | ' . $zutrittsberechtigung['sql'];

      foreach ($aggregated['zutrittsberechtigte'] as $key => $value) {
        $parlamentarier = table_by_id('parlamentarier', $value['parlamentarier_id']);
        $aggregated['zutrittsberechtigte'][$key]['parlamentarier'] = $parlamentarier['data'];
        $message .= ' | ' . $parlamentarier['message'];
        $sql .= ' | ' . $parlamentarier['sql'];
      }

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organsation['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }

}

/** Add knowledge_articles of current language. The Drupal 7 translation system is used. */
function _lobbywatch_add_wissensartikel($source_table, $id, &$aggregated, &$message, &$sql) {
  $lang = get_current_lang();
  $knowledge_articles = table_list('wissensartikel_link', "wissensartikel_link.target_id = $id AND wissensartikel_link.target_table_name = '$source_table'", '', 'JOIN v_d7_node node ON node.tnid_nid = (SELECT tnid_nid FROM v_d7_node WHERE nid=wissensartikel_link.node_id) AND node.status=1' . ($lang ? " AND node.language = '$lang'" : ''), ', node.tnid_nid, node.language article_language, node.type article_type, node.status article_status, node.nid article_nid, node.title as article_title');
  $aggregated['knowledge_articles'] = $knowledge_articles['data'];
  $message .= ' | ' . $knowledge_articles['message'];
  $sql .= ' | ' . $knowledge_articles['sql'];
}

function _lobbywatch_data_table_interessengruppe_aggregated_id($id, $json = true) {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'interessengruppe';

  try {
    $interessengruppe = table_by_id($table, $id);
    $aggregated = $interessengruppe['data'];
    $message .= ' | ' . $interessengruppe['message'];
    $sql .= ' | ' . $interessengruppe['sql'];
    $success = $success && $interessengruppe['success'];

    // load aggregated data only if main object is there
    if ($success) {
      $organisationen = table_list('organisation', "organisation.interessengruppe_id = $id OR organisation.interessengruppe2_id = $id OR organisation.interessengruppe3_id = $id");

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

      $zwischen_organisationen = table_list('organisation', "(" . implode(" OR ", $zwischen_organisationen_conditions) . ")");

      $aggregated['zwischen_organisationen'] = $zwischen_organisationen['data'];
      $message .= ' | ' . $zwischen_organisationen['message'];
      $sql .= ' | ' . $zwischen_organisationen['sql'];

      $zutrittsberechtigte_conditions = array_map(function ($con) {
        return "zutrittsberechtigung.person_id = " . $con['person_id'];
      }, array_filter($aggregated['connections'], function ($con) {
        return !empty($con['person_id']);
      }));
      $zutrittsberechtigte_conditions = !empty($zutrittsberechtigte_conditions) ? $zutrittsberechtigte_conditions : ['1=0'];

      $zutrittsberechtigte = table_list('zutrittsberechtigung', "(" . implode(" OR ", $zutrittsberechtigte_conditions) . ")");

      $aggregated['zutrittsberechtigte'] = $zutrittsberechtigte['data'];
      $message .= ' | ' . $zutrittsberechtigte['message'];
      $sql .= ' | ' . $zutrittsberechtigte['sql'];

      _lobbywatch_add_wissensartikel($table, $id, $aggregated, $message, $sql);
    }

    $count = $organsation['count'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
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

  $connections = table_list('organisation_parlamentarier_beide_indirekt', "(" . implode(" OR ", $org_conditions) . ")");

  $aggregated['connections'] = $connections['data'];
  $message .= ' | ' . $connections['message'];
  $sql .= ' | ' . $connections['sql'];

  $parlamentarier_conditions = array_map(function ($con) {
    return "parlamentarier.id = " . $con['parlamentarier_id'];
  }, $connections['data']);

  $parlamentarier = table_list('parlamentarier', "(" . implode(" OR ", $parlamentarier_conditions) . ")");

  $aggregated['parlamentarier'] = $parlamentarier['data'];
  $message .= ' | ' . $parlamentarier['message'];
  $sql .= ' | ' . $parlamentarier['sql'];

  return $aggregated;
}

function _lobbywatch_data_table_branche_aggregated_id($id, $json = true) {
  global $show_sql, $show_stacktrace;
  $success = true;
  $count = 0;
  $message = '';
  $sql = '';
  $table = 'branche';

  try {
    $branche = table_by_id($table, $id);
    $aggregated = $branche['data'];
    $message .= ' | ' . $branche['message'];
    $sql .= ' | ' . $branche['sql'];
    $success = $success && $branche['success'];

    // load aggregated data only if main object is there
    if ($success) {
      // interessengruppe
      $interessengruppe = table_list('interessengruppe', "interessengruppe.branche_id = $id");

      $aggregated['interessengruppe'] = $interessengruppe['data'];
      $message .= ' | ' . $interessengruppe['message'];
      $sql .= ' | ' . $interessengruppe['sql'];
      $success = $success && $interessengruppe['success'];

      // organisations
      $organisationen = table_list('organisation', "organisation.interessengruppe_branche_id = $id OR organisation.interessengruppe2_branche_id = $id OR organisation.interessengruppe3_branche_id = $id");

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
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $aggregated);
    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_query_parlament_partei_aggregated_list($condition = '1', $json = true) {
  global $show_sql, $show_stacktrace;
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
    $items['parteien'] = clean_records($result);

    $count = count($items['parteien']);
    $message .= $count . " record(s) found";
    $success = $count > 0;

    foreach ($items['parteien'] as &$record) {
      $parlamentarier = table_list('parlamentarier', "parlamentarier.partei_id = {$record['id']}");
      $record['members'] = $parlamentarier['data'];
      $message .= ' | ' . $parlamentarier['message'];
      $sql .= ' | ' . $parlamentarier['sql'];
      $success = $success && ($parlamentarier['success'] || (!$parlamentarier['success'] && $parlamentarier['data']['members'] == 0));
    }

  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $items);

    if ($json) {
      json_response($response);
    } else {
      return $response;
    }
  }
}

function _lobbywatch_data_router($path = '', $version = '', $data_type = '', $call_type = '', $object = '', $response_type = '', $response_object = '', $parameter = '', $json_output = false) {
  if ($call_type === 'table' && $object === 'zutrittsberechtigung' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
    return _lobbywatch_data_table_zutrittsberechtigte_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'parlamentarier' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
    return _lobbywatch_data_table_parlamentarier_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'organisation' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
    return _lobbywatch_data_table_organisation_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'interessengruppe' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
    return _lobbywatch_data_table_interessengruppe_aggregated_id($parameter, false);
  } else if ($call_type === 'table' && $object === 'branche' && $response_type === 'aggregated' && $response_object === 'id' && $parameter) {
    return _lobbywatch_data_table_branche_aggregated_id($parameter, false);
  } else if ($call_type === 'query' && $object === 'parlament-partei' && $response_type === 'aggregated' && $response_object === 'list') {
    return _lobbywatch_data_query_parlament_partei_aggregated_list(1, false);
  } else if ($call_type === 'search' && $object === 'default' /*&& $response_type === 'aggregated' && $respone_object === 'list'*/ /*&& $parameter*/) {
    return _lobbywatch_data_search($response_type, false);
  }
}

function _lobbywatch_data_ws_uid($table, $uid, $json = true) {
  global $show_sql, $show_stacktrace;
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
    json_response(forbidden_response());
  } else if (in_array($table, ['zefix-soap']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $zefix_ws_login['keys'], true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_response(forbidden_response());
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
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $success ? $items['data'] : null);

    if ($json) {
      json_response($response, cors: !$no_cors);
    } else {
      return $response;
    }
  }
}

