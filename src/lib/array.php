<?php
declare(strict_types=1);

namespace App\Lib\Array;

// https://stackoverflow.com/questions/36602362/how-can-i-find-the-max-attribute-in-an-array-of-objects
function max_attribute_in_array($array, $prop) {
  return max(array_column($array, $prop));
}
