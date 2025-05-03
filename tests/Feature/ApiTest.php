<?php

test('unknown', function () {
  $response = $this->get('/unknown/path');
  expect($response)->toMatchSnapshot();
});

test('relation', function (string $path) {
  $response = $this->get('/data/interface/v1/json/relation' . $path);
  expect($response)->toMatchSnapshot();
})->with([
  '/in_kommission_liste/flat/list',
]);

test('table', function (string $path, array $query = []) {
  $response = $this->get('/data/interface/v1/json/table' . $path, $query);
  expect($response)->toMatchSnapshot();
})->with([
  '/branche/aggregated/id/1',
  '/branche/flat/list',
  ['/branche/flat/list', ['select_fields' => 'id,name']],

  '/interessengruppe/aggregated/id/1',
  '/interessengruppe/flat/list',
  ['/interessengruppe/flat/list', ['select_fields' => 'id,name']],

  '/organisation/aggregated/id/2',
  // Querying this without any field restrictions exhausts the memory
  [
    '/organisation/flat/list',
    ['select_fields' => 'name_de,name_fr,rechtsform,ort,abkuerzung_de,abkuerzung_fr,interessengruppe_de,interessengruppe_fr,interessengruppe_id,interessengruppe2_de,interessengruppe2_fr,interessengruppe2_id,interessengruppe3_de,interessengruppe3_fr,interessengruppe3_id,uid,alias_namen_de,alias_namen_fr']
  ],

  '/parlamentarier/aggregated/id/6',
  '/parlamentarier/flat/list',
  ['/parlamentarier/flat/list', ['select_fields' => 'parlament_number,vorname,nachname']],

  '/zutrittsberechtigung/aggregated/id/1566',
  '/zutrittsberechtigung/flat/list',
  ['/zutrittsberechtigung/flat/list', ['select_fields' => 'id,vorname,nachname']],
]);

