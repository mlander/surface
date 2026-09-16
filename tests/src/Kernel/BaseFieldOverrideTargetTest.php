<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per bundle overrides of an entity type's base fields.
 *
 * The node type demo exercises this target through a composite, which is
 * how it is used in practice and not where its own edges are visible.
 * Two of those edges cost data if they are wrong: an override a caller
 * cannot remove is an override a site builder is stuck with, and a map
 * naming a configurable field would rewrite that field instance instead
 * of overriding a base field.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class BaseFieldOverrideTargetTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'entity_test', 'data_surface'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
  }

  /**
   * Builds the one key surface these tests submit through.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder([
      'name_label' => DataDefinition::create('string')
        ->setLabel('Name field label')
        ->setRequired(FALSE),
    ]);
    $builder->setDefault('name_label', NULL);
    return $builder->seal();
  }

  /**
   * Builds the target under test.
   *
   * @param array $map
   *   The override map, defaulting to the name base field's label.
   *
   * @return \Drupal\data_surface\Target\BaseFieldOverrideTarget
   *   The target.
   */
  protected function target(array $map = ['name_label' => ['field' => 'name']]): BaseFieldOverrideTarget {
    return new BaseFieldOverrideTarget(
      $this->container->get('entity_field.manager'),
      'entity_test',
      'entity_test',
      $map,
    );
  }

  /**
   * Loads the stored override for the name base field.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The override, or NULL when none is stored.
   */
  protected function storedOverride() {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    return $this->container->get('entity_type.manager')
      ->getStorage('base_field_override')
      ->load('entity_test.entity_test.name');
  }

  /**
   * Tests writing, then clearing, one base field override.
   *
   * Clearing is what NULL asks for, and it is not the same as saying
   * nothing: a key the caller did not send leaves the override alone,
   * and a key sent as NULL removes it so the bundle reads the base
   * field again. Skipping NULL, as this target used to, made an
   * override impossible to undo through a surface at all.
   */
  public function testNullClearsAnOverride(): void {
    $surface = $this->surface();
    $pipeline = $this->container->get('data_surface.pipeline');

    // The base field's own label, before anything overrides it.
    $base = (string) $this->container->get('entity_field.manager')
      ->getBaseFieldDefinitions('entity_test')['name']
      ->getLabel();
    $this->assertNull($this->storedOverride());

    $written = $pipeline->submit($surface, ['name_label' => 'Given name'], $this->target());
    $this->assertTrue($written->committed);
    $this->assertNotNull($this->storedOverride());
    $this->assertSame('Given name', (string) $this->storedOverride()->getLabel());

    // A submission that says nothing about the key leaves it alone.
    $target = $this->target();
    $prepared = $target->prepare($surface, []);
    $this->assertSame([], $prepared->artifact[BaseFieldOverrideTarget::SAVE]);
    $this->assertSame([], $prepared->artifact[BaseFieldOverrideTarget::DELETE]);

    // NULL removes the override, and the bundle reads the base field.
    $target = $this->target();
    $prepared = $target->prepare($surface, ['name_label' => NULL]);
    $this->assertCount(1, $prepared->artifact[BaseFieldOverrideTarget::DELETE]);
    $this->assertSame([], $prepared->artifact[BaseFieldOverrideTarget::SAVE]);
    // Preparing removed nothing: the artifact is a plan.
    $this->assertNotNull($this->storedOverride());

    $target->commit($prepared);
    $this->assertNull($this->storedOverride());
    $this->assertSame($base, $target->load($surface)['name_label']);

    // Clearing what is already clear is not an error and writes nothing.
    $target = $this->target();
    $prepared = $target->prepare($surface, ['name_label' => NULL]);
    $this->assertSame([], $prepared->artifact[BaseFieldOverrideTarget::DELETE]);
  }

  /**
   * Tests that a configurable field is refused rather than rewritten.
   *
   * A configurable field is a field config entity in its own right and
   * has no per bundle override: asking this target to write one used to
   * reach a field definition that answers getConfig() with itself, so
   * the "override" would have been the field instance.
   */
  public function testConfigurableFieldIsRefused(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_note',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_note',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Note',
    ])->save();

    $target = $this->target(['name_label' => ['field' => 'field_note']]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('which is a configurable field rather than a base field');
    $target->load($this->surface());
  }

  /**
   * Tests that clearing and setting the same field at once is refused.
   *
   * Clearing removes the whole override, so a submission asking for both
   * is asking for two things that cannot both happen. Saying so is
   * better than letting the order of the map decide.
   */
  public function testClearingAndSettingOneFieldIsRefused(): void {
    $builder = new DataSurfaceBuilder([
      'name_label' => DataDefinition::create('string')->setLabel('Label')->setRequired(FALSE),
      'name_default' => DataDefinition::create('string')->setLabel('Default')->setRequired(FALSE),
    ]);
    $builder->setDefault('name_label', NULL);
    $builder->setDefault('name_default', NULL);
    $surface = $builder->seal();

    $this->container->get('data_surface.pipeline')->submit(
      $surface,
      ['name_label' => 'Given name'],
      $this->target([
        'name_label' => ['field' => 'name', 'property' => BaseFieldOverrideTarget::LABEL],
        'name_default' => ['field' => 'name', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
      ]),
    );

    $target = $this->target([
      'name_label' => ['field' => 'name', 'property' => BaseFieldOverrideTarget::LABEL],
      'name_default' => ['field' => 'name', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('cannot be cleared and set in one submission');
    $target->prepare($surface, ['name_label' => NULL, 'name_default' => 'Anonymous']);
  }

}
