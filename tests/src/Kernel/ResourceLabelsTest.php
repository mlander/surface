<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\my_module\Resource\MySettingsResource;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface_intro_test\Structure\TeaserBlockStructure;
use Drupal\surface_resource_test\OutsideDiscovery\Labels\ClayPotStructure;
use Drupal\surface_resource_test\OutsideDiscovery\Labels\PlanterStructure;
use Drupal\surface_resource_test\OutsideDiscovery\Labels\SoilStructure;
use Drupal\surface_resource_test\OutsideDiscovery\Labels\StonePotStructure;
use Drupal\surface_resource_test\Alter\SkuAlter;
use Drupal\surface_resource_test\Shape\Sku;
use Drupal\surface_test\Structure\GatedFieldSettingsStructure;
use Drupal\Tests\surface\Traits\SchemaRoundTripTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a resource or structure says it is called and is for.
 *
 * `label:` and `description:` on #[Resource] and #[Structure]: the
 * declared words win; a structure one plugin holds takes the plugin's
 * when it says none; one with neither has none, and is named by its id.
 * The label offers a variant's value where its #[Variant] gives none,
 * and titles and describes its def in the served contract, which the
 * schema reader reads back.
 *
 * @see docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
final class ResourceLabelsTest extends SurfaceKernelTestBase {

  use SchemaRoundTripTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'surface',
    'surface_examples_block',
    'surface_intro_test',
    'surface_resource_test',
    'surface_test',
    'my_module',
  ];

  /**
   * Builds the planter with the registry that alone knows it.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The build step over that registry.
   */
  protected function planters(): ResourcesInterface {
    $registry = new ResourceRegistry([
      'resources' => [
        PlanterStructure::class => 'surface_resource_test',
        SoilStructure::class => 'surface_resource_test',
        ClayPotStructure::class => 'surface_resource_test',
        StonePotStructure::class => 'surface_resource_test',
      ],
      'shapes' => [Sku::class => 'surface_resource_test'],
      // The site's own alter of the shape, as the document read back has it.
      'alters' => [SkuAlter::class => 'surface_resource_test'],
    ], $this->container->get('cache.discovery'));
    return $this->container->get('surface.resources')->withRegistry($registry, $this->container->get('class_resolver'));
  }

  /**
   * Gets the planter's full schema.
   *
   * @return array
   *   The schema.
   */
  protected function planterSchema(): array {
    $emitter = $this->container->get('surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    return $emitter->emitFull($this->planters()->buildStatic(PlanterStructure::class, new OperationContext('configure')), 'resource_test.planter')->document['schema'];
  }

  /**
   * Tests the declared label and description are what the definition says.
   */
  public function testTheDeclaredWordsWin(): void {
    $settings = $this->container->get('surface.resource_registry')->getDefinition(MySettingsResource::class);
    $this->assertSame('My module settings', (string) $settings->label());
    $this->assertStringStartsWith('What my module lists and how', (string) $settings->description());

    $registry = new ResourceRegistry([
      'resources' => [
        PlanterStructure::class => 'surface_resource_test',
        SoilStructure::class => 'surface_resource_test',
      ],
    ], $this->container->get('cache.discovery'));
    $planter = $registry->getDefinition(PlanterStructure::class);
    $this->assertSame('Planter', (string) $planter->label());
    $this->assertSame('A pot its kind chooses, and the soil in it.', (string) $planter->description());
  }

  /**
   * Tests a structure one plugin holds takes the plugin's words.
   *
   * The teaser block's admin label, and no description, since a block
   * definition has none; the gated field type's label and description.
   */
  public function testThePluginSaysWhatItsStructureIsCalled(): void {
    $registry = $this->container->get('surface.resource_registry');
    $teaser = $registry->getDefinition(TeaserBlockStructure::class);
    $this->assertNull($teaser->label, 'The structure declares no label.');
    $this->assertSame('Teaser', (string) $teaser->label(), 'The block\'s admin label.');
    $this->assertNull($teaser->description());

    $gated = $registry->getDefinition(GatedFieldSettingsStructure::class);
    $this->assertSame('Gated text (Surface API test)', (string) $gated->label());
    $this->assertSame('A short text field whose instance settings the field type itself may refuse.', (string) $gated->description());
  }

  /**
   * Tests a structure that says nothing, and no plugin holds, has nothing.
   */
  public function testNeitherSaysNothing(): void {
    $registry = new ResourceRegistry([
      'resources' => [SoilStructure::class => 'surface_resource_test'],
    ], $this->container->get('cache.discovery'), $this->container->get('surface.resource_plugins'));
    $this->assertNull($registry->getDefinition(SoilStructure::class)->label());
    $this->assertNull($registry->getDefinition(SoilStructure::class)->description());
  }

  /**
   * Tests a variant is offered by its structure's label, under its own.
   */
  public function testVariantIsOfferedByWhatItIsCalled(): void {
    $kind = $this->planters()->buildStatic(PlanterStructure::class, new OperationContext('configure'))->getDefinition('kind');
    $set = ChoiceSet::of($kind);
    $this->assertNotNull($set);
    $this->assertSame('LabeledChoice', $set->constraint);
    $this->assertSame(['clay' => 'Clay pot', 'stone' => 'Stone'], array_map('strval', $set->labels), 'The clay pot\'s own label, and the stone pot\'s #[Variant] label over its own.');
  }

  /**
   * Tests each def is titled and described by what its structure says.
   *
   * A structure with a label titles its def by it and describes it by its
   * description; one with none is titled by its key, and the key is the
   * id either way; a shape's def is titled by the shape's label.
   */
  public function testTheDefsSayWhatTheirStructuresAre(): void {
    $defs = $this->planterSchema()['$defs'];
    $this->assertSame(['title' => 'Clay pot', 'description' => 'A pot of fired clay, glazed or not.'], array_slice($defs['resource_test.planter.pot.clay'], 0, 2));
    $this->assertSame('Stone pot', $defs['resource_test.planter.pot.stone']['title']);
    $this->assertArrayNotHasKey('description', $defs['resource_test.planter.pot.stone']);
    $this->assertSame('resource_test.planter.soil', $defs['resource_test.planter.soil']['title'], 'No label: titled by its key.');
    $this->assertArrayNotHasKey('description', $defs['resource_test.planter.soil']);
    $this->assertSame('Stock code', $defs['resource_test.sku']['title'], 'The shape\'s label.');
  }

  /**
   * Tests a def's title and description read back into its structure.
   *
   * The planter's full schema, read by the schema reader, builds a
   * structure whose full schema is that document again; each def read
   * from it is called what the def's title says, and one titled by its
   * own key is called nothing.
   */
  public function testTheNamesReadBack(): void {
    $schema = $this->planterSchema();
    $back = $this->readBack($schema, 'resource_test.planter', 'surface_resource_test', 'configure', NULL, NULL, []);
    $this->assertSame('same', $back['verdict'], (string) $back['error']);

    $registry = $this->container->get('surface.resource_registry');
    assert($registry instanceof ResourceRegistry);
    $read = [];
    foreach ($registry->getReadDefinitions() as $definition) {
      $read[$definition->id] = [
        $definition->label() === NULL ? NULL : (string) $definition->label(),
        $definition->description() === NULL ? NULL : (string) $definition->description(),
      ];
    }
    $this->assertSame(['Clay pot', 'A pot of fired clay, glazed or not.'], $read['resource_test.planter.pot.clay']);
    $this->assertSame(['Stone pot', NULL], $read['resource_test.planter.pot.stone']);
    $this->assertSame([NULL, NULL], $read['resource_test.planter.soil']);
  }

}
