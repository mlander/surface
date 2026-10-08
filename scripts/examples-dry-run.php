#!/usr/bin/env php
<?php

/**
 * @file
 * Calls step 3 of the examples as a tool, three times, and prints why.
 *
 * Step 5 of modules/data_surface_examples: the tool
 * data_surface:registration.step3:configure, derived from step 3's
 * surface, called with valid values, then with a capacity above the
 * room's, then as a dry run. Discovery needs a booted kernel with the
 * examples enabled, and the one supported way to boot one is a kernel
 * test, so — exactly like scripts/generate-catalogue.php — this script
 * asks PHPUnit to run ExamplesToolTest, which makes the three calls and
 * asserts their outcomes, with a file to write what they answered to;
 * then prints that file.
 *
 * Run it from anywhere inside the site:
 * @code
 *   ddev exec php web/modules/custom/data_surface/scripts/examples-dry-run.php
 * @endcode
 *
 * @see \Drupal\Tests\data_surface\Kernel\ExamplesToolTest
 * @see \Drupal\data_surface_examples\ExampleCalls
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

$output = tempnam(sys_get_temp_dir(), 'data-surface-examples-');
$command = sprintf(
  'cd %s && DATA_SURFACE_EXAMPLES_DRY_RUN_OUTPUT=%s SIMPLETEST_DB=%s SIMPLETEST_BASE_URL=%s %s -c core --filter %s %s',
  escapeshellarg($web_root),
  escapeshellarg((string) $output),
  escapeshellarg(getenv('SIMPLETEST_DB') ?: 'mysql://db:db@db/db'),
  escapeshellarg(getenv('SIMPLETEST_BASE_URL') ?: 'http://localhost'),
  escapeshellarg($phpunit),
  escapeshellarg('testTheThreeCalls'),
  escapeshellarg($module . '/tests/src/Kernel/ExamplesToolTest.php'),
);
fwrite(STDOUT, "Calling data_surface:registration.step3:configure three times, in a kernel test ...\n");
exec($command, $lines, $status);
$results = json_decode((string) file_get_contents((string) $output), TRUE);
@unlink((string) $output);
if ($status !== 0 || !is_array($results)) {
  fwrite(STDERR, implode("\n", $lines) . "\n");
  exit($status ?: 1);
}

$titles = [
  'valid' => '1. Valid values',
  'over_capacity' => '2. A capacity above the room\'s',
  'dry_run' => '3. A dry run',
];
foreach ($results as $call => $result) {
  fwrite(STDOUT, "\n" . ($titles[$call] ?? $call) . "\n");
  fwrite(STDOUT, ($result['success'] ? 'Success: ' : 'Failed: ') . $result['message'] . "\n");
  foreach ($result['outputs'] as $name => $value) {
    fwrite(STDOUT, "  $name: " . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
  }
}
exit(0);
