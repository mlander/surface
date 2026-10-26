<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Drush\Generators\Resource\ResourceSources;
use Drupal\data_surface\Drush\Generators\ResourceGenerator;
use Drupal\data_surface\Storage\ConfigSchemaGenerator;
use Drupal\KernelTests\KernelTestBase;
use DrupalCodeGenerator\Application as DcgApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs drush generate surface:resource into a root of the test's own.
 *
 * As the tool module's generator test does: the generator is bound to a
 * code generator application built from the kernel container, the way
 * Drush wires it, and run with the answers a person would type. Drupal
 * Code Generator's own GeneratorTestBase bootstraps the site it runs in,
 * which a kernel test has none of.
 *
 * Core forbids PHPUnit metadata on abstract test classes; each concrete
 * class carries its own.
 */
abstract class ResourceGeneratorTestBase extends KernelTestBase {

  /**
   * A root of the test's own, standing in for the Drupal root.
   */
  protected string $sandbox;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A directory on disk, not the virtual site directory: the written
    // classes are parsed by PHP and read back by the class loader.
    $this->sandbox = sys_get_temp_dir() . '/data_surface_generator_' . $this->databasePrefix;
    (new Filesystem())->mkdir($this->sandbox);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new Filesystem())->remove($this->sandbox);
    parent::tearDown();
  }

  /**
   * Gives where a module's files are written, under the sandbox root.
   *
   * @param string $module
   *   The module.
   *
   * @return string
   *   Its directory, as it would be under the Drupal root.
   */
  protected function moduleDirectory(string $module = 'data_surface_first'): string {
    return $this->sandbox . '/' . $this->container->get('extension.list.module')->getPath($module);
  }

  /**
   * Runs the generator.
   *
   * @param string[] $answers
   *   What is typed at each question; an empty one takes the default.
   * @param array<string, string|bool> $options
   *   The generator's options, by name with the dashes.
   * @param string|null $destination
   *   Where the files are written; the module's directory under the
   *   sandbox root by default.
   * @param int $expected
   *   The exit status expected.
   *
   * @return string
   *   What it printed, on one line.
   */
  protected function generate(array $answers, array $options = [], ?string $destination = NULL, int $expected = 0): string {
    $destination ??= $this->moduleDirectory();
    (new Filesystem())->mkdir($destination);
    $container = $this->container;
    $modules = $container->get('extension.list.module');
    $schemas = new ConfigSchemaGenerator(
      $container->get('data_surface.resource_registry'),
      $container->get('data_surface.resources'),
      $modules,
      $this->sandbox,
      $container->getParameter('container.modules'),
      $container,
      $container->get('class_resolver'),
      $container->get('data_surface.resource_plugins'),
      $container->get('entity_type.manager'),
    );
    $sources = new ResourceSources(
      $container->get('data_surface.resource_registry'),
      $container->get('data_surface.resources'),
      $container->get('data_surface.schema_reader'),
      $container->get('data_surface.config_schema_document'),
      $container->get('config.factory'),
      $modules,
      $this->root,
    );
    $generator = new ResourceGenerator($sources, $schemas, $modules, $this->sandbox);
    $generator->setApplication(DcgApplication::create($container));
    $tester = new CommandTester($generator);
    $tester->setInputs($answers);
    $status = $tester->execute(['--destination' => $destination] + $options);
    $display = $tester->getDisplay();
    $this->assertSame($expected, $status, $display);
    // As one line: a note or an error is wrapped to the terminal's width.
    return (string) preg_replace('/\s+/', ' ', str_replace(["\n ! ", '! [NOTE]', '[ERROR]'], ' ', $display));
  }

  /**
   * Reads a file the generator wrote.
   *
   * @param string $file
   *   The file, from the module's directory.
   * @param string|null $directory
   *   The directory; the module's under the sandbox root by default.
   *
   * @return string
   *   What it holds.
   */
  protected function written(string $file, ?string $directory = NULL): string {
    $path = ($directory ?? $this->moduleDirectory()) . '/' . $file;
    $this->assertFileExists($path);
    return (string) file_get_contents($path);
  }

  /**
   * Asserts each PHP file written parses.
   *
   * @param string[] $files
   *   The files, from the module's directory.
   * @param string|null $directory
   *   The directory.
   */
  protected function assertParses(array $files, ?string $directory = NULL): void {
    foreach ($files as $file) {
      $path = ($directory ?? $this->moduleDirectory()) . '/' . $file;
      exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $code);
      $this->assertSame(0, $code, implode("\n", $output));
    }
  }

}
