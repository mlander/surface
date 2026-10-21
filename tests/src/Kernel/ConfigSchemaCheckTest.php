<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface_demo_node_type\Storage\NodeTypeStorage;
use Drupal\data_surface_schema_test\Storage\NarrowedStorage;
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
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaCheckTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_node_type',
    'data_surface_schema_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'data_surface_schema_test']);
  }

  /**
   * Tests a hand-written schema narrower than its resource refuses the build.
   */
  public function testNarrowerSchemaIsRefusedAtBuild(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation('schema_test.narrowed', 'configure');
    $expected = 'The schema_test.narrowed resource advertises more than data_surface_schema_test.narrowed, the config schema ' . NarrowedStorage::class . ' holds what it stores to, so storage would refuse what every door offers. Where: "code": the schema refuses a value that does not match /^[a-z0-9_]+$/, which the resource does not; "limit": it may be more than 10, which the schema refuses; "mode": it allows silent, which the schema\'s Choice does not; "size": it is an integer, which the schema types string, a string. Narrow the resource to what the schema states; a schema generated from the resource is written again with drush data_surface:config-schema --write.';
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
    $generated = $this->container->get('data_surface.config_schema')->files(['data_surface_schema_test']);
    $schema = $generated[$this->container->get('extension.list.module')->getPath('data_surface_schema_test') . '/config/schema/data_surface_schema_test.schema.yml'];
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
    $resources = $this->container->get('data_surface.resources');
    $add = $resources->build('node.type', $resources->operation('node.type', 'add'));
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $edit = $resources->build('node.type', $resources->operation('node.type', 'edit', ['type' => NodeType::load('page')]));
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
    $found = new DataSurfaceBuilder([
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
    $longer = new DataSurfaceBuilder([
      'type' => DataDefinition::create('string')->setRequired(TRUE)->addConstraint('Length', ['max' => 64])->addConstraint('Regex', ['pattern' => '/^[a-z0-9_]+$/D']),
    ]);
    $this->assertSame(['type' => 'it may be more than 32 characters long, which the schema refuses'], $storage->widerThanSchema($longer->seal()));
  }

}
