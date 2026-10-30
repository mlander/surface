<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples\Entity\EventVenue;
use Drupal\surface_examples\Resource\VenueResource;
use Drupal\surface_examples\Storage\VenueStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a machine name computed unless sent and held once, at two doors.
 *
 * Example 15's venue: its `id` is a `machine_name` computed from its name
 * when a call leaves it out, kept as sent when one is given, and the stock
 * SurfaceUnique the build adds to a create's identity refuses one a
 * venue holds, in one sentence, at the tool door and over HTTP. On an
 * update the identity is the venue the call names: a value sent for it is
 * not taken.
 *
 * @see docs/decisions.md#a-machine-name-is-a-computed-identity
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class UniqueIdentityTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
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
    'surface',
    'surface_examples',
  ];

  /**
   * The tools' prefix.
   */
  protected const TOOL = 'surface:event.venue:';

  /**
   * The refusal of a machine name Riverside Hall holds.
   */
  protected const TAKEN = 'A venue named riverside already exists.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Calls a venue tool and says what it answered.
   *
   * @param string $operation
   *   The operation.
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
   * Gives a venue's values, a name and a capacity, and no rooms.
   *
   * @param array $values
   *   What the call says beside them.
   *
   * @return array
   *   The values.
   */
  protected static function venue(array $values): array {
    return $values + ['capacity' => 40, 'rooms' => []];
  }

  /**
   * Gives the one refusal of a taken machine name.
   *
   * @param string $after
   *   The sentence after the constraint's, if any.
   *
   * @return list<array{path: string, code: string, message: string}>
   *   The refusals, as the tool and HTTP answer them.
   */
  protected static function refused(string $after = ''): array {
    return [
      [
        'path' => '/id',
        'code' => Violation::TAKEN,
        'message' => self::TAKEN . ($after === '' ? '' : ' ' . $after),
      ],
    ];
  }

  /**
   * Tests the tool: a machine name left out, sent, and taken.
   */
  public function testTheToolDoor(): void {
    $left_out = $this->call('create', ['values' => static::venue(['label' => 'Café Déjà Vu']), 'dry_run' => TRUE]);
    $this->assertTrue($left_out['success'], $left_out['message']);
    $this->assertSame('cafe_deja_vu', $left_out['values']['id'], 'Left out: computed from the name.');

    $deja_vu = static::venue(['label' => 'Café Déjà Vu', 'id' => 'deja_vu']);
    $sent = $this->call('create', ['values' => $deja_vu, 'dry_run' => TRUE]);
    $this->assertTrue($sent['success'], $sent['message']);
    $this->assertSame('deja_vu', $sent['values']['id'], 'Sent: kept as sent.');
    $this->assertSame([], $sent['notes'] ?? [], 'And nothing noted about it.');

    // Riverside Hall is `riverside`: the same id sent is refused in the
    // constraint's sentence, nothing more, since the value is the
    // caller's.
    $taken = $this->call('create', ['values' => static::venue(['label' => 'Main stage', 'id' => 'riverside'])]);
    $this->assertFalse($taken['success']);
    $this->assertSame(static::refused(), $taken['violations']);

    // Computed from a name: the sentence names the key it is computed
    // from, by its path at this door, which the caller can change, or a
    // value it can send instead.
    $computed = $this->call('create', ['values' => static::venue(['label' => 'Riverside'])]);
    $this->assertFalse($computed['success']);
    $this->assertSame(static::refused('This is computed from label unless a value is sent.'), $computed['violations']);
    $this->assertNull(EventVenue::load('main_stage'));

    $added = $this->call('create', ['values' => static::venue(['label' => 'Concert Hall'])]);
    $this->assertTrue($added['success'], $added['message']);
    $this->assertSame('Concert Hall', EventVenue::load('concert_hall')?->label(), 'A new one is accepted, under the id computed.');

    // On an update the identity is the venue the call names: no value
    // sent for it is taken, and the venue keeps its machine name.
    $update = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL . 'update');
    $listed = $this->container->get('tool.definition_serializer')->normalizeInputSchema($update);
    $this->assertArrayNotHasKey('id', $listed['properties']['values']['properties'], 'The update tool knows it, so it is no value.');
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(VenueResource::class, 'update', ['id' => 'concert_hall']);
    $built = $resources->build(VenueResource::class, $context);
    $storage = $resources->storage(VenueResource::class, $context, $built);
    $result = $this->pipeline()->submit($built, ['id' => 'symphony_hall', 'capacity' => 600], $storage);
    $this->assertTrue($result->isValid());
    $notes = array_map(static fn (Violation $note): array => [$note->fullPath(), $note->code], iterator_to_array($result->notes, FALSE));
    $this->assertSame([['id', Violation::LOCKED]], $notes);
    $this->assertSame(600, EventVenue::load('concert_hall')->get('capacity'));
    $this->assertNull(EventVenue::load('symphony_hall'));
  }

  /**
   * Tests HTTP: the same refusal in the same words, and a new one created.
   */
  public function testOverHttp(): void {
    $main_stage = static::venue(['label' => 'Main stage', 'id' => 'riverside']);
    $taken = $this->servedJson('POST', '/surface-api/event.venue', $main_stage);
    $this->assertSame(422, $taken['status'] ?? NULL, print_r($taken, TRUE));
    $said = array_flip(['path', 'code', 'message']);
    $errors = array_map(static fn (array $error): array => array_intersect_key($error, $said), $taken['errors']);
    $this->assertSame(static::refused(), $errors);

    $created = $this->served('POST', '/surface-api/event.venue', static::venue(['label' => 'Harbour Loft']));
    $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
    $this->assertStringEndsWith('/surface-api/event.venue/harbour_loft', $created->headers->get('Location'));
    $this->assertSame('Harbour Loft', EventVenue::load('harbour_loft')?->label());
  }

  /**
   * Tests the full schema names the constraint with its reason.
   */
  public function testTheSchemaNamesItCheckedOnTheServer(): void {
    $resources = $this->container->get('surface.resources');
    $built = $resources->build(VenueResource::class, $resources->operation(VenueResource::class, 'create'));
    $id = $this->container->get('surface.contract_emitter')->emitFull($built, 'event.venue', 'create')->document['schema']['properties']['id'];
    $this->assertSame([
      [
        'constraint' => 'SurfaceUnique',
        'message' => 'Refused when a venue named this already exists.',
      ],
    ], $id['x-surface']['checkedOnServer']);
    $this->assertSame(['^[a-z0-9_]+$', 32], [$id['pattern'], $id['maxLength']]);
    $this->assertArrayNotHasKey('readOnly', $id);
  }

  /**
   * Tests the storage refuses a taken id in the same words, asked directly.
   */
  public function testTheStorageSaysItInTheSameWords(): void {
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(VenueStorage::class);
    $this->assertTrue($storage->exists(new OperationContext(known: ['id' => 'riverside'])));
    $this->assertFalse($storage->exists(new OperationContext(known: ['id' => 'nowhere'])));
    $this->assertSame('venue', (string) $storage->singularLabel());
    try {
      $storage->prepare(new OperationContext('create', TRUE), static::venue(['id' => 'riverside', 'label' => 'Again']), $this->container->get('current_user'));
      $this->fail('A taken id is refused.');
    }
    catch (StorageViolationsException $e) {
      $said = array_map(static fn (Violation $violation): string => (string) $violation->message, iterator_to_array($e->getViolations(), FALSE));
      $this->assertContains(self::TAKEN, $said);
    }
  }

}
