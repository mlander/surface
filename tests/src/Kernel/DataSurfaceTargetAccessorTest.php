<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Target\FieldSettingsTarget;
use Drupal\data_surface\Target\PluginConfigurationTarget;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the third answer of the triple on the host families.
 *
 * Surface, access and target are now all resolvable from one operation
 * and subject pair, which is what lets a caller holding nothing but a
 * coordinate write as well as read. This asserts the accessor on the
 * two families that have a target — a configurable plugin, whose values
 * live in its own configuration array, and a field item, whose values
 * live on the field config entity it is bound to — and the refusal on
 * the one that does not.
 *
 * The plugin half also holds the accessor to the target the submit path
 * built inline before it existed: the same destination, host-owned keys
 * and all, so moving the construction changed nothing about what is
 * stored.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceTargetAccessorTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'entity_test',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
  }

  /**
   * Tests that a plugin host answers with the plugin's own destination.
   */
  public function testPluginHostWrapsItself(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block', ['headline' => 'Stored']);

    $target = $block->getDataSurfaceTarget();
    $this->assertInstanceOf(PluginConfigurationTarget::class, $target);

    // The same destination the submit path used to build inline: what it
    // reads is the plugin's configuration narrowed to the surface's own
    // keys, and what it prepares puts the host-owned keys back.
    $surface = $block->getDataSurface();
    $this->assertSame('Stored', $target->load($surface)['headline']);
    $this->assertArrayNotHasKey('provider', $target->load($surface));

    $prepared = $target->prepare($surface, ['headline' => 'Moved'] + $target->load($surface));
    $this->assertSame('Moved', $prepared->artifact['headline']);
    $this->assertSame('data_surface_test', $prepared->artifact['provider']);
    $this->assertArrayHasKey('label', $prepared->artifact);

    // And committing through it is what the form's submit does, so the
    // plugin holds what the target wrote.
    $target->commit($prepared);
    $this->assertSame('Moved', $block->getConfiguration()['headline']);
  }

  /**
   * Tests that a plugin, being its own subject, refuses another.
   *
   * The same rule the surface accessor follows, asserted on the target
   * accessor because the two have to agree: a coordinate the surface
   * refuses must not find a destination waiting for it.
   */
  public function testPluginHostRefusesAnyOtherSubject(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is its own subject');
    $block->getDataSurfaceTarget('configure', 'something');
  }

  /**
   * Tests the field item's accessor, and the subject rule on it.
   */
  public function testFieldItemAnswersWithItsFieldSettingsTarget(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_secret',
      'entity_type' => 'entity_test',
      'type' => 'data_surface_secret',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_secret',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Secret bearing',
    ])->save();
    $field = FieldConfig::load('entity_test.entity_test.field_secret');
    $item = $this->container->get('typed_data_manager')->create($field->getItemDefinition());
    $this->assertInstanceOf(FieldSurfaceProviderInterface::class, $item);

    $target = $item->getDataSurfaceTarget();
    $this->assertInstanceOf(FieldSettingsTarget::class, $target);
    // Bound to this field instance rather than to the field type, which
    // is the whole reason the accessor is on the item.
    $surface = $item->getFieldSurface();
    $result = $this->pipeline()->submit($surface, ['endpoint' => 'https://example.com/hook'], $target);
    $this->assertTrue($result->committed);
    $this->assertSame(
      'https://example.com/hook',
      FieldConfig::load('entity_test.entity_test.field_secret')->getSettings()['endpoint'],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is its own subject');
    $item->getDataSurfaceTarget(FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, 'field_secret');
  }

  /**
   * Tests that a formatter says out loud that it has no target.
   *
   * The documented refusal: a formatter's settings belong to the entity
   * view display that hosts it, so there is no destination the plugin
   * could name, and a quietly useless one would let a caller submit
   * into it and be told the values were stored.
   */
  public function testFormatterNamesNoTarget(): void {
    $definition = BaseFieldDefinition::create('string')
      ->setName('field_demo')
      ->setLabel('Demo');
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance(
      'data_surface_test_formatter',
      [
        'field_definition' => $definition,
        'settings' => [],
        'label' => 'above',
        'view_mode' => 'default',
        'third_party_settings' => [],
      ],
    );

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('stored by the entity view display');
    $formatter->getDataSurfaceTarget();
  }

}
