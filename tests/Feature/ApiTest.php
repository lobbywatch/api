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
//  '/organisation/aggregated/id/1',
//  '/parlamentarier/aggregated/id/1',
//  '/parlamentarier/flat/id/1',
//  '/parlamentarier/flat/list',
//  '/zutrittsberechtigung/aggregated/id/1',
//  '/zutrittsberechtigung/flat/list',
]);

