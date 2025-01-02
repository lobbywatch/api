<?php
declare(strict_types=1);

namespace App\Lib\Localization;

function get_lang_suffix(string|null $lang = null): string {
//    if ($lang === null) {
//        $lang = get_lang();
//    }
//
//    if ($lang == 'fr') {
//        return '_fr';
//    } else {
//        return '_de';
//    }
    return '_de';
}


function get_current_lang(): string {
//    global $language;
//    $langcode = isset($language->language) ?N $language->language : 'de';
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

function get_lang(): string {
    if (isset($_GET['lang']) && $_GET['lang'] == 'fr') {
        return 'fr';
    } else {
        return 'de';
    }
}

function lobbywatch_set_lang(string $lang): void {
    // TODO
    //  global $language;
    //  $old_lang = $language;
    //  $langs = array("de" => "de", "fr" => "fr");
    //  $language = $langs[$lang];
    //  return $old_lang;
}
