<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\surface\Contract\ContractBaselines;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\surface\Resource\OperationContext;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a widget changes nothing a contract says but its hints.
 *
 * For every example resource and operation, the content entity resources
 * among them, whose keys ask for the widgets their form displays name:
 * both documents emitted with the widget hints, less every hint, are the
 * documents emitted without them, byte for byte. A widget draws; what is
 * accepted is the key's.
 *
 * @see docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WidgetHintsOnlyTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'surface_http',
    'surface_examples',
    'surface_examples_first',
    'surface_examples_references',
  ];

  /**
   * The keywords of `x-surface` that are widget hints.
   */
  protected const HINTS = ['widget', 'multiple', 'arrangement'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'filter',
      'node',
      'system',
      'surface_examples',
      'surface_examples_first',
      'surface_examples_references',
    ]);
    FilterFormat::create(['format' => 'basic', 'name' => 'Basic', 'weight' => 0])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    // Every covered field, each drawn by its type's default widget, which
    // its key then asks for.
    $this->createKitchen();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Strips every widget hint from a document, at every depth.
   *
   * @param mixed $schema
   *   The document or a part of it.
   *
   * @return mixed
   *   It, with no hint, and no `x-surface` a hint alone made.
   */
  protected static function withoutHints(mixed $schema): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $key => $value) {
      if ($key === 'x-surface' && is_array($value)) {
        $value = array_diff_key($value, array_flip(self::HINTS));
        if (is_array($value['within'] ?? NULL)) {
          // What the extension says of a pointer inside the property is
          // an extension too.
          $value['within'] = array_filter(array_map(static fn (array $inside): array => array_diff_key($inside, array_flip(self::HINTS)), $value['within']));
          if ($value['within'] === []) {
            unset($value['within']);
          }
        }
        if ($value === []) {
          unset($schema[$key]);
          continue;
        }
      }
      $schema[$key] = static::withoutHints($value);
    }
    return $schema;
  }

  /**
   * Tests every example's documents differ by their hints alone.
   */
  public function testHintsAreAllWidgetsChange(): void {
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $compared = [];
    foreach ($registry->getDefinitions() as $definition) {
      if (!str_starts_with($definition->module, 'surface_examples')) {
        continue;
      }
      $name = $definition->name();
      $operations = $registry->getOperations($name);
      $asked = $operations === [] ? [NULL] : array_filter($operations, static fn ($operation): bool => $operation->needsNothing());
      foreach ($asked as $operation) {
        $context = $operation === NULL ? new OperationContext(ContractBaselines::ALONE) : $resources->operation($name, $operation->id);
        $built = $resources->build($name, $context);
        $label = $definition->id . ':' . $context->id;
        $full = static fn (bool $widgets): array => $emitter->emitFull($built, $definition->id, $operation?->id, widgets: $widgets)->document;
        $this->assertSame(json_encode($full(FALSE)), json_encode(static::withoutHints($full(TRUE))), $label . ', the full schema');
        $values = $built->getDefaultValues();
        $present = static fn (bool $widgets): array => $emitter->emit($built, $values, $definition->id, $operation?->id, widgets: $widgets)->document;
        $this->assertSame(json_encode($present(FALSE)), json_encode(static::withoutHints($present(TRUE))), $label . ', the schema as it stands');
        $compared[] = $label;
      }
    }
    $this->assertContains('entity.content:node.kitchen:create', $compared, 'The kitchen sink, every covered field asking for its form display\'s widget.');
    $this->assertContains('event.sponsorship:configure', $compared);
    $this->assertGreaterThan(40, count($compared));
  }

}
