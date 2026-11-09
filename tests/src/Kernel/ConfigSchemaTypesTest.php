<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface_resource_test\Plugin\Block\StickyNoteBlock;
use Drupal\surface_resource_test\Structure\ThresholdStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generic provider honors a plugin's declared structure.
 *
 * Where the deciding key's values come from a plugin manager (a
 * PluginExists constraint), a plugin whose class names its settings with
 * #[UsesStructure] is listed as that structure, and its config schema is
 * not read; any other plugin is read from its schema. The test module's
 * sticky note block is its own structure, and its threshold condition
 * names one: each is listed by ConfigSchemaTypes through core's schema,
 * `block.block.*` at `settings` (variants at a key) and
 * `condition.plugin.[id]` (variants at `configuration`), and each is
 * built as declared.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaTypesTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'serialization',
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * Tests a block plugin's declared structure is its variant at a key.
   */
  public function testBlockSettingsNameTheDeclaredStructure(): void {
    $entries = $this->container->get(ConfigSchemaTypes::class)->listFor([
      'type' => 'block.block.*',
      'path' => 'settings',
      'by' => 'plugin',
    ])->entries();
    $this->assertSame(StickyNoteBlock::class, $entries['surface_resource_test_sticky_note']['structure'], 'The plugin that is its own structure is listed as its class.');
    $this->assertSame(ConfigSchemaReader::id('block.settings.*'), $this->registry()->getDefinition($entries['system_powered_by_block']['structure'])->id, 'A plugin naming none is read from its schema.');

    $full = $this->full($this->container->get(ConfigSchemaReader::class)->structure('block.block.*'));
    $variants = $full['properties']['settings']['x-surface']['variants'];
    $this->assertSame('resource_test.sticky_note', SchemaResolver::defName($variants['surface_resource_test_sticky_note']));
    $def = $full['$defs']['resource_test.sticky_note'];
    $this->assertSame(['color', 'note'], array_keys($def['properties']), 'Built as declared: its own keys, not its schema\'s.');
    $this->assertSame('yellow', $def['properties']['color']['default'], 'With the defaults it declares, which no schema states.');
  }

  /**
   * Tests a condition's declared structure is its variant at configuration.
   */
  public function testConditionNamesTheDeclaredStructure(): void {
    $entries = $this->container->get(ConfigSchemaTypes::class)->listFor([
      'type' => 'condition.plugin.[id]',
      'by' => 'id',
    ])->entries();
    $this->assertSame(ThresholdStructure::class, $entries['surface_resource_test_threshold']['structure']);
    $this->assertSame(ConfigSchemaReader::id('condition.plugin.request_path', '', 'condition.plugin'), $this->registry()->getDefinition($entries['request_path']['structure'])->id);

    $full = $this->full($this->container->get(ConfigSchemaReader::class)->structure('condition.plugin.[id]'));
    $chosen = $full['properties'][ConfigSchemaReader::CHOSEN]['x-surface']['variants'];
    $this->assertSame('test.threshold', SchemaResolver::defName($chosen['surface_resource_test_threshold']));
    $def = $full['$defs']['test.threshold'];
    $this->assertSame(['mode', 'threshold', 'reading'], array_keys($def['properties']));
    $this->assertSame(10, $def['properties']['threshold']['default']);
    $this->assertSame('config_schema:condition.plugin.request_path:own', SchemaResolver::defName($chosen['request_path']));
  }

  /**
   * Tests the provider refuses an argument it does not take.
   */
  public function testTheProviderRefusesAnUnknownArgument(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('ConfigSchemaTypes takes the arguments type, path and by; given at.');
    $this->container->get(ConfigSchemaTypes::class)->listFor(['type' => 'block.block.*', 'at' => 'settings']);
  }

  /**
   * Tests a deciding key refused for an own-key expression names no path.
   */
  public function testTheDecidingKeyRefusedForAnExpressionNamesItAlone(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('ConfigSchemaTypes lists the variants of condition.plugin.[id] for "plugin", and the schema types it by "id"');
    $this->container->get(ConfigSchemaTypes::class)->listFor(['type' => 'condition.plugin.[id]', 'by' => 'plugin']);
  }

  /**
   * Builds a structure in the configure context and emits its full schema.
   *
   * @param string $name
   *   The structure's name.
   *
   * @return array
   *   The full schema, decoded.
   */
  protected function full(string $name): array {
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    return json_decode((string) json_encode($this->container->get('surface.contract_emitter')->emitFull($built, $name)->document['schema']), TRUE);
  }

  /**
   * Gets the registry.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceRegistry
   *   The registry.
   */
  protected function registry(): mixed {
    return $this->container->get('surface.resource_registry');
  }

}
