<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Contract\ContractChange;
use Drupal\surface\Contract\ContractDiff;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_resource_test\Resource\Pack\PackResource;
use Drupal\surface_resource_test\Resource\Shelter\ShelterResource;
use Drupal\surface_resource_test\Structure\Shelter\CampStructure;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Variants that hold children, and variants that hold variants.
 *
 * The pack attaches the variant its carry chooses at its load: the frame
 * pack holds its hip belt (a child at a fixed key) and its pockets (a
 * collection), the daypack its rain cover (a map holding a pouch, a
 * structure with no id). Each child is the variant's own, in the
 * variant's frame under the load.
 *
 * The shelter attaches the variant its kind chooses at its shelter, the
 * hut holding variants of its own: its roof chooses the covering, thatch
 * or slate, inside the hut's frame. The camp lists the same hut from a
 * provider and holds the same covering.
 *
 * @see docs/resources.md#variants
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class VariantsHoldTest extends SurfaceKernelTestBase {

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
   * The pack's keys: its own, and the load.
   */
  protected const PACK_KEYS = ['title', 'carry', 'load', 'notes'];

  /**
   * The pack as installed.
   */
  protected const PACK = [
    'title' => 'Day hike',
    'carry' => 'frame',
    'load' => [
      'capacity' => 40,
      'belt' => ['padded' => TRUE, 'width' => 8],
      'pockets' => [['name' => 'Lid']],
    ],
    'notes' => '',
  ];

  /**
   * The shelter's keys: its own, and the shelter the kind chooses.
   */
  protected const SHELTER_KEYS = ['title', 'kind', 'shelter', 'notes'];

  /**
   * The shelter as installed.
   */
  protected const SHELTER = [
    'title' => 'Ridge shelter',
    'kind' => 'hut',
    'shelter' => [
      'roof' => 'thatch',
      'covering' => ['depth' => 30],
    ],
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
   * Accepts and validates values, answering the messages by path.
   *
   * @param array $input
   *   The input.
   * @param array $stored
   *   What is stored.
   * @param \Drupal\surface\SurfaceInterface $built
   *   The data surface.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(array $input, array $stored, SurfaceInterface $built): array {
    $accepted = $this->pipeline()->accept($built, $input, $stored);
    $messages = [];
    foreach ($this->pipeline()->validate($built, $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Builds the pack in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built data surface.
   */
  protected function pack(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(PackResource::class, $resources->operation(PackResource::class, 'configure'));
  }

  /**
   * Emits the pack's full schema, decoded.
   *
   * @return array
   *   The schema.
   */
  protected function packSchema(): array {
    $served = $this->container->get('surface.contract_emitter')->emitFull($this->pack(), 'resource_test.pack', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Tests each child a variant holds is the variant's, under the load.
   */
  public function testVariantHoldsChildren(): void {
    $pack = $this->pack();
    $map = $pack->getDefinitions();
    $this->assertSame(self::PACK_KEYS, $map->names());
    $load = $map->entry('load')->variants;
    $this->assertSame('carry', $load->by);
    $this->assertSame(['day', 'frame'], $load->variantIds(), 'In the order discovery found them.');
    $frame = $load->variant('frame')->child->getDefinitions();
    $this->assertSame(['capacity', 'belt', 'pockets'], $frame->names());
    $this->assertSame('resource_test.pack.belt', $frame->entry('belt')->attachment?->id, 'The hip belt, a child at a fixed key.');
    $this->assertSame('resource_test.pack.pocket', $frame->entry('pockets')->list?->item->id, 'The pockets, a collection.');
    $day = $load->variant('day')->child->getDefinitions();
    $this->assertSame(['capacity', 'cover'], $day->names());
    $this->assertSame('resource_test.pack.day.cover', $day->entry('cover')->attachment?->id, 'The rain cover, a structure with no id.');

    // Each accepted under its value, in the variant's frame.
    $frame_pack = [
      'load' => [
        'capacity' => 50,
        'belt' => ['padded' => FALSE, 'width' => 6],
        'pockets' => [['name' => 'Lid'], ['name' => 'Hip']],
      ],
    ];
    $this->assertSame([], $this->refusals($frame_pack, self::PACK, $pack));
    $cover = ['color' => 'red', 'pouch' => ['name' => 'Fold']];
    $day_pack = ['carry' => 'day', 'load' => ['capacity' => 20, 'cover' => $cover]];
    $this->assertSame([], $this->refusals($day_pack, self::PACK, $pack));
    // Each child holds its own keys, and the list its count.
    $this->assertSame(['load.belt.width' => 'This value should be 1 or more.'], $this->refusals(['load' => ['belt' => ['width' => 0]] + self::PACK['load']], self::PACK, $pack));
    $four = array_fill(0, 4, ['name' => 'Side']);
    $this->assertSame(['load.pockets' => 'This collection should contain 3 elements or less.'], $this->refusals(['load' => ['pockets' => $four] + self::PACK['load']], self::PACK, $pack));
    $unnamed = ['pouch' => ['name' => NULL]];
    $this->assertSame(['load.cover.pouch.name' => 'Name is required.'], $this->refusals(['load' => ['cover' => $unnamed] + $day_pack['load']] + $day_pack, self::PACK, $pack), 'The cover\'s pouch, in its own frame.');
    $this->assertSame(['load.capacity' => 'This value should be 10 or more.'], $this->refusals(['load' => ['capacity' => 5] + $day_pack['load']] + $day_pack, self::PACK, $pack));

    // A write stores the chosen variant's keys under the load.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(PackResource::class, 'configure');
    $result = $this->pipeline()->submit($pack, $day_pack, $resources->storage(PackResource::class, $context, $pack));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.pack')->getRawData();
    $this->assertSame(['day', ['capacity' => 20, 'cover' => $cover]], [$stored['carry'], $stored['load']]);
    $this->assertStaticRefinersAgree($pack);
  }

  /**
   * Tests a value shaped for the other variant is refused under the carry.
   */
  public function testTheChildOfAnotherVariantIsRefused(): void {
    $this->expectException(VariantMismatchException::class);
    $this->expectExceptionMessage('The value at load is shaped for another variant than "day", which carry chose: belt.');
    $this->refusals(['carry' => 'day', 'load' => ['belt' => ['width' => 6]]], self::PACK, $this->pack());
  }

  /**
   * Tests the full document states each variant's children in its def.
   */
  public function testTheFullDocumentNamesEachChildInItsVariant(): void {
    $full = $this->packSchema();
    $this->assertSame(self::PACK_KEYS, array_keys($full['properties']));
    $this->assertSame([
      'day' => '#/$defs/resource_test.pack.day',
      'frame' => '#/$defs/resource_test.pack.frame',
    ], $full['properties']['load']['x-surface']['variants']);
    $frame = $full['$defs']['resource_test.pack.frame'];
    $this->assertSame(['capacity', 'belt', 'pockets'], array_keys($frame['properties']));
    $this->assertSame(['title' => 'Hip belt', '$ref' => '#/$defs/resource_test.pack.belt'], $frame['properties']['belt'], 'The child\'s def, by reference, inside the variant\'s.');
    $this->assertSame(['$ref' => '#/$defs/resource_test.pack.pocket'], $frame['properties']['pockets']['items']);
    $this->assertSame(3, $frame['properties']['pockets']['maxItems']);
    $this->assertSame('Capacity', $frame['properties']['capacity']['title'], 'Each def its own.');
    $day = $full['$defs']['resource_test.pack.day'];
    $volume = $day['properties']['capacity'];
    $this->assertSame(['Volume', 'In liters.'], [$volume['title'], $volume['description']]);
    $this->assertSame(['color', 'pouch'], array_keys($day['properties']['cover']['properties']), 'The cover, inline in the daypack\'s def.');
    $this->assertSame(['padded', 'width'], array_keys($full['$defs']['resource_test.pack.belt']['properties']));

    $this->assertTrue($this->validates($full, self::PACK));
    $this->assertFalse($this->validates($full, ['load' => ['cover' => ['color' => 'red']] + self::PACK['load']] + self::PACK), 'The daypack\'s cover under the frame pack.');
    $this->assertFalse($this->validates($full, ['load' => ['belt' => ['width' => 0]] + self::PACK['load']] + self::PACK), 'The belt\'s own def holds.');
    $this->assertFalse($this->validates($full, ['load' => ['pockets' => array_fill(0, 4, ['name' => 'Side'])] + self::PACK['load']] + self::PACK));
    $day_pack = [
      'title' => 'Day hike',
      'carry' => 'day',
      'load' => ['capacity' => 20, 'cover' => ['color' => 'red', 'pouch' => ['name' => 'Fold']]],
      'notes' => '',
    ];
    $this->assertTrue($this->validates($full, $day_pack));
    $this->assertFalse($this->validates($full, ['load' => ['belt' => ['width' => 6]] + $day_pack['load']] + $day_pack), 'The frame pack\'s belt under the daypack.');
    $this->assertFalse($this->validates($full, ['load' => ['capacity' => 20, 'cover' => ['pouch' => ['name' => NULL]]]] + $day_pack), 'The pouch\'s def holds.');
    $inline = SchemaResolver::inline($full);
    $this->assertSame(self::PACK_KEYS, array_keys($inline['properties']));
    $this->assertTrue($this->validates($inline, $day_pack));
    $this->assertFalse($this->validates($inline, ['load' => ['belt' => ['width' => 6]] + $day_pack['load']] + $day_pack));
  }

  /**
   * Tests the form draws the chosen variant's children under the load.
   */
  public function testTheFormDrawsTheChosenVariantsChildren(): void {
    $container = $this->form('pack');
    $this->assertSame(self::PACK_KEYS, array_values(array_filter(self::PACK_KEYS, static fn (string $key): bool => isset($container[$key]))));
    $load = $container['load'];
    $drawn = array_values(array_filter(['capacity', 'cover', 'belt', 'pockets'], static fn (string $key): bool => isset($load[$key])));
    $this->assertSame(['capacity', 'belt', 'pockets'], $drawn, 'The frame pack is chosen: the daypack\'s cover is not drawn.');
    $this->assertSame(8, $load['belt']['width']['#default_value'], 'The belt\'s own keys, under the frame pack.');
    $this->assertContains('load', $container['carry'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another carry redraws the load.');
  }

  /**
   * Tests the tool lists every variant's keys at the load.
   */
  public function testTheToolListsEveryChild(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.pack:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::PACK_KEYS, array_keys($properties));
    $this->assertSame(['capacity', 'cover', 'belt', 'pockets'], array_keys($properties['load']->getPropertyDefinitions()));
    $this->assertStringStartsWith('Its keys depend on carry. day: capacity, cover; frame: capacity, belt, pockets.', (string) $properties['load']->getDescription());
    $tool = $manager->createInstance('surface:resource_test.pack:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['load' => ['cover' => ['color' => 'red']] + self::PACK['load']] + self::PACK);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/load/cover: load.cover belongs to the day variant, but carry chose frame.", strip_tags((string) $tool->getResult()->getMessage()));
  }

  /**
   * Tests a variant's access omits it, its children with it.
   */
  public function testAnOmittedVariantTakesItsChildren(): void {
    $this->setUpCurrentUser([], ['administer site configuration', 'hide frame pack']);
    $pack = $this->pack();
    $this->assertSame(self::PACK_KEYS, $pack->getDefinitions()->names());
    $this->assertSame(['day'], $pack->getDefinitions()->entry('load')->variants->variantIds(), 'The frame pack is not offered.');
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(PackResource::class, 'configure');
    $load = ['capacity' => 20, 'cover' => ['pouch' => ['name' => 'Fold']]];
    $kept = ['title' => 'Kept apart', 'carry' => 'day', 'load' => $load];
    $result = $this->pipeline()->submit($pack, $kept, $resources->storage(PackResource::class, $context, $pack));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $this->assertSame('day', $this->config('surface_resource_test.pack')->get('carry'));
  }

  /**
   * Tests the pack's build is kept, and read back equal from the build cache.
   */
  public function testThePackReadsBackFromTheCache(): void {
    $this->assertReadsBackFromTheCache(PackResource::class, 'resource_test.pack');
  }

  /**
   * Builds the shelter in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built data surface.
   */
  protected function shelter(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(ShelterResource::class, $resources->operation(ShelterResource::class, 'configure'));
  }

  /**
   * Emits the shelter's full schema, decoded.
   *
   * @return array
   *   The schema.
   */
  protected function shelterSchema(): array {
    $served = $this->container->get('surface.contract_emitter')->emitFull($this->shelter(), 'resource_test.shelter', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Tests a variant holding variants: the hut's covering, chosen by its roof.
   */
  public function testVariantHoldsVariants(): void {
    $shelter = $this->shelter();
    $map = $shelter->getDefinitions();
    $this->assertSame(self::SHELTER_KEYS, $map->names());
    $kinds = $map->entry('shelter')->variants;
    $this->assertSame('kind', $kinds->by);
    $this->assertSame(['hut', 'tarp'], $kinds->variantIds());
    $hut = $kinds->variant('hut')->child->getDefinitions();
    $this->assertSame(['roof', 'covering'], $hut->names());
    $covering = $hut->entry('covering')->variants;
    $this->assertNotNull($covering, 'The hut\'s variants at the covering.');
    $this->assertSame('roof', $covering->by, 'Chosen by the hut\'s roof.');
    $this->assertSame(['resource_test.shelter.slate', 'resource_test.shelter.thatch'], array_values(array_map(static fn ($variant): ?string => $variant->id, $covering->variants)));

    // Each nested value accepted under the hut.
    $this->assertSame([], $this->refusals(['shelter' => ['roof' => 'thatch', 'covering' => ['depth' => 12]]], self::SHELTER, $shelter));
    $this->assertSame([], $this->refusals(['shelter' => ['roof' => 'slate', 'covering' => ['tiles' => 400]]], self::SHELTER, $shelter));
    // The covering's own frame holds, by the roof the hut holds.
    $no_tiles = ['roof' => 'slate', 'covering' => ['tiles' => NULL]];
    $this->assertSame(['shelter.covering.tiles' => 'Tiles is required.'], $this->refusals(['shelter' => $no_tiles], self::SHELTER, $shelter));
    $shallow = ['roof' => 'thatch', 'covering' => ['depth' => 0]];
    $this->assertSame(['shelter.covering.depth' => 'This value should be 1 or more.'], $this->refusals(['shelter' => $shallow], self::SHELTER, $shelter));
    // Under the tarp, the tarp's keys.
    $this->assertSame([], $this->refusals(['kind' => 'tarp', 'shelter' => ['size' => 4]], self::SHELTER, $shelter));
    $this->assertSame([
      'shelter.roof' => 'Roof is required.',
      'shelter.covering' => 'Covering depends on Roof, which holds no value.',
    ], $this->refusals(['shelter' => ['roof' => NULL]], self::SHELTER, $shelter), 'Required in the hut\'s frame, and the covering waits for it.');

    // A write stores the hut's keys under the shelter, its covering inside.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(ShelterResource::class, 'configure');
    $result = $this->pipeline()->submit($shelter, ['shelter' => ['roof' => 'slate', 'covering' => ['tiles' => 400]]], $resources->storage(ShelterResource::class, $context, $shelter));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.shelter')->getRawData();
    $slate = ['roof' => 'slate', 'covering' => ['tiles' => 400]];
    $this->assertSame(['hut', $slate], [$stored['kind'], $stored['shelter']]);
    $this->assertStaticRefinersAgree($shelter);
  }

  /**
   * Tests a value of another variant's covering is refused under the roof.
   */
  public function testTheCoveringOfAnotherRoofIsRefused(): void {
    $this->expectException(VariantMismatchException::class);
    $this->expectExceptionMessage('The value at shelter.covering is shaped for another variant than "slate", which roof chose: depth.');
    $this->refusals(['shelter' => ['roof' => 'slate', 'covering' => ['depth' => 12, 'tiles' => 400]]], self::SHELTER, $this->shelter());
  }

  /**
   * Tests the full document: the covering's variants inside the hut's def.
   */
  public function testTheFullDocumentNamesTheNestedVariantsInTheirVariant(): void {
    $full = $this->shelterSchema();
    $this->assertSame(self::SHELTER_KEYS, array_keys($full['properties']));
    $this->assertSame([
      'hut' => '#/$defs/resource_test.shelter.hut',
      'tarp' => '#/$defs/resource_test.shelter.tarp',
    ], $full['properties']['shelter']['x-surface']['variants']);
    $hut = $full['$defs']['resource_test.shelter.hut'];
    $this->assertSame(['roof', 'covering'], array_keys($hut['properties']));
    $this->assertSame('roof', $hut['properties']['covering']['x-surface']['by']);
    $this->assertSame([
      'slate' => '#/$defs/resource_test.shelter.slate',
      'thatch' => '#/$defs/resource_test.shelter.thatch',
    ], $hut['properties']['covering']['x-surface']['variants']);
    $nested = array_values(array_filter($hut['allOf'] ?? [], static fn (array $branch): bool => isset($branch['if']['properties']['roof'])));
    $this->assertCount(2, $nested, 'One conditional per covering, on the roof, inside the hut\'s def.');

    $this->assertTrue($this->validates($full, self::SHELTER));
    $this->assertTrue($this->validates($full, ['shelter' => ['roof' => 'slate', 'covering' => ['tiles' => 400]]] + self::SHELTER));
    $this->assertFalse($this->validates($full, ['shelter' => ['roof' => 'slate', 'covering' => ['depth' => 3]]] + self::SHELTER), 'The slate\'s def holds the covering.');
    $this->assertFalse($this->validates($full, ['shelter' => ['roof' => 'thatch', 'covering' => ['depth' => 0]]] + self::SHELTER), 'The thatch\'s def holds.');
    $tarp = ['title' => 'Fly', 'kind' => 'tarp', 'shelter' => ['size' => 4], 'notes' => ''];
    $this->assertTrue($this->validates($full, $tarp));
    $this->assertFalse($this->validates($full, ['shelter' => ['size' => 4, 'covering' => ['depth' => 3]]] + $tarp), 'The hut\'s covering under the tarp.');
    $inline = SchemaResolver::inline($full);
    $this->assertSame(self::SHELTER_KEYS, array_keys($inline['properties']));
    $this->assertTrue($this->validates($inline, self::SHELTER));
    $this->assertFalse($this->validates($inline, ['shelter' => ['size' => 4, 'covering' => ['depth' => 3]]] + $tarp));

    // Read back to itself.
    $read = $this->container->get('surface.schema_reader')->structure($full, 'resource_test.shelter', 'surface_resource_test');
    $built = $this->container->get('surface.resources')->buildStatic($read, new OperationContext('configure'));
    $again = $this->container->get('surface.contract_emitter')->emitFull($built, 'resource_test.shelter', 'configure')->document['schema'];
    $this->assertSame(json_encode($full), json_encode($again));
  }

  /**
   * Tests the contract diff judges a covering added or removed as nested.
   *
   * The hut's def holds the covering's variants as any def holds variants
   * at a key, so a covering removed is the roof's choice removed, and one
   * added a choice added, whatever owner holds the hut.
   */
  public function testTheDiffJudgesTheNestedVariants(): void {
    $baseline = ['schema' => $this->shelterSchema()];
    $removed = $baseline;
    $hut = &$removed['schema']['$defs']['resource_test.shelter.hut'];
    unset($hut['properties']['covering']['x-surface']['variants']['slate'], $removed['schema']['$defs']['resource_test.shelter.slate']);
    $hut['properties']['covering']['anyOf'] = array_values(array_filter($hut['properties']['covering']['anyOf'], static fn (array $one): bool => ($one['$ref'] ?? '') !== '#/$defs/resource_test.shelter.slate'));
    $hut['allOf'] = array_values(array_filter($hut['allOf'], static fn (array $branch): bool => ($branch['if']['properties']['roof']['const'] ?? NULL) !== 'slate'));
    $hut['properties']['roof']['oneOf'] = array_values(array_filter($hut['properties']['roof']['oneOf'], static fn (array $choice): bool => $choice['const'] !== 'slate'));
    unset($hut);
    $judged = static fn (array $from, array $to): array => array_map(static fn (ContractChange $change): string => $change->status . ' ' . $change->change, (new ContractDiff())->compare($from, $to));
    // As for variants at any key: the choice the roof loses breaks, what
    // states it only notes.
    $this->assertSame([
      'compatible def no longer used',
      'breaking choice "slate" removed',
      'compatible the union of the variants changed',
      'compatible x-surface variants changed',
      'compatible rule removed',
    ], $judged($baseline, $removed));
    $this->assertSame([
      'compatible choice "slate" added',
      'compatible the union of the variants changed',
      'compatible x-surface variants changed',
      'compatible rule added for a value the baseline did not allow',
      'compatible def added',
    ], $judged($removed, $baseline));
  }

  /**
   * Tests the form draws the covering under the hut, redrawn by the roof.
   */
  public function testTheFormDrawsTheNestedChoice(): void {
    $container = $this->form('shelter');
    $this->assertSame(self::SHELTER_KEYS, array_values(array_filter(self::SHELTER_KEYS, static fn (string $key): bool => isset($container[$key]))));
    $hut = $container['shelter'];
    $this->assertFalse(isset($hut['size']), 'The hut is chosen: the tarp\'s size is not drawn.');
    $this->assertSame(30, $hut['covering']['depth']['#default_value'] ?? NULL, 'The thatch\'s depth, under the hut.');
    $this->assertContains('shelter', $container['kind'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another kind redraws the shelter.');
    $this->assertContains('shelter.covering', $hut['roof'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another roof redraws the covering.');
  }

  /**
   * Tests the tool lists the shelter's keys, refusing the hut's under the tarp.
   */
  public function testTheToolListsTheNestedVariants(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.shelter:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::SHELTER_KEYS, array_keys($properties));
    $this->assertSame(['roof', 'covering', 'size'], array_keys($properties['shelter']->getPropertyDefinitions()));
    $tool = $manager->createInstance('surface:resource_test.shelter:configure');
    $covered = ['size' => 4, 'covering' => ['depth' => 3]];
    $tool->setInputValue(OperationInputs::VALUES, ['kind' => 'tarp', 'shelter' => $covered] + self::SHELTER);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/shelter/covering: shelter.covering belongs to the hut variant, but kind chose tarp.", strip_tags((string) $tool->getResult()->getMessage()));
    $tool = $manager->createInstance('surface:resource_test.shelter:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['shelter' => ['roof' => 'slate', 'covering' => ['tiles' => 400]]] + self::SHELTER);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), strip_tags((string) $tool->getResult()->getMessage()));
  }

  /**
   * Tests the shelter's build is kept, and read back equal from the cache.
   */
  public function testTheShelterReadsBackFromTheCache(): void {
    $this->assertReadsBackFromTheCache(ShelterResource::class, 'resource_test.shelter');
  }

  /**
   * Tests one variant held by two owners holds the same variants in both.
   *
   * The camp lists the hut the shelter holds, from a provider; its
   * covering is the hut's thatch and slate there too, the same structures
   * by the same ids.
   */
  public function testOneVariantHeldByTwoOwners(): void {
    $resources = $this->container->get('surface.resources');
    $camp = $resources->build(CampStructure::class, new OperationContext('configure'));
    $this->assertSame(['site', 'camp'], $camp->getDefinitions()->names());
    $ids = static fn (SurfaceInterface $owner, string $key): array => array_values(array_map(static fn ($variant): ?string => $variant->id, $owner->getDefinitions()->entry($key)->variants->variant('hut')->child->getDefinitions()->entry('covering')->variants->variants));
    $this->assertSame($ids($this->shelter(), 'shelter'), $ids($camp, 'camp'));
    $slate = ['roof' => 'slate', 'covering' => ['tiles' => 2]];
    $accepted = $this->pipeline()->accept($camp, ['site' => 'hut', 'camp' => $slate], []);
    $this->assertSame([], iterator_to_array($this->pipeline()->validate($camp, $accepted, [])));
  }

  /**
   * Draws a fixture's configure form, answering the data surface's container.
   *
   * @param string $fixture
   *   The fixture's name: `pack` or `shelter`.
   *
   * @return array
   *   The form's data surface container.
   */
  protected function form(string $fixture): array {
    $route = new Route('/' . $fixture, [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => 'resource_test.' . $fixture,
      OperationRoute::OPERATION => 'configure',
    ]);
    $stack = $this->container->get('request_stack');
    $request = Request::create('/' . $fixture, 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.' . $fixture);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $state = new FormState();
    return $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Builds a fixture twice, the second read from the build cache, and compares.
   *
   * @param class-string $class
   *   The resource.
   * @param string $id
   *   Its id.
   */
  protected function assertReadsBackFromTheCache(string $class, string $id): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $emitted = static fn (SurfaceInterface $built): string => (string) json_encode([
      $emitter->emitFull($built, $id, 'configure')->document,
      $emitter->emit($built, $built->getDefaultValues(), $id, 'configure')->document,
    ]);
    $context = $resources->operation($class, 'configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared($class, $context);
    $document = $emitted($fresh);
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared($class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);
    $this->assertNotSame($fresh, $woken);
    $this->assertSame($fresh->getDefinitions()->names(), $woken->getDefinitions()->names());
    $this->assertSame($document, $emitted($woken));
  }

}
