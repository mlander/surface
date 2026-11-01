<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_resource_test\Storage\StallStorage;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests addMap(): an object inline, plain or holding structures.
 *
 * The market stall's `where` is a plain map holding a plain map; its
 * `kit` holds a structure and a map that holds one, two levels deep,
 * each a structure with no id. Both are written inline, the structures
 * they hold as references to their defs; the form draws them, the tool
 * takes and answers them nested, and storage holds them as sent.
 *
 * @see docs/decisions.md#an-anonymous-map
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AnonymousMapTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
  ];

  /**
   * The resource.
   */
  protected const RESOURCE = 'resource_test.stall';

  /**
   * What a call sends, and storage then holds.
   */
  protected const SENT = [
    'name' => 'Corner',
    'where' => ['row' => 'C', 'point' => ['lat' => 51.5, 'lng' => -0.1]],
    'kit' => [
      'tent' => TRUE,
      'bench' => ['enabled' => TRUE, 'hours' => 4],
      'spare' => ['count' => 2, 'bench' => ['enabled' => FALSE]],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the stall in its operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function built(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(self::RESOURCE, $resources->operation(self::RESOURCE, 'configure'));
  }

  /**
   * Tests a plain map is a map, and one holding a structure a structure.
   */
  public function testWhatEachMapIs(): void {
    $definitions = $this->built()->getDefinitions();
    $where = $definitions->entry('where');
    $this->assertNull($where->attachment, 'A map of plain keys is a map definition at the key.');
    $this->assertInstanceOf(MapDataDefinition::class, $where->definition);
    $this->assertSame(['row', 'point'], array_keys($where->definition->getPropertyDefinitions()));
    $this->assertSame(['row' => 'A'], $this->built()->getDefault('where'));

    $kit = $definitions->entry('kit')->attachment;
    $this->assertNotNull($kit, 'A map holding a structure is a structure with no id, attached at the key.');
    $this->assertTrue(ResourceRegistry::isMap($kit->source));
    $this->assertSame(self::RESOURCE . '.kit', $kit->id);
    $spare = $kit->child->getDefinitions()->entry('spare')->attachment;
    $this->assertTrue(ResourceRegistry::isMap($spare->source), 'Two levels deep.');
    $this->assertSame(self::RESOURCE . '.kit.spare', $spare->id);
    $this->assertSame(BenchStructure::class, $spare->child->getDefinitions()->entry('bench')->attachment->source);
  }

  /**
   * Tests both documents write each map inline and each structure by def.
   */
  public function testBothDocumentsWriteTheMapsInline(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    foreach ([
      'full' => $emitter->emitFull($this->built(), self::RESOURCE, 'configure')->document['schema'],
      'present' => $emitter->emit($this->built(), self::SENT, self::RESOURCE, 'configure')->document['schema'],
    ] as $which => $schema) {
      $this->assertSame(['resource_test.workshop.bench'], array_keys($schema['$defs']), $which . ': no def for a map, one for the structure inside.');
      $where = $schema['properties']['where'];
      $this->assertSame(['object', 'null'], $where['type']);
      $this->assertSame(['row', 'point'], array_keys($where['properties']));
      $this->assertSame(['lat', 'lng'], array_keys($where['properties']['point']['properties']));
      $this->assertSame('number', $where['properties']['point']['properties']['lat']['type'][0]);
      $kit = $schema['properties']['kit'];
      $said = [$kit['title'], $kit['description'], $kit['type']];
      $this->assertSame(['Kit', 'What the stall brings.', ['object', 'null']], $said, $which);
      $this->assertFalse($kit['additionalProperties']);
      $this->assertSame(['tent', 'bench', 'spare'], array_keys($kit['properties']));
      $this->assertSame('#/$defs/resource_test.workshop.bench', $kit['properties']['bench']['$ref'] ?? $kit['properties']['bench']['allOf'][0]['$ref'] ?? NULL, $which);
      $this->assertSame(['count', 'bench'], array_keys($kit['properties']['spare']['properties']));
      $this->assertSame('#/$defs/resource_test.workshop.bench', $kit['properties']['spare']['properties']['bench']['$ref'] ?? $kit['properties']['spare']['properties']['bench']['allOf'][0]['$ref'] ?? NULL, $which);
    }
  }

  /**
   * Tests the form draws every key, two levels deep.
   */
  public function testTheFormDrawsEveryKey(): void {
    $built = $this->built();
    $form = $this->formBuilder()->buildSurfaceForm($built, self::SENT, new FormState());
    $this->assertSame(51.5, (float) $form['where']['point']['lat']['#default_value']);
    $this->assertSame('C', $form['where']['row']['#default_value']);
    $this->assertSame('checkbox', $form['kit']['tent']['#type']);
    $this->assertSame('4', (string) $form['kit']['bench']['hours']['#default_value']);
    $this->assertSame('2', (string) $form['kit']['spare']['count']['#default_value']);
    $this->assertArrayHasKey('enabled', $form['kit']['spare']['bench']);
  }

  /**
   * Tests the tool takes the maps nested, and storage holds them as sent.
   */
  public function testTheToolWritesAndStorageHoldsThem(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $definition = $tools->getDefinition('surface:' . self::RESOURCE . ':configure');
    $values = $definition->getInputDefinitions()[OperationInputs::VALUES];
    $kit = $values->getPropertyDefinitions()['kit'];
    $this->assertSame(['tent', 'bench', 'spare'], array_keys($kit->getPropertyDefinitions()));
    $this->assertSame(['count', 'bench'], array_keys($kit->getPropertyDefinitions()['spare']->getPropertyDefinitions()));
    $this->assertSame(['row', 'point'], array_keys($values->getPropertyDefinitions()['where']->getPropertyDefinitions()));

    $tool = $tools->createInstance('surface:' . self::RESOURCE . ':configure');
    $tool->setInputValue(OperationInputs::VALUES, self::SENT);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->container->get('state')->get(StallStorage::KEY);
    $this->assertSame(self::SENT['where'], $stored['where']);
    $this->assertSame(self::SENT['kit']['bench'], $stored['kit']['bench']);
    $this->assertSame(self::SENT['kit']['spare']['count'], $stored['kit']['spare']['count']);
    $this->assertFalse($stored['kit']['spare']['bench']['enabled']);

    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(self::RESOURCE, 'configure');
    $built = $resources->build(self::RESOURCE, $context);
    $loaded = $resources->storage(self::RESOURCE, $context, $built)->load($built);
    $this->assertSame($stored['kit'], $loaded['kit'], 'Read back as stored, through the child it is.');

    $read = $tools->createInstance('surface:' . self::RESOURCE . ':read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $answered = $read->getResult()->getContextValues()[OperationInputs::VALUES];
    $this->assertSame(self::SENT['where'], $answered['where']);
    $this->assertSame(4, $answered['kit']['bench']['hours']);

    // Refused inside a map as at the top: the bench's own range.
    $tool = $tools->createInstance('surface:' . self::RESOURCE . ':configure');
    $long = ['kit' => ['spare' => ['bench' => ['enabled' => TRUE, 'hours' => 40]]]];
    $tool->setInputValue(OperationInputs::VALUES, $long + self::SENT);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
  }

  /**
   * Tests a map needing a frame is refused where no structure can stand.
   */
  public function testWhereNoStructureCanStandItIsRefused(): void {
    $typed = $this->container->get('typed_data_manager');
    $attaching = static function (BlueprintInterface $map): void {
      $map->add('label', 'string', 'Label');
      $map->attach('bench', BenchStructure::class);
    };
    $refused = function (callable $call, string $said): void {
      try {
        $call();
        $this->fail($said);
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($said, $e->getMessage());
      }
    };
    $alter = new ResourceBlueprintAdditions(new SurfaceBuilder(), $typed, 'surface_test');
    $refused(fn () => $alter->addMap('kit', 'Kit', $attaching), 'attach("bench") inside the map "kit", declared with addMap(): a map whose keys need a frame of their own is a structure with no id, attached at the key, and only a resource\'s or a structure\'s own inputs attach one');
    $outputs = new ResourceBlueprint(new SurfaceBuilder(), $typed, TRUE);
    $refused(fn () => $outputs->addMap('kit', 'Kit', $attaching), 'outputs hold no attached structures yet.');
    $nested = static function (BlueprintInterface $map) use ($attaching): void {
      $map->addMap('inner', 'Inner', $attaching);
    };
    $refused(fn () => $alter->addMap('kit', 'Kit', $nested), 'attach("bench") inside the map "kit.inner"');

    // Plain keys are a map wherever one is declared.
    $alter->addMap('plain', 'Plain', static function (BlueprintInterface $map): void {
      $map->add('label', 'string', 'Label', default: 'x');
    });
    $this->assertSame(['plain'], $alter->keys());
  }

}
