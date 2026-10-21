#!/usr/bin/env php
<?php

/**
 * @file
 * Regenerates docs/catalog.md, listing every resource and structure.
 *
 * The catalog lists every resource and structure the modules in this
 * repository declare, with its kind, identity, storage, operations,
 * alters and variants, as ResourceCatalog::describe() reads them.
 * Discovery needs a booted kernel with those modules enabled, and the one
 * supported way to boot one is a kernel test, so (exactly like
 * scripts/generate-comparison.php) this script asks PHPUnit to run
 * ResourceCatalogTest's drift test with writing turned on. What the
 * test writes is rendered by the renderer beside this script, and what it
 * asserts on every ordinary run is that the checked-in file still matches
 * that renderer.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/data_surface/scripts/generate-catalog.php
 * @endcode
 *
 * @see scripts/DataSurfaceCatalogDocument.php
 * @see \Drupal\Tests\data_surface\Kernel\ResourceCatalogTest
 */

declare(strict_types=1);

require_once __DIR__ . '/DataSurfaceCatalogDocument.php';

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
  DataSurfaceCatalogDocument::WRITE_VARIABLE,
  escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
  escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
  escapeshellarg($phpunit),
  escapeshellarg('testCatalogueHasNotDrifted'),
  escapeshellarg($module . '/tests/src/Kernel/ResourceCatalogTest.php'),
);
fwrite(STDOUT, 'Regenerating ' . DataSurfaceCatalogDocument::PATH . " ...\n");
passthru($command, $status);
exit($status);
