<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Form\SurfacePluginForm;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Pipeline\Storage\PluginConfigurationStorage;
use Drupal\surface_examples_address\Resource\AddressFieldSettingsResource;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples_plugins\Plugin\Field\FieldFormatter\ExampleFormatter;
use Drupal\surface_examples_plugins\Structure\DemoBlockStructure;
use Drupal\surface_resource_test\PlainThresholdPlugin;
use Drupal\surface_resource_test\Plugin\Action\ThresholdAction;
use Drupal\surface_resource_test\Plugin\Block\StickyNoteBlock;
use Drupal\surface_resource_test\Plugin\Condition\ThresholdCondition;
use Drupal\surface_resource_test\Resource\PinnedNoteResource;
use Drupal\surface_resource_test\Structure\ThresholdStructure;
use Drupal\surface_resource_test\Alter\StickyNoteAlter;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every plugin host reading the class #[UsesStructure] names.
 *
 * One test per host: the block, the formatter, the condition, the action
 * and the field type, and the generic plugin form, which serves a plugin
 * that only names a structure. Each host reads the class from the
 * plugin definition, where its definition alter copied the attribute,
 * builds it in its own `configure` context and supplies the storage; the
 * plugin writes nothing about its settings. A plugin may also be its own
 * structure, #[UsesStructure] with no argument: the formatter and a block
 * here, found through their definitions and built from their classes.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class UsesStructureHostsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'address',
    'surface',
    'surface_examples_address',
    'surface_examples_plugins',
    'surface_resource_test',
  ];

  /**
   * Reads the threshold's allowed range off its definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $threshold
   *   The threshold definition.
   *
   * @return array
   *   Its Range constraint's options.
   */
  protected function range(?DataDefinitionInterface $threshold): array {
    $this->assertNotNull($threshold);
    return $threshold->getConstraints()['Range'];
  }

  /**
   * Tests that every host's definitions carry the class it uses.
   */
  public function testEveryHostRecordsTheAttribute(): void {
    $named = [
      ['plugin.manager.block', 'surface_examples_plugins', DemoBlockStructure::class],
      ['plugin.manager.block', 'surface_resource_test_pinned_note', PinnedNoteResource::class],
      // A plugin that is its own structure records its own class.
      ['plugin.manager.field.formatter', 'surface_examples_plugins_string', ExampleFormatter::class],
      ['plugin.manager.block', 'surface_resource_test_sticky_note', StickyNoteBlock::class],
      ['plugin.manager.condition', 'surface_resource_test_threshold', ThresholdStructure::class],
      ['plugin.manager.action', 'surface_resource_test_threshold', ThresholdStructure::class],
      // Read off the class another module's alter swapped in.
      ['plugin.manager.field.field_type', 'address', AddressFieldSettingsResource::class],
    ];
    foreach ($named as [$manager, $id, $surface]) {
      $this->assertSame($surface, $this->container->get($manager)->getDefinition($id)[UsesStructure::DEFINITION_KEY] ?? NULL, $manager . ' ' . $id);
    }
    $this->assertArrayNotHasKey(UsesStructure::DEFINITION_KEY, $this->container->get('plugin.manager.field.field_type')->getDefinition('string'));
    $this->assertSame(
      ['action:surface_resource_test_threshold', 'condition:surface_resource_test_threshold'],
      $this->container->get('surface.resource_plugins')->usedBy(ThresholdStructure::class),
    );
  }

  /**
   * Tests that a plugin that is its own structure is discovered as one.
   *
   * Found through the plugin definitions, not a directory; its id comes
   * from the plugin unless the class carries #[Structure]; the plugin it
   * is used by is itself; and an alter names it by the plugin class.
   */
  public function testPluginsThatAreTheirOwnStructure(): void {
    $registry = $this->container->get('surface.resource_registry');
    $plugins = $this->container->get('surface.resource_plugins');

    $formatter = $registry->getDefinition(ExampleFormatter::class);
    $this->assertSame('field_formatter:surface_examples_plugins_string', $formatter->id);
    $this->assertSame('surface_examples_plugins', $formatter->module);
    $this->assertSame($formatter, $registry->getDefinition('field_formatter:surface_examples_plugins_string'));
    $this->assertNull($formatter->storage);
    $this->assertSame(['variant'], array_map(static fn ($refiner) => $refiner->key, $formatter->refiners));
    $this->assertTrue($formatter->refiners[0]->isStatic);
    $this->assertSame(['field_formatter:surface_examples_plugins_string'], $plugins->usedBy(ExampleFormatter::class));

    $sticky = $registry->getDefinition(StickyNoteBlock::class);
    $this->assertSame('resource_test.sticky_note', $sticky->id);
    $this->assertSame('surface_resource_test', $sticky->module);
    $this->assertSame(['block:surface_resource_test_sticky_note'], $plugins->usedBy(StickyNoteBlock::class));
    $this->assertSame([StickyNoteAlter::class], array_map(static fn ($alter) => $alter->class, $sticky->alters));

    // Neither is in the list the compiler pass made: both came from the
    // plugin definitions.
    $this->assertSame([ExampleFormatter::class, StickyNoteBlock::class], array_keys($plugins->ownStructures()));

    // Built by id as well as by class, on the class, with no instance.
    $built = $this->container->get('surface.resources')->build('field_formatter:surface_examples_plugins_string', new OperationContext('configure'));
    $this->assertSame(['prefix', 'casing', 'variant'], $built->getDefinitions()->names());
    $this->assertSame(['text', 'classes'], $built->getOutputDefinitions()->names());
    // The refiner rides along as the class name, so a cached form holds
    // no formatter.
    $serialized = serialize($built);
    $this->assertStringNotContainsString('O:' . strlen(ExampleFormatter::class) . ':"' . ExampleFormatter::class . '"', $serialized);
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize($serialized);
    $this->assertSame(['muted', 'quiet'], $this->variants($restored->refine(['casing' => 'lowercase'])->getDefinition('variant')));
  }

  /**
   * Reads the values a variant definition offers, sorted.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $variant
   *   The variant definition.
   *
   * @return string[]
   *   The offered values.
   */
  protected function variants(?DataDefinitionInterface $variant): array {
    $this->assertNotNull($variant);
    $values = ChoiceSet::of($variant)->values ?? [];
    sort($values);
    return $values;
  }

  /**
   * Tests the block host on a block that is its own structure.
   */
  public function testTheBlockHostWhenTheBlockIsItsOwnStructure(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('surface_resource_test_sticky_note');
    $this->assertInstanceOf(StickyNoteBlock::class, $block);
    $surface = $block->getSurface();
    $this->assertSame(['color', 'note'], $surface->getDefinitions()->names());
    // Defaults read off the block's own static defineInputs().
    $this->assertSame('yellow', $block->getConfiguration()['color']);
    $this->assertSame('Sticky', $block->getConfiguration()['note']);
    // The alter that names the block class applies.
    $this->assertSame('Written on the note.', (string) $surface->getDefinition('note')->getDescription());
    // The static refiner runs.
    $this->assertSame(['max' => 10], $surface->refine(['color' => 'pink'])->getDefinition('note')->getConstraints()['Length']);
    $this->assertSame(['max' => 40], $surface->refine(['color' => 'yellow'])->getDefinition('note')->getConstraints()['Length']);

    $result = $this->pipeline()->submit($surface, ['color' => 'pink', 'note' => 'Far too long a note'], $block->getSurfaceStorage());
    $this->assertFalse($result->committed);
    $this->assertSame(['note'], $result->violations->keys());
    $result = $this->pipeline()->submit($surface, ['color' => 'pink', 'note' => 'Short'], $block->getSurfaceStorage());
    $this->assertTrue($result->committed);
    $this->assertSame('Short', $block->getConfiguration()['note']);
  }

  /**
   * Tests the block host.
   */
  public function testTheBlockHost(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('surface_resource_test_pinned_note');
    $surface = $block->getSurface();
    $this->assertSame(['note'], $surface->getDefinitions()->names());
    // Defaults reach the configuration through the host, beside the
    // host's own keys.
    $this->assertSame('Pinned', $block->getConfiguration()['note']);
    $this->assertSame('surface_resource_test_pinned_note', $block->getConfiguration()['id']);
    // The host supplies the storage: the plugin's configuration, never
    // the resource's own, which only an operation asked on its own uses.
    $this->assertInstanceOf(PluginConfigurationStorage::class, $block->getSurfaceStorage());
    $result = $this->pipeline()->submit($surface, ['note' => 'Moved'], $block->getSurfaceStorage());
    $this->assertTrue($result->committed);
    $this->assertSame('Moved', $block->getConfiguration()['note']);
  }

  /**
   * Tests the formatter host.
   */
  public function testTheFormatterHost(): void {
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance('surface_examples_plugins_string', [
      'field_definition' => BaseFieldDefinition::create('string')->setName('field_demo'),
      'settings' => ['casing' => 'lowercase'],
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $surface = $formatter->getSurface();
    $this->assertSame(['prefix', 'casing', 'variant'], $surface->getDefinitions()->names());
    $this->assertSame(['text', 'classes'], $surface->getOutputDefinitions()->names());
    $form = $formatter->settingsForm([], new FormState());
    $this->assertSame(['quiet' => 'Quiet', 'muted' => 'Muted'], array_map('strval', $form['variant']['#options']));
  }

  /**
   * Tests the condition host, the structure's refiner included.
   */
  public function testTheConditionHost(): void {
    $condition = $this->container->get('plugin.manager.condition')->createInstance('surface_resource_test_threshold', ['reading' => 20]);
    $this->assertInstanceOf(ThresholdCondition::class, $condition);
    $surface = $condition->getSurface();
    $this->assertSame(['mode', 'threshold', 'reading'], $surface->getDefinitions()->names());
    // The host's own key stays the host's.
    $configuration = $condition->getConfiguration();
    $this->assertSame('surface_resource_test_threshold', $configuration['id']);
    $this->assertFalse($configuration['negate']);
    $this->assertSame(10, $configuration['threshold']);
    $this->assertTrue($condition->evaluate());

    $this->assertSame(0, $this->range($surface->refine(['mode' => 'at_least'])->getDefinition('threshold'))['min']);
    $this->assertSame(1, $this->range($surface->refine(['mode' => 'at_most'])->getDefinition('threshold'))['min']);

    $form = $condition->buildConfigurationForm([], new FormState());
    $this->assertArrayHasKey('threshold', $form);
    $this->assertArrayHasKey('negate', $form);
    $this->assertArrayHasKey('#ajax', $form['mode']);
  }

  /**
   * Tests the action host, writing through the storage it supplies.
   */
  public function testTheActionHost(): void {
    $action = $this->container->get('plugin.manager.action')->createInstance('surface_resource_test_threshold');
    $this->assertInstanceOf(ThresholdAction::class, $action);
    $this->assertSame(['mode' => 'at_least', 'threshold' => 10, 'reading' => 0], $action->getConfiguration());

    $result = $this->pipeline()->submit($action->getSurface(), ['mode' => 'at_most', 'threshold' => 0], $action->getSurfaceStorage());
    $this->assertFalse($result->committed);
    $this->assertSame(['threshold'], $result->violations->keys());

    $result = $this->pipeline()->submit($action->getSurface(), ['mode' => 'at_most', 'threshold' => 5], $action->getSurfaceStorage());
    $this->assertTrue($result->committed);
    $this->assertSame(['mode' => 'at_most', 'threshold' => 5, 'reading' => 0], $action->getConfiguration());
  }

  /**
   * Tests the field type host, on the address field type it swaps in.
   */
  public function testTheFieldTypeHost(): void {
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ]);
    $field->save();
    $item = $this->container->get('typed_data_manager')->create($field->getItemDefinition());
    $surface = $item->getFieldSurface();
    $this->assertSame(['available_countries', 'langcode_override', 'field_overrides'], $surface->getDefinitions()->names());
    $this->assertSame(['available_countries' => [], 'langcode_override' => NULL, 'field_overrides' => []], $surface->getDefaultValues());
  }

  /**
   * Tests the generic plugin form serving a plugin that only names one.
   *
   * No base class: the form builds the structure the definition names, in
   * its operation, and stores into the configuration array.
   */
  public function testGenericPluginFormServesNamedStructure(): void {
    $plugin = new PlainThresholdPlugin(['reading' => 3], 'plain', [
      'id' => 'plain',
      'class' => PlainThresholdPlugin::class,
      'provider' => 'surface_resource_test',
      UsesStructure::DEFINITION_KEY => ThresholdStructure::class,
    ]);
    $form_object = new SurfacePluginForm();
    $form_object->setPlugin($plugin);

    $form_state = new FormState();
    $form = $form_object->buildConfigurationForm([], $form_state);
    $this->assertSame(['mode', 'threshold', 'reading'], array_values(array_intersect(['mode', 'threshold', 'reading'], array_keys($form))));
    $this->assertSame(3, $form['reading']['#default_value']);
    $this->assertInstanceOf(PluginConfigurationStorage::class, $form_object->getSurfaceStorage());

    $form_state->setValues(['mode' => 'at_most', 'threshold' => '7', 'reading' => '3']);
    $form_object->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $form_object->submitConfigurationForm($form, $form_state);
    $this->assertSame(['mode' => 'at_most', 'threshold' => 7, 'reading' => 3], $plugin->getConfiguration());
  }

}
