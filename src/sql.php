<?php
declare(strict_types=1);

namespace App\Sql;

use App\Constants;
use function App\Domain\IdentityAccess\user_access;
use function App\Lib\Http\base_root;
use function App\Lib\Localization\get_current_lang;
use function App\Lib\String\{clean_db_value, clean_str};
use function App\Settings\getRechercheJahrFromSettings;
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

function filter_limit_SQL(): string {
    if (isset($_GET['limit']) && $_GET['limit'] == 'none') {
        return '';
    } else {
        return " LIMIT " . (isset($_GET['limit']) && is_int($limit = $_GET['limit'] + 0) && $limit > 0 ? $limit : 10) . " ";
    }
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

function clean_records($result) {
    $items = [];
    $includeHistorised = isset($_GET['includeInactive']) && $_GET['includeInactive'] == 1 && user_access('access lobbywatch unpublished content');

    // TODO test exclusion of historised records
    foreach ($result as $record) {
        if ((
                (!array_key_exists('bis_unix', $record) || (is_null($record['bis_unix']) || $record['bis_unix'] > time()))
                && (!array_key_exists('im_rat_bis_unix', $record) || (is_null($record['im_rat_bis_unix']) || $record['im_rat_bis_unix'] > time()))
                && (!array_key_exists('zutrittsberechtigung_bis_unix', $record) || (is_null($record['zutrittsberechtigung_bis_unix']) || $record['zutrittsberechtigung_bis_unix'] > time()))
            )
            || $includeHistorised
        ) {
            $fields = data_clean_fields($record);
            enrich_fields($fields);
            $items[] = $fields;
        }
    }

    _lobbywatch_data_handle_lang_fields($items);

    return $items;
}

function enrich_fields(array &$items) {
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

function data_clean_fields($input_record) {

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

function data_transformation($table, &$items) {
    if (in_array($table, ['organisation', 'interessenbindung_liste'], true)) {
        $lang = get_current_lang();

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
        $lang = get_current_lang();

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

function add_verguetungen(&$arr, $verguetungen, $ib_von, $im_rat_seit = null) {
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
