<?php

/**
 * @file
 * Makes Drupal's classes resolvable to PHPStan without booting Drupal.
 *
 * PHPStan's Drupal extension ships an autoloader that scans every
 * extension in the site and parses every service file it finds. On a
 * site carrying an unrelated contributed module whose service file names
 * a class with a missing parent, that scan throws before this module is
 * ever read. This file does the one job the analysis needs instead: it
 * registers the PSR-4 namespaces of core, of core's test base classes,
 * and of the few extensions this module knows about, so their classes
 * resolve.
 *
 * The optional dependencies are optional here too. A directory that is
 * not present is skipped, so the analysis runs whether or not the
 * address module and the tool module are installed beside this one.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/**
 * Locates a file or directory by walking up from this module.
 *
 * @param string $relative
 *   The path to find, relative to a candidate ancestor directory.
 *
 * @return string|null
 *   The absolute path, or NULL when no ancestor holds it.
 */
function data_surface_phpstan_find(string $relative): ?string {
  $directory = __DIR__;
  for ($depth = 0; $depth < 12; $depth++) {
    $candidate = $directory . '/' . $relative;
    if (file_exists($candidate)) {
      return $candidate;
    }
    $parent = dirname($directory);
    if ($parent === $directory) {
      break;
    }
    $directory = $parent;
  }
  return NULL;
}

$autoload = data_surface_phpstan_find('vendor/autoload.php');
if ($autoload === NULL) {
  throw new RuntimeException('Could not find vendor/autoload.php.');
}
require_once $autoload;
// PHPStan loads the same autoloader before it reaches this file, so take
// the loader that is already registered rather than a second one.
$loaders = ClassLoader::getRegisteredLoaders();
$loader = $loaders[dirname($autoload)] ?? reset($loaders);
if (!$loader instanceof ClassLoader) {
  throw new RuntimeException('Could not find the Composer class loader.');
}

$drupal_root = data_surface_phpstan_find('core/includes/bootstrap.inc');
if ($drupal_root === NULL) {
  throw new RuntimeException('Could not find the Drupal root.');
}
$drupal_root = dirname($drupal_root, 3);

/**
 * Registers a PSR-4 namespace when its directory exists.
 *
 * @param \Composer\Autoload\ClassLoader $loader
 *   The Composer class loader.
 * @param string $namespace
 *   The namespace prefix, without a trailing separator.
 * @param string $directory
 *   The directory holding the namespace.
 */
function data_surface_phpstan_namespace(ClassLoader $loader, string $namespace, string $directory): void {
  if (is_dir($directory)) {
    $loader->addPsr4($namespace . '\\', $directory);
  }
}

// Core's test base classes live outside any module.
$core_tests = $drupal_root . '/core/tests/Drupal';
foreach (['BuildTests', 'FunctionalJavascriptTests', 'FunctionalTests', 'KernelTests', 'TestSite', 'TestTools', 'Tests'] as $suite) {
  data_surface_phpstan_namespace($loader, 'Drupal\\' . $suite, $core_tests . '/' . $suite);
}

// Every extension this module may see: core's own modules and their
// test modules, the two optional dependencies, and this module with its
// submodules. Registering a namespace only teaches the autoloader where
// to look, so an extension that is present but never used costs
// nothing.
$extension_roots = [
  $drupal_root . '/core/modules',
  $drupal_root . '/core/profiles',
  $drupal_root . '/modules/contrib',
  $drupal_root . '/modules/custom',
  $drupal_root . '/modules',
];
$patterns = [];
foreach ($extension_roots as $root) {
  if (!is_dir($root)) {
    continue;
  }
  $patterns[] = $root . '/*/*.info.yml';
  $patterns[] = $root . '/*/modules/*/*.info.yml';
  $patterns[] = $root . '/*/tests/modules/*/*.info.yml';
  $patterns[] = $root . '/*/modules/*/tests/modules/*/*.info.yml';
}
// This module itself, wherever it has been placed.
$patterns[] = __DIR__ . '/*.info.yml';
$patterns[] = __DIR__ . '/modules/*/*.info.yml';
$patterns[] = __DIR__ . '/tests/modules/*/*.info.yml';
foreach ($patterns as $pattern) {
  foreach (glob($pattern) ?: [] as $info) {
    $directory = dirname($info);
    $name = basename($info, '.info.yml');
    if ($name !== basename($directory)) {
      continue;
    }
    data_surface_phpstan_namespace($loader, 'Drupal\\' . $name, $directory . '/src');
    data_surface_phpstan_namespace($loader, 'Drupal\\Tests\\' . $name, $directory . '/tests/src');
  }
}

// Core's procedural API lives in include files no autoloader sees.
foreach (glob($drupal_root . '/core/includes/*.inc') ?: [] as $include) {
  require_once $include;
}
