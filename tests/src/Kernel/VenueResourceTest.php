<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_examples\Entity\EventVenue;
use Drupal\data_surface_examples\Resource\RegistrationStep2Resource;
use Drupal\data_surface_examples\Resource\VenueResource;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolOperation;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 15: a config entity type of the module's own, a resource.
 *
 * The venue entity type installs with its three venues and its generated
 * schema; the resource builds for each of its five operations, two of
 * them taking the keys of a structure of their own; both documents of
 * add, edit, list and reorder are pinned whole under
 * tests/fixtures/venues/ (written again with DATA_SURFACE_VENUE_WRITE=1),
 * the listing's outputs inline; the tools page, filter and leave out what
 * an account may not read; the reorder writes in one commit and refuses an
 * unknown venue and one named twice; and example 2 offers a venue added.
 *
 * @see docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class VenueResourceTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

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
  ];

  /**
   * The tools' prefix.
   */
  protected const TOOL = 'data_surface:event.venue:';

  /**
   * Where both documents of each operation are pinned.
   */
  protected const FIXTURES = __DIR__ . '/../../fixtures/venues/';

  /**
   * A fourth venue.
   */
  protected const TOWN_HALL = [
    'label' => 'Town Hall',
    'id' => 'town_hall',
    'capacity' => 300,
    'rooms' => [['id' => 'town_hall_council', 'label' => 'Council chamber', 'seats' => 120]],
  ];

  /**
   * Every venue's id and weight, as the reorder reads them shipped.
   */
  protected const SHIPPED_ORDER = [
    'venues' => [
      ['id' => 'riverside', 'weight' => 0],
      ['id' => 'library', 'weight' => 1],
      ['id' => 'harbour', 'weight' => 2],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Calls a venue tool and says what it answered.
   *
   * @param string $operation
   *   The operation, or `reorder:read`.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return array
   *   `success` and `message`, then every output.
   */
  protected function call(string $operation, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL . $operation);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => html_entity_decode(strip_tags((string) $result->getMessage()), ENT_QUOTES),
    ] + $result->getContextValues();
  }

  /**
   * Gets the venues' ids, lighter first.
   *
   * @return string[]
   *   The ids.
   */
  protected function order(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('event_venue');
    $storage->resetCache();
    $venues = $storage->loadMultiple();
    uasort($venues, [EventVenue::class, 'sort']);
    return array_map('strval', array_keys($venues));
  }

  /**
   * Tests the entity type installs, with its venues and its own schema.
   */
  public function testTheEntityTypeInstalls(): void {
    $type = $this->container->get('entity_type.manager')->getDefinition('event_venue');
    $this->assertInstanceOf(ConfigEntityTypeInterface::class, $type);
    $this->assertSame('data_surface_examples', $type->getProvider());
    $this->assertSame('data_surface_examples.event_venue', $type->getConfigPrefix());
    $this->assertSame([], $type->getLinkTemplates(), 'No links: the resource\'s routes are the forms.');
    $this->assertFalse($type->hasListBuilderClass());
    $this->assertSame([], $type->getHandlerClasses()['form'] ?? []);
    $this->assertSame(['id', 'label', 'slug', 'capacity', 'address', 'rooms', 'weight'], $type->get('config_export'));
    $this->assertSame(['riverside', 'library', 'harbour'], $this->order());

    // The schema is the generated one, and each shipped venue holds to it.
    $described = $this->container->get('data_surface.config_schema')->describe($this->container->get('data_surface.resource_registry')->getDefinition(VenueResource::class));
    $this->assertSame('data_surface_examples.event_venue.*', $described['config']);
    $this->assertNull($described['install'], 'The things shipped are no resource\'s to describe: no install file.');
    $this->assertSame('config_entity', $described['entries'][0]['schema']['type']);
    $this->assertSame(['id', 'label', 'slug', 'capacity', 'address', 'rooms', 'weight', 'status'], array_keys($described['entries'][0]['schema']['mapping']));
    $this->assertTrue($described['entries'][0]['schema']['mapping']['slug']['nullable'], 'The computed slug is in the schema, as any key.');
    $this->assertSame(['Regex' => ['pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D'], 'Length' => ['max' => 128]], $described['entries'][0]['schema']['mapping']['slug']['constraints']);
    $this->assertTrue($described['entries'][0]['schema']['mapping']['address']['nullable']);
    $this->assertArrayNotHasKey('nullable', $described['entries'][0]['schema']['mapping']['weight'], 'Always held.');
    $typed = $this->container->get('config.typed');
    foreach (['riverside', 'library', 'harbour'] as $id) {
      $name = 'data_surface_examples.event_venue.' . $id;
      $this->assertCount(0, $typed->createFromNameAndData($name, $this->config($name)->getRawData())->validate(), $name);
    }
  }

  /**
   * Tests the resource builds for each operation, two with keys of their own.
   */
  public function testTheResourceBuildsForEachOperation(): void {
    $resources = $this->container->get('data_surface.resources');
    $keys = static fn (string $operation, array $arguments = []): array => $resources->build(VenueResource::class, $resources->operation(VenueResource::class, $operation, $arguments))->getDefinitions()->names();
    $outputs = static fn (string $operation): array => $resources->build(VenueResource::class, $resources->operation(VenueResource::class, $operation))->getOutputDefinitions()->names();
    $this->assertSame(['label', 'id', 'slug', 'capacity', 'address', 'rooms', 'weight', 'status'], $keys('create'));
    $this->assertSame(['label', 'id', 'slug', 'capacity', 'address', 'rooms', 'weight', 'status'], $keys('update', ['id' => 'riverside']));
    $this->assertSame(['offset', 'limit', 'status'], $keys('list'));
    $this->assertSame(['venues'], $keys('reorder'));
    $this->assertSame([], $outputs('create'));
    $this->assertSame(['items', 'total'], $outputs('list'));
    $this->assertTrue($resources->build(VenueResource::class, $resources->operation(VenueResource::class, 'list'))->changesNothing());
    $edit = $resources->build(VenueResource::class, $resources->operation(VenueResource::class, 'update', ['id' => 'riverside']));
    $this->assertTrue($edit->isLocked('id'), 'Edit knows the venue.');

    $registry = $this->container->get('data_surface.resource_registry');
    $this->assertSame(['create', 'read', 'update', 'update', 'delete'], array_map(static fn ($operation): string => $operation->kind(), array_values($registry->getOperations(VenueResource::class))));
    $this->assertSame(['create', 'list', 'reorder', 'update', 'delete'], array_keys($registry->getOperations(VenueResource::class)));
  }

  /**
   * Tests both documents of add, edit, list and reorder, whole.
   */
  public function testBothDocumentsOfEachOperation(): void {
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $registry = $this->container->get('data_surface.resource_registry');
    $asked = ['create' => [], 'update' => ['id' => 'library'], 'list' => [], 'reorder' => []];
    foreach ($asked as $id => $arguments) {
      $operation = $registry->getOperation(VenueResource::class, $id);
      $context = $resources->operation(VenueResource::class, $id, $arguments);
      $built = $resources->build(VenueResource::class, $context);
      $stored = $resources->storage(VenueResource::class, $context, $built)->load($built);
      $values = array_replace($built->getDefaultValues(), $stored);
      $documents = [
        'emit' => $emitter->emit($built, $values, 'event.venue', $id, $operation->label, operation: $operation->facts())->document,
        'full' => $emitter->emitFull($resources->buildStatic(VenueResource::class, $context), 'event.venue', $id, $operation->label, operation: $operation->facts())->document,
      ];
      foreach ($documents as $kind => $document) {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $file = self::FIXTURES . $id . '.' . $kind . '.json';
        if (getenv('DATA_SURFACE_VENUE_WRITE')) {
          @mkdir(self::FIXTURES);
          file_put_contents($file, $json);
          continue;
        }
        $this->assertFileExists($file);
        $this->assertSame(file_get_contents($file), $json, sprintf('The %s document of %s; written again with DATA_SURFACE_VENUE_WRITE=1.', $kind, $id));
      }
    }
    if (getenv('DATA_SURFACE_VENUE_WRITE')) {
      $this->markTestSkipped('The venue documents were written.');
    }

    // What the documents say of each: the edit's machine name locked to
    // the venue, its values what is stored; the listing's values its
    // defaults; the reorder's values every venue's id and weight.
    $edit = json_decode((string) file_get_contents(self::FIXTURES . 'update.emit.json'), TRUE);
    $this->assertTrue($edit['schema']['properties']['id']['readOnly']);
    $this->assertSame('library', $edit['schema']['properties']['id']['const']);
    $stored = array_intersect_key($edit['values'], ['label' => TRUE, 'id' => TRUE, 'capacity' => TRUE]);
    $this->assertSame(['label' => 'Old Library', 'id' => 'library', 'capacity' => 90], $stored);
    $list = json_decode((string) file_get_contents(self::FIXTURES . 'list.emit.json'), TRUE);
    $this->assertSame(['offset' => 0, 'limit' => 20, 'status' => NULL], $list['values']);
    $this->assertSame('read', $list['schema']['x-surface']['operation']['kind']);
    $reorder = json_decode((string) file_get_contents(self::FIXTURES . 'reorder.emit.json'), TRUE);
    $this->assertSame(self::SHIPPED_ORDER, $reorder['values']);
    $this->assertSame('update', $reorder['schema']['x-surface']['operation']['kind']);
  }

  /**
   * Tests the listing's outputs: the page of venues, and the total.
   */
  public function testTheListingDeclaresItsOutputs(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(VenueResource::class, 'list');
    $outputs = $this->container->get('data_surface.contract_emitter')->emitFull($resources->buildStatic(VenueResource::class, $context), 'event.venue', 'list')->document['outputs'];
    $this->assertSame([
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      'type' => 'object',
      'properties' => [
        'items' => [
          'title' => 'Venues',
          'description' => 'The page of venues, lighter first, each one the account may read.',
          'type' => 'array',
          'items' => [
            'title' => 'Venue',
            'type' => 'object',
            'properties' => [
              'id' => ['title' => 'Machine name', 'type' => 'string'],
              'label' => ['title' => 'Name', 'type' => 'string'],
              'capacity' => ['title' => 'Capacity', 'type' => 'integer'],
              'weight' => ['title' => 'Weight', 'type' => 'integer'],
            ],
            'additionalProperties' => FALSE,
            'required' => ['id', 'label', 'capacity', 'weight'],
          ],
        ],
        'total' => [
          'title' => 'Total',
          'description' => 'How many venues the filter matched that the account may read, on every page.',
          'type' => 'integer',
        ],
      ],
      'required' => ['items', 'total'],
    ], $outputs);
  }

  /**
   * Tests the tools: their kinds, the listing paged and filtered by access.
   */
  public function testTheToolsPageFilterAndLeaveOutWhatMayNotBeRead(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $expected = [
      'create' => [ToolOperation::Write, FALSE, [OperationInputs::VALUES, OperationInputs::DRY_RUN]],
      'list' => [ToolOperation::Read, FALSE, [OperationInputs::VALUES]],
      'reorder' => [ToolOperation::Write, FALSE, [OperationInputs::VALUES, OperationInputs::DRY_RUN]],
      'reorder:read' => [ToolOperation::Read, FALSE, []],
      'update' => [ToolOperation::Write, FALSE, ['id', OperationInputs::VALUES, OperationInputs::DRY_RUN]],
      'update:read' => [ToolOperation::Read, FALSE, ['id']],
      'delete' => [ToolOperation::Write, TRUE, ['id', OperationInputs::DRY_RUN]],
    ];
    foreach ($expected as $operation => [$kind, $destructive, $inputs]) {
      $definition = $tools->getDefinition(self::TOOL . $operation);
      $this->assertSame($kind, $definition->getOperation(), $operation);
      $this->assertSame($destructive, $definition->isDestructive(), $operation);
      $this->assertSame($inputs, array_keys($definition->getInputDefinitions()), $operation);
    }
    $this->assertFalse($tools->hasDefinition(self::TOOL . 'list:read'), 'A read has no read tool beside it.');
    $this->assertSame(['items', 'total'], array_slice(array_keys($tools->getDefinition(self::TOOL . 'list')->getOutputDefinitions()), -2), 'The listing answers its outputs after the one result shape.');

    $page = $this->call('list', ['values' => ['offset' => 1, 'limit' => 1]]);
    $this->assertTrue($page['success'], $page['message']);
    $this->assertFalse($page['committed']);
    $this->assertSame([['id' => 'library', 'label' => 'Old Library', 'capacity' => 90, 'weight' => 1]], $page['items']);
    $this->assertSame(3, $page['total']);
    $refused = $this->call('list', ['values' => ['limit' => 51]]);
    $this->assertFalse($refused['success']);
    $limit = ['path' => '/limit', 'code' => 'not_in_range', 'message' => 'This value should be between 1 and 50.'];
    $this->assertSame([$limit], $refused['violations']);

    // Harbour Centre disabled: the filter finds it, and an account that
    // only views the venues does not.
    $harbour = EventVenue::load('harbour');
    $harbour->setStatus(FALSE)->save();
    $this->assertSame(['harbour'], array_column($this->call('list', ['values' => ['status' => FALSE]])['items'], 'id'));
    $this->assertSame(['riverside', 'library', 'harbour'], array_column($this->call('list', ['values' => []])['items'], 'id'));
    $this->setUpCurrentUser([], [VenueResource::VIEW]);
    $viewer = $this->call('list', ['values' => []]);
    $this->assertTrue($viewer['success'], $viewer['message']);
    $this->assertSame(['riverside', 'library'], array_column($viewer['items'], 'id'), 'A disabled venue is left out for an account that only views the venues.');
    $this->assertSame(2, $viewer['total'], 'And it is not counted.');
    $this->assertFalse($this->call('reorder', ['values' => ['venues' => [['id' => 'library', 'weight' => 0]]]])['success'], 'Reordering asks to administer the venues.');
  }

  /**
   * Tests the reorder: written in one commit, refused as a whole.
   */
  public function testTheReorderWritesInOneCommitOrNotAtAll(): void {
    $read = $this->call('reorder:read', []);
    $this->assertSame(self::SHIPPED_ORDER, $read['values']);

    $first = ['id' => 'harbour', 'weight' => -1];
    $rehearsed = $this->call('reorder', ['values' => ['venues' => [$first]], 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success'], $rehearsed['message']);
    $this->assertSame(['/venues'], $rehearsed['changes']);
    $this->assertSame(['riverside', 'library', 'harbour'], $this->order(), 'A dry run writes nothing.');

    // One unknown venue refuses the whole: the venue named rightly keeps
    // its weight too.
    $sent = [$first, ['id' => 'concert_hall', 'weight' => 0], ['id' => 'harbour', 'weight' => 4]];
    $refused = $this->call('reorder', ['values' => ['venues' => $sent]]);
    $this->assertFalse($refused['success']);
    $this->assertSame([
      ['path' => '/venues/1/id', 'code' => 'not_found', 'message' => 'No venue is named concert_hall.'],
      [
        'path' => '/venues/2/id',
        'code' => 'taken',
        'message' => 'The harbour venue is named already, at position 0: a reorder names each venue once.',
      ],
    ], $refused['violations']);
    $this->assertSame(['riverside', 'library', 'harbour'], $this->order(), 'Nothing written.');

    $written = $this->call('reorder', ['values' => ['venues' => [$first, ['id' => 'riverside', 'weight' => 5]]]]);
    $this->assertTrue($written['success'], $written['message']);
    $this->assertTrue($written['committed']);
    $this->assertSame(['harbour', 'library', 'riverside'], $this->order(), 'Both weights, in one write; the Old Library keeps its own.');
    $this->assertSame(1, EventVenue::load('library')->weight());
  }

  /**
   * Tests add, edit and remove, and a room id held once across the venues.
   */
  public function testAddEditAndRemoveOneVenue(): void {
    $added = $this->call('create', ['values' => self::TOWN_HALL]);
    $this->assertTrue($added['success'], $added['message']);
    $this->assertSame(['riverside', 'town_hall', 'library', 'harbour'], $this->order(), 'Weight 0, after Riverside Hall by name.');
    $again = $this->call('create', ['values' => self::TOWN_HALL]);
    $taken = [
      'path' => '/id',
      'code' => 'taken',
      'message' => 'The machine name town_hall is already in use: choose another.',
    ];
    $this->assertSame([$taken], $again['violations']);
    $main = ['id' => 'riverside_main', 'label' => 'Main hall', 'seats' => 10];
    $room = $this->call('update', ['id' => 'town_hall', 'values' => ['rooms' => [$main]]]);
    $held = [
      'path' => '/rooms/0/id',
      'code' => 'taken',
      'message' => 'The room id riverside_main is held already, by riverside: a room id is held once across the venues.',
    ];
    $this->assertSame([$held], $room['violations']);
    $edited = $this->call('update', ['id' => 'town_hall', 'values' => ['capacity' => 350]]);
    $this->assertTrue($edited['success'], $edited['message']);
    $this->assertSame(350, EventVenue::load('town_hall')->get('capacity'));
    $removed = $this->call('delete', ['id' => 'town_hall']);
    $this->assertTrue($removed['success'], $removed['message']);
    $this->assertNull(EventVenue::load('town_hall'));
  }

  /**
   * Tests the slug: computed from the name when a venue is added, and kept.
   *
   * The write tool takes no slug and the read answers it; a create stores
   * the slug computed from the name, an edit of the name keeps it; one
   * sent equal to the one it holds is noted, any other refused naming the
   * name; one another venue holds is refused by the storage, naming the
   * name; and the shipped venues hold theirs, which the post-update wrote
   * where a venue had none.
   */
  public function testTheSlugIsComputedFromTheName(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $serializer = $this->container->get('tool.definition_serializer');
    $listed = static fn (string $id): array => $serializer->normalizeInputSchema($tools->createInstance(self::TOOL . $id))['properties'][OperationInputs::VALUES]['properties'] ?? [];
    $this->assertArrayNotHasKey('slug', $listed('create'), 'Nobody types it: the write leaves it out.');
    $this->assertArrayNotHasKey('slug', $listed('update'));
    $this->assertArrayHasKey('slug', $serializer->normalizeOutputSchema($tools->createInstance(self::TOOL . 'update:read'))['properties'][OperationInputs::VALUES]['properties'], 'The read answers it.');

    $added = $this->call('create', ['values' => self::TOWN_HALL]);
    $this->assertTrue($added['success'], $added['message']);
    $this->assertSame('town-hall', EventVenue::load('town_hall')->slug());
    $read = $this->call('update:read', ['id' => 'town_hall']);
    $this->assertSame('town-hall', $read[OperationInputs::VALUES]['slug']);

    $renamed = $this->call('update', ['id' => 'town_hall', 'values' => ['label' => 'Town Hall and Annex']]);
    $this->assertTrue($renamed['success'], $renamed['message']);
    $this->assertSame('Town Hall and Annex', EventVenue::load('town_hall')->label());
    $this->assertSame('town-hall', EventVenue::load('town_hall')->slug(), 'Computed on create: a rename keeps it.');

    $equal = $this->call('update', ['id' => 'town_hall', 'values' => ['slug' => 'town-hall', 'capacity' => 320]]);
    $this->assertTrue($equal['success'], $equal['message']);
    $this->assertStringContainsString('/slug: Slug is computed from label; the value sent was not taken.', $equal['message'], 'The one it holds: noted, not taken.');
    $this->assertSame(320, EventVenue::load('town_hall')->get('capacity'));
    $other = $this->call('update', ['id' => 'town_hall', 'values' => ['slug' => 'annex']]);
    $this->assertSame([
      [
        'path' => '/slug',
        'code' => 'computed',
        'message' => 'Slug is computed from label and takes no value of its own; leave it out.',
      ],
    ], $other['violations']);
    $this->assertSame('town-hall', EventVenue::load('town_hall')->slug());

    $twin = $this->call('create', [
      'values' => ['label' => 'Riverside  Hall!', 'id' => 'riverside_two', 'capacity' => 10, 'rooms' => []],
    ]);
    $this->assertSame([
      [
        'path' => '/slug',
        'code' => 'taken',
        'message' => 'The slug riverside-hall is held already, by riverside: a slug is held once across the venues. This is computed from label.',
      ],
    ], $twin['violations'], 'A refusal of a value nobody sent names the key the caller can change.');
    $this->assertNull(EventVenue::load('riverside_two'));

    $shipped = ['riverside', 'library', 'harbour'];
    $slugs = array_map(static fn (string $id): ?string => EventVenue::load($id)->slug(), $shipped);
    $this->assertSame(['riverside-hall', 'old-library', 'harbour-centre'], $slugs, 'Each venue shipped holds its slug.');
    EventVenue::load('library')->set('slug', NULL)->save();
    require_once dirname(__DIR__, 3) . '/modules/data_surface_examples/data_surface_examples.post_update.php';
    data_surface_examples_post_update_venue_slugs();
    $this->assertSame('old-library', EventVenue::load('library')->slug(), 'A venue saved without one is given what an add would have stored.');
  }

  /**
   * Reads the venues and rooms example 2's full schema offers now.
   *
   * @return array{0: string[], 1: string[]}
   *   The venues, then the rooms.
   */
  protected function offered(): array {
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->buildStatic(RegistrationStep2Resource::class, $resources->operation(RegistrationStep2Resource::class, 'configure'));
    $full = $this->container->get('data_surface.contract_emitter')->emitFull($built, 'registration.step2', 'configure')->document['schema'];
    return [
      array_column($full['properties']['venue']['oneOf'], 'const'),
      array_column($full['properties']['room']['oneOf'], 'const'),
    ];
  }

  /**
   * Tests example 2 offers a venue added, with no cache rebuild.
   */
  public function testExampleTwoOffersTheVenueAdded(): void {
    $resources = $this->container->get('data_surface.resources');
    $rooms = [
      'riverside_main',
      'riverside_east',
      'library_reading',
      'library_garden',
      'harbour_auditorium',
      'harbour_deck',
    ];
    // The venue is a reference: core's selection handler's candidates,
    // sorted by name. The rooms are the resolver's, the venues lighter
    // first.
    $this->assertSame([['harbour', 'library', 'riverside'], $rooms], $this->offered());
    $this->assertTrue($this->call('create', ['values' => ['weight' => 5] + self::TOWN_HALL])['success']);
    [$venue, $room] = $this->offered();
    $this->assertSame(['harbour', 'library', 'riverside', 'town_hall'], $venue, 'Offered at the next build, read under the venues\' list tag.');
    $this->assertContains('town_hall_council', $room);
    // A venue disabled is still offered, with its rooms: core's selection
    // for a config entity type lists every one.
    EventVenue::load('library')->setStatus(FALSE)->save();
    $this->assertSame([['harbour', 'library', 'riverside', 'town_hall'], [...$rooms, 'town_hall_council']], $this->offered());
    // And its room is refused by the pipeline in Choice's own words.
    $context = $resources->operation(RegistrationStep2Resource::class, 'configure');
    $built = $resources->build(RegistrationStep2Resource::class, $context);
    $values = [
      'title' => 'Meetup',
      'open' => TRUE,
      'venue' => 'town_hall',
      'room' => 'riverside_main',
      'capacity' => 50,
    ];
    $violations = $this->pipeline()->validate($built, $values);
    $this->assertSame(['room'], array_map(static fn ($violation): string => $violation->key, iterator_to_array($violations, FALSE)));
    $this->assertSame('The value you selected is not a valid choice. This applies when Venue is Town Hall. Choose Council chamber.', (string) iterator_to_array($violations, FALSE)[0]->message, 'In the words a LabeledChoice was refused in, naming the venue and its room.');
    $this->assertInstanceOf(OperationContext::class, $context);
  }

}
