<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a module's missing config objects are created from config/install.
 *
 * What a post-update hook calls for a site that installed a module before
 * one of its config objects shipped: every missing object is created from
 * its file, as core's installer would have created it, and every object
 * the site has is left as the site has it.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class InstallMissingConfigTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
    'config_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['surface_examples', 'config_test']);
  }

  /**
   * Reads a config object as stored.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   Its raw data; empty when it does not exist.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset();
    return $this->config($name)->getRawData();
  }

  /**
   * Tests the examples' post-update creates what is missing and only that.
   */
  public function testThePostUpdateCreatesTheMissingExamples(): void {
    $doors = 'surface_examples.doors';
    $sales = 'surface_examples.sales';
    $step1 = 'surface_examples.registration_step1';
    $installed = [$doors => $this->stored($doors), $sales => $this->stored($sales)];
    $this->assertArrayHasKey('_core', $installed[$doors]);
    $this->config($doors)->delete();
    $this->config($sales)->delete();
    $this->config($step1)->set('title', 'Changed by the site')->save();
    $this->assertSame([], $this->stored($doors));

    $path = $this->container->get('extension.list.module')->getPath('surface_examples');
    require_once $this->root . '/' . $path . '/surface_examples.post_update.php';
    surface_examples_post_update_install_missing_examples();

    // As core's installer wrote them: the file's values and its hash.
    $this->assertSame($installed[$doors], $this->stored($doors));
    $this->assertSame($installed[$sales], $this->stored($sales));
    $this->assertSame('Changed by the site', $this->stored($step1)['title'], 'An existing object is not overwritten.');
    $this->assertSame([], surface_install_missing_config('surface_examples'), 'Nothing is missing the second time.');
  }

  /**
   * Tests missing config entities are created through core's installer.
   */
  public function testMissingConfigEntitiesAreCreated(): void {
    $entities = $this->container->get('entity_type.manager')->getStorage('config_test');
    $entities->load('dotted.default')->delete();
    $this->config('config_test.system')->delete();

    $created = surface_install_missing_config('config_test');
    $this->assertSame(['config_test.system', 'config_test.dynamic.dotted.default'], $created);
    $entities->resetCache();
    $this->assertSame('Default', $entities->load('dotted.default')->label());
    $this->assertSame('bar', $this->stored('config_test.system')['foo']);
  }

}
