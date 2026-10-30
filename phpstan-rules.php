<?php

/**
 * @file
 * Registers this module's own PHPStan rules.
 *
 * PHPStan compiles its container before it runs the bootstrap files, so
 * a rule class the bootstrap's autoloader would find is not found yet.
 * A PHP config file is read while the configuration loads: it loads the
 * rule itself and names it.
 */

declare(strict_types=1);

use Drupal\Tests\surface\PHPStan\DoorSpecificRule;
use Drupal\Tests\surface\PHPStan\StaticRefinerRule;

require_once __DIR__ . '/tests/src/PHPStan/StaticRefinerRule.php';
require_once __DIR__ . '/tests/src/PHPStan/DoorSpecificRule.php';

return [
  'rules' => [
    StaticRefinerRule::class,
    DoorSpecificRule::class,
  ],
];
