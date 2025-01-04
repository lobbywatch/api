<?php

test('table/branche/flat/list', function () {
  $response = $this->get('/data/interface/v1/json/table/branche/flat/list');
  expect($response)->toMatchSnapshot();
});
