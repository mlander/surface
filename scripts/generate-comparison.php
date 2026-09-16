#!/usr/bin/env php
<?php

/**
 * @file
 * Regenerates the data_surface_tool module's COMPARISON.md.
 *
 * The document puts two settings inputs for one field type side by side:
 * the one a tool derives from the field type's config schema, and the one
 * it derives from the field type's surface. Both are produced the way an
 * invoker produces them, by the Tool API's own definition serializer, so
 * the file is a record of what a real MCP client would be handed.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/data_surface/scripts/generate-comparison.php
 * @endcode
 *
 * The generator needs a Drupal kernel carrying seven modules that are not
 * installed on an ordinary site — entity_test among them — plus an address
 * field to describe. That is a kernel test environment, and Drupal has
 * exactly one supported way to boot one, so this script asks PHPUnit for
 * it and lets FieldToolsComparisonTest do the work. What the test writes
 * is rendered by DataSurfaceComparisonDocument, and what the test asserts
 * on every ordinary run is that the checked-in file still matches that
 * same renderer. There is one generator, and the file cannot drift away
 * from it unnoticed.
 *
 * Including this file defines nothing and runs nothing; the block below
 * fires only when the file is the entry point, so the renderer beside it
 * can be included from anywhere without starting a second PHPUnit.
 *
 * @see scripts/DataSurfaceComparisonDocument.php
 * @see \Drupal\Tests\data_surface\Kernel\FieldToolsComparisonTest
 */

declare(strict_types=1);

require_once __DIR__ . '/DataSurfaceComparisonDocument.php';

// Everything below runs only when this file is the entry point.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
  return;
}

$module = dirname(__DIR__);
$web_root = dirname($module, 3);
$phpunit = dirname($web_root) . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
  fwrite(STDERR, "Could not find PHPUnit at $phpunit.\n");
  exit(1);
}

$command = sprintf(
  'cd %s && %s=1 SIMPLETEST_DB=%s SIMPLETEST_BASE_URL=%s %s -c core --filter %s %s',
  escapeshellarg($web_root),
  DataSurfaceComparisonDocument::WRITE_VARIABLE,
  escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
  escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
  escapeshellarg($phpunit),
  escapeshellarg('testComparisonHasNotDrifted'),
  escapeshellarg($module . '/tests/src/Kernel/FieldToolsComparisonTest.php'),
);

fwrite(STDOUT, "Regenerating modules/data_surface_tool/COMPARISON.md ...\n");
passthru($command, $status);
exit($status);
