<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool\Plugin\Derivative\ResourceOperationToolDeriver;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationReadTool;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationSchemaTool;
use Drupal\data_surface_tool_test\Resource\VaultResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every shipped operation's tool asks for what its kind says.
 *
 * The kind is derived from the attribute, `create`, `update` or `read`,
 * and stated in both documents and in the tool's description, whose
 * first sentence is the operation's own description and whose one line
 * of call rules is its kind's. A create's tool requires the keys the
 * resource requires; an update's requires none, at any depth, says on
 * each key the resource requires that a value already stored stands, and
 * has a read tool; a read only operation's requires none either, since a
 * key left out is checked at its current value, and has no read tool
 * beside it; a read tool takes only the operation's parameters. No two
 * tools share a title. Over the examples, the first module, the field
 * tools and the tool test module.
 *
 * @see docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door
 * @see docs/decisions.md#an-operation-states-how-it-behaves
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OperationKindsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * What an update tool says on each key the resource requires.
   */
  protected const NOTE = 'Required; a value already stored stands when left out.';

  /**
   * The line of call rules each kind's tool description ends with.
   */
  protected const RULES = [
    'create' => 'Create: a key left out takes its default, and every key marked required must be sent.',
    'update' => 'Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.',
    'read' => 'Read only: nothing is written; a key left out is checked at its current value.',
    'delete' => 'Delete: send only what names it; the values it held are answered, and a dry run removes nothing.',
  ];

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
    'data_surface_first',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests each tool's values input follows its operation's kind.
   */
  public function testEachToolAsksWhatItsKindSays(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $registry = $this->container->get('data_surface.resource_registry');
    $resources = $this->container->get('data_surface.resources');
    $operation_inputs = $this->container->get('data_surface_tool.operation_inputs');
    $prefix = OperationInputs::TOOL . ':';
    $kinds = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      if (!str_starts_with($id, $prefix) || str_ends_with($id, ResourceOperationReadTool::SUFFIX) || str_ends_with($id, ResourceOperationSchemaTool::SUFFIX)) {
        continue;
      }
      $derivative = substr($id, strlen($prefix));
      $separator = (int) strrpos($derivative, ':');
      $resource = $registry->getDefinition(substr($derivative, 0, $separator));
      $operation = $registry->getOperation($resource->name(), substr($derivative, $separator + 1));
      $kind = $operation_inputs->kind($resource, $operation);
      $kinds[$id] = $kind;
      $this->assertSame($kind, $operation->facts()['kind'], $id . ': the tool and the documents name one kind.');
      $description = (string) $definition->getDescription();
      // The vault says none, and is described by its label.
      $this->assertSame($operation->description === NULL, $id === 'data_surface:tool_test.vault:configure', $id . ': every shipped operation says what it does.');
      $this->assertSame((string) ($operation->description ?? $operation->label . '.') . ' ' . self::RULES[$kind], $description, $id . ': its own description, then its kind\'s call rules.');
      $this->assertStringNotContainsString('"', $description, $id . ': no literal quotes, which a bridge escapes.');

      $context = $operation->needsNothing() ? $resources->operation($resource->name(), $operation->id) : new OperationContext($operation->id);
      if ($operation->needsNothing()) {
        $this->assertSame($operation->creates, $context->creates, $id . ': the context says what the attribute says.');
      }
      if ($kind === OperationDefinition::DELETE) {
        // A delete takes no values: the parameters name what it removes.
        $this->assertArrayNotHasKey(OperationInputs::VALUES, $definition->getInputDefinitions(), $id);
        $this->assertFalse($manager->hasDefinition($id . ResourceOperationReadTool::SUFFIX), $id . ': nothing is left to read after a delete.');
        continue;
      }
      $values = $definition->getInputDefinition(OperationInputs::VALUES);
      $this->assertInstanceOf(MapInputDefinition::class, $values, $id);
      $built = $resources->buildStatic($resource->name(), $context);
      $requires = [];
      foreach ($built->getDefinitions()->entries() as $name => $entry) {
        if ($values->getPropertyDefinition($name) !== NULL && $entry->definition->isRequired() && !in_array('', $entry->mayNotApply(), TRUE) && !DefinitionMetadata::isSecret($entry->definition)) {
          $requires[] = $name;
        }
      }
      $read = $manager->hasDefinition($id . ResourceOperationReadTool::SUFFIX);

      if ($kind === OperationDefinition::CREATE) {
        $this->assertSame($requires, $this->required($values, FALSE), $id . ': a create lists the keys the resource requires.');
        $this->assertStringNotContainsString(self::NOTE, $this->descriptions($values), $id);
        $this->assertFalse($read, $id . ': nothing exists to read before a create.');
        continue;
      }
      $this->assertSame([], $this->required($values), $id . ': nothing is required at the door.');
      // The map itself is always sent, {} when nothing changes.
      $this->assertTrue($values->isRequired(), $id);
      foreach ($requires as $name) {
        $this->assertStringEndsWith(self::NOTE, (string) $values->getPropertyDefinition($name)?->getDescription(), $id . ': ' . $name);
      }
      if ($kind === OperationDefinition::UPDATE) {
        $this->assertTrue($read, $id . ': an update has a read tool beside it.');
        $this->assertSame('Current values: ' . $definition->getLabel(), (string) $manager->getDefinition($id . ResourceOperationReadTool::SUFFIX)->getLabel(), $id);
        $this->assertSame(
          array_map(static fn ($parameter): string => $parameter->name, $operation->parameters),
          array_keys($manager->getDefinition($id . ResourceOperationReadTool::SUFFIX)->getInputDefinitions()),
          $id . ': a read tool takes only the parameters.',
        );
        continue;
      }
      $this->assertSame(OperationDefinition::READ, $kind);
      $this->assertFalse($read, $id . ': a read only operation has no read tool beside it.');
    }

    ksort($kinds);
    $this->assertSame([
      'data_surface:event.bulletin:configure' => 'update',
      'data_surface:event.desk:configure' => 'update',
      'data_surface:event.doors:configure' => 'update',
      'data_surface:event.mailer:configure' => 'update',
      'data_surface:event.press:configure' => 'update',
      'data_surface:event.program:check' => 'read',
      'data_surface:event.program:configure' => 'update',
      'data_surface:event.sales:configure' => 'update',
      'data_surface:event.spotlight:configure' => 'update',
      'data_surface:event.venue:create' => 'create',
      'data_surface:event.venue:delete' => 'delete',
      'data_surface:event.venue:list' => 'read',
      'data_surface:event.venue:reorder' => 'update',
      'data_surface:event.venue:update' => 'update',
      'data_surface:event.webhook:configure' => 'update',
      'data_surface:field.instance:create' => 'create',
      'data_surface:field.instance:reuse' => 'create',
      'data_surface:field.instance:update' => 'update',
      'data_surface:field.storage:update' => 'update',
      'data_surface:first.newsletter:configure' => 'update',
      'data_surface:first.store:configure' => 'update',
      'data_surface:registration.step1:configure' => 'update',
      'data_surface:registration.step2:configure' => 'update',
      'data_surface:registration.step3:configure' => 'update',
      'data_surface:tool_test.cabinet:count' => 'read',
      'data_surface:tool_test.cabinet:update' => 'update',
      'data_surface:tool_test.logbook:append' => 'update',
      'data_surface:tool_test.logbook:clear' => 'update',
      'data_surface:tool_test.logbook:configure' => 'update',
      'data_surface:tool_test.logbook:inspect' => 'read',
      'data_surface:tool_test.logbook:start' => 'create',
      'data_surface:tool_test.specimen:configure' => 'update',
      'data_surface:tool_test.vault:configure' => 'update',
    ], $kinds);
  }

  /**
   * Tests no two tools share a title, and what one shared would read.
   *
   * A client lists tools by title. The examples' three registration steps
   * say which step they are; where operations of several resources still
   * share a label, each tool adds its resource, and the read tool is
   * "Current values:" and the write tool's title.
   */
  public function testNoTwoToolsShareTitles(): void {
    $titles = [];
    foreach ($this->container->get('plugin.manager.tool')->getDefinitions() as $id => $definition) {
      if (str_starts_with($id, OperationInputs::TOOL . ':')) {
        $titles[$id] = (string) $definition->getLabel();
      }
    }
    $this->assertSame(array_unique($titles), $titles);
    $this->assertSame('Configure registration (step 3)', $titles['data_surface:registration.step3:configure']);
    $this->assertSame('Current values: Configure registration (step 3)', $titles['data_surface:registration.step3:configure:read']);

    $deriver = ResourceOperationToolDeriver::create($this->container, OperationInputs::TOOL);
    $shared = new \ReflectionMethod($deriver, 'sharedLabels');
    $registry = $this->container->get('data_surface.resource_registry');
    $this->assertSame([], $shared->invoke($deriver, $registry->getDefinitions()), 'No shipped label is shared.');
    $title = new \ReflectionMethod($deriver, 'title');
    $vault = $registry->getDefinition(VaultResource::class);
    $configure = $registry->getOperation(VaultResource::class, 'configure');
    $this->assertSame('Configure the vault', (string) $title->invoke($deriver, $vault, $configure, []));
    $this->assertSame('Configure the vault (tool_test.vault)', (string) $title->invoke($deriver, $vault, $configure, ['Configure the vault' => TRUE]), 'A class has no label, so its id.');
  }

  /**
   * Lists every property a map requires.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map.
   * @param bool $deep
   *   Whether to look below the top, at every map but a list's items.
   * @param string $prefix
   *   The path above it.
   *
   * @return string[]
   *   The dotted path of each required property.
   */
  protected function required(MapInputDefinition $map, bool $deep = TRUE, string $prefix = ''): array {
    $required = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      if ($property->isRequired()) {
        $required[] = $prefix . $name;
      }
      if ($deep && $property instanceof MapInputDefinition) {
        $required = [...$required, ...$this->required($property, TRUE, $prefix . $name . '.')];
      }
    }
    return $required;
  }

  /**
   * Joins every description in a map, at any depth.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map.
   *
   * @return string
   *   The descriptions, one per line.
   */
  protected function descriptions(MapInputDefinition $map): string {
    $lines = [];
    foreach ($map->getPropertyDefinitions() as $property) {
      $lines[] = (string) $property->getDescription();
      if ($property instanceof MapInputDefinition) {
        $lines[] = $this->descriptions($property);
      }
    }
    return implode("\n", $lines);
  }

}
