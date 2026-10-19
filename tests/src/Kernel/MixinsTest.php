<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\Journal\JournalResource;
use Drupal\data_surface_resource_test\Resource\Journal\LedgerResource;
use Drupal\data_surface_resource_test\Structure\Broken\MixesMountingStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinByAlterStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinClashLateStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinClashStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinCycleStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinGuardStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinResourceStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinRulesOutStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinUnwiredStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinWiresMemberStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MixinWithChildStructure;
use Drupal\data_surface_resource_test\Structure\Broken\TwoMixinsClashStructure;
use Drupal\data_surface_resource_test\Structure\Mixin\EntryStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\data_surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Mixins: a structure's members merged into its owner's keys, flat.
 *
 * The journal mixes in the ownership (whose port reads the journal's
 * visibility, and whose access class locks or omits its members) and the
 * timestamps (whose refiner and rule relate its two members), and its
 * own rule reads a member the timestamps brought. Its archive is
 * attached, and mixes the ownership in itself; the ledger attaches the
 * timestamps, the other composition of the same structure.
 *
 * @see docs/resources.md#mixins
 * @see docs/decisions.md#a-mixin-is-merged-flat
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class MixinsTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_resource_test',
  ];

  /**
   * The journal's keys, the members of what it mixes in among them.
   */
  protected const KEYS = [
    'title',
    'visibility',
    'owner',
    'shared',
    'created',
    'changed',
    'revision',
    'published',
    'archive',
  ];

  /**
   * The journal as installed.
   */
  protected const JOURNAL = [
    'title' => 'Field notes',
    'visibility' => 'staff',
    'owner' => 'ada',
    'shared' => FALSE,
    'created' => 1700000000,
    'changed' => 1700000500,
    'revision' => 3,
    'published' => TRUE,
    'archive' => [
      'shelf' => 'B2',
      'circle' => 'staff',
      'owner' => 'grace',
      'shared' => TRUE,
      'stamps' => ['created' => 1690000000, 'changed' => 1690000000],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Builds a fixture in its one operation.
   *
   * @param class-string $class
   *   The resource or structure.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built data surface.
   */
  protected function built(string $class): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    $context = in_array($class, [JournalResource::class, LedgerResource::class], TRUE) ? $resources->operation($class, 'configure') : new OperationContext('configure');
    return $resources->build($class, $context);
  }

  /**
   * Emits a fixture's schema, decoded.
   *
   * @param class-string $class
   *   The resource.
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function schema(string $class, ?array $values = NULL): array {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $built = $this->built($class);
    $id = $class === JournalResource::class ? 'resource_test.journal' : 'resource_test.ledger';
    $served = $values === NULL
      ? $emitter->emitFull($built, $id, 'configure')
      : $emitter->emit($built, $values, $id, 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Accepts and validates values, answering the messages by path.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $input
   *   The input.
   * @param array $stored
   *   What is stored.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(DataSurfaceInterface $surface, array $input, array $stored = self::JOURNAL): array {
    $accepted = $this->pipeline()->accept($surface, $input, $stored);
    $messages = [];
    foreach ($this->pipeline()->validate($surface, $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Reads one refined definition at a dotted path.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The refined data surface.
   * @param string $path
   *   The path.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition.
   */
  protected static function at(DataSurfaceInterface $surface, string $path): ?DataDefinitionInterface {
    $parents = explode('.', $path);
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = $definition?->getPropertyDefinition($property);
    }
    return $definition;
  }

  /**
   * Tests the members are the owner's keys, where with() stands, stored flat.
   */
  public function testMembersAreTheOwnersKeys(): void {
    $journal = $this->built(JournalResource::class);
    $map = $journal->getDefinitions();
    $this->assertSame(self::KEYS, $map->names());
    $this->assertSame([], $map->ports(), 'A mixed-in structure\'s port is no port of its owner\'s.');
    $mixins = $map->mixins();
    $this->assertCount(2, $mixins);
    $this->assertSame('resource_test.ownership', $mixins[0]->structure->id);
    $this->assertSame(['owner', 'shared'], $mixins[0]->keys);
    $this->assertSame(['audience' => 'visibility'], $mixins[0]->reads);
    $this->assertSame('resource_test.timestamps', $mixins[1]->structure->id);
    $this->assertSame(['created', 'changed'], $mixins[1]->keys);
    $this->assertSame([], $mixins[1]->reads);
    $this->assertSame(['owner', 'shared'], $mixins[0]->structure->child->getDefinitions()->names(), 'Built by itself, for its def.');
    $this->assertSame([
      'owner' => ['visibility'],
      'shared' => ['visibility'],
      'created' => ['published'],
      'changed' => ['created'],
      'revision' => ['created'],
    ], $map->refinementPaths(), 'Each port read at the owner\'s key it is wired to; the owner\'s rules read and narrow members.');
    $this->assertSame(['shelf', 'circle', 'owner', 'shared', 'stamps'], $map->entry('archive')->attachment->child->getDefinitions()->names(), 'The archive mixes the ownership in, in its own frame.');

    $this->assertSame([
      'title' => NULL,
      'visibility' => 'staff',
      'owner' => NULL,
      'shared' => FALSE,
      'created' => NULL,
      'changed' => NULL,
      'revision' => 1,
      'published' => FALSE,
      'archive' => NULL,
    ], $this->container->get('data_surface.resources')->defaults(JournalResource::class), 'The members\' declared defaults are the owner\'s.');
    $this->assertSame('Seconds since the epoch.', (string) $journal->getDefinition('created')->getDescription(), 'The timestamps\' alter applies where they are mixed in.');
    $this->assertSame('Seconds since the epoch.', (string) self::at($this->built(LedgerResource::class), 'stamps.created')->getDescription(), 'And where they are attached.');

    // Stored flat: the members beside the owner's own keys.
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(JournalResource::class, 'configure');
    $storage = $resources->storage(JournalResource::class, $context, $journal);
    $this->assertSame(self::JOURNAL, array_intersect_key($storage->load($journal), self::JOURNAL));
    $result = $this->pipeline()->submit($journal, ['owner' => 'bea', 'created' => 1700000100], $storage);
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('data_surface_resource_test.journal')->get();
    $this->assertSame(['bea', 1700000100, 1700000500], [$stored['owner'], $stored['created'], $stored['changed']]);
  }

  /**
   * Tests each mixin the build cannot honour is refused, naming it.
   */
  public function testEachRefusal(): void {
    $refusals = [
      MixinClashStructure::class => 'resource_test.timestamps, mixed into resource_test.broken.mixin_clash, declares "created", which resource_test.broken.mixin_clash declares too. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the structure under a key of its own.',
      MixinClashLateStructure::class => 'resource_test.broken.mixin_clash_late declares "changed", which resource_test.timestamps, mixed in, declares already. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the structure under a key of its own.',
      TwoMixinsClashStructure::class => 'resource_test.broken.created_only, mixed into resource_test.broken.two_mixins_clash, declares "created", which resource_test.timestamps, also mixed in, declares too. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once.',
      MixinByAlterStructure::class => 'The data_surface_resource_test alter mixes Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure in. Only an owner mixes a structure in: an alter\'s keys live under its own module\'s name, and a mixed-in structure\'s members would be the owner\'s. Attach it at the alter\'s own mount, third_party_settings.data_surface_resource_test, instead.',
      MixinWithChildStructure::class => 'resource_test.notice, mixed into resource_test.broken.mixin_with_child, attaches Drupal\data_surface_resource_test\Structure\Notice\NoticeAddressStructure at "address". A mixed-in structure holds keys and maps, not children, for now: attach resource_test.notice under a key of its own instead.',
      MixinCycleStructure::class => 'resource_test.broken.mixin_cycle_loop mixes resource_test.broken.mixin_cycle in, through resource_test.broken.mixin_cycle -> resource_test.broken.mixin_cycle_loop -> resource_test.broken.mixin_cycle: a structure cannot be mixed into itself, directly or through another.',
      MixinUnwiredStructure::class => 'resource_test.broken.mixin_unwired mixes resource_test.ownership in without wiring its "audience" port: with(..., reads: [\'audience\' => \'<key>\']) names the key of resource_test.broken.mixin_unwired it reads. A mixed-in structure\'s refiners read each port as the owner\'s key it is wired to, so every port is wired, required or not.',
      MixinWiresMemberStructure::class => 'resource_test.broken.mixin_wires_member wires the "audience" port of resource_test.ownership, mixed in, to "owner", which resource_test.ownership brought itself: a port reads a key of the owner it is mixed into, and its members are its own.',
      MixinGuardStructure::class => 'Drupal\data_surface_resource_test\Structure\Broken\MixinGuardStructure::noteOfTheOwner() names "owner", a member of resource_test.ownership, which is mixed into resource_test.broken.mixin_guard and has an access class. What that access may take away for one account, nothing of the owner\'s may depend on: read it inside resource_test.ownership, or drop the access class.',
      MixinRulesOutStructure::class => 'narrow("changed") in resource_test.broken.mixin_rules_out may answer that "changed", a member of resource_test.timestamps mixed into resource_test.broken.mixin_rules_out, does not apply. Whether a mixed-in structure\'s member applies is the structure\'s to say: its def, which every owner shares, says where it is required.',
      MixinResourceStructure::class => 'resource_test.broken.mixin_resource mixes resource_test.ledger in, which names storage of its own. A mixed-in structure\'s members are the owner\'s keys, stored by the owner: mix in a structure, or attach a resource under a key of its own.',
      MixesMountingStructure::class => 'The data_surface_resource_test alter of resource_test.broken.mounting_mixin adds "color", and resource_test.broken.mounting_mixin is mixed into resource_test.broken.mixes_mounting. An alter of a mixed-in structure narrows and rewords its members where it is mixed in; what it mounts would be mounted on resource_test.broken.mixes_mounting, which is not built.',
    ];
    $resources = $this->container->get('data_surface.resources');
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
   * Tests the members refine in the owner's frame, their ports its keys.
   */
  public function testRefinementInTheOwnersFrame(): void {
    $journal = $this->built(JournalResource::class);
    // The ownership's coded refiner reads its port at the visibility.
    $this->assertTrue(DoesNotApply::marks($journal->refine(['visibility' => 'public'])->getDefinition('shared')));
    $this->assertFalse(DoesNotApply::marks($journal->refine(['visibility' => 'staff'])->getDefinition('shared')));
    // Its declared rule reads the port at the visibility too.
    $this->assertSame(32, $journal->refine(['visibility' => 'staff'])->getDefinition('owner')->getConstraints()['Length']['max']);
    $this->assertArrayNotHasKey('Length', $journal->refine(['visibility' => 'public'])->getDefinition('owner')->getConstraints());
    // The timestamps relate their own members.
    $this->assertSame(1700000000, $journal->refine(['created' => 1700000000])->getDefinition('changed')->getConstraints()['Range']['min']);
    $this->assertTrue($journal->refine(['created' => 5])->getDefinition('changed')->isRequired());
    $this->assertFalse($journal->refine([])->getDefinition('changed')->isRequired());
    // The owner's rule reads a member, as one of its own keys.
    $this->assertSame(1, $journal->refine(['created' => NULL])->getDefinition('revision')->getConstraints()['Range']['max']);
    $this->assertSame(1, $journal->refine([])->getDefinition('revision')->getConstraints()['Range']['max'] ?? NULL);
    $this->assertArrayNotHasKey('max', $journal->refine(['created' => 5])->getDefinition('revision')->getConstraints()['Range']);
    // The owner's rule narrows a member, as one of its own keys.
    $this->assertTrue($journal->refine(['published' => TRUE])->getDefinition('created')->isRequired());
    $this->assertFalse($journal->refine(['published' => FALSE])->getDefinition('created')->isRequired());

    $this->assertSame([], $this->refusals($journal, []));
    $this->assertSame(
      ['changed' => 'This value should be 1700000000 or more when Created is 1700000000.'],
      $this->refusals($journal, ['changed' => 1600000000]),
      'Changed before created, refused in the owner\'s frame, naming the member it depends on.',
    );
    $this->assertSame(
      ['owner' => 'This value is too long. It should have 32 characters or less when Visibility is Staff.'],
      $this->refusals($journal, ['owner' => str_repeat('a', 40)]),
      'A refusal the port caused names the owner\'s key it reads.',
    );
    $this->assertSame([], $this->refusals($journal, ['visibility' => 'public', 'owner' => str_repeat('a', 40)]));
    $this->assertSame(
      ['shared' => 'Shared with editors does not apply when Visibility is Public.'],
      $this->refusals($journal, ['visibility' => 'public', 'shared' => TRUE]),
    );
    $this->assertSame(
      ['changed' => 'Changed is required.'],
      $this->refusals($journal, ['changed' => NULL]),
      'Once the created time is set, the changed time is required.',
    );
    $this->assertSame(
      ['revision' => 'This value should be between 1 and 1 when Created is empty.'],
      $this->refusals($journal, ['published' => FALSE, 'created' => NULL, 'changed' => NULL]),
      'The owner\'s rule on a member: never created, the first revision.',
    );
    $this->assertSame(
      [
        'created' => 'Created is required.',
        'revision' => 'This value should be between 1 and 1 when Created is empty.',
      ],
      $this->refusals($journal, ['created' => NULL, 'changed' => NULL]),
      'The owner\'s rules on a member: a published journal says when it was created, and one never created is on its first revision.',
    );
    // Inside the archive the ownership reads the archive's circle.
    $this->assertTrue(DoesNotApply::marks(self::at($journal->refine(['archive' => ['circle' => 'public']]), 'archive.shared')));
  }

  /**
   * Tests the mixin's access locks or omits its members, the owner's the rest.
   *
   * The two answers compose as any two do: the stricter stands. The
   * ownership locked inside an archive its own access left open is locked;
   * open inside an archive locked, locked with it; locked inside an
   * archive omitted, gone with it.
   */
  public function testAccessLocksOrOmitsTheMembers(): void {
    $this->setUpCurrentUser([], ['administer site configuration', 'inspect ownership']);
    $journal = $this->built(JournalResource::class);
    $map = $journal->getDefinitions();
    $this->assertTrue($map->isLocked('owner') && $map->isHeld('owner'));
    $this->assertTrue($map->isLocked('shared') && $map->isHeld('shared'));
    $this->assertFalse($map->isLocked('created'));
    $accepted = $this->pipeline()->accept($journal, ['owner' => 'mallory', 'title' => 'Renamed'], self::JOURNAL);
    $this->assertSame(['ada', 'Renamed'], [$accepted['owner'], $accepted['title']], 'A posted member is ignored; it holds what is stored.');
    $full = $this->schema(JournalResource::class);
    $this->assertTrue($full['properties']['owner']['readOnly']);
    $this->assertArrayNotHasKey('const', $full['properties']['owner'], 'Nothing ahead of time knows what is stored.');
    $these = $this->schema(JournalResource::class, self::JOURNAL);
    $this->assertSame('ada', $these['properties']['owner']['const'], 'What is stored, for these values.');
    $this->assertTrue($this->validates($these, self::JOURNAL));
    $this->assertTrue($this->validates($full, self::JOURNAL));

    $this->setUpCurrentUser([], ['administer site configuration', 'hide ownership']);
    $journal = $this->built(JournalResource::class);
    $this->assertSame(['title', 'visibility', 'created', 'changed', 'revision', 'published', 'archive'], $journal->getDefinitions()->names());
    $this->assertSame(['resource_test.timestamps'], array_map(static fn ($mixin) => $mixin->structure->id, $journal->getDefinitions()->mixins()));
    $this->assertSame(['shelf', 'circle', 'stamps'], $journal->getDefinitions()->entry('archive')->attachment->child->getDefinitions()->names());
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(JournalResource::class, 'configure');
    $result = $this->pipeline()->submit($journal, ['title' => 'Kept apart'], $resources->storage(JournalResource::class, $context, $journal));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $stored = $this->config('data_surface_resource_test.journal')->get();
    $kept = [$stored['title'], $stored['owner'], $stored['shared'], $stored['archive']['owner']];
    $this->assertSame(['Kept apart', 'ada', FALSE, 'grace'], $kept, 'What the access took away, storage keeps.');
    $full = $this->schema(JournalResource::class);
    $this->assertArrayNotHasKey('owner', $full['properties']);
    $this->assertArrayNotHasKey('resource_test.ownership', $full['$defs'], 'Omitted: no def, no reference.');

    // The stricter of the archive's answer and the ownership's.
    $this->setUpCurrentUser([], ['administer site configuration', 'inspect archive']);
    $archive = $this->built(JournalResource::class)->getDefinitions()->entry('archive');
    $this->assertTrue($archive->locked, 'The archive locked: everything in it, the ownership\'s members with it.');
    $this->setUpCurrentUser([], ['administer site configuration', 'hide archive', 'inspect ownership']);
    $this->assertNull($this->built(JournalResource::class)->getDefinitions()->entry('archive'), 'The archive omitted: the ownership\'s lock inside it is gone with it.');
    $this->assertTrue($this->built(JournalResource::class)->getDefinitions()->isHeld('owner'), 'At the top, the ownership still answers for its own members.');
  }

  /**
   * Tests both documents: the def composed, members kept in place.
   */
  public function testTheEmitterComposesTheDefs(): void {
    $full = $this->schema(JournalResource::class);
    $this->assertSame(['$schema', 'type', 'properties', 'unevaluatedProperties', 'required', 'allOf', '$defs'], array_keys($full));
    $this->assertSame(['resource_test.timestamps', 'resource_test.ownership', 'resource_test.archive'], array_keys($full['$defs']), 'The archive\'s stamps first, then what it mixes in.');
    $this->assertSame(self::KEYS, array_keys($full['properties']), 'Every member keeps its place among the owner\'s keys.');
    $this->assertTrue($full['properties']['changed'], 'The def says all there is.');
    $this->assertSame(['x-surface' => ['locked' => FALSE, 'dependsOn' => ['published'], 'declared' => TRUE]], $full['properties']['created'], 'The owner narrows it: said beside the def.');
    $this->assertFalse($full['unevaluatedProperties']);
    $this->assertArrayNotHasKey('additionalProperties', $full);
    $this->assertSame(['title', 'visibility'], $full['required'], 'What the defs require, they say.');
    $this->assertSame(['$ref' => '#/$defs/resource_test.ownership'], $full['allOf'][0]);
    $this->assertSame(['$ref' => '#/$defs/resource_test.timestamps'], $full['allOf'][1]);
    // The defs are open: an owner composes them with keys of its own.
    $this->assertArrayNotHasKey('additionalProperties', $full['$defs']['resource_test.timestamps']);
    $this->assertArrayNotHasKey('additionalProperties', $full['$defs']['resource_test.ownership']);
    // The archive's own stamps refer to the same def, closed at the site.
    $archive = $full['$defs']['resource_test.archive'];
    $stamps = array_intersect_key($archive['properties']['stamps'], array_flip(['$ref', 'unevaluatedProperties']));
    $this->assertSame(['$ref' => '#/$defs/resource_test.timestamps', 'unevaluatedProperties' => FALSE], $stamps);
    $this->assertSame(['$ref' => '#/$defs/resource_test.ownership'], $archive['allOf'][0]);

    // Nested by the ledger: one def, closed, the keys under its key.
    $ledger = $this->schema(LedgerResource::class);
    $def = $ledger['$defs']['resource_test.timestamps'];
    $this->assertFalse($def['additionalProperties']);
    unset($def['additionalProperties']);
    $this->assertSame($full['$defs']['resource_test.timestamps'], $def, 'The same def, open where it is mixed in.');

    $expected = json_decode(<<<'JSON'
      {
        "resource_test.timestamps": {
          "type": "object",
          "properties": {
            "created": {"title": "Created", "description": "Seconds since the epoch.", "type": ["integer", "null"], "minimum": 0,
                        "x-surface": {"locked": false, "dependsOn": []}},
            "changed": {"title": "Changed", "type": ["integer", "null"], "minimum": 0,
                        "x-surface": {"locked": false, "dependsOn": ["created"], "dynamic": true, "declared": true}}
          },
          "allOf": [
            {"if": {"properties": {"created": {"not": {"type": "null"}}}, "required": ["created"]},
             "then": {"properties": {"changed": {"description": "Required once the created time is set.", "type": "integer"}}, "required": ["changed"]},
             "x-surface": {"declared": true}}
          ]
        },
        "resource_test.ownership": {
          "type": "object",
          "properties": {
            "owner": {"title": "Owner", "type": "string", "x-surface": {"locked": false, "dependsOn": ["audience"]}},
            "shared": {"title": "Shared with editors", "type": ["boolean", "null"], "default": false,
                       "x-surface": {"locked": false, "dependsOn": ["audience"]}}
          },
          "required": ["owner"],
          "x-surface": {"ports": {"audience": {"type": "string", "required": true}}}
        }
      }
      JSON, TRUE);
    $this->assertSame($expected['resource_test.timestamps'], $full['$defs']['resource_test.timestamps']);
    $this->assertSame($expected['resource_test.ownership'], $full['$defs']['resource_test.ownership']);
    // What reads the port is stated on the owner, at its key.
    $this->assertSame(['x-surface' => ['locked' => FALSE, 'dependsOn' => ['visibility'], 'declared' => TRUE]], $full['properties']['owner']);
    $this->assertSame(['x-surface' => ['locked' => FALSE, 'dependsOn' => ['visibility'], 'enumerated' => TRUE]], $full['properties']['shared']);
    $stated = array_slice($full['allOf'], 2);
    $this->assertContains(json_decode('{"if": {"properties": {"visibility": {"const": "public"}}, "required": ["visibility"]}, "then": {"properties": {"shared": false}}}', TRUE), $stated);
    $this->assertContains(json_decode('{"if": {"properties": {"visibility": {"const": "staff"}}, "required": ["visibility"]}, "then": {"properties": {"owner": {"description": "Up to 32 characters for a staff audience.", "maxLength": 32}}}, "x-surface": {"declared": true}}', TRUE), $stated);
    $this->assertContains(json_decode('{"if": {"properties": {"created": {"type": "null"}}}, "then": {"properties": {"revision": {"description": "A journal never created is on its first revision.", "maximum": 1}}}, "x-surface": {"declared": true}}', TRUE), $stated);
    $this->assertContains(json_decode('{"if": {"properties": {"published": {"const": true}}, "required": ["published"]}, "then": {"properties": {"created": {"description": "A published journal says when it was created.", "type": "integer"}}, "required": ["created"]}, "x-surface": {"declared": true}}', TRUE), $stated);
    $this->assertCount(6, $full['allOf'], 'Two references and four conditionals: the owner\'s two rules, and what reads the port.');

    // A standard validator holds the composed document as the pipeline.
    $this->assertTrue($this->validates($full, self::JOURNAL));
    $this->assertFalse($this->validates($full, ['fax' => '1'] + self::JOURNAL), 'An unknown key is refused: the composition is closed.');
    $this->assertFalse($this->validates($full, ['shared' => TRUE, 'visibility' => 'public'] + self::JOURNAL));
    $this->assertFalse($this->validates($full, ['changed' => NULL] + self::JOURNAL));
    $this->assertFalse($this->validates($full, ['published' => FALSE, 'created' => NULL, 'changed' => NULL] + self::JOURNAL), 'Never created, revision 3.');
    $this->assertFalse($this->validates($full, ['owner' => str_repeat('a', 40)] + self::JOURNAL));
    $public = ['owner' => str_repeat('a', 40), 'visibility' => 'public'] + self::JOURNAL;
    unset($public['shared']);
    $this->assertTrue($this->validates($full, $public), 'Public: a long owner, and no shared, which does not apply.');
    $this->assertFalse($this->validates($full, ['created' => NULL, 'changed' => NULL, 'revision' => 1] + self::JOURNAL), 'Published, never created.');
    $first = ['published' => FALSE, 'created' => NULL, 'changed' => NULL, 'revision' => 1];
    $this->assertTrue($this->validates($full, $first + self::JOURNAL), 'Unpublished, never created, the first revision.');

    // Inlined, the frame is flat: what a client without references reads.
    $inline = SchemaResolver::inline($full);
    $this->assertSame(self::KEYS, array_keys($inline['properties']));
    $this->assertFalse($inline['additionalProperties']);
    $this->assertArrayNotHasKey('unevaluatedProperties', $inline);
    $this->assertSame(['title', 'visibility', 'owner'], $inline['required'], 'The frame\'s own and the def\'s, in the frame\'s order.');
    $this->assertSame('Owner', $inline['properties']['owner']['title']);
    $this->assertSame(['visibility'], $inline['properties']['owner']['x-surface']['dependsOn']);
    $this->assertSame(['shelf', 'circle', 'owner', 'shared', 'stamps'], array_keys($inline['properties']['archive']['properties']));
    $this->assertTrue($this->validates($inline, self::JOURNAL));

    // The schema for these answers composes the same way.
    $these = $this->schema(JournalResource::class, ['visibility' => 'public'] + self::JOURNAL);
    $this->assertSame(['$ref' => '#/$defs/resource_test.ownership'], $these['allOf'][0]);
    $this->assertFalse($these['properties']['shared'], 'Not applying to these values: the def holds it, so the frame says it must not be there.');
    $this->assertSame(['shared' => ['visibility']], $these['x-surface']['doesNotApply']);
    $this->assertTrue($this->validates($these, $public));
    $this->assertFalse($this->validates($these, ['shared' => TRUE] + $public));

    $this->assertStaticRefinersAgree($this->built(JournalResource::class));
  }

  /**
   * Tests a structure that mixes another in, mixed in itself.
   */
  public function testStructureMixesAnotherIn(): void {
    $entry = $this->container->get('data_surface.resources')->build(EntryStructure::class, new OperationContext('configure'));
    $map = $entry->getDefinitions();
    $this->assertSame(['line', 'reference', 'created', 'changed'], $map->names());
    $this->assertCount(1, $map->mixins());
    $this->assertSame(['reference', 'created', 'changed'], $map->mixins()[0]->keys);
    $this->assertSame(['changed' => ['created']], $map->refinementPaths(), 'The timestamps refine in the entry\'s frame, two levels down.');
    $this->assertSame(5, $entry->refine(['created' => 5])->getDefinition('changed')->getConstraints()['Range']['min']);
    $this->assertSame('Seconds since the epoch.', (string) $entry->getDefinition('created')->getDescription());

    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($entry, 'resource_test.entry')->document['schema']), TRUE);
    $this->assertSame(['$ref' => '#/$defs/resource_test.record'], $full['allOf'][0]);
    $this->assertSame(['line', 'reference', 'created', 'changed'], array_keys($full['properties']));
    foreach (['reference', 'created', 'changed'] as $key) {
      $this->assertTrue($full['properties'][$key], $key);
    }
    $record = $full['$defs']['resource_test.record'];
    $this->assertSame(['$ref' => '#/$defs/resource_test.timestamps'], $record['allOf'][0], 'The record\'s def composes the timestamps\' in turn.');
    $this->assertArrayNotHasKey('unevaluatedProperties', $record, 'Mixed in, so open.');
    $this->assertSame(['line', 'reference', 'created', 'changed'], array_keys(SchemaResolver::inline($full)['properties']));
    $this->assertTrue($this->validates($full, ['line' => 'l', 'reference' => 'r', 'created' => 5, 'changed' => 6]));
    $this->assertFalse($this->validates($full, ['created' => 5, 'changed' => NULL]), 'The timestamps\' rule holds, two levels down.');
    $this->assertFalse($this->validates($full, ['fax' => 1]));
    $this->assertStaticRefinersAgree($entry);
  }

  /**
   * Tests the tool listing and the read tool read the members flat.
   */
  public function testToolsReadTheMembersFlat(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $this->assertTrue($manager->hasDefinition('data_surface:resource_test.journal:configure'));
    $values = $manager->getDefinition('data_surface:resource_test.journal:configure')->getInputDefinition(OperationInputs::VALUES);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(self::KEYS, array_keys($properties));
    $this->assertTrue($properties['owner']->isRequired());
    $this->assertFalse($properties['shared']->isRequired());

    $read = $manager->createInstance('data_surface:resource_test.journal:configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $this->assertSame(self::JOURNAL, $read->getResult()->getContextValues()[OperationInputs::VALUES] ?? NULL);

    $tool = $manager->createInstance('data_surface:resource_test.journal:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['changed' => 1600000000] + self::JOURNAL);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame('The values were refused: changed: This value should be 1700000000 or more when Created is 1700000000.', strip_tags((string) $tool->getResult()->getMessage()));
    $tool = $manager->createInstance('data_surface:resource_test.journal:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['owner' => 'bea', 'changed' => 1700000900] + self::JOURNAL);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('data_surface_resource_test.journal');
    $this->assertSame(['bea', 1700000900], [$stored->get('owner'), $stored->get('changed')]);
  }

}
