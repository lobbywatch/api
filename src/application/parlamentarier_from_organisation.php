<?php
declare(strict_types=1);

namespace App\Application;

/**
 * @param $orgs array of organisation having 'id'
 */
function get_parlamentarier_from_organisation(array $orgs): array {

  $aggregated = [];
  // FIXME Need to pass this into the function
  $message ??= '';
  // FIXME Need to pass this into the function
  $sql ??= '';

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
