<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_resource_test\Resource\Studio\StudioResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Tests the widget hints are the dialect's `x-portable-ui`.
 *
 * The studio declares two groups, one collapsed, keys in both and two
 * ungrouped, a placeholder, a prefix, a suffix and a help text. Each key
 * names its group by id and its frame declares the group once; a module's
 * mount is a frame of its own. Without the hints only the empty option is
 * left, the full schema has none, and the document reads back with its
 * arrangement.
 *
 * @see docs/decisions.md#the-ui-hints-are-x-portable-ui
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class UiKeywordTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'surface',
    'surface_resource_test',
  ];

  /**
   * The groups the studio's top frame declares.
   */
  protected const GROUPS = [
    'hours' => ['label' => 'Opening hours', 'widget' => 'fieldset'],
    'pricing' => ['label' => 'Pricing', 'widget' => 'details', 'options' => ['open' => FALSE]],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the studio in its operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The studio, sealed.
   */
  protected function studio(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(StudioResource::class, $resources->operation(StudioResource::class, 'configure'));
  }

  /**
   * Tests a key names its group, and its frame declares the group once.
   */
  public function testTheFrameDeclaresEachGroupOnce(): void {
    $studio = $this->studio();
    $emitter = $this->container->get('surface.contract_emitter');
    $documents = [
      'emit' => $emitter->emit($studio, $studio->getDefaultValues(), 'resource_test.studio', 'configure', widgets: TRUE),
      'emitFull' => $emitter->emitFull($studio, 'resource_test.studio', 'configure', widgets: TRUE),
    ];
    foreach ($documents as $which => $contract) {
      $schema = $contract->document['schema'];
      $properties = $schema['properties'];
      $this->assertSame([
        'widget' => 'number',
        'options' => [
          'placeholder' => 'For example, 40',
          'prefix' => '$',
          'suffix' => 'per hour',
          'help' => 'Whole dollars.',
        ],
        'group' => 'pricing',
      ], $properties['rate']['x-portable-ui'], $which);
      $this->assertSame(['widget' => 'checkbox', 'group' => 'pricing', 'weight' => -1], $properties['deposit']['x-portable-ui'], $which);
      $this->assertSame(['widget' => 'text', 'group' => 'hours', 'weight' => 2], $properties['opens']['x-portable-ui'], $which);
      $this->assertSame(['widget' => 'text', 'group' => 'hours', 'weight' => 1], $properties['closes']['x-portable-ui'], $which);
      $this->assertSame(['widget' => 'textarea', 'weight' => -10], $properties['notes']['x-portable-ui'], $which);
      $name = ['placeholder' => 'Riverside studio', 'help' => 'Shown on the door.'];
      $this->assertSame(['widget' => 'text', 'options' => $name], $properties['name']['x-portable-ui'], $which);
      // Declared once, on the frame, in the order its keys name them; the
      // whole group is on no key.
      $this->assertSame(['groups' => self::GROUPS], $schema['x-portable-ui'], $which);
      $this->assertArrayNotHasKey('x-surface', $properties['rate'], $which . ': an `x-surface` that said only hints is not written.');
      // Before `x-surface`, after the rest, on a key that has both.
      $mount = $properties['third_party_settings']['properties']['surface_resource_test'];
      $keys = array_keys($mount);
      $this->assertSame(['x-portable-ui', 'x-surface'], array_slice($keys, -2), $which);
      $this->assertInstanceOf(Validator::class, new Validator(Registry::withTypes()->add($contract->schema()->toDocument(), 'surface:studio/' . $which)), $which . ': loads in the dialect.');
    }
  }

  /**
   * Tests a module's mount declares its own groups, and the empty option.
   */
  public function testMountDeclaresItsOwnGroups(): void {
    $surface = $this->builtWithMount();
    $emitter = $this->container->get('surface.contract_emitter');
    $hinted = $emitter->emitFull($surface, 'test.mounted', widgets: TRUE)->document['schema'];
    $this->assertSame(['groups' => ['look' => ['label' => 'Look', 'widget' => 'fieldset']]], $hinted['x-portable-ui']);
    $mount = $hinted['properties']['third_party_settings']['properties']['first_module'];
    $extras = [
      'label' => 'Extras',
      'description' => 'Rarely needed.',
      'widget' => 'details',
      'options' => ['open' => FALSE],
      'weight' => 5,
    ];
    $this->assertSame(['extras' => $extras], $mount['x-portable-ui']['groups']);
    $this->assertSame('fieldset', $mount['x-portable-ui']['widget'], 'Its own widget first, then the groups its keys name.');
    $this->assertSame(['widget', 'groups'], array_keys($mount['x-portable-ui']));
    $this->assertSame('extras', $mount['properties']['badge']['x-portable-ui']['group']);

    // Without the hints only the empty option is left, in the schema for
    // these answers; the full schema has none.
    $present = $emitter->emit($surface, $surface->getDefaultValues(), 'test.mounted')->document['schema'];
    $this->assertSame(['options' => ['emptyOption' => ['show' => TRUE, 'label' => '- None -']]], $present['properties']['size']['x-portable-ui']);
    $this->assertArrayNotHasKey('x-portable-ui', $present);
    $this->assertArrayNotHasKey('x-portable-ui', $present['properties']['third_party_settings']['properties']['first_module']);
    $this->assertArrayNotHasKey('x-portable-ui', $present['properties']['third_party_settings']['properties']['first_module']['properties']['badge']);
    $full = $emitter->emitFull($surface, 'test.mounted')->document['schema'];
    $this->assertStringNotContainsString('x-portable-ui', (string) json_encode($full));
    $hintedPresent = $emitter->emit($surface, $surface->getDefaultValues(), 'test.mounted', widgets: TRUE)->document['schema'];
    $empty = ['emptyOption' => ['show' => TRUE, 'label' => '- None -']];
    $this->assertSame(['widget' => 'select', 'options' => $empty, 'group' => 'look'], $hintedPresent['properties']['size']['x-portable-ui']);
    $this->assertArrayNotHasKey('emptyOption', $hinted['properties']['size']['x-portable-ui']['options'] ?? [], 'The full schema has no empty option, hints or not.');
  }

  /**
   * Tests the document reads back with its arrangement.
   */
  public function testTheDocumentReadsBackWithItsArrangement(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $first = $emitter->emitFull($this->studio(), 'resource_test.studio', 'configure', widgets: TRUE);
    $read = $this->container->get('surface.resources')->buildStatic($this->container->get('surface.schema_reader')->structure(json_decode((string) json_encode($first->document['schema']), TRUE), 'test.studio_read'), new OperationContext('configure'));
    $again = $emitter->emitFull($read, 'test.studio_read', widgets: TRUE);
    $this->assertSame(self::hints($first), self::hints($again));
    $this->assertSame(['groups' => self::GROUPS], $again->document['schema']['x-portable-ui']);

    // A module's mount is read as a map: its keys keep their groups too.
    $first = $emitter->emitFull($this->builtWithMount(), 'test.mounted', widgets: TRUE);
    $read = $this->container->get('surface.resources')->buildStatic($this->container->get('surface.schema_reader')->structure(json_decode((string) json_encode($first->document['schema']), TRUE), 'test.mounted_read'), new OperationContext('configure'));
    $again = $emitter->emitFull($read, 'test.mounted_read', widgets: TRUE);
    $this->assertSame(self::hints($first), self::hints($again));
    $this->assertSame('extras', self::hints($again)['third_party_settings.first_module.badge']['group']);
  }

  /**
   * Tests a text that is empty as plain text is not written.
   */
  public function testTextOfMarkupAloneIsNotWritten(): void {
    $builder = new SurfaceBuilder();
    $owner = new ResourceBlueprint($builder, $this->container->get('typed_data_manager'));
    $owner->add('code', 'string', 'Code');
    $owner->describe('code', placeholder: '<span></span>', help: 'Four <em>letters</em>.');
    $schema = $this->container->get('surface.contract_emitter')->emitFull($builder->seal(), 'test.texts', widgets: TRUE)->document['schema'];
    $this->assertSame(['help' => 'Four letters.'], $schema['properties']['code']['x-portable-ui']['options']);
  }

  /**
   * Builds a data surface whose module mount groups its own key.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface, sealed.
   */
  protected function builtWithMount(): SurfaceInterface {
    $typed_data = $this->container->get('typed_data_manager');
    $builder = new SurfaceBuilder();
    $owner = new ResourceBlueprint($builder, $typed_data);
    $owner->add('size', 'string', 'Size')
      ->addConstraint('LabeledChoice', ['choices' => ['s' => 'Small', 'm' => 'Medium']]);
    $owner->describe('size', group: ArrangementGroup::fieldset('look', 'Look'));
    $alter = new ResourceBlueprintAdditions($builder, $typed_data, 'first_module');
    $alter->add('badge', 'string', 'Badge');
    $alter->describe('third_party_settings.first_module.badge', group: ArrangementGroup::details('extras', 'Extras', collapsed: TRUE, weight: 5, description: 'Rarely needed.'));
    return $builder->seal();
  }

  /**
   * Reads the hints of the root and of every key below it, maps included.
   *
   * @param \Drupal\surface\Contract\ServedContract $contract
   *   The contract.
   *
   * @return array
   *   The `x-portable-ui` of each, by the key's dotted path; the root's
   *   under ''.
   */
  protected static function hints(ServedContract $contract): array {
    $hints = [];
    $walk = static function (array $schema, string $path) use (&$walk, &$hints): void {
      $hints[$path] = $schema['x-portable-ui'] ?? NULL;
      foreach (is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [] as $key => $property) {
        if (is_array($property)) {
          $walk($property, ltrim($path . '.' . $key, '.'));
        }
      }
    };
    $walk($contract->document['schema'], '');
    return $hints;
  }

}
