<?php
declare(strict_types=1);

namespace Tests;

use Exception;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase {
  public function get($path): array {
    $lang = 'de';
    $baseUrl = "http://127.0.0.1:8000";
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $baseUrl . '/data.php?' . http_build_query([
          'q' => $lang . $path,
          'includeMetaData' => 1,
          'limit' => 'none',
          'lang' => $lang
        ]),
      CURLOPT_RETURNTRANSFER => true
    ]);
    try {
      $response = curl_exec($curl);
      $error = curl_error($curl);
    } catch (Exception $e) {
      $error = $e->getMessage();
      return ["error" => $error];
    } finally {
      curl_close($curl);
    }

    $result = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
    // Build secs can differ between requests
    unset($result['build secs']);
    return $result;
  }
}
