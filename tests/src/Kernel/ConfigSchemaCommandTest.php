<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Drush\Commands\ConfigSchemaCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests drush data_surface:config-schema, against a root of its own.
 *
 * Without --write it writes nothing, prints how each file differs and
 * fails when one does; with it, it writes them, and a run after passes.
 * A module that writes nothing is refused by name. A module that is not
 * installed is read from its code, so its files are written before it is
 * installed, an owner and a module mounting keys on it in one run.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaCommandTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_first',
  ];

  /**
   * Where the command writes, in place of the Drupal root.
   */
  protected string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = $this->siteDirectory . '/config_schema_root';
    mkdir($this->directory, 0775, TRUE);
  }

  /**
   * Runs the command.
   *
   * @param array<string, mixed> $options
   *   Its options, by name with the dashes.
   *
   * @return array{0: int, 1: string}
   *   The exit status and what it printed.
   */
  protected function command(array $options): array {
    $command = new ConfigSchemaCommand($this->container->get('data_surface.config_schema'), $this->directory);
    $tester = new CommandTester($command);
    $status = $tester->execute($options);
    return [$status, $tester->getDisplay()];
  }

  /**
   * Tests a dry run, a write, and a dry run that then agrees.
   */
  public function testDryRunThenWrite(): void {
    $path = $this->container->get('extension.list.module')->getPath('data_surface_first');
    $schema = $path . '/config/schema/data_surface_first.schema.yml';

    [$status, $display] = $this->command(['--module' => 'data_surface_first']);
    $this->assertSame(1, $status, 'A file that differs fails a dry run.');
    $this->assertStringContainsString('--- ' . $schema . ' (not there)', $display);
    $this->assertStringContainsString('+data_surface_first.newsletter:', $display);
    $this->assertStringContainsString('+    FullyValidatable: ~', $display);
    $this->assertFileDoesNotExist($this->directory . '/' . $schema, 'A dry run writes nothing.');

    [$status] = $this->command(['--module' => 'data_surface_first', '--write' => TRUE]);
    $this->assertSame(0, $status);
    $written = $this->container->get('data_surface.config_schema')->files(['data_surface_first']);
    foreach ($written as $file => $contents) {
      $this->assertSame($contents, file_get_contents($this->directory . '/' . $file));
    }
    $this->assertSame(file_get_contents($this->root . '/' . $schema), file_get_contents($this->directory . '/' . $schema), 'What it writes is what the module ships.');

    [$status, $display] = $this->command(['--module' => 'data_surface_first']);
    $this->assertSame(0, $status, $display);
    $this->assertStringContainsString('0 differ from what the resources declare', $display);
  }

  /**
   * Tests modules not installed are written in one run, before an install.
   *
   * The examples own the registration's third step, and the compliance
   * module mounts keys on it; neither is installed here. Named together,
   * both modules' files come out as they ship, the owner's schema typing
   * the compliance module's mount, with nothing said between them.
   */
  public function testModulesNotInstalledAreWrittenInOneRun(): void {
    $modules = ['data_surface_examples', 'data_surface_examples_compliance', 'data_surface_examples_keys'];
    $installed = $this->container->get('module_handler');
    foreach ($modules as $module) {
      $this->assertFalse($installed->moduleExists($module));
    }
    [$status, $display] = $this->command(['--module' => implode(',', $modules), '--write' => TRUE]);
    $this->assertSame(0, $status, $display);
    $this->assertStringNotContainsString('No tool', $display);
    $this->assertStringNotContainsString('[warning]', $display);
    $list = $this->container->get('extension.list.module');
    $files = [
      $list->getPath('data_surface_examples') . '/config/schema/data_surface_examples.schema.yml',
      $list->getPath('data_surface_examples') . '/config/install/data_surface_examples.registration_step3.yml',
      $list->getPath('data_surface_examples_compliance') . '/config/schema/data_surface_examples_compliance.schema.yml',
      $list->getPath('data_surface_examples_keys') . '/config/schema/data_surface_examples_keys.schema.yml',
    ];
    foreach ($files as $file) {
      $this->assertFileExists($this->directory . '/' . $file);
      $this->assertSame(file_get_contents($this->root . '/' . $file), file_get_contents($this->directory . '/' . $file), $file . ' is written as it ships.');
    }
    $this->assertStringContainsString("data_surface_examples.registration_step3.third_party.[%key]", (string) file_get_contents($this->directory . '/' . $files[0]));
    $this->assertStringContainsString('data_surface_examples.registration_step3.third_party.data_surface_examples_compliance:', (string) file_get_contents($this->directory . '/' . $files[2]));
    // Nothing was installed by reading them.
    foreach ($modules as $module) {
      $this->assertFalse($installed->moduleExists($module));
    }

    [$status, $display] = $this->command(['--module' => implode(',', $modules)]);
    $this->assertSame(0, $status, $display);

    // The owner alone writes no mount: the module mounting keys is
    // neither installed nor named.
    $alone = $this->container->get('data_surface.config_schema')->including(['data_surface_examples'])->files(['data_surface_examples']);
    $this->assertStringNotContainsString('third_party', $alone[$files[0]]);
  }

  /**
   * Tests a module that is not in the code base is refused by name.
   */
  public function testAbsentModuleIsRefused(): void {
    [$status, $display] = $this->command(['--module' => 'data_surface_no_such_module']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('No module named data_surface_no_such_module is in the code base.', (string) preg_replace('/\s+/', ' ', $display));
  }

  /**
   * Tests a module that writes nothing is refused by name.
   */
  public function testUnknownModuleIsRefused(): void {
    [$status, $display] = $this->command(['--module' => 'system']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('system owns no resource stored in a simple config object', (string) preg_replace('/\s+/', ' ', $display));
  }

}
