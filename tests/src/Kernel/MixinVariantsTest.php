<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Contract\ContractChange;
use Drupal\surface\Contract\ContractDiff;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_resource_test\Resource\Beacon\BeaconResource;
use Drupal\surface_resource_test\Resource\Kit\KitResource;
use Drupal\surface_resource_test\Resource\Pack\PackResource;
use Drupal\surface_resource_test\Resource\Shelter\ShelterResource;
use Drupal\surface_resource_test\Structure\Beacon\BeaconPostStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitBothStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitChildVariantStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitCoveringClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitListByStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitMixinClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitNestedMixinStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitOutputsVariantStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitOwnerClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitPortVariantStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitResourceVariantStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitTwoCallsClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Kit\KitUndeclaredByStructure;
use Drupal\surface_resource_test\Structure\Broken\Pack\PackChildClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Pack\PackRangeClashStructure;
use Drupal\surface_resource_test\Structure\Broken\Pack\PackWiredChildStructure;
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
 * Variants mixed in: the structure a sibling key chooses, its keys flat.
 *
 * The kit mixes in the variant its kind chooses: the stove (its fuel,
 * required, and an access class that locks or omits it) and the tent
 * (its poles, required, and its rain fly, with a rule of its own); both
 * declare the weight alike, which is one key of the kit's. Every member
 * is the kit's own key, stored flat beside the kind, where
 * mixInVariants() stands; a member of a variant not chosen does not
 * apply.
 *
 * The pack mixes in the variant its carry chooses, each holding children:
 * the frame pack its hip belt (a child at a fixed key) and its pockets (a
 * collection), the daypack its rain cover (a map holding a pouch, a structure
 * with no id). Each child is the pack's own at its key, applying only
 * where its variant is chosen. Both declare the capacity alike but for
 * the label and the description, which the daypack's, found first, gives
 * the pack's key.
 *
 * The shelter mixes in the variant its kind chooses, the hut holding
 * variants of its own: its roof chooses the covering, thatch or slate.
 * The roof is the shelter's key, the covering the shelter's variants at
 * that key, chosen by the roof, applying only where the hut is chosen;
 * the camp mixes the same hut in and holds the same covering.
 *
 * @see docs/resources.md#variants-mixed-in
 * @see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class MixinVariantsTest extends SurfaceKernelTestBase {

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
   * The kit's keys: its own, and every variant's members where the call stands.
   */
  protected const KEYS = ['title', 'kind', 'weight', 'fuel', 'poles', 'fly', 'notes'];

  /**
   * The kit as installed.
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
   * The pack's keys: its own, and every variant's members, children too.
   */
  protected const PACK_KEYS = ['title', 'carry', 'capacity', 'cover', 'belt', 'pockets', 'notes'];

  /**
   * The pack as installed.
   */
  protected const PACK = [
    'title' => 'Day hike',
    'carry' => 'frame',
    'capacity' => 40,
    'belt' => ['padded' => TRUE, 'width' => 8],
    'pockets' => [['name' => 'Lid']],
    'notes' => '',
  ];

  /**
   * The shelter's keys: its own, the hut's roof and covering, the tarp's size.
   */
  protected const SHELTER_KEYS = ['title', 'kind', 'roof', 'covering', 'size', 'notes'];

  /**
   * The shelter as installed.
   */
  protected const SHELTER = [
    'title' => 'Ridge shelter',
    'kind' => 'hut',
    'roof' => 'thatch',
    'covering' => ['depth' => 30],
    'size' => NULL,
    'notes' => '',
  ];

  /**
   * The beacon's keys: its own, and the members both variants declare unlike.
   */
  protected const BEACON_KEYS = ['title', 'mode', 'code', 'color', 'pattern', 'notes'];

  /**
   * The beacon as installed.
   */
  protected const BEACON = [
    'title' => 'Ridge beacon',
    'mode' => 'flash',
    'code' => 'SOS',
    'color' => 'red',
    'pattern' => ['long', 'short'],
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
   *   What is stored.
   * @param \Drupal\surface\SurfaceInterface|null $built
   *   The data surface; NULL for the kit.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(array $input, array $stored = self::KIT, ?SurfaceInterface $built = NULL): array {
    $kit = $built ?? $this->kit();
    $accepted = $this->pipeline()->accept($kit, $input, $stored);
    $messages = [];
    foreach ($this->pipeline()->validate($kit, $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Tests the members land where the call stands, in variant then key order.
   */
  public function testMembersLandWhereTheCallStands(): void {
    $map = $this->kit()->getDefinitions();
    $this->assertSame(self::KEYS, $map->names(), 'Before the notes: the stove\'s, then the tent\'s, the weight they share once.');
    [$mixed] = $map->mixinVariants();
    $this->assertSame('kind', $mixed->by);
    $this->assertSame(['stove', 'tent'], $mixed->variantIds(), 'In the order discovery found them.');
    $this->assertSame(['weight', 'fuel'], $mixed->variants['stove']->keys);
    $this->assertSame(['weight', 'poles', 'fly'], $mixed->variants['tent']->keys);
    $this->assertSame(['resource_test.kit.stove', 'resource_test.kit.tent'], array_map(static fn ($variant) => $variant->structure->id, array_values($mixed->variants)));
    $this->assertSame(['stove', 'tent'], $mixed->valuesOf('weight'));
    $this->assertSame([], $map->mixins(), 'No structure is mixed in: variants are.');
    foreach (['weight', 'fuel', 'poles', 'fly'] as $member) {
      $this->assertFalse($map->get($member)->isRequired(), $member . ' is required only where its variant is chosen.');
    }
    $defaults = ['fuel' => 'gas', 'poles' => 2, 'fly' => FALSE];
    $this->assertSame(array_replace(array_fill_keys(self::KEYS, NULL), $defaults), $this->kit()->getDefaultValues());
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
   * Tests what applies, and what is required, for each value chosen.
   */
  public function testEachValueChoosesWhatApplies(): void {
    $kit = $this->kit();
    $tent = $kit->refine(['kind' => 'tent']);
    $this->assertTrue(DoesNotApply::marks($tent->getDefinition('fuel')));
    $this->assertTrue($tent->getDefinition('poles')->isRequired());
    $this->assertFalse(DoesNotApply::marks($tent->getDefinition('weight')));
    $this->assertFalse($tent->getDefinition('weight')->isRequired());
    $stove = $kit->refine(['kind' => 'stove']);
    $this->assertTrue(DoesNotApply::marks($stove->getDefinition('poles')));
    $this->assertTrue(DoesNotApply::marks($stove->getDefinition('fly')));
    $this->assertTrue($stove->getDefinition('fuel')->isRequired());
    $none = $kit->refine([]);
    foreach (['weight', 'fuel', 'poles', 'fly'] as $member) {
      $this->assertTrue(DoesNotApply::marks($none->getDefinition($member)), $member . ' applies to no variant chosen.');
    }
    // The tent's own rule, bound in the kit's frame, on its member.
    $this->assertSame(2, $kit->refine(['kind' => 'tent', 'fly' => TRUE])->getDefinition('poles')->getConstraints()['Range']['min']);

    $this->assertSame([], $this->refusals([]));
    $this->assertSame(['fuel' => 'Fuel does not apply when Kind is Tent.'], $this->refusals(['fuel' => 'spirit']), 'A member of a variant not chosen, sent, is refused naming the deciding value.');
    $this->assertSame(['poles' => 'Poles is required when Kind is Tent and Rain fly is on.'], $this->refusals(['poles' => NULL]));
    $stove = ['kind' => 'stove', 'fuel' => NULL];
    $this->assertSame(['fuel' => 'Fuel is required when Kind is Stove.'], $this->refusals($stove));
    $this->assertSame([
      'kind' => 'Kind is required.',
      'weight' => 'Weight does not apply when Kind is empty.',
    ], $this->refusals(['kind' => NULL, 'weight' => 9]));
    // The weight they share is accepted under either.
    $this->assertSame([], $this->refusals(['kind' => 'stove', 'weight' => 900]));
    $this->assertSame([], $this->refusals(['kind' => 'tent', 'weight' => 900]));

    // The chosen variant's defaults are laid in, and what does not apply
    // is left out of what is accepted.
    $accepted = $this->pipeline()->accept($kit, ['title' => 'Stove kit', 'kind' => 'stove'], []);
    $this->assertSame(['title' => 'Stove kit', 'kind' => 'stove', 'weight' => NULL, 'fuel' => 'gas', 'notes' => NULL], $accepted);

    // A write stores the members flat beside the kind.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(KitResource::class, 'configure');
    $result = $this->pipeline()->submit($kit, ['kind' => 'stove', 'fuel' => 'spirit', 'weight' => 640], $resources->storage(KitResource::class, $context, $kit));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.kit')->getRawData();
    $written = ['kind' => 'stove', 'weight' => 640, 'fuel' => 'spirit'];
    $this->assertSame($written, array_intersect_key($stored, $written));
    $this->assertSame(2, $stored['poles'], 'A member that does not apply keeps what is stored.');
    $this->assertStaticRefinersAgree($kit);
  }

  /**
   * Tests each collision is refused at the build, naming what collides.
   */
  public function testCollisionsAreRefused(): void {
    $refusals = [
      KitOwnerClashStructure::class => 'resource_test.broken.kit_owner_clash mixes in the variants chosen by "kind": the variant for "a", resource_test.broken.kit.weight_only, declares "weight", which resource_test.broken.kit_owner_clash declares too. A variant\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the variants under a key of their own with attachVariants().',
      KitMixinClashStructure::class => 'resource_test.broken.kit_mixin_clash mixes in the variants chosen by "kind": the variant for "a", resource_test.broken.created_only, declares "created", which resource_test.timestamps, mixed in, declares too. A variant\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the variants under a key of their own with attachVariants().',
      KitTwoCallsClashStructure::class => 'resource_test.broken.kit_two_calls_clash mixes in the variants chosen by "mode": the variant for "b", resource_test.broken.kit.weight_only, declares "weight", which the variant for "a", resource_test.broken.kit.weight_only, of the variants chosen by "kind", declares too. A variant\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the variants under a key of their own with attachVariants().',
      KitCoveringClashStructure::class => 'resource_test.broken.kit_covering_clash mixes in the variants chosen by "kind": the variant for "hut", resource_test.shelter.hut, and the variant for "flat", resource_test.broken.kit.covering_word, both declare "covering", and not alike, where one holds variants at it. Variants a variant mixed in holds at a key are the owner\'s variants there, so every variant that declares the key holds the same variants by the same deciding key: declare them alike, or rename one.',
    ];
    $this->assertRefused($refusals);
  }

  /**
   * Tests what cannot be a deciding key or a variant is refused at the build.
   */
  public function testWhatCannotBeMixedInIsRefused(): void {
    $this->assertRefused([
      KitUndeclaredByStructure::class => 'In resource_test.broken.kit_undeclared_by, the variants mixed in are chosen by "kind", which it never declares as a plain input: variants are chosen by a sibling that holds one value.',
      KitListByStructure::class => 'The variants mixed in by "kind" are chosen by "kind", which is a list: a deciding key holds one value.',
      KitResourceVariantStructure::class => 'resource_test.broken.kit_resource_variant mixes in the variants chosen by "kind", and the variant for "ledger" is resource_test.ledger, which names storage of its own. A variant\'s members are the owner\'s keys, stored by the owner: mix in a structure, or attach a resource under a key of its own.',
      KitOutputsVariantStructure::class => 'resource_test.broken.kit_outputs_variant mixes in the variants chosen by "kind", and the variant for "out" is resource_test.broken.refines_output, which declares outputs. A variant\'s inputs are mixed in; its outputs are not, yet.',
      KitChildVariantStructure::class => 'The surface_resource_test alter of resource_test.notice adds "signature", and resource_test.notice is mixed into resource_test.broken.kit_child_variant. An alter of a mixed-in structure narrows and rewords its members where it is mixed in; what it mounts would be mounted on resource_test.broken.kit_child_variant, which is not built.',
      KitNestedMixinStructure::class => 'resource_test.broken.kit_nested_mixin mixes in the variants chosen by "kind", and the variant for "camp" is resource_test.camp, which holds variants mixed in of its own. A variant mixed in holds keys, maps, children at a fixed key or in a collection, and variants at a key, not keys from the site or variants mixed in of its own, for now: attach the variants under a key of their own with attachVariants() instead.',
      PackWiredChildStructure::class => 'resource_test.broken.pack_wired_child mixes in the variants chosen by "kind", and the variant for "shared" is resource_test.broken.pack.wired_ownership, which attaches resource_test.ownership at "owned" and wires its "audience" port. A child a variant mixed in brings is the owner\'s child at that key, and nothing wires its ports, for now: attach it with no reads:.',
      KitPortVariantStructure::class => 'resource_test.broken.kit_port_variant mixes in the variants chosen by "kind", and the variant for "owned" is resource_test.ownership, which reads the "audience" port. Nothing wires a variant mixed in: any module may add one, so no wiring the owner writes could name its ports.',
      KitBothStructure::class => 'In resource_test.broken.kit_both, "kind" holds variants (attachVariants()) and chooses variants mixed in (mixInVariants()), and a #[Variant(of:, at: \'kind\')] names one of the two: Drupal\surface_resource_test\Structure\Broken\Kit\KitBothVariantStructure would be a variant of both. The variants at a key and the variants a key mixes in are found by the same `at`, so one name is one of them: rename the key the variants sit at, or the deciding key.',
    ]);
  }

  /**
   * Builds each fixture, expecting it refused with its message.
   *
   * @param array<class-string, string> $refusals
   *   The message each fixture is refused with.
   */
  protected function assertRefused(array $refusals): void {
    $resources = $this->container->get('surface.resources');
    foreach ($refusals as $class => $message) {
      try {
        $resources->build($class, new OperationContext('configure'));
        $this->fail($class . ' was built.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $class);
      }
    }
  }

  /**
   * Tests a variant's access omits or locks it, the others standing.
   */
  public function testAccessOmitsOrLocksOneVariant(): void {
    $this->setUpCurrentUser([], ['administer site configuration', 'hide stove']);
    $kit = $this->kit();
    $this->assertSame(['title', 'kind', 'weight', 'poles', 'fly', 'notes'], $kit->getDefinitions()->names(), 'The stove\'s own member is gone; the weight the tent brings stays.');
    $this->assertSame(['tent'], $kit->getDefinition('kind')->getConstraints()['LabeledChoice']['choices'], 'Its value is not offered.');
    [$mixed] = $kit->getDefinitions()->mixinVariants();
    $this->assertSame(['tent'], $mixed->variantIds());
    $this->assertSame(['fuel'], $mixed->withdrawn);
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(KitResource::class, 'configure');
    $result = $this->pipeline()->submit($kit, ['title' => 'Kept apart'], $resources->storage(KitResource::class, $context, $kit));
    $this->assertTrue($result->isValid());
    $stored = $this->config('surface_resource_test.kit')->getRawData();
    $this->assertSame(['Kept apart', 'gas'], [$stored['title'], $stored['fuel']], 'What the access took away, storage keeps.');
    $full = $this->schema();
    $this->assertArrayNotHasKey('resource_test.kit.stove', $full['$defs'], 'Omitted: no def, no conditional.');
    $this->assertSame(['tent'], array_keys($full['x-surface']['variantsMixedIn']['kind']));

    $this->setUpCurrentUser([], ['administer site configuration', 'inspect stove']);
    $map = $this->kit()->getDefinitions();
    $this->assertTrue($map->isLocked('fuel') && $map->isHeld('fuel'), 'Locked: the stove\'s own member holds what is stored.');
    $this->assertFalse($map->isLocked('weight'), 'The weight the open tent brings too is not held.');
    $this->assertSame(['stove', 'tent'], $map->mixinVariants()[0]->variantIds(), 'A locked variant is offered.');
  }

  /**
   * Tests the full document: one conditional per variant, its def open.
   */
  public function testTheFullDocumentStatesEachVariantOnce(): void {
    $full = $this->schema();
    $keys = ['$schema', 'x-surface', 'type', 'properties', 'unevaluatedProperties', 'required', 'allOf', '$defs'];
    $this->assertSame($keys, array_keys($full));
    $this->assertSame([
      'contract' => 4,
      'version' => 1,
      'variantsMixedIn' => [
        'kind' => [
          'stove' => '#/$defs/resource_test.kit.stove',
          'tent' => '#/$defs/resource_test.kit.tent',
        ],
      ],
    ], $full['x-surface']);
    $this->assertSame(['title', 'kind', 'notes'], array_keys($full['properties']), 'A member is stated by its variant\'s def, and nowhere else.');
    $this->assertFalse($full['unevaluatedProperties']);
    $this->assertSame(['title', 'kind'], $full['required']);
    $expected = json_decode(<<<'JSON'
      [
        {"if": {"properties": {"kind": {"const": "stove"}}, "required": ["kind"]},
         "then": {"allOf": [{"$ref": "#/$defs/resource_test.kit.stove"}]}},
        {"if": {"properties": {"kind": {"const": "tent"}}, "required": ["kind"]},
         "then": {"allOf": [{"$ref": "#/$defs/resource_test.kit.tent"}]}}
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
        "required": ["fuel"]
      }
      JSON, TRUE);
    $this->assertSame($stove, $full['$defs']['resource_test.kit.stove'], 'Open: the kit composes it with keys of its own.');
    $this->assertArrayNotHasKey('additionalProperties', $full['$defs']['resource_test.kit.tent']);
    $this->assertSame(['poles'], $full['$defs']['resource_test.kit.tent']['required']);
    $this->assertSame([0], $full['$defs']['resource_test.kit.tent']['x-surface']['rules'], 'The tent\'s own rule, in its def.');

    // A standard validator holds the document as the pipeline holds values.
    $tent = array_diff_key(self::KIT, ['fuel' => 0]);
    $this->assertTrue($this->validates($full, $tent));
    $this->assertFalse($this->validates($full, self::KIT), 'The stove\'s fuel under the tent: no conditional evaluates it.');
    $this->assertFalse($this->validates($full, ['poles' => NULL] + $tent), 'The tent\'s poles are required.');
    $this->assertFalse($this->validates($full, ['fly' => TRUE, 'poles' => 1] + $tent), 'The tent\'s rule holds.');
    $stove_kit = ['title' => 'Stove kit', 'kind' => 'stove', 'weight' => 640, 'fuel' => 'spirit', 'notes' => ''];
    $this->assertTrue($this->validates($full, $stove_kit));
    $this->assertFalse($this->validates($full, ['poles' => 2] + $stove_kit), 'A tent\'s member under the stove: no conditional evaluates it.');
    $this->assertFalse($this->validates($full, ['fuel' => NULL] + $stove_kit), 'The stove\'s fuel is required.');
    $this->assertFalse($this->validates($full, ['fax' => 1] + $stove_kit), 'The composition is closed.');

    // The schema for these answers composes the same way, and names no
    // member the choice alone moved.
    $these = $this->schema($stove_kit);
    $this->assertSame(['title', 'kind', 'notes'], array_keys($these['properties']));
    $this->assertSame($expected, $these['allOf']);
    $this->assertArrayNotHasKey('doesNotApply', $these['x-surface'] ?? [], 'What the choice rules out, the conditionals say.');
    $this->assertTrue($this->validates($these, $stove_kit));
  }

  /**
   * Tests the inline reading lays each variant over the frame, flat.
   */
  public function testInlineReadsTheFrameFlat(): void {
    $inline = SchemaResolver::inline($this->schema());
    $this->assertSame(self::KEYS, array_keys($inline['properties']), 'Every member after the key that chooses it.');
    $this->assertFalse($inline['additionalProperties']);
    $this->assertArrayNotHasKey('unevaluatedProperties', $inline);
    $this->assertArrayNotHasKey('variantsMixedIn', $inline['x-surface'] ?? [], 'Spent: the frame reads flat.');
    $rules = json_decode(<<<'JSON'
      [
        {"if": {"not": {"properties": {"kind": {"enum": ["stove", "tent"]}}, "required": ["kind"]}},
         "then": {"properties": {"weight": false}}, "x-surface": {"declared": true}},
        {"if": {"not": {"properties": {"kind": {"const": "stove"}}, "required": ["kind"]}},
         "then": {"properties": {"fuel": false}}, "x-surface": {"declared": true}},
        {"if": {"properties": {"kind": {"const": "stove"}}, "required": ["kind"]},
         "then": {"properties": {"fuel": {"type": "string", "oneOf": [{"const": "gas", "title": "Gas"}, {"const": "spirit", "title": "Spirit"}]}}, "required": ["fuel"]}, "x-surface": {"declared": true}},
        {"if": {"not": {"properties": {"kind": {"const": "tent"}}, "required": ["kind"]}},
         "then": {"properties": {"poles": false}}, "x-surface": {"declared": true}},
        {"if": {"properties": {"kind": {"const": "tent"}}, "required": ["kind"]},
         "then": {"properties": {"poles": {"type": "integer"}}, "required": ["poles"]}, "x-surface": {"declared": true}},
        {"if": {"not": {"properties": {"kind": {"const": "tent"}}, "required": ["kind"]}},
         "then": {"properties": {"fly": false}}, "x-surface": {"declared": true}}
      ]
      JSON, TRUE);
    $this->assertSame($rules, array_slice($inline['allOf'], 1), 'Where each member applies and is required: the rules the build bound for the choice.');
    $this->assertSame(['fly'], array_keys($inline['allOf'][0]['if']['properties']), 'First, the tent\'s own rule, from its def.');
    $this->assertSame(SchemaResolver::inline($inline), $inline, 'Read flat twice, the same.');
    $tent = array_diff_key(self::KIT, ['fuel' => 0]);
    $this->assertTrue($this->validates($inline, $tent));
    $this->assertFalse($this->validates($inline, self::KIT));
    $this->assertFalse($this->validates($inline, ['kind' => 'stove'] + $tent));
    $stove_kit = ['title' => 'Stove kit', 'kind' => 'stove', 'weight' => 640, 'fuel' => 'spirit', 'notes' => ''];
    $this->assertTrue($this->validates($inline, $stove_kit));
  }

  /**
   * Tests the full schema reads back to itself through the schema reader.
   */
  public function testTheDocumentReadsBack(): void {
    $full = $this->schema();
    $read = $this->container->get('surface.schema_reader')->structure($full, 'resource_test.kit', 'surface_resource_test');
    $built = $this->container->get('surface.resources')->buildStatic($read, new OperationContext('configure'));
    $this->assertSame(self::KEYS, $built->getDefinitions()->names(), 'The members after the key that chooses them.');
    $this->assertSame(['stove', 'tent'], $built->getDefinitions()->mixinVariants()[0]->variantIds());
    $again = $this->container->get('surface.contract_emitter')->emitFull($built, 'resource_test.kit', 'configure')->document['schema'];
    $this->assertSame(json_encode($full), json_encode($again));
  }

  /**
   * Tests the form draws the chosen variant's members, redrawn by the kind.
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
    $drawn = array_values(array_filter(self::KEYS, static fn (string $key): bool => isset($container[$key]) && empty($container[$key][SurfaceFormBuilderInterface::RULED_OUT_KEY])));
    $this->assertSame(['title', 'kind', 'weight', 'poles', 'fly', 'notes'], $drawn, 'The tent is chosen: the stove\'s fuel is not drawn.');
    $this->assertTrue($container['fuel'][SurfaceFormBuilderInterface::RULED_OUT_KEY], 'Its wrapper alone, for the rebuild that makes it apply.');
    $this->assertSame(2, $container['poles']['#default_value']);
    $replaces = $container['kind'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [];
    foreach (['weight', 'fuel', 'poles', 'fly'] as $member) {
      $this->assertContains($member, $replaces, 'Choosing another kind redraws ' . $member . '.');
    }
  }

  /**
   * Tests the tool lists every member, each with where it applies.
   */
  public function testTheToolListsEveryMember(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.kit:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::KEYS, array_keys($properties));
    $this->assertStringEndsWith('Depends on kind.', (string) $properties['fuel']->getDescription(), 'Where it applies is said as a rule is.');
    $tool = $manager->createInstance('surface:resource_test.kit:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['fuel' => 'spirit'] + self::KIT);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/fuel: Fuel does not apply when Kind is tent (Tent).", strip_tags((string) $tool->getResult()->getMessage()), 'The tool names the value as sent, and its label.');
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
    $this->assertSame($fresh->getDefinitions()->mixinVariants()[0]->variantIds(), $woken->getDefinitions()->mixinVariants()[0]->variantIds());
    $this->assertSame($document, $emitted($woken));
  }

  /**
   * Tests the config schema written from the kit lists every member.
   *
   * ConfigSchemaDriftTest holds the file to what the generator writes;
   * this reads what it says.
   */
  public function testTheConfigSchemaListsEveryMember(): void {
    $schema = $this->container->get('config.typed')->getDefinition('surface_resource_test.kit');
    $mapping = array_diff_key($schema['mapping'], array_flip(['_core', 'langcode']));
    $this->assertSame(self::KEYS, array_keys($mapping), 'Flat, in the kit\'s order, after what every config object holds.');
    foreach (['weight', 'fuel', 'poles', 'fly'] as $member) {
      $this->assertTrue($mapping[$member]['nullable'] ?? FALSE, $member . ' is optional: it may not apply.');
    }
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
   * Tests a member two variants declare alike but for its label is shared.
   *
   * It wears the label and the description of the variant found first;
   * each variant's def keeps its own. One that differs by a constraint is
   * declared as wide as both and held to each (testUnlikeMembersAreHeld()).
   */
  public function testLabelAloneIsNoDifference(): void {
    $pack = $this->pack();
    [$mixed] = $pack->getDefinitions()->mixinVariants();
    $this->assertSame(['day', 'frame'], $mixed->variantIds(), 'In the order discovery found them.');
    $this->assertSame(['day', 'frame'], $mixed->valuesOf('capacity'), 'One key for both.');
    $capacity = $pack->getDefinition('capacity');
    $this->assertSame(['Volume', 'In liters.'], [(string) $capacity->getLabel(), (string) $capacity->getDescription()], 'The daypack\'s, found first.');
    $full = $this->packSchema();
    $day = $full['$defs']['resource_test.pack.day']['properties']['capacity'];
    $this->assertSame(['Volume', 'In liters.'], [$day['title'], $day['description']]);
    $this->assertSame('Capacity', $full['$defs']['resource_test.pack.frame']['properties']['capacity']['title'], 'Each def its own.');
    $this->assertArrayNotHasKey('description', $full['$defs']['resource_test.pack.frame']['properties']['capacity']);
    $this->assertSame([], $this->refusals(['carry' => 'frame', 'capacity' => 50], self::PACK, $pack));
    $small = ['carry' => 'day', 'capacity' => 5, 'cover' => ['pouch' => ['name' => 'Fold']]];
    $this->assertSame(['capacity' => 'This value should be 10 or more.'], $this->refusals($small, self::PACK, $pack), 'Its constraint holds under either.');
  }

  /**
   * Emits the pack's schema, decoded.
   *
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function packSchema(?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL
      ? $emitter->emitFull($this->pack(), 'resource_test.pack', 'configure')
      : $emitter->emit($this->pack(), $values, 'resource_test.pack', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Tests each child a variant holds is the pack's, where it is chosen.
   */
  public function testVariantHoldsChildren(): void {
    $pack = $this->pack();
    $map = $pack->getDefinitions();
    $this->assertSame(self::PACK_KEYS, $map->names(), 'Each child where its variant declares it, in variant then key order.');
    [$mixed] = $map->mixinVariants();
    $this->assertSame(['capacity', 'cover'], $mixed->variants['day']->keys);
    $this->assertSame(['capacity', 'belt', 'pockets'], $mixed->variants['frame']->keys);
    $this->assertSame('resource_test.pack.belt', $map->entry('belt')->attachment?->id, 'The hip belt, a child at a fixed key.');
    $this->assertSame('resource_test.pack.pocket', $map->entry('pockets')->list?->item->id, 'The pockets, a collection.');
    $this->assertSame('resource_test.pack.day.cover', $map->entry('cover')->attachment?->id, 'The rain cover, a structure with no id.');

    $frame = $pack->refine(['carry' => 'frame']);
    $this->assertTrue(DoesNotApply::marks($frame->getDefinition('cover')));
    $this->assertFalse(DoesNotApply::marks($frame->getDefinition('belt')));
    $day = $pack->refine(['carry' => 'day']);
    $this->assertTrue(DoesNotApply::marks($day->getDefinition('belt')));
    $this->assertTrue(DoesNotApply::marks($day->getDefinition('pockets')));
    $this->assertFalse(DoesNotApply::marks($day->getDefinition('cover')));

    // Each accepted under its value, refused under another.
    $frame_pack = [
      'belt' => ['padded' => FALSE, 'width' => 6],
      'pockets' => [['name' => 'Lid'], ['name' => 'Hip']],
    ];
    $this->assertSame([], $this->refusals($frame_pack, self::PACK, $pack));
    $this->assertSame(['cover' => 'Rain cover does not apply when Carry is Frame pack.'], $this->refusals(['cover' => ['color' => 'red']], self::PACK, $pack));
    $day_pack = ['carry' => 'day', 'belt' => NULL, 'pockets' => NULL];
    $pouched = ['cover' => ['pouch' => ['name' => 'Fold']]];
    $this->assertSame([], $this->refusals($day_pack + ['cover' => ['color' => 'red', 'pouch' => ['name' => 'Fold']]], self::PACK, $pack));
    $belted = ['carry' => 'day', 'belt' => ['width' => 6], 'pockets' => NULL] + $pouched;
    $this->assertSame(['belt' => 'Hip belt does not apply when Carry is Daypack.'], $this->refusals($belted, self::PACK, $pack));
    $pocketed = ['carry' => 'day', 'belt' => NULL, 'pockets' => [['name' => 'Side']]] + $pouched;
    $this->assertSame(['pockets' => 'Pockets does not apply when Carry is Daypack.'], $this->refusals($pocketed, self::PACK, $pack), 'Other than what is stored.');
    // Each child holds its own keys, and the list its count.
    $this->assertSame(['belt.width' => 'This value should be 1 or more.'], $this->refusals(['belt' => ['width' => 0]], self::PACK, $pack));
    $four = array_fill(0, 4, ['name' => 'Side']);
    $this->assertSame(['pockets' => 'This collection should contain 3 elements or less.'], $this->refusals(['pockets' => $four], self::PACK, $pack));
    $this->assertSame(['cover.pouch.name' => 'Name is required.'], $this->refusals($day_pack + ['cover' => ['pouch' => ['name' => NULL]]], self::PACK, $pack), 'The cover\'s pouch, in its own frame.');

    // A write stores each child flat beside the carry, at its key.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(PackResource::class, 'configure');
    $cover = ['color' => 'red', 'pouch' => ['name' => 'Fold']];
    $result = $this->pipeline()->submit($pack, $day_pack + ['cover' => $cover], $resources->storage(PackResource::class, $context, $pack));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.pack')->getRawData();
    $this->assertSame(['day', $cover], [$stored['carry'], $stored['cover']]);
    $this->assertSame(['padded' => TRUE, 'width' => 8], $stored['belt'], 'A child that does not apply keeps what is stored.');
    $this->assertStaticRefinersAgree($pack);
  }

  /**
   * Tests the full document references each variant's def, its children inside.
   */
  public function testTheFullDocumentNamesEachChildInItsVariant(): void {
    $full = $this->packSchema();
    $this->assertSame(['title', 'carry', 'notes'], array_keys($full['properties']), 'A child is stated by its variant\'s def, and nowhere else.');
    $this->assertSame([
      'day' => '#/$defs/resource_test.pack.day',
      'frame' => '#/$defs/resource_test.pack.frame',
    ], $full['x-surface']['variantsMixedIn']['carry']);
    $this->assertSame(['#/$defs/resource_test.pack.day', '#/$defs/resource_test.pack.frame'], array_map(static fn (array $branch): string => $branch['then']['allOf'][0]['$ref'], $full['allOf']));
    $frame = $full['$defs']['resource_test.pack.frame'];
    $this->assertSame(['capacity', 'belt', 'pockets'], array_keys($frame['properties']));
    $this->assertSame(['title' => 'Hip belt', '$ref' => '#/$defs/resource_test.pack.belt'], $frame['properties']['belt'], 'The child\'s def, by reference, inside the variant\'s.');
    $this->assertSame(['$ref' => '#/$defs/resource_test.pack.pocket'], $frame['properties']['pockets']['items']);
    $this->assertSame(3, $frame['properties']['pockets']['maxItems']);
    $this->assertSame(['color', 'pouch'], array_keys($full['$defs']['resource_test.pack.day']['properties']['cover']['properties']), 'The cover, inline in the daypack\'s def.');
    $this->assertSame(['padded', 'width'], array_keys($full['$defs']['resource_test.pack.belt']['properties']));

    $this->assertTrue($this->validates($full, self::PACK));
    $this->assertFalse($this->validates($full, ['cover' => ['color' => 'red']] + self::PACK), 'The daypack\'s cover under the frame pack: no conditional evaluates it.');
    $this->assertFalse($this->validates($full, ['belt' => ['width' => 0]] + self::PACK), 'The belt\'s own def holds.');
    $this->assertFalse($this->validates($full, ['pockets' => array_fill(0, 4, ['name' => 'Side'])] + self::PACK));
    $day_pack = [
      'title' => 'Day hike',
      'carry' => 'day',
      'capacity' => 20,
      'cover' => ['color' => 'red', 'pouch' => ['name' => 'Fold']],
      'notes' => '',
    ];
    $this->assertTrue($this->validates($full, $day_pack));
    $this->assertFalse($this->validates($full, ['belt' => ['width' => 6]] + $day_pack), 'The frame pack\'s belt under the daypack.');
    $this->assertFalse($this->validates($full, ['cover' => ['pouch' => ['name' => NULL]]] + $day_pack), 'The pouch\'s def holds.');
    $inline = SchemaResolver::inline($full);
    $this->assertSame(self::PACK_KEYS, array_keys($inline['properties']), 'Read flat, each child where its variant declares it.');
    $this->assertTrue($this->validates($inline, $day_pack));
    $this->assertFalse($this->validates($inline, ['belt' => ['width' => 6]] + $day_pack));
  }

  /**
   * Tests the form draws a child under its variant, redrawn by the carry.
   */
  public function testTheFormDrawsTheChosenVariantsChildren(): void {
    $route = new Route('/pack', [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => 'resource_test.pack',
      OperationRoute::OPERATION => 'configure',
    ]);
    $stack = $this->container->get('request_stack');
    $request = Request::create('/pack', 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.pack');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $state = new FormState();
    $container = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
    $drawn = array_values(array_filter(self::PACK_KEYS, static fn (string $key): bool => isset($container[$key]) && empty($container[$key][SurfaceFormBuilderInterface::RULED_OUT_KEY])));
    $this->assertSame(['title', 'carry', 'capacity', 'belt', 'pockets', 'notes'], $drawn, 'The frame pack is chosen: the daypack\'s cover is not drawn.');
    $this->assertSame(8, $container['belt']['width']['#default_value'], 'The belt\'s own keys, under the frame pack.');
    $replaces = $container['carry'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [];
    foreach (['capacity', 'cover', 'belt', 'pockets'] as $member) {
      $this->assertContains($member, $replaces, 'Choosing another carry redraws ' . $member . '.');
    }
  }

  /**
   * Tests the tool lists each child, and refuses one under another variant.
   */
  public function testTheToolListsEveryChild(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.pack:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::PACK_KEYS, array_keys($properties));
    $this->assertSame(['padded', 'width'], array_keys($properties['belt']->getPropertyDefinitions()));
    $this->assertStringEndsWith('Depends on carry.', (string) $properties['belt']->getDescription());
    $tool = $manager->createInstance('surface:resource_test.pack:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['cover' => ['color' => 'red']] + self::PACK);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/cover: Rain cover does not apply when Carry is frame (Frame pack).", strip_tags((string) $tool->getResult()->getMessage()));
  }

  /**
   * Tests a variant's access omits its children with its members.
   */
  public function testAnOmittedVariantTakesItsChildren(): void {
    $this->setUpCurrentUser([], ['administer site configuration', 'hide frame pack']);
    $pack = $this->pack();
    $this->assertSame(['title', 'carry', 'capacity', 'cover', 'notes'], $pack->getDefinitions()->names(), 'The frame pack\'s belt and pockets are gone; the capacity the daypack brings stays.');
    [$mixed] = $pack->getDefinitions()->mixinVariants();
    $this->assertSame(['day'], $mixed->variantIds());
    $this->assertSame(['belt', 'pockets'], $mixed->withdrawn);
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(PackResource::class, 'configure');
    $kept = ['title' => 'Kept apart', 'carry' => 'day', 'cover' => ['pouch' => ['name' => 'Fold']]];
    $result = $this->pipeline()->submit($pack, $kept, $resources->storage(PackResource::class, $context, $pack));
    $this->assertTrue($result->isValid());
    $stored = $this->config('surface_resource_test.pack')->getRawData();
    $this->assertSame([['padded' => TRUE, 'width' => 8], [['name' => 'Lid']]], [$stored['belt'], $stored['pockets']], 'What the access took away, storage keeps.');
  }

  /**
   * Tests the pack's build is kept, and read back equal from the build cache.
   */
  public function testThePackReadsBackFromTheCache(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $emitted = static fn (SurfaceInterface $built): string => (string) json_encode([
      $emitter->emitFull($built, 'resource_test.pack', 'configure')->document,
      $emitter->emit($built, $built->getDefaultValues(), 'resource_test.pack', 'configure')->document,
    ]);
    $context = $resources->operation(PackResource::class, 'configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared(PackResource::class, $context);
    $document = $emitted($fresh);
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared(PackResource::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);
    $this->assertNotSame($fresh, $woken);
    $this->assertSame($fresh->getDefinitions()->names(), $woken->getDefinitions()->names());
    $this->assertSame($document, $emitted($woken));
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
   * Emits the shelter's schema, decoded.
   *
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function shelterSchema(?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL
      ? $emitter->emitFull($this->shelter(), 'resource_test.shelter', 'configure')
      : $emitter->emit($this->shelter(), $values, 'resource_test.shelter', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Tests a variant holding variants: the owner's at that key, by its member.
   */
  public function testVariantHoldsVariants(): void {
    $shelter = $this->shelter();
    $map = $shelter->getDefinitions();
    $this->assertSame(self::SHELTER_KEYS, $map->names(), 'The roof and the covering where the hut declares them.');
    [$mixed] = $map->mixinVariants();
    $this->assertSame(['roof', 'covering'], $mixed->variants['hut']->keys);
    $covering = $map->entry('covering')->variants;
    $this->assertNotNull($covering, 'The shelter\'s variants at the covering.');
    $this->assertSame('roof', $covering->by, 'Chosen by the hut\'s roof, the shelter\'s key.');
    $this->assertSame(['resource_test.shelter.slate', 'resource_test.shelter.thatch'], array_values(array_map(static fn ($variant): ?string => $variant->id, $covering->variants)));
    $this->assertSame(['hut'], $mixed->valuesOf('covering'));

    $tarp = $shelter->refine(['kind' => 'tarp']);
    $this->assertTrue(DoesNotApply::marks($tarp->getDefinition('covering')));
    $this->assertTrue(DoesNotApply::marks($tarp->getDefinition('roof')));
    $this->assertFalse(DoesNotApply::marks($shelter->refine(['kind' => 'hut', 'roof' => 'thatch'])->getDefinition('covering')));

    // Each nested value accepted under the hut.
    $this->assertSame([], $this->refusals(['roof' => 'thatch', 'covering' => ['depth' => 12]], self::SHELTER, $shelter));
    $this->assertSame([], $this->refusals(['roof' => 'slate', 'covering' => ['tiles' => 400]], self::SHELTER, $shelter));
    // The covering's own frame holds, by the roof the hut brings.
    $no_tiles = ['roof' => 'slate', 'covering' => ['tiles' => NULL]];
    $this->assertSame(['covering.tiles' => 'Tiles is required.'], $this->refusals($no_tiles, self::SHELTER, $shelter));
    $this->assertSame(['covering.depth' => 'This value should be 1 or more.'], $this->refusals(['covering' => ['depth' => 0]], self::SHELTER, $shelter));
    // Under the tarp, neither applies.
    $tarp_shelter = ['kind' => 'tarp', 'roof' => NULL, 'size' => 4];
    $this->assertSame(['covering' => 'Covering does not apply when Kind is Tarp.'], $this->refusals($tarp_shelter + ['covering' => ['depth' => 3]], self::SHELTER, $shelter));
    $roofed = ['kind' => 'tarp', 'roof' => 'slate', 'size' => 4];
    $this->assertSame(['roof' => 'Roof does not apply when Kind is Tarp.'], $this->refusals($roofed, self::SHELTER, $shelter));
    $this->assertSame([
      'roof' => 'Roof is required when Kind is Hut.',
      'covering' => 'Covering depends on Roof, which holds no value.',
    ], $this->refusals(['roof' => NULL], self::SHELTER, $shelter), 'Required where the hut is chosen, and the covering waits for it.');

    // A write stores the roof flat, the covering at its key.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(ShelterResource::class, 'configure');
    $result = $this->pipeline()->submit($shelter, ['roof' => 'slate', 'covering' => ['tiles' => 400]], $resources->storage(ShelterResource::class, $context, $shelter));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.shelter')->getRawData();
    $this->assertSame(['hut', 'slate', ['tiles' => 400]], [$stored['kind'], $stored['roof'], $stored['covering']]);
    $this->assertStaticRefinersAgree($shelter);
  }

  /**
   * Tests a value of another variant's covering is refused under the roof.
   */
  public function testTheCoveringOfAnotherRoofIsRefused(): void {
    $this->expectException(VariantMismatchException::class);
    $this->expectExceptionMessage('The value at covering is shaped for another variant than "slate", which roof chose: depth.');
    $this->refusals(['roof' => 'slate', 'covering' => ['depth' => 12, 'tiles' => 400]], self::SHELTER, $this->shelter());
  }

  /**
   * Tests the full document: the covering's variants inside the hut's def.
   */
  public function testTheFullDocumentNamesTheNestedVariantsInTheirVariant(): void {
    $full = $this->shelterSchema();
    $this->assertSame(['title', 'kind', 'notes'], array_keys($full['properties']), 'The covering is stated by the hut\'s def, and nowhere else.');
    $this->assertSame([
      'hut' => '#/$defs/resource_test.shelter.hut',
      'tarp' => '#/$defs/resource_test.shelter.tarp',
    ], $full['x-surface']['variantsMixedIn']['kind']);
    $hut = $full['$defs']['resource_test.shelter.hut'];
    $this->assertSame(['roof', 'covering'], array_keys($hut['properties']));
    $this->assertSame('roof', $hut['properties']['covering']['x-surface']['by']);
    $this->assertSame([
      'slate' => '#/$defs/resource_test.shelter.slate',
      'thatch' => '#/$defs/resource_test.shelter.thatch',
    ], $hut['properties']['covering']['x-surface']['variants']);
    $nested = array_values(array_filter($hut['allOf'] ?? [], static fn (array $branch): bool => isset($branch['if']['properties']['roof'])));
    $this->assertCount(2, $nested, 'One conditional per covering, on the roof, inside the hut\'s def.');

    // The tarp's size, null, is a key the hut does not hold.
    $hut_shelter = array_diff_key(self::SHELTER, ['size' => 0]);
    $this->assertTrue($this->validates($full, $hut_shelter));
    $this->assertFalse($this->validates($full, self::SHELTER), 'The tarp\'s size under the hut: no conditional evaluates it.');
    $this->assertTrue($this->validates($full, ['roof' => 'slate', 'covering' => ['tiles' => 400]] + $hut_shelter));
    $this->assertFalse($this->validates($full, ['roof' => 'slate', 'covering' => ['depth' => 3]] + $hut_shelter), 'The slate\'s def holds the covering.');
    $this->assertFalse($this->validates($full, ['covering' => ['depth' => 0]] + $hut_shelter), 'The thatch\'s def holds.');
    $tarp = ['title' => 'Fly', 'kind' => 'tarp', 'size' => 4, 'notes' => ''];
    $this->assertTrue($this->validates($full, $tarp));
    $this->assertFalse($this->validates($full, ['covering' => ['depth' => 3]] + $tarp), 'The hut\'s covering under the tarp: no conditional evaluates it.');
    $inline = SchemaResolver::inline($full);
    $this->assertSame(self::SHELTER_KEYS, array_keys($inline['properties']), 'Read flat, the covering where the hut declares it.');
    $this->assertTrue($this->validates($inline, $hut_shelter));
    $this->assertFalse($this->validates($inline, ['covering' => ['depth' => 3]] + $tarp));

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
   * added a choice added, whatever owner mixes the hut in.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
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
    $route = new Route('/shelter', [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => 'resource_test.shelter',
      OperationRoute::OPERATION => 'configure',
    ]);
    $stack = $this->container->get('request_stack');
    $request = Request::create('/shelter', 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.shelter');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $state = new FormState();
    $container = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
    $drawn = array_values(array_filter(self::SHELTER_KEYS, static fn (string $key): bool => isset($container[$key]) && empty($container[$key][SurfaceFormBuilderInterface::RULED_OUT_KEY])));
    $this->assertSame(['title', 'kind', 'roof', 'covering', 'notes'], $drawn, 'The hut is chosen: the tarp\'s size is not drawn.');
    $this->assertSame(30, $container['covering']['depth']['#default_value'] ?? NULL, 'The thatch\'s depth, under the hut.');
    $this->assertContains('covering', $container['kind'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another kind redraws the covering.');
    $this->assertContains('covering', $container['roof'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? [], 'Choosing another roof redraws it.');
  }

  /**
   * Tests the tool lists the covering, and refuses it under the tarp.
   */
  public function testTheToolListsTheNestedVariants(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.shelter:configure')->getInputDefinition(OperationInputs::VALUES);
    $this->assertSame(self::SHELTER_KEYS, array_keys($values->getPropertyDefinitions()));
    $tool = $manager->createInstance('surface:resource_test.shelter:configure');
    $covered = ['kind' => 'tarp', 'roof' => NULL, 'size' => 4, 'covering' => ['depth' => 3]];
    $tool->setInputValue(OperationInputs::VALUES, $covered + self::SHELTER);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/covering: Covering does not apply when Kind is tarp (Tarp).", strip_tags((string) $tool->getResult()->getMessage()));
    $tool = $manager->createInstance('surface:resource_test.shelter:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['roof' => 'slate', 'covering' => ['tiles' => 400]] + self::SHELTER);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), strip_tags((string) $tool->getResult()->getMessage()));
  }

  /**
   * Tests the shelter's build is kept, and read back equal from the cache.
   */
  public function testTheShelterReadsBackFromTheCache(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $emitted = static fn (SurfaceInterface $built): string => (string) json_encode([
      $emitter->emitFull($built, 'resource_test.shelter', 'configure')->document,
      $emitter->emit($built, $built->getDefaultValues(), 'resource_test.shelter', 'configure')->document,
    ]);
    $context = $resources->operation(ShelterResource::class, 'configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared(ShelterResource::class, $context);
    $document = $emitted($fresh);
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared(ShelterResource::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);
    $this->assertNotSame($fresh, $woken);
    $this->assertSame($fresh->getDefinitions()->names(), $woken->getDefinitions()->names());
    $this->assertSame($document, $emitted($woken));
  }

  /**
   * Tests one variant mixed into two owners holds the same variants in both.
   *
   * The camp mixes in the hut the shelter does; its covering is the hut's
   * thatch and slate there too, the same structures by the same ids.
   */
  public function testOneVariantMixedIntoTwoOwners(): void {
    $resources = $this->container->get('surface.resources');
    $camp = $resources->build(CampStructure::class, new OperationContext('configure'));
    $this->assertSame(['site', 'roof', 'covering'], $camp->getDefinitions()->names());
    $ids = static fn (SurfaceInterface $built): array => array_values(array_map(static fn ($variant): ?string => $variant->id, $built->getDefinitions()->entry('covering')->variants->variants));
    $this->assertSame($ids($this->shelter()), $ids($camp));
    $accepted = $this->pipeline()->accept($camp, ['site' => 'hut', 'roof' => 'slate', 'covering' => ['tiles' => 2]], []);
    $this->assertSame([], iterator_to_array($this->pipeline()->validate($camp, $accepted, [])));
  }

  /**
   * Builds the beacon in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built data surface.
   */
  protected function beacon(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(BeaconResource::class, $resources->operation(BeaconResource::class, 'configure'));
  }

  /**
   * Emits the beacon's schema, decoded.
   *
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function beaconSchema(?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL
      ? $emitter->emitFull($this->beacon(), 'resource_test.beacon', 'configure')
      : $emitter->emit($this->beacon(), $values, 'resource_test.beacon', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Submits values to the beacon as a dry run, answering the messages by path.
   *
   * @param array $input
   *   The input.
   *
   * @return array<string, string>
   *   The refusals, by dotted path, a value its key cannot hold among them.
   */
  protected function beaconRefusals(array $input): array {
    $resources = $this->container->get('surface.resources');
    $beacon = $this->beacon();
    $storage = $resources->storage(BeaconResource::class, $resources->operation(BeaconResource::class, 'configure'), $beacon);
    $messages = [];
    foreach ($this->pipeline()->submit($beacon, $input, $storage, TRUE)->violations as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Tests members two variants declare unlike: one key, as wide as both.
   *
   * The code is a constraint apart, so the frame keeps the string and
   * drops the constraint; the color a default apart, so the frame has no
   * default; the pattern a kind apart, so the frame holds any value. Each
   * is held to the chosen variant's own definition, its label the frame's.
   */
  public function testUnlikeMembersAreHeld(): void {
    $beacon = $this->beacon();
    $map = $beacon->getDefinitions();
    $this->assertSame(self::BEACON_KEYS, $map->names());
    [$mixed] = $map->mixinVariants();
    $this->assertSame(['flash', 'steady'], $mixed->variantIds());
    $this->assertSame(['code', 'color', 'pattern'], $mixed->heldTo, 'Each declared unlike.');
    $code = $map->get('code');
    $this->assertSame('string', $code->getDataType());
    $this->assertArrayNotHasKey('Length', $code->getConstraints(), 'The constraint only one declares, left out.');
    $this->assertSame('string', $map->get('color')->getDataType());
    $this->assertNull($beacon->getDefault('color'), 'A default that differs is no default.');
    $pattern = $map->get('pattern');
    $this->assertSame('any', $pattern->getDataType(), 'A list in one, a map in the other: any value.');
    $this->assertTrue(DefinitionMetadata::isHeldWhole($pattern));
    $this->assertSame('Pattern', (string) $pattern->getLabel());

    $flash = $beacon->refine(['mode' => 'flash']);
    $this->assertSame(['max' => 5], $flash->getDefinition('code')->getConstraints()['Length']);
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $flash->getDefinition('pattern'));
    $this->assertSame('string', $flash->getDefinition('pattern')->getItemDefinition()->getDataType());
    $this->assertSame('red', DefinitionMetadata::getDefaultValue($flash->getDefinition('color')));
    $steady = $beacon->refine(['mode' => 'steady']);
    $this->assertArrayNotHasKey('Length', $steady->getDefinition('code')->getConstraints());
    $this->assertInstanceOf(MapDataDefinition::class, $steady->getDefinition('pattern'));
    $this->assertSame(['on', 'off'], array_keys($steady->getDefinition('pattern')->getPropertyDefinitions()));
    $this->assertSame('Pattern', (string) $steady->getDefinition('pattern')->getLabel(), 'The frame\'s label.');
    $this->assertSame('white', DefinitionMetadata::getDefaultValue($steady->getDefinition('color')));
    $this->assertFalse($steady->getDefinition('pattern')->isRequired(), 'Required is the rule\'s to say, and none does.');
    foreach (['code', 'color', 'pattern'] as $member) {
      $this->assertTrue(DoesNotApply::marks($beacon->refine([])->getDefinition($member)), $member . ' applies to no variant chosen.');
    }
  }

  /**
   * Tests each value is accepted by the definition its variant holds it to.
   */
  public function testEachVariantHoldsItsOwn(): void {
    $this->assertSame([], $this->beaconRefusals(['code' => 'SOS']));
    $this->assertSame(['code' => 'This value is too long. It should have 5 characters or less. This applies when Mode is Flashing.'], $this->beaconRefusals(['code' => 'LONGER']), 'The flashing beacon\'s constraint, named as a rule\'s is.');
    $steady = ['mode' => 'steady', 'pattern' => ['on' => 3, 'off' => 1]];
    $this->assertSame([], $this->beaconRefusals($steady + ['code' => 'LONGER']), 'Not the steady one\'s.');
    $this->assertSame([], $this->beaconRefusals(['pattern' => ['short', 'short', 'long']]), 'A list under the flashing beacon.');
    $off = ['mode' => 'steady', 'pattern' => ['on' => 0]];
    $this->assertSame(['pattern.on' => 'This value should be 1 or more.'], $this->beaconRefusals($off), 'A map under the steady one, its keys held.');
    $word = ['mode' => 'steady', 'pattern' => 'long'];
    $this->assertSame(['pattern' => 'This value must be of type map, string given.'], $this->beaconRefusals($word), 'A word where the steady beacon holds a map.');
    $none = ['mode' => NULL, 'pattern' => ['long']];
    $this->assertSame([
      'mode' => 'Mode is required.',
      'pattern' => 'Pattern does not apply when Mode is empty.',
    ], $this->beaconRefusals($none), 'Nothing chosen.');
    // What the flashing beacon left stored, a list, under the steady one:
    // read by the map it is held to, which it does not fill.
    $this->assertSame(['pattern.on' => 'On is required.'], $this->beaconRefusals(['mode' => 'steady']));
    $listed = ['mode' => 'steady', 'pattern' => ['long']];
    $this->assertSame(['pattern.0' => 'Unknown key pattern.0.'], $this->beaconRefusals($listed), 'A list sent where the steady beacon holds a map.');

    // The chosen variant's default is laid in where nothing is stored.
    $accepted = $this->pipeline()->accept($this->beacon(), ['title' => 'Fresh', 'mode' => 'steady'], []);
    $this->assertSame([
      'title' => 'Fresh',
      'mode' => 'steady',
      'code' => NULL,
      'color' => 'white',
      'pattern' => NULL,
      'notes' => NULL,
    ], $accepted);
    $accepted = $this->pipeline()->accept($this->beacon(), ['title' => 'Fresh', 'mode' => 'flash'], []);
    $this->assertSame('red', $accepted['color']);

    // A write stores each shape flat beside the mode.
    $resources = $this->container->get('surface.resources');
    $beacon = $this->beacon();
    $result = $this->pipeline()->submit($beacon, $steady, $resources->storage(BeaconResource::class, $resources->operation(BeaconResource::class, 'configure'), $beacon));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('surface_resource_test.beacon')->getRawData();
    $this->assertSame(['steady', ['on' => 3, 'off' => 1]], [$stored['mode'], $stored['pattern']]);
    $this->assertStaticRefinersAgree($beacon);
  }

  /**
   * Tests the full document: the frame lists no member, each def strict.
   */
  public function testTheFullDocumentHoldsEachDefToItsOwn(): void {
    $full = $this->beaconSchema();
    $this->assertSame(['title', 'mode', 'notes'], array_keys($full['properties']), 'A member declared unlike is stated by each def, and nowhere else.');
    $this->assertSame([
      'flash' => '#/$defs/resource_test.beacon.flash',
      'steady' => '#/$defs/resource_test.beacon.steady',
    ], $full['x-surface']['variantsMixedIn']['mode']);
    $flash = $full['$defs']['resource_test.beacon.flash']['properties'];
    $steady = $full['$defs']['resource_test.beacon.steady']['properties'];
    $this->assertSame(5, $flash['code']['maxLength']);
    $this->assertArrayNotHasKey('maxLength', $steady['code']);
    $this->assertSame(['red', 'white'], [$flash['color']['default'], $steady['color']['default']]);
    $this->assertSame(['type' => 'string'], $flash['pattern']['items']);
    $this->assertSame(['on', 'off'], array_keys($steady['pattern']['properties']));

    $beacon = self::BEACON;
    $this->assertTrue($this->validates($full, $beacon));
    $this->assertFalse($this->validates($full, ['code' => 'LONGER'] + $beacon), 'The flashing beacon\'s def holds its code.');
    $this->assertFalse($this->validates($full, ['pattern' => ['on' => 3]] + $beacon), 'A map under the flashing beacon.');
    $steady_beacon = ['mode' => 'steady', 'code' => 'LONGER', 'pattern' => ['on' => 3, 'off' => 1]] + $beacon;
    $this->assertTrue($this->validates($full, $steady_beacon));
    $this->assertFalse($this->validates($full, ['pattern' => ['long']] + $steady_beacon), 'A list under the steady one.');
    $this->assertFalse($this->validates($full, ['pattern' => ['on' => 0]] + $steady_beacon), 'Its keys held.');
    $this->assertFalse($this->validates($full, ['mode' => NULL] + $beacon), 'Nothing chosen: no conditional evaluates a member.');
  }

  /**
   * Tests the inline reading: the wide member in the frame, each def's held.
   */
  public function testInlineHoldsEachMemberToItsDef(): void {
    $inline = SchemaResolver::inline($this->beaconSchema());
    $this->assertSame(self::BEACON_KEYS, array_keys($inline['properties']));
    $this->assertSame(['title' => 'Pattern'], $inline['properties']['pattern'], 'Open: what both defs say alike, which is nothing.');
    $this->assertSame(['title' => 'Code', 'type' => ['string', 'null']], $inline['properties']['code'], 'A string, the length only one says left out.');
    $this->assertArrayNotHasKey('default', $inline['properties']['color'], 'A default only each says.');
    $held = array_values(array_filter($inline['allOf'], static fn (array $rule): bool => isset($rule['then']['properties']['pattern']) && $rule['then']['properties']['pattern'] !== FALSE));
    $this->assertSame([
      ['properties' => ['mode' => ['const' => 'flash']], 'required' => ['mode']],
      ['properties' => ['mode' => ['const' => 'steady']], 'required' => ['mode']],
    ], array_column($held, 'if'), 'One conditional per def, on the value that chooses it.');
    $this->assertSame(['type' => ['array', 'null'], 'items' => ['type' => 'string']], $held[0]['then']['properties']['pattern']);
    $this->assertSame(SchemaResolver::inline($inline), $inline, 'Read flat twice, the same.');
    $beacon = self::BEACON;
    $this->assertTrue($this->validates($inline, $beacon));
    $this->assertFalse($this->validates($inline, ['code' => 'LONGER'] + $beacon));
    $this->assertFalse($this->validates($inline, ['pattern' => ['on' => 3]] + $beacon));
    $steady_beacon = ['mode' => 'steady', 'code' => 'LONGER', 'pattern' => ['on' => 3, 'off' => 1]] + $beacon;
    $this->assertTrue($this->validates($inline, $steady_beacon));
    $this->assertFalse($this->validates($inline, ['pattern' => ['long']] + $steady_beacon));
  }

  /**
   * Tests the beacon's full schema reads back to itself.
   */
  public function testTheBeaconReadsBack(): void {
    $full = $this->beaconSchema();
    $read = $this->container->get('surface.schema_reader')->structure($full, 'resource_test.beacon', 'surface_resource_test');
    $built = $this->container->get('surface.resources')->buildStatic($read, new OperationContext('configure'));
    $this->assertSame(self::BEACON_KEYS, $built->getDefinitions()->names());
    $this->assertSame(['code', 'color', 'pattern'], $built->getDefinitions()->mixinVariants()[0]->heldTo);
    $again = $this->container->get('surface.contract_emitter')->emitFull($built, 'resource_test.beacon', 'configure')->document['schema'];
    $this->assertSame(json_encode($full), json_encode($again));
  }

  /**
   * Tests the contract diff judges a variant removed or added.
   *
   * The frame lists no member declared unlike, so nothing of the frame
   * moves: the deciding key's choice and the def judge it, as for any
   * variant mixed in.
   */
  public function testTheDiffJudgesVariantHeldApart(): void {
    $baseline = ['schema' => $this->beaconSchema()];
    $removed = $baseline;
    $schema = &$removed['schema'];
    unset($schema['$defs']['resource_test.beacon.steady'], $schema['x-surface']['variantsMixedIn']['mode']['steady']);
    $schema['allOf'] = array_values(array_filter($schema['allOf'], static fn (array $branch): bool => ($branch['if']['properties']['mode']['const'] ?? NULL) !== 'steady'));
    $schema['properties']['mode']['oneOf'] = array_values(array_filter($schema['properties']['mode']['oneOf'], static fn (array $choice): bool => $choice['const'] !== 'steady'));
    unset($schema);
    $judged = static fn (array $from, array $to): array => array_map(static fn (ContractChange $change): string => $change->status . ' ' . $change->change, (new ContractDiff())->compare($from, $to));
    $this->assertSame([
      'compatible variant mixed in removed',
      'breaking choice "steady" removed',
      'compatible rule removed',
      'compatible def no longer used',
    ], $judged($baseline, $removed));
    $this->assertSame([
      'compatible variant mixed in added',
      'compatible choice "steady" added',
      'compatible rule added for a value the baseline did not allow',
      'compatible def added',
    ], $judged($removed, $baseline));
  }

  /**
   * Tests the form draws the chosen variant's definition of each member.
   */
  public function testTheFormDrawsTheHeldDefinition(): void {
    $drawn = function (): array {
      $route = new Route('/beacon', [
        '_form' => SurfaceOperationForm::class,
        OperationRoute::RESOURCE => 'resource_test.beacon',
        OperationRoute::OPERATION => 'configure',
      ]);
      $stack = $this->container->get('request_stack');
      $request = Request::create('/beacon', 'POST');
      $request->setSession($stack->getSession());
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.beacon');
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
      $stack->push($request);
      $state = new FormState();
      return $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
    };
    $flash = $drawn();
    $this->assertSame('fieldset', $flash['pattern']['#type'] ?? NULL, 'The flashing beacon\'s list.');
    $this->config('surface_resource_test.beacon')->set('mode', 'steady')->set('pattern', ['on' => 2, 'off' => 1])->set('color', NULL)->save();
    $steady = $drawn();
    $this->assertSame(2, $steady['pattern']['on']['#default_value'] ?? NULL, 'The steady beacon\'s map, its keys drawn.');
    $this->assertSame('white', $steady['color']['#default_value'] ?? NULL, 'Its default, where nothing is stored.');
  }

  /**
   * Tests the tool lists each member as the frame declares it.
   */
  public function testTheToolListsTheWideMembers(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.beacon:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::BEACON_KEYS, array_keys($properties));
    $this->assertSame('any', $properties['pattern']->getDataType());
    $this->assertSame('Depends on mode.', (string) $properties['pattern']->getDescription(), 'Its shape too, which the open value does not say.');
    $tool = $manager->createInstance('surface:resource_test.beacon:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['mode' => 'steady', 'pattern' => 'long'] + self::BEACON);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/pattern: This value must be of type map, string given.", strip_tags((string) $tool->getResult()->getMessage()), 'Read by the map the steady beacon holds it to.');
  }

  /**
   * Tests the beacon's build is kept, and read back equal from the cache.
   */
  public function testTheBeaconReadsBackFromTheCache(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $emitted = static fn (SurfaceInterface $built): string => (string) json_encode([
      $emitter->emitFull($built, 'resource_test.beacon', 'configure')->document,
      $emitter->emit($built, $built->getDefaultValues(), 'resource_test.beacon', 'configure')->document,
    ]);
    $context = $resources->operation(BeaconResource::class, 'configure');
    $this->container->get('cache.surface_build')->deleteAll();
    $resources->forgetBuilds();
    $fresh = $resources->buildDeclared(BeaconResource::class, $context);
    $document = $emitted($fresh);
    $cached = $resources->buildCounts()['cached'];
    $resources->forgetBuilds();
    (new \ReflectionProperty(ResourceRegistry::class, 'read'))->setValue($registry, []);
    $woken = $resources->buildDeclared(BeaconResource::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);
    $this->assertNotSame($fresh, $woken);
    $this->assertSame($fresh->getDefinitions()->mixinVariants()[0]->heldTo, $woken->getDefinitions()->mixinVariants()[0]->heldTo);
    $this->assertSame($document, $emitted($woken));
    $steady = ['mode' => 'steady', 'pattern' => ['on' => 2]];
    $this->assertSame($this->pipeline()->accept($fresh, $steady, []), $this->pipeline()->accept($woken, $steady, []));
  }

  /**
   * Tests the same two variants mixed into a second owner are held there too.
   */
  public function testTwoOwnersHoldTheSameVariants(): void {
    $resources = $this->container->get('surface.resources');
    $post = $resources->build(BeaconPostStructure::class, new OperationContext('configure'));
    $this->assertSame(['signal', 'code', 'color', 'pattern'], $post->getDefinitions()->names());
    $this->assertSame(['code', 'color', 'pattern'], $post->getDefinitions()->mixinVariants()[0]->heldTo);
    $judge = function (array $input) use ($post): array {
      $messages = [];
      foreach ($this->pipeline()->validate($post, $this->pipeline()->accept($post, $input, []), []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame([], $judge(['signal' => 'steady', 'pattern' => ['on' => 2]]));
    $this->assertSame([], $judge(['signal' => 'flash', 'pattern' => ['long']]));
    $long = 'This value is too long. It should have 5 characters or less. This applies when Signal is flash.';
    $this->assertSame(['code' => $long], $judge(['signal' => 'flash', 'code' => 'LONGER']));
  }

  /**
   * Tests the pairs step 2 refused now build, each member held apart.
   *
   * A size an integer in one and a word in the other; a capacity a
   * constraint apart; a belt two children of different structures, which
   * is no child of the owner's: a value of any shape, held to each
   * variant's child as its map.
   */
  public function testUnlikePairsOnceRefusedBuild(): void {
    $resources = $this->container->get('surface.resources');
    $kit = $resources->build(KitClashStructure::class, new OperationContext('configure'));
    $this->assertSame(['size'], $kit->getDefinitions()->mixinVariants()[0]->heldTo);
    $this->assertSame('any', $kit->getDefinition('size')->getDataType());
    $this->assertSame('integer', $kit->refine(['kind' => 'count'])->getDefinition('size')->getDataType());
    $this->assertSame('string', $kit->refine(['kind' => 'word'])->getDefinition('size')->getDataType());
    $range = $resources->build(PackRangeClashStructure::class, new OperationContext('configure'));
    $this->assertSame(['PrimitiveType'], array_keys($range->getDefinition('capacity')->getConstraints()), 'The frame: an integer, either bound left out.');
    $belt = $resources->build(PackChildClashStructure::class, new OperationContext('configure'));
    $this->assertNull($belt->getDefinitions()->entry('belt')->attachment, 'No child of the owner\'s.');
    $this->assertSame(['padded', 'width'], array_keys($belt->refine(['kind' => 'belted'])->getDefinition('belt')->getPropertyDefinitions()));
    $this->assertSame(['name'], array_keys($belt->refine(['kind' => 'pocketed'])->getDefinition('belt')->getPropertyDefinitions()));
    $accepted = $this->pipeline()->accept($belt, ['kind' => 'pocketed', 'belt' => ['name' => 'Hip']], []);
    $this->assertSame([], iterator_to_array($this->pipeline()->validate($belt, $accepted, [])));
  }

}
