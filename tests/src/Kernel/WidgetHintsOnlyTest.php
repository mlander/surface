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
   * The members and options of `x-portable-ui` that are widget hints.
   *
   * With every option a module's widget publishes, which the examples'
   * widgets publish none of.
   */
  protected const HINTS = [
    'widget',
    'multiple',
    'placeholder',
    'prefix',
    'suffix',
    'help',
    'size',
    'rows',
    'group',
    'weight',
    'groups',
  ];

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
   * As the emitter does before it lays the document out: a property below
   * an applicator that said nothing but its hints (its `x-surface` said in
   * `within`) is no property there, a narrowing left with nothing is
   * `true`, and any other schema left with nothing is `{}`.
   *
   * @param mixed $schema
   *   The document or a part of it.
   *
   * @return mixed
   *   It, with no hint, and no `x-portable-ui` a hint alone made.
   */
  protected static function withoutHints(mixed $schema): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $key => $value) {
      if (!is_array($value) || in_array($key, ['const', 'default', 'examples', 'enum', 'x-surface'], TRUE)) {
        continue;
      }
      if ($key === 'x-portable-ui') {
        $value = array_diff_key($value, array_flip(self::HINTS));
        if (is_array($value['options'] ?? NULL)) {
          $value['options'] = array_diff_key($value['options'], array_flip(self::HINTS));
          if ($value['options'] === []) {
            unset($value['options']);
          }
        }
        if ($value === []) {
          unset($schema[$key]);
          continue;
        }
        $schema[$key] = $value;
        continue;
      }
      if ($key === 'properties' || $key === '$defs') {
        foreach ($value as $name => $child) {
          $stripped = static::withoutHints($child);
          if ($stripped === [] && $child !== [] && $key === 'properties') {
            unset($value[$name]);
            continue;
          }
          $value[$name] = $stripped;
        }
        if ($value === [] && $schema[$key] !== []) {
          unset($schema[$key]);
          continue;
        }
        $schema[$key] = $value;
        continue;
      }
      if (array_is_list($value) && in_array($key, ['allOf', 'anyOf', 'oneOf'], TRUE)) {
        $schema[$key] = array_map(static fn (mixed $member): mixed => is_array($member) && $member !== [] && static::withoutHints($member) === [] ? TRUE : static::withoutHints($member), $value);
        continue;
      }
      $stripped = static::withoutHints($value);
      $schema[$key] = $stripped === [] && $value !== [] ? new \stdClass() : $stripped;
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
      $asked = $operations === [] ? [
        NULL,
      ] : array_filter($operations, static fn ($operation): bool => $operation->needsNothing());
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
