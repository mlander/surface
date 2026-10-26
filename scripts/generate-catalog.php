#!/usr/bin/env php
<?php

/**
 * @file
 * Regenerates docs/catalog.md, listing every resource and structure.
 *
 * The catalog is what `drush surface:catalog --format=markdown` prints
 * (SiteCatalog::markdown()) for a site with the modules in this
 * repository installed and nothing else. That site is a kernel test's,
 * so (exactly like scripts/generate-comparison.php) this script asks
 * PHPUnit to run ResourceCatalogTest's drift test with writing turned
 * on; what it asserts on every ordinary run is that the checked-in file
 * still matches what the command's builder writes.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/data_surface/scripts/generate-catalog.php
 * @endcode
 *
 * @see \Drupal\data_surface\ResourceBuild\SiteCatalog::markdown()
 * @see \Drupal\Tests\data_surface\Kernel\ResourceCatalogTest
 */

declare(strict_types=1);

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
  'DATA_SURFACE_WRITE_CATALOGUE',
  escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
  escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
  escapeshellarg($phpunit),
  escapeshellarg('testCatalogueHasNotDrifted'),
  escapeshellarg($module . '/tests/src/Kernel/ResourceCatalogTest.php'),
);
fwrite(STDOUT, "Regenerating docs/catalog.md ...\n");
passthru($command, $status);
exit($status);
