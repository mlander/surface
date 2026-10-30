#!/usr/bin/env php
<?php

/**
 * @file
 * Regenerates the two generated COMPARISON.md documents.
 *
 * The surface_examples_field module's document puts two settings inputs
 * for one field type side by side: the one Tool Belt's field_add derives from
 * the field type's config schema, and the one
 * surface:field.instance:reuse, the tool generated from the field
 * instance resource's reuse operation, takes from the field type's
 * settings resource. The surface_examples_node_type_tool module's does
 * the same for creating a content type that another module has extended,
 * and records what each tool, and core's own form, did with the same
 * cases. Every schema is produced the way an invoker produces it, by the
 * Tool API's own definition serializer, so both files are a record of
 * what a real MCP client would be handed.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/surface/scripts/generate-comparison.php
 * @endcode
 *
 * The generator needs a Drupal kernel carrying modules that are not
 * installed on an ordinary site — entity_test among them — plus an address
 * field or a set of content types to describe. That is a kernel test
 * environment, and Drupal has exactly one supported way to boot one, so
 * this script asks PHPUnit for it and lets FieldToolsComparisonTest and
 * NodeTypeToolComparisonTest do the work. What each test writes is
 * rendered by the renderer beside this script, and what each test
 * asserts on every ordinary run is that its checked-in file still matches
 * that same renderer. There is one generator per document, and neither
 * file can drift away from it unnoticed.
 *
 * Including this file defines nothing and runs nothing; the block below
 * fires only when the file is the entry point, so the renderer beside it
 * can be included from anywhere without starting a second PHPUnit.
 *
 * @see scripts/SurfaceComparisonDocument.php
 * @see scripts/SurfaceNodeTypeComparisonDocument.php
 * @see \Drupal\Tests\surface\Kernel\FieldToolsComparisonTest
 * @see \Drupal\Tests\surface\Kernel\NodeTypeToolComparisonTest
 */

declare(strict_types=1);

require_once __DIR__ . '/SurfaceComparisonDocument.php';

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

$documents = [
  'modules/surface_examples_field/COMPARISON.md' => 'FieldToolsComparisonTest',
  'modules/surface_examples_node_type_tool/COMPARISON.md' => 'NodeTypeToolComparisonTest',
];
foreach ($documents as $document => $test) {
  $command = sprintf(
    'cd %s && %s=1 SIMPLETEST_DB=%s SIMPLETEST_BASE_URL=%s %s -c core --filter %s %s',
    escapeshellarg($web_root),
    SurfaceComparisonDocument::WRITE_VARIABLE,
    escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
    escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
    escapeshellarg($phpunit),
    escapeshellarg('testComparisonHasNotDrifted'),
    escapeshellarg($module . '/tests/src/Kernel/' . $test . '.php'),
  );
  fwrite(STDOUT, "Regenerating $document ...\n");
  passthru($command, $status);
  if ($status !== 0) {
    exit($status);
  }
}
exit(0);
