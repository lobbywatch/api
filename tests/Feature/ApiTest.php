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

test('table', function (string $path) {
  $response = $this->get('/data/interface/v1/json/table' . $path);
  expect($response)->toMatchSnapshot();
})->with([
  '/branche/aggregated/id/1',
  '/branche/flat/list',
  '/interessengruppe/aggregated/id/1',
  '/interessengruppe/flat/list',
  '/organisation/aggregated/id/2',
//  '/organisation/flat/list?select_fields=uid,name_de,name_fr,rechtsform',
  '/parlamentarier/aggregated/id/6',
//  '/parlamentarier/flat/list',
//  '/parlamentarier/flat/list?select_fields=parlament_number,vorname,nachname',
//  '/zutrittsberechtigung/aggregated/id/1',
  '/zutrittsberechtigung/flat/list',
//  '/zutrittsberechtigung/flat/list?select_fields=id,vorname,nachname',
]);

