<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Holds every shipped config schema and install file to its resource.
 *
 * A resource stored in a simple config object is the one description of
 * that object: its schema entry and install file are written from it by
 * drush data_surface:config-schema --write, and this fails when either
 * differs from what the command would write, so a resource changed
 * without regenerating them is caught here rather than at a save.
 *
 * The test modules are never installed on a site, so the command cannot
 * reach them: run this test with DATA_SURFACE_CONFIG_SCHEMA_WRITE=1 in the
 * environment and it writes every file instead of comparing, as --write
 * does.
 *
 * Decision: see docs/decisions.md#a-config-object-is-described-by-its-resource.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaDriftTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_workflow',
    'data_surface_examples_keys',
    'data_surface_first',
    'data_surface_resource_test',
  ];

  /**
   * The modules whose files are written from their resources.
   */
  protected const GENERATED = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_keys',
    'data_surface_first',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first', 'data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests each shipped file is what its resources write.
   */
  public function testEveryFileIsWhatItsResourceWrites(): void {
    $generator = $this->container->get('data_surface.config_schema');
    $this->assertSame(self::GENERATED, array_values(array_intersect(self::GENERATED, $generator->modules())), 'Every module named owns a resource stored in a simple config object, or writes an entry for one.');
    $files = $generator->files(self::GENERATED);
    $root = $this->container->getParameter('app.root');
    if (getenv('DATA_SURFACE_CONFIG_SCHEMA_WRITE')) {
      foreach ($files as $path => $contents) {
        file_put_contents($root . '/' . $path, $contents);
      }
      $this->markTestSkipped(sprintf('%d files written.', count($files)));
    }
    foreach ($files as $path => $contents) {
      $this->assertFileExists($root . '/' . $path);
      $this->assertSame($contents, file_get_contents($root . '/' . $path), sprintf('%s is not what its resources declare. Run drush data_surface:config-schema --write (for a test module, this test with DATA_SURFACE_CONFIG_SCHEMA_WRITE=1), and commit what it writes.', $path));
    }
  }

  /**
   * Tests every shipped object, as installed, holds to the schema written.
   *
   * A generated schema is fully validatable, so core's typed config
   * validation is the check: each installed object answers no violation.
   */
  public function testEveryInstalledObjectValidates(): void {
    $generator = $this->container->get('data_surface.config_schema');
    $typed = $this->container->get('config.typed');
    $checked = [];
    foreach ($generator->resources() as $definition) {
      if (!in_array($definition->module, self::GENERATED, TRUE)) {
        continue;
      }
      // A config object, or each thing of a config entity type the
      // module declares.
      $names = defined($definition->storage . '::CONFIG')
        ? [constant($definition->storage . '::CONFIG')]
        : $this->container->get('config.factory')->listAll($this->container->get('entity_type.manager')->getDefinition(constant($definition->storage . '::ENTITY_TYPE_ID'))->getConfigPrefix() . '.');
      foreach ($names as $name) {
        $data = $this->config($name)->getRawData();
        if ($data === []) {
          continue;
        }
        $messages = [];
        foreach ($typed->createFromNameAndData($name, $data)->validate() as $violation) {
          $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
        }
        $this->assertSame([], $messages, $name);
        $checked[] = $name;
      }
    }
    $this->assertContains('data_surface_first.newsletter', $checked);
    $this->assertContains('data_surface_examples.registration_step3', $checked);
    $this->assertContains('data_surface_resource_test.opening_hours', $checked);
    $this->assertContains('data_surface_examples.event_venue.riverside', $checked, 'A config entity type the module declares: each thing it ships.');
  }

  /**
   * Tests the generated newsletter entry, the first page's.
   *
   * What each key says comes from the class: required, so not nullable
   * while the object is installed holding a value; optional, so null
   * clears it, `nullable: true`; the bounds a schema carries, and no list
   * of values; the root fully validatable.
   */
  public function testTheNewsletterEntry(): void {
    $files = $this->container->get('data_surface.config_schema')->files(['data_surface_first']);
    $schema = $files[$this->container->get('extension.list.module')->getPath('data_surface_first') . '/config/schema/data_surface_first.schema.yml'];
    $this->assertStringContainsString(<<<'YAML'
      # Generated from the first.newsletter resource by drush data_surface:config-schema.
      data_surface_first.newsletter:
        type: config_object
        label: 'Newsletter settings'
        constraints:
          FullyValidatable: ~
        mapping:
          sender_name:
            type: string
            label: 'Sender name'
            constraints:
              Length:
                max: 64
          sender_email:
            type: email
            label: 'Sender email'
          frequency:
            type: string
            label: 'Frequency'
          max_recipients:
            type: integer
            label: 'Maximum recipients per send'
            nullable: true
            constraints:
              Range:
                min: 1
                max: 10000
      YAML, $schema);
  }

}
