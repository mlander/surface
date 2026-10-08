<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Form\DataSurfacePluginForm;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Target\PluginConfigurationTarget;
use Drupal\data_surface_address\Surface\AddressFieldSettingsSurface;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;
use Drupal\data_surface_demo\Surface\DemoFormatterSurface;
use Drupal\data_surface_surface_test\PlainThresholdPlugin;
use Drupal\data_surface_surface_test\Plugin\Action\ThresholdAction;
use Drupal\data_surface_surface_test\Plugin\Condition\ThresholdCondition;
use Drupal\data_surface_surface_test\Surface\PinnedNoteSurface;
use Drupal\data_surface_surface_test\Surface\ThresholdSurface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every plugin host reading its surface from #[UsesSurface].
 *
 * One test per host: the block, the formatter, the condition, the action
 * and the field type, and the generic plugin form, which serves a plugin
 * that only names a surface. Each host reads the surface class from the
 * plugin definition, where its definition alter copied the attribute,
 * builds it in its own `configure` context and supplies the target; the
 * plugin writes nothing about its settings.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class UsesSurfaceHostsTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'address',
    'data_surface',
    'data_surface_address',
    'data_surface_demo',
    'data_surface_surface_test',
  ];

  /**
   * Reads the threshold's allowed range off a surface.
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
   * Tests that every host's definitions carry the surface class.
   */
  public function testEveryHostRecordsTheAttribute(): void {
    $named = [
      ['plugin.manager.block', 'data_surface_demo', DemoBlockSurface::class],
      ['plugin.manager.block', 'data_surface_surface_test_pinned_note', PinnedNoteSurface::class],
      ['plugin.manager.field.formatter', 'data_surface_demo_string', DemoFormatterSurface::class],
      ['plugin.manager.condition', 'data_surface_surface_test_threshold', ThresholdSurface::class],
      ['plugin.manager.action', 'data_surface_surface_test_threshold', ThresholdSurface::class],
      // Read off the class another module's alter swapped in.
      ['plugin.manager.field.field_type', 'address', AddressFieldSettingsSurface::class],
    ];
    foreach ($named as [$manager, $id, $surface]) {
      $this->assertSame($surface, $this->container->get($manager)->getDefinition($id)[UsesSurface::DEFINITION_KEY] ?? NULL, $manager . ' ' . $id);
    }
    $this->assertArrayNotHasKey(UsesSurface::DEFINITION_KEY, $this->container->get('plugin.manager.field.field_type')->getDefinition('string'));
    $this->assertSame(
      ['action:data_surface_surface_test_threshold', 'condition:data_surface_surface_test_threshold'],
      $this->container->get('data_surface.surface_plugins')->usedBy(ThresholdSurface::class),
    );
  }

  /**
   * Tests the block host.
   */
  public function testTheBlockHost(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_surface_test_pinned_note');
    $surface = $block->getDataSurface();
    $this->assertSame(['note'], $surface->getDefinitions()->names());
    // Defaults reach the configuration through the host, beside the
    // host's own keys.
    $this->assertSame('Pinned', $block->getConfiguration()['note']);
    $this->assertSame('data_surface_surface_test_pinned_note', $block->getConfiguration()['id']);
    // The host supplies the target: the plugin's configuration, never
    // the surface's own, which only a situation asked on its own uses.
    $this->assertInstanceOf(PluginConfigurationTarget::class, $block->getDataSurfaceTarget());
    $result = $this->pipeline()->submit($surface, ['note' => 'Moved'], $block->getDataSurfaceTarget());
    $this->assertTrue($result->committed);
    $this->assertSame('Moved', $block->getConfiguration()['note']);
  }

  /**
   * Tests the formatter host.
   */
  public function testTheFormatterHost(): void {
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance('data_surface_demo_string', [
      'field_definition' => BaseFieldDefinition::create('string')->setName('field_demo'),
      'settings' => ['casing' => 'lowercase'],
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $surface = $formatter->getDataSurface();
    $this->assertSame(['prefix', 'casing', 'variant'], $surface->getDefinitions()->names());
    $this->assertSame(['text', 'classes'], $surface->getOutputDefinitions()->names());
    $form = $formatter->settingsForm([], new FormState());
    $this->assertSame(['quiet' => 'Quiet', 'muted' => 'Muted'], array_map('strval', $form['variant']['#options']));
  }

  /**
   * Tests the condition host, the surface's refiner included.
   */
  public function testTheConditionHost(): void {
    $condition = $this->container->get('plugin.manager.condition')->createInstance('data_surface_surface_test_threshold', ['reading' => 20]);
    $this->assertInstanceOf(ThresholdCondition::class, $condition);
    $surface = $condition->getDataSurface();
    $this->assertSame(['mode', 'threshold', 'reading'], $surface->getDefinitions()->names());
    // The host's own key stays the host's.
    $configuration = $condition->getConfiguration();
    $this->assertSame('data_surface_surface_test_threshold', $configuration['id']);
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
   * Tests the action host, writing through the target it supplies.
   */
  public function testTheActionHost(): void {
    $action = $this->container->get('plugin.manager.action')->createInstance('data_surface_surface_test_threshold');
    $this->assertInstanceOf(ThresholdAction::class, $action);
    $this->assertSame(['mode' => 'at_least', 'threshold' => 10, 'reading' => 0], $action->getConfiguration());

    $result = $this->pipeline()->submit($action->getDataSurface(), ['mode' => 'at_most', 'threshold' => 0], $action->getDataSurfaceTarget());
    $this->assertFalse($result->committed);
    $this->assertSame(['threshold'], $result->violations->keys());

    $result = $this->pipeline()->submit($action->getDataSurface(), ['mode' => 'at_most', 'threshold' => 5], $action->getDataSurfaceTarget());
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
   * No base class: the form builds the surface the definition names, in
   * its operation, and stores into the configuration array.
   */
  public function testGenericPluginFormServesNamedSurface(): void {
    $plugin = new PlainThresholdPlugin(['reading' => 3], 'plain', [
      'id' => 'plain',
      'class' => PlainThresholdPlugin::class,
      'provider' => 'data_surface_surface_test',
      UsesSurface::DEFINITION_KEY => ThresholdSurface::class,
    ]);
    $form_object = new DataSurfacePluginForm();
    $form_object->setPlugin($plugin);

    $form_state = new FormState();
    $form = $form_object->buildConfigurationForm([], $form_state);
    $this->assertSame(['mode', 'threshold', 'reading'], array_values(array_intersect(['mode', 'threshold', 'reading'], array_keys($form))));
    $this->assertSame(3, $form['reading']['#default_value']);
    $this->assertInstanceOf(PluginConfigurationTarget::class, $form_object->getDataSurfaceTarget());

    $form_state->setValues(['mode' => 'at_most', 'threshold' => '7', 'reading' => '3']);
    $form_object->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $form_object->submitConfigurationForm($form, $form_state);
    $this->assertSame(['mode' => 'at_most', 'threshold' => 7, 'reading' => 3], $plugin->getConfiguration());
  }

}
