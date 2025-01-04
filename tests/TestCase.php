<?php
declare(strict_types=1);

namespace Tests;

use Exception;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase {
  public function get($path): array {
    $baseUrl = "http://127.0.0.1:8000";
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $baseUrl . $path,
      CURLOPT_RETURNTRANSFER => true
    ]);
    try {
      $response = json_decode(curl_exec($curl), associative: true, flags: JSON_THROW_ON_ERROR);
      $error = curl_error($curl);
    } catch (Exception $e) {
      $error = $e->getMessage();
      return ["error" => $error];
    } finally {
      curl_close($curl);
    }
    return $response;
  }
}
