#!/usr/bin/env php
<?php

/**
 * @file
 * Regenerates docs/catalogue.md, the catalogue of discovered surfaces.
 *
 * The catalogue lists every surface the modules in this repository
 * declare, with its identity, situations, alters and variants, as
 * SurfaceCatalogue::describe() reads them. Discovery needs a booted
 * kernel with those modules enabled, and the one supported way to boot
 * one is a kernel test, so — exactly like scripts/generate-comparison.php
 * — this script asks PHPUnit to run SurfaceCatalogueTest's drift test
 * with writing turned on. What the test writes is rendered by the
 * renderer beside this script, and what it asserts on every ordinary run
 * is that the checked-in file still matches that renderer.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/data_surface/scripts/generate-catalogue.php
 * @endcode
 *
 * @see scripts/DataSurfaceCatalogueDocument.php
 * @see \Drupal\Tests\data_surface\Kernel\SurfaceCatalogueTest
 */

declare(strict_types=1);

require_once __DIR__ . '/DataSurfaceCatalogueDocument.php';

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
  DataSurfaceCatalogueDocument::WRITE_VARIABLE,
  escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
  escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
  escapeshellarg($phpunit),
  escapeshellarg('testCatalogueHasNotDrifted'),
  escapeshellarg($module . '/tests/src/Kernel/SurfaceCatalogueTest.php'),
);
fwrite(STDOUT, 'Regenerating ' . DataSurfaceCatalogueDocument::PATH . " ...\n");
passthru($command, $status);
exit($status);
