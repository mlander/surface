<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool_explorer\Controller\ToolExplorerController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the derived tools' definitions as Tool Explorer reads them.
 *
 * Two regressions, both seen on a live site. Tool Explorer's page for a
 * tool prints the config schema it suggests for the tool's inputs with
 * Yaml::encode(), which copies every constraint's options and refuses an
 * object: a LabeledChoice's TranslatableMarkup labels on the node type's
 * preview mode and on the extras' deadline unit broke the page for
 * data_surface:node.type:add. And a module installed after the tool
 * definitions were cached has to have its surfaces' tools discovered
 * without anyone clearing a plugin cache by hand.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DerivedToolDefinitionsTest extends DataSurfaceKernelTestBase {

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
    'entity_test',
    'address',
    'serialization',
    'tool',
    'tool_explorer',
    'data_surface',
    'data_surface_address',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_node_type',
    'data_surface_surface_test',
    'data_surface_tool',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['field', 'node']);
  }

  /**
   * Gets the tool manager from the current container.
   *
   * @return \Drupal\tool\Tool\ToolManager
   *   The tool manager.
   */
  protected function toolManager(): ToolManager {
    return $this->container->get('plugin.manager.tool');
  }

  /**
   * Lists the derived tools' ids.
   *
   * @return list<string>
   *   Every `data_surface:<surface>:<situation>` tool, sorted.
   */
  protected function derivedIds(): array {
    $ids = array_values(array_filter(
      array_keys($this->toolManager()->getDefinitions()),
      static fn (string $id): bool => str_starts_with($id, 'data_surface:') && substr_count($id, ':') === 2,
    ));
    sort($ids);
    return $ids;
  }

  /**
   * Gets Tool Explorer's controller.
   *
   * @return \Drupal\tool_explorer\Controller\ToolExplorerController
   *   The controller.
   */
  protected function explorer(): ToolExplorerController {
    return ToolExplorerController::create($this->container);
  }

  /**
   * Gets the config schema Tool Explorer suggests for a tool's inputs.
   *
   * @param \Drupal\tool_explorer\Controller\ToolExplorerController $explorer
   *   The controller.
   * @param array<string, \Drupal\tool\TypedData\InputDefinitionInterface> $inputs
   *   The tool's input definitions.
   *
   * @return array<string, mixed>
   *   The schema, exactly as the explorer builds it to encode.
   */
  protected function suggestedSchema(ToolExplorerController $explorer, array $inputs): array {
    $schema = (new \ReflectionMethod($explorer, 'buildSuggestedConfigSchema'))->invoke($explorer, $inputs);
    $this->assertIsArray($schema);
    return $schema;
  }

  /**
   * Tests that Tool Explorer can show every derived tool.
   *
   * Explorer's view page is built for each, which is where the encoding
   * failed, and the schema it suggests is encoded here as well, so the
   * test still covers the encoding on the day a schema file makes the
   * page print the file instead. Every constraint option on every input
   * and output, at any depth, is a scalar or an array of them.
   */
  public function testExplorerShowsEveryDerivedTool(): void {
    $this->container->get('module_installer')->install(['data_surface_examples', 'data_surface_examples_compliance']);
    $ids = $this->derivedIds();
    // The tools that carry what broke, so this cannot pass by having
    // nothing to look at: the node type's add, built in its real
    // context with the extras' LabeledChoice units, and the examples.
    $this->assertContains('data_surface:node.type:add', $ids);
    $this->assertContains('data_surface:node.type:edit', $ids);
    $this->assertContains('data_surface:registration.step3:configure', $ids);
    $this->assertContains('data_surface:field.instance:edit', $ids);

    $explorer = $this->explorer();
    foreach ($ids as $id) {
      $definition = $this->toolManager()->getDefinition($id);
      assert($definition instanceof ToolDefinition);
      $build = $explorer->viewTool($id);
      $this->assertArrayHasKey('config_schema', $build, $id);
      $schema = $this->suggestedSchema($explorer, $definition->getInputDefinitions());
      $this->assertIsString(Yaml::encode(['tool.plugin.' . $id => $schema]), $id);
      foreach ($definition->getInputDefinitions() as $name => $input) {
        $this->assertNoObjects($input, $id . ' input ' . $name);
      }
      foreach ($definition->getOutputDefinitions() as $name => $output) {
        $this->assertNoObjects($output, $id . ' output ' . $name);
      }
    }

    // The labels still say what they said, as strings.
    $add = $this->toolManager()->getDefinition('data_surface:node.type:add');
    assert($add instanceof ToolDefinition);
    $values = $add->getInputDefinition('values');
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $labels = $values->getPropertyDefinitions()['preview_mode']->getConstraints()['LabeledChoice']['labels'];
    $this->assertSame(['Disabled', 'Optional', 'Required'], array_values($labels));
  }

  /**
   * Tests that installing a module with a surface derives its tools.
   *
   * The tool definitions are read, and so cached, before the module is
   * installed; installing it is all that happens before they are read
   * again, as on a site. Tool Explorer's index lists them too.
   */
  public function testInstalledModuleSurfacesBecomeTools(): void {
    $before = $this->derivedIds();
    $this->assertContains('data_surface:node.type:add', $before);
    $this->assertNotContains('data_surface:registration.step3:configure', $before);
    $this->assertNotFalse($this->container->get('cache.discovery')->get('tool_plugins'));

    $this->container->get('module_installer')->install(['data_surface_examples']);

    $after = $this->derivedIds();
    $this->assertSame([
      'data_surface:registration.step1:configure',
      'data_surface:registration.step2:configure',
      'data_surface:registration.step3:configure',
    ], array_values(array_diff($after, $before)));
    $this->assertSame([], array_diff($before, $after));

    $build = $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn (): array => $this->explorer()->listTools());
    $listed = array_column($build['tools_table']['#rows'], 'id');
    foreach ($after as $id) {
      $this->assertContains($id, $listed);
    }
  }

  /**
   * Asserts a definition's constraint options and default hold no object.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, walked through its properties and items.
   * @param string $path
   *   Where it is, for the message.
   */
  protected function assertNoObjects(DataDefinitionInterface $definition, string $path): void {
    $this->assertScalars($definition->getConstraints(), $path . ' constraints');
    if ($definition instanceof InputDefinitionInterface) {
      $this->assertScalars($definition->getDefaultValue(), $path . ' default');
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $this->assertNoObjects($property, $path . '.' . $name);
      }
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $this->assertNoObjects($definition->getItemDefinition(), $path . '.item');
    }
  }

  /**
   * Asserts a value is a scalar, NULL, or an array of them, at any depth.
   *
   * @param mixed $value
   *   The value.
   * @param string $path
   *   Where it is, for the message.
   */
  protected function assertScalars(mixed $value, string $path): void {
    if (is_array($value)) {
      foreach ($value as $key => $item) {
        $this->assertScalars($item, $path . '.' . $key);
      }
      return;
    }
    $this->assertFalse(is_object($value), sprintf('%s is a %s.', $path, get_debug_type($value)));
  }

}
