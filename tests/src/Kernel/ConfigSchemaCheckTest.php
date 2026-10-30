<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\ReferenceStorageLayout;
use Drupal\surface\Storage\ConfigObjectStorage;
use Drupal\surface_examples_node_type\Storage\NodeTypeStorage;
use Drupal\surface_schema_test\Storage\AliasSettingsStorage;
use Drupal\surface_schema_test\Storage\NarrowedStorage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Holds a resource to a config schema the module did not generate.
 *
 * A storage held to a config schema (HeldToConfigSchemaInterface) says
 * where the resource advertises more than that schema, and the build
 * refuses it, naming each key: a type the schema types otherwise, a key
 * it lets be cleared that the schema calls not nullable, a constraint the
 * schema states and the resource does not. A generated schema is never
 * narrower; core's node.type.* and a schema written by hand are held to it.
 *
 * Decision: see docs/decisions.md#a-config-object-is-described-by-its-resource.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaCheckTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'surface',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_node_type',
    'surface_schema_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface_schema_test']);
  }

  /**
   * Tests a hand-written schema narrower than its resource refuses the build.
   */
  public function testNarrowerSchemaIsRefusedAtBuild(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('schema_test.narrowed', 'configure');
    $expected = 'The schema_test.narrowed resource advertises more than surface_schema_test.narrowed, the config schema ' . NarrowedStorage::class . ' holds what it stores to, so storage would refuse what every door offers. Where: "code": the schema refuses a value that does not match /^[a-z0-9_]+$/, which the resource does not; "limit": it may be more than 10, which the schema refuses; "mode": it allows silent, which the schema\'s Choice does not; "size": it is an integer, which the schema types string, a string. Narrow the resource to what the schema states; a schema generated from the resource is written again with drush surface:config-schema --write.';
    foreach (['build', 'buildStatic', 'buildDeclared'] as $method) {
      try {
        $resources->{$method}('schema_test.narrowed', $context);
        $this->fail(sprintf('%s() refuses a resource wider than its schema.', $method));
      }
      catch (\LogicException $e) {
        $this->assertSame($expected, $e->getMessage(), $method);
      }
    }
    // What the schema is generated from is built all the same: the schema
    // on disk is what is being replaced.
    $declared = $resources->declaration('schema_test.narrowed', $context);
    $this->assertSame(['code', 'limit', 'mode', 'size', 'note'], $declared->getDefinitions()->names());
    $generated = $this->container->get('surface.config_schema')->files(['surface_schema_test']);
    $schema = $generated[$this->container->get('extension.list.module')->getPath('surface_schema_test') . '/config/schema/surface_schema_test.schema.yml'];
    $this->assertStringContainsString(<<<'YAML'
          limit:
            type: integer
            label: 'Limit'
            nullable: true
            constraints:
              Range:
                min: 1
                max: 100
      YAML, $schema, 'Generated, the schema says what the resource does.');
  }

  /**
   * Tests a key asked as checkboxes and stored as a list is told to the check.
   *
   * The alias settings ask for a checkbox per type, and their storage keeps
   * the list of the types checked through its layout, as docs/recipes.md
   * shows. The layout says the stored schema of the key it translates, a
   * sequence of strings: the check skips the key, so the build passes, and
   * the generator writes that schema rather than the asked one.
   */
  public function testTranslatedKeyIsToldToTheCheck(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('schema_test.aliases', 'configure');
    $built = $resources->build('schema_test.aliases', $context);
    $this->assertSame(['types', 'marks'], $built->getDefinitions()->names());
    $this->assertSame([], $this->container->get('class_resolver')->getInstanceFromDefinition(AliasSettingsStorage::class)->widerThanSchema($built));
    // Not told, the check names each entry.
    $untold = new class(
      $this->container->get('config.factory'),
      $this->container->get('config.typed'),
      $this->container->get('language_manager'),
      $this->container->get('string_translation'),
    ) extends ConfigObjectStorage {

      /**
       * {@inheritdoc}
       */
      public const CONFIG = 'surface_schema_test.aliases';

    };
    $this->assertSame([
      'types.node' => 'it is a boolean, which the schema types string, a string',
      'types.term' => 'it is a boolean, which the schema types string, a string',
      'types.user' => 'it is a boolean, which the schema types string, a string',
    ], $untold->widerThanSchema($built));
    $generated = $this->container->get('surface.config_schema')->files(['surface_schema_test']);
    $schema = $generated[$this->container->get('extension.list.module')->getPath('surface_schema_test') . '/config/schema/surface_schema_test.schema.yml'];
    $this->assertStringContainsString(<<<'YAML'
          types:
            type: sequence
            label: 'Types with aliases'
            sequence:
              type: string
              label: 'Type'
      YAML, $schema, 'Generated, the schema says what the layout stores.');
  }

  /**
   * Tests an alter's layout of its mount is told to the check and generator.
   *
   * The mount test module mounts a reviewer, a user stored by uuid, and a
   * delay asked in minutes and stored as a duration, which its layout
   * says. Each is named at third_party_settings.<module>.<key>: the check
   * skips both, and the generator writes each as it is stored.
   */
  public function testAnAltersMountLayoutIsToldToTheCheck(): void {
    $this->enableModules(['surface_mount_test']);
    $resources = $this->container->get('surface.resources');
    $built = $resources->build('schema_test.aliases', $resources->operation('schema_test.aliases', 'configure'));
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(AliasSettingsStorage::class);
    $translated = $storage->translatedKeys($built);
    $mount = 'third_party_settings.surface_mount_test.';
    $this->assertSame(['type' => 'string'], $translated[$mount . 'delay'], 'What the alter\'s layout says.');
    $this->assertSame(ReferenceStorageLayout::UUID_SCHEMA, $translated[$mount . 'reviewer'], 'A reference at the mount, by uuid.');
    $this->assertSame([], $storage->widerThanSchema($built));
    // Told of the references alone, the check names the delay.
    $references = new class(
      $this->container->get('config.factory'),
      $this->container->get('config.typed'),
      $this->container->get('language_manager'),
      $this->container->get('string_translation'),
    ) extends ConfigObjectStorage {

      /**
       * {@inheritdoc}
       */
      public const CONFIG = 'surface_schema_test.aliases';

      /**
       * {@inheritdoc}
       */
      public function translatedKeys(SurfaceInterface $built): array {
        return ReferenceStorageLayout::storedSchemaOf($built);
      }

    };
    $this->assertSame(['it is an integer, which the schema types string, a string'], array_values(array_intersect_key($references->widerThanSchema($built), [$mount . 'delay' => TRUE])));

    $modules = ['surface_schema_test', 'surface_mount_test'];
    $generated = $this->container->get('surface.config_schema')->files($modules);
    $schema = $generated[$this->container->get('extension.list.module')->getPath('surface_mount_test') . '/config/schema/surface_mount_test.schema.yml'] ?? '';
    $this->assertStringContainsString(<<<'YAML'
          delay:
            type: string
            label: 'Delay in minutes'
      YAML, $schema, 'Generated, the mount\'s schema says what its layout stores.');
    $this->assertStringContainsString(<<<'YAML'
          reviewer:
            type: uuid
      YAML, $schema);
  }

  /**
   * Tests the node type resource is held to core's node.type.* schema.
   *
   * Core's schema is fully validatable, stores the name as a label and the
   * description and the help as text, each refusing control characters,
   * and calls neither boolean nullable. The resource as the architecture
   * review found it restated the schema less strictly: the build would
   * refuse it now, naming each of those keys. As shipped, it states the
   * patterns, and the storage says from the schema which keys always hold
   * a value.
   */
  public function testTheNodeTypeIsHeldToCoresSchema(): void {
    $resources = $this->container->get('surface.resources');
    $add = $resources->build('node.type', $resources->operation('node.type', 'create'));
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $edit = $resources->build('node.type', $resources->operation('node.type', 'update', ['type' => NodeType::load('page')]));
    foreach ([$add, $edit] as $built) {
      $this->assertTrue(DefinitionMetadata::isAlwaysHeld($built->getDefinition('new_revision')), 'Not nullable in core\'s schema: always held.');
      $this->assertTrue(DefinitionMetadata::isAlwaysHeld($built->getDefinition('display_submitted')));
      $this->assertFalse(DefinitionMetadata::isAlwaysHeld($built->getDefinition('description')), 'Nullable in core\'s schema: may be cleared.');
      $regex = $built->getDefinition('name')->getConstraints()['Regex'];
      $this->assertSame('/([^\PC])/u', $regex['pattern']);
      $this->assertFalse($regex['match']);
    }

    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(NodeTypeStorage::class);
    $this->assertSame([], $storage->widerThanSchema($add));
    // The node type's keys as the review found them: no pattern, and the
    // booleans clearable, which the schema refuses.
    $found = new SurfaceBuilder([
      'name' => DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE)->addConstraint('Length', ['max' => 255]),
      'type' => DataDefinition::create('string')->setLabel('Machine name')->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 32])
        ->addConstraint('Regex', ['pattern' => '/^[a-z0-9_]+$/D']),
      'description' => DataDefinition::create('string')->setLabel('Description')->setSetting('multiline', TRUE),
      'help' => DataDefinition::create('string')->setLabel('Help')->setSetting('multiline', TRUE),
      'preview_mode' => DataDefinition::create('integer')->setLabel('Preview')->setRequired(TRUE)
        ->addConstraint('LabeledChoice', ['choices' => [0, 1, 2], 'labels' => []]),
      'new_revision' => DataDefinition::create('boolean')->setLabel('Create new revision'),
      'display_submitted' => DataDefinition::create('boolean')->setLabel('Display author and date information'),
    ]);
    $this->assertSame([
      'name' => 'the schema refuses a value that matches /([^\PC])/u, which the resource does not',
      'description' => 'the schema refuses a value that matches /([^\PC\x09\x0a\x0d])/u, which the resource does not',
      'help' => 'the schema refuses a value that matches /([^\PC\x09\x0a\x0d])/u, which the resource does not',
      'new_revision' => 'it may be cleared, which the schema refuses: boolean is not nullable',
      'display_submitted' => 'it may be cleared, which the schema refuses: boolean is not nullable',
    ], $storage->widerThanSchema($found->seal()));
    // A machine name allowed longer than core's 32: refused too.
    $longer = new SurfaceBuilder([
      'type' => DataDefinition::create('string')->setRequired(TRUE)->addConstraint('Length', ['max' => 64])->addConstraint('Regex', ['pattern' => '/^[a-z0-9_]+$/D']),
    ]);
    $this->assertSame(['type' => 'it may be more than 32 characters long, which the schema refuses'], $storage->widerThanSchema($longer->seal()));
  }

}
