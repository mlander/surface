<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\Storage\FlatVariantsLayout;
use Drupal\surface\Storage\StoredSchemaInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Resource\Broken\FlatStateResource;
use Drupal\surface_resource_test\Resource\Campsite\CampsiteResource;
use Drupal\surface_resource_test\Resource\Kit\KitResource;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatListedClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatListedNestedStructure;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatMixinClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatNestedStructure;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatOwnerClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Flat\FlatTwoKeysStructure;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use PortableSchema\Builder\Schema;

/**
 * Variants stored flat: asked for under their key, stored beside its sibling.
 *
 * The kit attaches the variant its kind chooses at its gear, the stove
 * (its fuel, required, and an access class that locks or omits it) or the
 * tent (its poles, required, and its rain fly, with a rule of its own);
 * both declare the weight. The gear's map says Variants::STORED_AS FLAT:
 * the contract, the form and the tool ask for the gear as variants at a
 * key, and the kit's config object keeps the chosen keys beside the kind,
 * with no gear, through the engine's FlatVariantsLayout.
 *
 * The campsite lists pitches, each a name, a kind and the gear that kind
 * chooses, stored flat in the item: its generated config schema types each
 * item by the item's own kind, core's own-key form, which the kit's root
 * cannot take, since core types a config object's root by its name alone.
 *
 * @see \Drupal\surface\Storage\FlatVariantsLayout
 * @see docs/resources.md#stored-flat
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FlatVariantsLayoutTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
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
   * The kit's keys, the gear among them.
   */
  protected const KEYS = ['title', 'kind', 'gear', 'notes'];

  /**
   * The kit as its install file stores it, the stove's fuel by the tent.
   */
  protected const KIT = [
    'title' => 'Weekend kit',
    'kind' => 'tent',
    'weight' => 2300,
    'fuel' => 'gas',
    'poles' => 2,
    'fly' => TRUE,
    'notes' => '',
  ];

  /**
   * The kit as installed, read: the tent's keys under the gear.
   */
  protected const READ = [
    'title' => 'Weekend kit',
    'kind' => 'tent',
    'gear' => ['weight' => 2300, 'poles' => 2, 'fly' => TRUE],
    'notes' => '',
  ];

  /**
   * A stove kit, as it is asked for.
   */
  protected const STOVE_KIT = [
    'title' => 'Stove kit',
    'kind' => 'stove',
    'gear' => ['weight' => 640, 'fuel' => 'spirit'],
    'notes' => '',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Builds the kit in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built data surface.
   */
  protected function kit(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(KitResource::class, $resources->operation(KitResource::class, 'configure'));
  }

  /**
   * Writes values to the kit through the pipeline and its storage.
   *
   * @param array $input
   *   The input.
   *
   * @return array
   *   What the kit's config object holds after, but for `_core`.
   */
  protected function write(array $input): array {
    $kit = $this->kit();
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(KitResource::class, 'configure');
    $result = $this->pipeline()->submit($kit, $input, $resources->storage(KitResource::class, $context, $kit));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    return array_diff_key($this->config('surface_resource_test.kit')->getRawData(), ['_core' => 0]);
  }

  /**
   * Reads the kit through its storage, as every door reads it.
   *
   * @return array
   *   The values, as asked for.
   */
  protected function read(): array {
    $kit = $this->kit();
    $resources = $this->container->get('surface.resources');
    return $resources->storage(KitResource::class, $resources->operation(KitResource::class, 'configure'), $kit)->load($kit);
  }

  /**
   * Emits the kit's schema, decoded.
   *
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function schema(?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL
      ? $emitter->emitFull($this->kit(), 'resource_test.kit', 'configure')
      : $emitter->emit($this->kit(), $values, 'resource_test.kit', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Accepts and validates values, answering the messages by path.
   *
   * @param array $input
   *   The input.
   * @param array $stored
   *   What is stored, as read.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(array $input, array $stored = self::READ): array {
    $kit = $this->kit();
    $accepted = $this->pipeline()->accept($kit, $input, $stored);
    $messages = [];
    foreach ($this->pipeline()->validate($kit, $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Tests the variants sit at the gear, the setting on its map.
   */
  public function testTheVariantsSitAtTheGear(): void {
    $map = $this->kit()->getDefinitions();
    $this->assertSame(self::KEYS, $map->names());
    $variants = $map->entry('gear')->variants;
    $this->assertSame('kind', $variants->by);
    $this->assertSame(['stove', 'tent'], $variants->variantIds(), 'In the order discovery found them.');
    $this->assertSame(Variants::FLAT, $variants->shell->getSetting(Variants::STORED_AS));
    $this->assertTrue($variants->storedFlat());
    $this->assertSame(['weight', 'fuel'], $variants->variant('stove')->child->getDefinitions()->names());
    $this->assertSame(['weight', 'poles', 'fly'], $variants->variant('tent')->child->getDefinitions()->names());
    $this->assertSame(['resource_test.kit.stove', 'resource_test.kit.tent'], array_map(static fn ($variant) => $variant->id, array_values($variants->variants)));
    $this->assertSame(array_fill_keys(self::KEYS, NULL), $this->kit()->getDefaultValues());

    // As properties, #[Setting] beside #[AttachVariants] says the same.
    $resources = $this->container->get('surface.resources');
    $pitch = $resources->build(CampsiteResource::class, $resources->operation(CampsiteResource::class, 'configure'))->getDefinitions()->entry('pitches')->list->child();
    $this->assertSame(['name', 'kind', 'gear'], $pitch->getDefinitions()->names());
    $this->assertTrue($pitch->getDefinitions()->entry('gear')->variants->storedFlat());
  }

  /**
   * Tests the deciding key is offered the variants' values, by their labels.
   */
  public function testTheDecidingKeyOffersTheVariants(): void {
    $kind = $this->kit()->getDefinition('kind');
    $offered = $kind->getConstraints()['LabeledChoice'];
    $this->assertSame(['stove', 'tent'], $offered['choices']);
    $this->assertSame(['stove' => 'Stove', 'tent' => 'Tent'], array_map('strval', $offered['labels']));
    $this->assertSame([['const' => 'stove', 'title' => 'Stove'], ['const' => 'tent', 'title' => 'Tent']], $this->schema()['properties']['kind']['oneOf']);
  }

  /**
   * Tests what each value chooses, and what is refused under it.
   */
  public function testEachValueChoosesWhatApplies(): void {
    $kit = $this->kit();
    $tent = $kit->refine(['kind' => 'tent'])->getDefinition('gear');
    $this->assertInstanceOf(MapDataDefinition::class, $tent, 'Chosen: the tent\'s map.');
    $this->assertSame(['weight', 'poles', 'fly'], array_keys($tent->getPropertyDefinitions()));
    $this->assertTrue($tent->getPropertyDefinition('poles')->isRequired());
    $this->assertFalse($tent->getPropertyDefinition('weight')->isRequired());
    $stove = $kit->refine(['kind' => 'stove'])->getDefinition('gear');
    $this->assertInstanceOf(MapDataDefinition::class, $stove);
    $this->assertSame(['weight', 'fuel'], array_keys($stove->getPropertyDefinitions()));
    $this->assertTrue($stove->getPropertyDefinition('fuel')->isRequired());
    $this->assertSame('any', $kit->refine([])->getDefinition('gear')->getDataType(), 'Nothing chosen: the placeholder.');

    $this->assertSame([], $this->refusals([]));
    try {
      $this->refusals(['gear' => ['fuel' => 'spirit']]);
      $this->fail('A key of the variant not chosen was accepted.');
    }
    catch (VariantMismatchException $e) {
      $this->assertSame('The value at gear is shaped for another variant than "tent", which kind chose: fuel.', $e->getMessage(), 'A key of the variant not chosen, sent, is refused naming the deciding key.');
    }
    $this->assertSame(['gear.poles' => 'Poles is required.'], $this->refusals(['gear' => ['poles' => NULL]]));
    $no_fuel = ['kind' => 'stove', 'gear' => ['fuel' => NULL]];
    $this->assertSame(['gear.fuel' => 'Fuel is required.'], $this->refusals($no_fuel));
    // The weight they both declare is accepted under either.
    $this->assertSame([], $this->refusals(['kind' => 'stove', 'gear' => ['weight' => 900]]));
    $this->assertSame([], $this->refusals(['gear' => ['weight' => 900]]));

    // The chosen variant's defaults are laid in under the gear.
    $accepted = $this->pipeline()->accept($kit, ['title' => 'Stove kit', 'kind' => 'stove'], []);
    $this->assertSame([
      'title' => 'Stove kit',
      'kind' => 'stove',
      'gear' => ['weight' => NULL, 'fuel' => 'gas'],
      'notes' => NULL,
    ], $accepted);
    $this->assertStaticRefinersAgree($kit);
  }

  /**
   * Tests a read gathers the chosen keys under the gear, and no other.
   */
  public function testReadGathersTheChosenKeysUnderTheGear(): void {
    $this->assertSame(self::KIT, array_diff_key($this->config('surface_resource_test.kit')->getRawData(), ['_core' => 0]), 'As installed: flat, the stove\'s fuel beside the tent.');
    $this->assertSame(self::READ, $this->read(), 'The stove\'s fuel was written for a variant not chosen: it is not read.');

    // Read under the gear, the fuel would have the pipeline read the gear
    // as written for another variant, and start the tent from its
    // defaults.
    $variants = $this->kit()->getDefinitions()->entry('gear')->variants;
    $this->assertNull($variants->held('tent', ['fuel' => 'gas'] + self::READ['gear']));
    $this->assertSame(self::READ['gear'], $variants->held('tent', self::READ['gear']));

    // The layout alone: what it reads, and what it writes back.
    $layout = FlatVariantsLayout::forSurface($this->kit());
    $this->assertInstanceOf(FlatVariantsLayout::class, $layout);
    $this->assertSame(self::READ, $layout->fromStorage(self::KIT));
    $stored = $layout->toStorage(self::READ);
    $this->assertSame(['title', 'kind', 'weight', 'poles', 'fly', 'notes'], array_keys($stored), 'The gear\'s keys where it stood, and no gear.');
    $this->assertSame(['fuel' => 'gas'], array_diff_key($layout->keepOnWrite($stored, self::KIT), $stored), 'What the read left out, the write keeps.');
    $this->assertSame(['gear' => StoredSchemaInterface::SPREAD], $layout->storedSchema());
  }

  /**
   * Tests a chosen variant of which nothing is stored reads as an empty map.
   *
   * As variants under a key do: the pipeline writes a chosen variant as
   * its defaults (Variants::defaultsOf()), an empty map for one that
   * declares no keys, and a nested storage reads that map back. Stored
   * flat, an empty map writes no key, so the layout reads the chosen
   * variant as an empty map where the level holds the deciding key and
   * the owner's keys alone (FlatVariantsLayout::gather()). With nothing
   * chosen, nothing is read under the key.
   */
  public function testNothingOfTheChosenVariantReadsAsAnEmptyMap(): void {
    $layout = FlatVariantsLayout::forSurface($this->kit());
    $this->assertInstanceOf(FlatVariantsLayout::class, $layout);
    $stored = ['title' => 'Bare kit', 'kind' => 'tent', 'notes' => ''];
    $read = ['title' => 'Bare kit', 'kind' => 'tent', 'gear' => [], 'notes' => ''];
    $this->assertSame($read, $layout->fromStorage($stored), 'Where the kit declares the gear, after the kind.');
    $this->assertSame($stored, $layout->toStorage($read), 'An empty map writes no key.');
    $no_notes = ['title' => 'Bare kit', 'kind' => 'tent'];
    $this->assertSame($no_notes + ['gear' => []], $layout->fromStorage($no_notes), 'At the end, with no own key after it.');
    $stale = ['title' => 'Bare kit', 'kind' => 'tent', 'stale' => 'x', 'notes' => ''];
    $this->assertSame($read, $layout->fromStorage($stale), 'A key no variant declares is not read.');
    $none = ['title' => 'Bare kit', 'notes' => ''];
    $this->assertSame($none, $layout->fromStorage($none), 'Nothing chosen: no gear.');
    $canoe = ['title' => 'Bare kit', 'kind' => 'canoe', 'notes' => ''];
    $this->assertSame($canoe, $layout->fromStorage($canoe), 'No variant for the value: no gear.');

    // In a collection's item the same.
    $resources = $this->container->get('surface.resources');
    $campsite = FlatVariantsLayout::forSurface($resources->build(CampsiteResource::class, $resources->operation(CampsiteResource::class, 'configure')));
    $this->assertInstanceOf(FlatVariantsLayout::class, $campsite);
    $read = $campsite->fromStorage(['title' => 'Lakeside', 'pitches' => [['name' => 'North', 'kind' => 'cot']]]);
    $this->assertSame([['name' => 'North', 'kind' => 'cot', 'gear' => []]], $read['pitches']);
  }

  /**
   * Tests a leftover is kept, and a switch back writes defaults, not it.
   */
  public function testLeftoverIsKeptAndSwitchBackWritesDefaults(): void {
    // A write with the kind unchanged keeps the stove's fuel.
    $this->assertSame(['title' => 'Kept'] + self::KIT, $this->write(['title' => 'Kept']));
    $this->assertSame(['title' => 'Kept'] + self::READ, $this->read());

    // The tent chosen, its poles are written flat, and read back under the
    // gear.
    $stored = $this->write(['gear' => ['weight' => 2300, 'poles' => 3, 'fly' => TRUE]]);
    $this->assertSame(array_replace(self::KIT, ['title' => 'Kept', 'poles' => 3]), $stored);
    $this->assertArrayNotHasKey('gear', $stored);
    $gear = ['weight' => 2300, 'poles' => 3, 'fly' => TRUE];
    $this->assertSame(array_replace(self::READ, ['title' => 'Kept', 'gear' => $gear]), $this->read());

    // A switch writes the stove flat beside the kind, and keeps the
    // tent's poles and fly, which it does not name.
    $stored = $this->write(['kind' => 'stove', 'gear' => ['weight' => 640, 'fuel' => 'spirit']]);
    $flat = ['title' => 'Kept', 'kind' => 'stove', 'weight' => 640, 'fuel' => 'spirit'];
    $this->assertSame($flat + ['poles' => 3, 'fly' => TRUE, 'notes' => ''], $stored);
    $this->assertArrayNotHasKey('gear', $stored);
    $read = ['title' => 'Kept', 'kind' => 'stove', 'gear' => ['weight' => 640, 'fuel' => 'spirit']];
    $this->assertSame($read + ['notes' => ''], $this->read());

    // Back to the tent, sending its weight alone: the tent is written
    // whole from its defaults beneath what is sent, as variants at a key
    // are once the deciding key moves on, so the poles kept in storage
    // are not read back. The stove's fuel is kept beside it.
    $stored = $this->write(['kind' => 'tent', 'gear' => ['weight' => 2300]]);
    $flat = ['title' => 'Kept', 'kind' => 'tent', 'weight' => 2300, 'fuel' => 'spirit'];
    $this->assertSame($flat + ['poles' => 2, 'fly' => FALSE, 'notes' => ''], $stored, 'The tent\'s defaults: two poles, no rain fly.');
    $gear = ['weight' => 2300, 'poles' => 2, 'fly' => FALSE];
    $this->assertSame(array_replace(self::READ, ['title' => 'Kept', 'gear' => $gear]), $this->read());
  }

  /**
   * Tests a variant's access omits or locks it, the other standing.
   */
  public function testAccessOmitsOrLocksOneVariant(): void {
    $this->setUpCurrentUser([], ['administer site configuration', 'hide stove']);
    $kit = $this->kit();
    $this->assertSame(self::KEYS, $kit->getDefinitions()->names());
    $this->assertSame(['tent'], $kit->getDefinition('kind')->getConstraints()['LabeledChoice']['choices'], 'Its value is not offered.');
    $this->assertSame(['tent'], $kit->getDefinitions()->entry('gear')->variants->variantIds());
    $stored = $this->write(['title' => 'Kept apart']);
    $this->assertSame(['Kept apart', 'gas'], [$stored['title'], $stored['fuel']], 'What the access took away, storage keeps.');
    $full = $this->schema();
    $this->assertArrayNotHasKey('resource_test.kit.stove', $full['$defs'], 'Omitted: no def, no conditional.');
    $this->assertSame(['tent'], array_keys($full['properties']['gear']['x-surface']['variants']));

    $this->setUpCurrentUser([], ['administer site configuration', 'inspect stove']);
    $variants = $this->kit()->getDefinitions()->entry('gear')->variants;
    $this->assertSame(['stove', 'tent'], $variants->variantIds(), 'A locked variant is offered.');
    $this->assertTrue($variants->variant('stove')->child->getDefinitions()->isLocked('fuel'), 'Locked whole.');
    $this->assertFalse($variants->variant('tent')->child->getDefinitions()->isLocked('weight'));
  }

  /**
   * Tests the full document states each variant once, at the gear.
   */
  public function testTheFullDocumentStatesEachVariantOnce(): void {
    $full = $this->schema();
    $this->assertSame(['contract' => 5, 'version' => 1], $full['x-surface']);
    $this->assertSame(self::KEYS, array_keys($full['properties']));
    $this->assertFalse($full['additionalProperties']);
    $this->assertSame(['title', 'kind'], $full['required']);
    $this->assertSame([
      ['$ref' => '#/$defs/resource_test.kit.stove'],
      ['$ref' => '#/$defs/resource_test.kit.tent'],
      ['type' => 'null'],
    ], $full['properties']['gear']['anyOf']);
    $this->assertSame([
      'dependsOn' => ['kind'],
      'by' => 'kind',
      'variants' => [
        'stove' => '#/$defs/resource_test.kit.stove',
        'tent' => '#/$defs/resource_test.kit.tent',
      ],
    ], $full['properties']['gear']['x-surface']);
    $expected = json_decode(<<<'JSON'
      [
        {"if": {"properties": {"kind": {"const": "stove"}}, "required": ["kind"]},
         "then": {"properties": {"gear": {"title": "Gear", "$ref": "#/$defs/resource_test.kit.stove"}}}},
        {"if": {"properties": {"kind": {"const": "tent"}}, "required": ["kind"]},
         "then": {"properties": {"gear": {"title": "Gear", "$ref": "#/$defs/resource_test.kit.tent"}}}}
      ]
      JSON, TRUE);
    $this->assertSame($expected, $full['allOf']);
    $stove = json_decode(<<<'JSON'
      {
        "title": "resource_test.kit.stove",
        "type": "object",
        "properties": {
          "weight": {"title": "Weight", "type": ["integer", "null"], "minimum": 0},
          "fuel": {"title": "Fuel", "type": "string", "oneOf": [{"const": "gas", "title": "Gas"}, {"const": "spirit", "title": "Spirit"}], "default": "gas"}
        },
        "additionalProperties": false,
        "required": ["fuel"]
      }
      JSON, TRUE);
    $this->assertSame($stove, $full['$defs']['resource_test.kit.stove'], 'Closed: the variant is whole in its own frame.');
    $this->assertFalse($full['$defs']['resource_test.kit.tent']['additionalProperties']);
    $this->assertSame(['poles'], $full['$defs']['resource_test.kit.tent']['required']);
    $this->assertSame([0], $full['$defs']['resource_test.kit.tent']['x-surface']['rules'], 'The tent\'s own rule, in its def.');

    // A standard validator holds the document as the pipeline holds values.
    $this->assertTrue($this->validates($full, self::READ));
    $this->assertFalse($this->validates($full, ['gear' => ['fuel' => 'gas'] + self::READ['gear']] + self::READ), 'The stove\'s fuel under the tent.');
    $this->assertFalse($this->validates($full, ['gear' => ['poles' => NULL] + self::READ['gear']] + self::READ), 'The tent\'s poles are required.');
    $this->assertFalse($this->validates($full, ['gear' => ['fly' => TRUE, 'poles' => 1, 'weight' => 2300]] + self::READ), 'The tent\'s rule holds.');
    $this->assertFalse($this->validates($full, self::KIT), 'Flat, as stored, is not what is asked.');
    $this->assertTrue($this->validates($full, self::STOVE_KIT));
    $this->assertFalse($this->validates($full, ['gear' => ['poles' => 2] + self::STOVE_KIT['gear']] + self::STOVE_KIT), 'A tent\'s key under the stove.');
    $this->assertFalse($this->validates($full, ['gear' => ['fuel' => NULL] + self::STOVE_KIT['gear']] + self::STOVE_KIT), 'The stove\'s fuel is required.');
    $this->assertFalse($this->validates($full, ['fax' => 1] + self::STOVE_KIT), 'The document is closed.');

    // The schema for these answers states the chosen variant at the gear.
    $these = $this->schema(self::STOVE_KIT);
    $this->assertSame(self::KEYS, array_keys($these['properties']));
    $this->assertTrue($this->validates($these, self::STOVE_KIT));
  }

  /**
   * Tests the inline reading states each variant under the gear.
   */
  public function testInlineReadsEachVariantUnderTheGear(): void {
    $inline = Schemas::decoded(SchemaResolver::inline(Schema::from($this->schema())));
    $this->assertSame(self::KEYS, array_keys($inline['properties']), 'The gear is one key.');
    $this->assertFalse($inline['additionalProperties']);
    $this->assertSame(['dependsOn', 'by', 'variants'], array_keys($inline['properties']['gear']['x-surface']));
    $this->assertSame(['weight', 'fuel'], array_keys($inline['allOf'][0]['then']['properties']['gear']['properties']), 'The stove, in place of its def.');
    $this->assertSame(['weight', 'poles', 'fly'], array_keys($inline['allOf'][1]['then']['properties']['gear']['properties']), 'The tent, in place of its def.');
    $this->assertSame(Schemas::decoded(SchemaResolver::inline(Schema::from($inline))), $inline, 'Read inline twice, the same.');
    $this->assertTrue($this->validates($inline, self::READ));
    $this->assertFalse($this->validates($inline, self::KIT));
    $this->assertFalse($this->validates($inline, ['kind' => 'stove'] + self::READ));
    $this->assertTrue($this->validates($inline, self::STOVE_KIT));
  }

  /**
   * Tests the full schema reads back to itself through the schema reader.
   */
  public function testTheDocumentReadsBack(): void {
    $full = $this->schema();
    $read = $this->container->get('surface.schema_reader')->structure($full, 'resource_test.kit', 'surface_resource_test');
    $built = $this->container->get('surface.resources')->buildStatic($read, new OperationContext('configure'));
    $this->assertSame(self::KEYS, $built->getDefinitions()->names());
    $this->assertSame(['stove', 'tent'], $built->getDefinitions()->entry('gear')->variants->variantIds());
    $again = $this->container->get('surface.contract_emitter')->emitFull($built, 'resource_test.kit', 'configure')->document['schema'];
    $this->assertSame(json_encode($full), json_encode($again));
  }

  /**
   * Tests the form draws the chosen variant under the gear, redrawn by kind.
   */
  public function testTheFormDrawsTheChosenVariant(): void {
    $route = new Route('/kit', [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => 'resource_test.kit',
      OperationRoute::OPERATION => 'configure',
    ]);
    $stack = $this->container->get('request_stack');
    $request = Request::create('/kit', 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.kit');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $state = new FormState();
    $container = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame(self::KEYS, array_values(array_filter(self::KEYS, static fn (string $key): bool => isset($container[$key]))));
    $gear = $container['gear'];
    $this->assertSame(['weight', 'poles', 'fly'], array_values(array_filter(['weight', 'fuel', 'poles', 'fly'], static fn (string $key): bool => isset($gear[$key]))), 'The tent is chosen: its keys under the gear, the stove\'s fuel not drawn.');
    $this->assertSame(2300, $gear['weight']['#default_value'], 'What storage holds, read under the gear.');
    $this->assertSame(2, $gear['poles']['#default_value']);
    $this->assertContains('gear', $container['kind'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another kind redraws the gear.');
  }

  /**
   * Tests the tool lists the gear's members, each variant's, by the kind.
   */
  public function testTheToolListsTheGearsMembers(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.kit:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::KEYS, array_keys($properties));
    $this->assertSame(['weight', 'fuel', 'poles', 'fly'], array_keys($properties['gear']->getPropertyDefinitions()));
    $this->assertStringStartsWith('Its keys depend on kind. stove: weight, fuel; tent: weight, poles, fly.', (string) $properties['gear']->getDescription());
    $tool = $manager->createInstance('surface:resource_test.kit:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['gear' => ['fuel' => 'spirit'] + self::READ['gear']] + self::READ);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/gear/fuel: gear.fuel belongs to the stove variant, but kind chose tent.", strip_tags((string) $tool->getResult()->getMessage()), 'The tool names the key and the variant it belongs to.');
  }

  /**
   * Tests the build is kept, and read back equal from the build cache.
   */
  public function testTheBuildReadsBackFromTheCache(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $emitted = static fn (SurfaceInterface $built): string => (string) json_encode([
      $emitter->emitFull($built, 'resource_test.kit', 'configure')->document,
      $emitter->emit($built, $built->getDefaultValues(), 'resource_test.kit', 'configure')->document,
    ]);
    $context = $resources->operation(KitResource::class, 'configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared(KitResource::class, $context);
    $document = $emitted($fresh);
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared(KitResource::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);
    $this->assertNotSame($fresh, $woken);
    $this->assertTrue($woken->getDefinitions()->entry('gear')->variants->storedFlat(), 'The setting survives the cache.');
    $this->assertSame($fresh->getDefinitions()->entry('gear')->variants->variantIds(), $woken->getDefinitions()->entry('gear')->variants->variantIds());
    $this->assertSame($document, $emitted($woken));
  }

  /**
   * Tests the config schema written from the kit lists every variant's keys.
   *
   * The block is byte for byte what the generator wrote for the kit when
   * it mixed its variants in: core types a config object's root by its
   * name alone, so the root lists every variant's keys flat, each
   * nullable. ConfigSchemaDriftTest holds the file to what the generator
   * writes; this reads what it says.
   */
  public function testTheConfigSchemaListsEveryMember(): void {
    $schema = $this->container->get('config.typed')->getDefinition('surface_resource_test.kit');
    $mapping = array_diff_key($schema['mapping'], array_flip(['_core', 'langcode']));
    $this->assertSame(['title', 'kind', 'weight', 'fuel', 'poles', 'fly', 'notes'], array_keys($mapping), 'Flat, the stove\'s keys then the tent\'s, the weight once, where the gear stands.');
    foreach (['weight', 'fuel', 'poles', 'fly'] as $member) {
      $this->assertTrue($mapping[$member]['nullable'] ?? FALSE, $member . ' is optional: it may be another variant\'s.');
      $this->assertArrayNotHasKey('constraints', $mapping[$member]);
    }
    $this->assertSame(['gear' => StoredSchemaInterface::SPREAD], $this->container->get('surface.resources')->storageInstance(KitResource::class)->translatedKeys($this->kit()));
  }

  /**
   * Tests a pitch is typed by its own kind, each gear extending the pitch.
   */
  public function testPitchIsTypedByItsOwnKind(): void {
    $typed = $this->container->get('config.typed');
    $root = $typed->getDefinition('surface_resource_test.campsite');
    $this->assertSame('surface_resource_test.campsite.pitches.[kind]', $root['mapping']['pitches']['sequence']['type']);
    $pitch = $typed->getDefinition('surface_resource_test.campsite.pitches');
    $this->assertSame(['name', 'kind'], array_keys($pitch['mapping']), 'The pitch\'s own keys.');
    foreach (['hammock' => 'straps', 'cot' => 'length'] as $kind => $key) {
      $this->assertSame(['name', 'kind', $key], array_keys($typed->getDefinition('surface_resource_test.campsite.pitches.' . $kind)['mapping']), $kind . ' extends the pitch with its own key.');
    }
    $data = $this->config('surface_resource_test.campsite')->getRawData();
    $object = $typed->createFromNameAndData('surface_resource_test.campsite', $data);
    $this->assertSame(['name', 'kind', 'straps'], array_keys($object->get('pitches.0')->getDataDefinition()->toArray()['mapping']), 'The hammock, read through its own kind.');
    $this->assertSame(['name', 'kind', 'length'], array_keys($object->get('pitches.1')->getDataDefinition()->toArray()['mapping']), 'The cot, the same way.');
    $this->assertCount(0, $object->validate());

    // Read, each pitch's gear is under its key; written, beside its kind.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(CampsiteResource::class, 'configure');
    $built = $resources->build(CampsiteResource::class, $context);
    $read = $resources->storage(CampsiteResource::class, $context, $built)->load($built);
    $this->assertSame([
      ['name' => 'Birch', 'kind' => 'hammock', 'gear' => ['straps' => 2]],
      ['name' => 'Shore', 'kind' => 'cot', 'gear' => ['length' => 210]],
    ], $read['pitches']);
    $pitches = [['name' => 'Birch', 'kind' => 'cot', 'gear' => ['length' => 190]]];
    $result = $this->pipeline()->submit($built, ['pitches' => $pitches], $resources->storage(CampsiteResource::class, $context, $built));
    $this->assertTrue($result->isValid());
    $this->assertSame([['name' => 'Birch', 'kind' => 'cot', 'length' => 190]], $this->config('surface_resource_test.campsite')->get('pitches'), 'Nothing kept of the hammock: an item\'s position may hold another item.');
    $this->assertSame(['pitches.gear' => StoredSchemaInterface::SPREAD], FlatVariantsLayout::storedSchemaOf($built));
  }

  /**
   * Tests a path asked for is given where it is stored, each flat key gone.
   *
   * What the storage restores of a member access omitted is found by that
   * path, below the top level as at it.
   */
  public function testStoredPathDropsEachFlatKey(): void {
    $resources = $this->container->get('surface.resources');
    $built = $resources->build(CampsiteResource::class, $resources->operation(CampsiteResource::class, 'configure'));
    $layout = FlatVariantsLayout::forSurface($built);
    $this->assertInstanceOf(FlatVariantsLayout::class, $layout);
    $this->assertSame('pitches.0.straps', $layout->storedPath('pitches.0.gear.straps'), 'The gear\'s keys are stored in the item, beside its kind.');
    $this->assertSame('pitches.1.length', $layout->storedPath('pitches.1.gear.length'));
    $this->assertSame('pitches.0.name', $layout->storedPath('pitches.0.name'), 'A path through no flat key is its own.');
    $this->assertSame('title', $layout->storedPath('title'));
    $this->assertSame('gear.straps', $layout->storedPath('gear.straps'), 'No gear stored flat at the top.');
    $this->assertSame('pitches.0.gear', $layout->storedPath('pitches.0.gear'), 'The flat key itself.');
    $this->assertSame('poles', FlatVariantsLayout::forSurface($this->kit())->storedPath('gear.poles'), 'At the top level the same.');
  }

  /**
   * Tests what cannot be stored flat is refused at the build.
   */
  public function testWhatCannotBeStoredFlatIsRefused(): void {
    $resources = $this->container->get('surface.resources');
    $refusals = [
      FlatOwnerClashStructure::class => 'resource_test.broken.flat_owner_clash stores the variants at "gear" flat beside its own keys (Variants::STORED_AS), and the variant for "a", resource_test.broken.flat.weight, declares "weight", which resource_test.broken.flat_owner_clash declares too. Stored flat, the two would be one key: rename one, or store the variants under "gear".',
      FlatMixinClashStructure::class => 'resource_test.broken.flat_mixin_clash stores the variants at "gear" flat beside its own keys (Variants::STORED_AS), and the variant for "a", resource_test.broken.flat.mixed_weight, declares "weight", which resource_test.broken.flat_mixin_clash declares too. Stored flat, the two would be one key: rename one, or store the variants under "gear".',
      FlatTwoKeysStructure::class => 'resource_test.broken.flat_two_keys stores the variants at "gear" and at "spare" flat at one level (Variants::STORED_AS). Read back, every key that is not its own is gathered under the one flat key, so nothing could tell which keys each left behind: store one of them under its key.',
      FlatNestedStructure::class => 'resource_test.broken.flat_nested stores the variants at "gear" flat (Variants::STORED_AS), and the variant for "a", resource_test.broken.flat.inner, stores the variants at "inner" flat too, at the same level. Read back, every key that is not the owner\'s is gathered under the one flat key, so nothing could tell which keys each left behind: store one of them under its key.',
      FlatStateResource::class => 'resource_test.broken.flat_state stores the variants at "gear" flat (Variants::STORED_AS), and Drupal\surface_resource_test\Storage\PinnedNoteStorage, which stores it, is no config storage: the layout that stores them beside their deciding key is applied to ConfigObjectStorage and ConfigEntityStorage alone. Store them under the key, or lay them out in the storage itself.',
    ];
    $refused = [];
    foreach (array_keys($refusals) as $class) {
      try {
        $resources->build($class, new OperationContext('configure'));
        $refused[$class] = 'built';
      }
      catch (\LogicException $e) {
        $refused[$class] = $e->getMessage();
      }
    }
    $this->assertSame($refusals, $refused);
  }

  /**
   * Tests a variant the site lists is refused stored flat when a door reads it.
   *
   * It is built when read, not with its owner, so the owner builds; the
   * key it shares with the owner is refused when it is settled.
   */
  public function testListedVariantIsRefusedWhenSettled(): void {
    $built = $this->container->get('surface.resources')->build(FlatListedClashStructure::class, new OperationContext('configure'));
    $variant = $built->getDefinitions()->entry('gear')->variants->variant('a');
    $this->assertFalse($variant->isSettled(), 'Held unsettled until a door reads it.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.broken.flat_listed_clash stores the variants at "gear" flat beside its own keys (Variants::STORED_AS), and the variant for "a", resource_test.broken.kit.weight_only, declares "weight", which resource_test.broken.flat_listed_clash declares too. Stored flat, the two would be one key: rename one, or store the variants under "gear".');
    $variant->child->getDefinitions();
  }

  /**
   * Tests a listed variant storing variants flat itself is refused when read.
   *
   * Its variants would land at its owner's level, beside the owner's flat
   * key, which the owner's build cannot see: the variant is built when a
   * door reads it.
   */
  public function testListedVariantStoringFlatIsRefusedWhenSettled(): void {
    $built = $this->container->get('surface.resources')->build(FlatListedNestedStructure::class, new OperationContext('configure'));
    $variant = $built->getDefinitions()->entry('gear')->variants->variant('a');
    $this->assertFalse($variant->isSettled(), 'Held unsettled until a door reads it.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.broken.flat_listed_nested stores the variants at "gear" flat (Variants::STORED_AS), and the variant for "a", resource_test.broken.flat.listed_inner, stores the variants at "inner" flat too, at the same level. Read back, every key that is not the owner\'s is gathered under the one flat key, so nothing could tell which keys each left behind: store one of them under its key.');
    $variant->child->getDefinitions();
  }

  /**
   * Tests a listed variant is refused when settled from a build in the cache.
   *
   * The owner's keys at the level are stored with the build
   * (VariantsToSettle), and the variant is stored as what builds it, so the
   * request that reads the build back settles it. The refusal fires only
   * when those keys came back with it: the variant, a weight alone,
   * builds on its own.
   */
  public function testListedVariantIsRefusedWhenSettledFromTheCache(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $context = new OperationContext('configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared(FlatListedClashStructure::class, $context);
    $this->assertFalse($fresh->getDefinitions()->entry('gear')->variants->variant('a')->isSettled());
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared(FlatListedClashStructure::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached'], 'Read back from the build cache.');
    $this->assertNotSame($fresh, $woken);
    $variant = $woken->getDefinitions()->entry('gear')->variants->variant('a');
    $this->assertFalse($variant->isSettled(), 'Stored as what builds it.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.broken.flat_listed_clash stores the variants at "gear" flat beside its own keys (Variants::STORED_AS), and the variant for "a", resource_test.broken.kit.weight_only, declares "weight", which resource_test.broken.flat_listed_clash declares too.');
    $variant->child->getDefinitions();
  }

}
