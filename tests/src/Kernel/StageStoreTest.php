<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Staging\ListsStagesInterface;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Storage\StageStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests where the stages are kept, and the lock their writes take.
 *
 * A record round-trips as a PHP array with no objects, a stage that is
 * missing or expired names nothing, and every write resets the expiry. A
 * staged commit waits once for a lock another request holds and then
 * gives up, a runtime failure; the lock this request holds already lets
 * it through. Opening a stage and deleting one take its lock too.
 *
 * @see docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageStoreTest extends SurfaceKernelTestBase {

  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'surface', 'surface_stage_test'];

  /**
   * {@inheritdoc}
   *
   * The database's lock, which a kernel test replaces with one that is
   * always free.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha', ['note' => 'First', 'tags' => ['one']]);
  }

  /**
   * Tests a record is written, named, deleted, and expires.
   */
  public function testCreateNamedWriteDeleteExpire(): void {
    $id = $this->open('Move the meetup');
    $stage = $this->stages()->named($id);
    $this->assertSame($id, $stage->id);
    $this->assertSame((int) $this->container->get('current_user')->id(), $stage->owner);
    $this->assertSame('Move the meetup', $stage->label);
    $this->assertSame([], $stage->arguments);
    $this->assertSame(['id' => 'a'], $stage->withArguments(['id' => 'a'])->arguments);

    $record = $this->stages()->read($id);
    $this->assertIsArray($record);
    $keys = [
      'version', 'id', 'owner', 'label', 'status', 'opened', 'updated',
      'keep', 'applied_at', 'applied', 'next', 'writes',
    ];
    $this->assertSame($keys, array_keys($record));
    $this->assertSame(StageRecord::VERSION, $record['version']);
    $this->assertSame(['open', 1, []], [$record['status'], $record['next'], $record['writes']]);
    $this->assertSame(604800, $record['keep'], 'Opened through the stage\'s create, a stage takes the long lifetime.');

    // A staged write: the record is still plain arrays and scalars.
    $result = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Beta']);
    $this->assertTrue($result->committed, $this->said($result));
    $record = $this->stages()->read($id);
    $this->assertCount(1, $record['writes']);
    $keys = [
      'position', 'resource', 'operation', 'kind', 'storage', 'arguments',
      'known', 'values', 'changed', 'token', 'staged', 'touched',
    ];
    $this->assertSame($keys, array_keys($record['writes'][0]));
    array_walk_recursive($record, function (mixed $value, int|string $key): void {
      $this->assertFalse(is_object($value), sprintf('The record holds an object at %s.', $key));
    });
    $this->assertSame($record, unserialize(serialize($record), ['allowed_classes' => FALSE]));

    // Every write resets the expiry.
    $database = $this->container->get('database');
    $expiry = fn (): int => (int) $database->select('key_value_expire', 'k')->fields('k', ['expire'])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $id)->execute()->fetchField();
    $database->update('key_value_expire')->fields(['expire' => time() + 10])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $id)->execute();
    $this->assertLessThanOrEqual(time() + 10, $expiry());
    $this->stages()->write($record);
    $this->assertGreaterThan(time() + 1000, $expiry());

    // Expired: read as nothing, and named nothing.
    $database->update('key_value_expire')->fields(['expire' => time() - 1])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $id)->execute();
    $this->assertNull($this->stages()->read($id));
    $this->assertNamesNothing($id);

    // Deleted, and never opened.
    $other = $this->open();
    $this->assertNotNull($this->stages()->read($other));
    $this->stages()->delete($other);
    $this->assertNull($this->stages()->read($other));
    $this->assertNamesNothing($other);
    $this->assertNamesNothing('00000000-0000-4000-8000-000000000000');
  }

  /**
   * Tests a staged commit waits once for another request's lock.
   *
   * The database backend's lock id is per instance, so a second instance
   * is another request; the one service, asked again, extends the lock it
   * holds.
   */
  public function testTheLockSerializesTwoWrites(): void {
    $id = $this->open();
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(StageStore::COLLECTION . ':' . $id));
    try {
      $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Beta']);
      $this->fail('A write waits once for a lock another request holds, then gives up.');
    }
    catch (StageRaceException $e) {
      $this->assertStringContainsString($id, $e->getMessage());
    }
    $this->assertSame([], $this->stages()->read($id)['writes']);
    $other->release(StageStore::COLLECTION . ':' . $id);

    // Held by this request's own lock service, the write proceeds.
    $this->assertTrue($this->container->get('lock')->acquire(StageStore::COLLECTION . ':' . $id));
    $result = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Beta']);
    $this->assertTrue($result->committed, $this->said($result));
    $this->assertCount(1, $this->stages()->read($id)['writes']);
    $this->assertTrue($other->lockMayBeAvailable(StageStore::COLLECTION . ':' . $id), 'The commit released the lock.');
  }

  /**
   * Tests opening and deleting a stage take the stage's lock.
   *
   * Every write of a record is under its lock, so a delete never lands
   * while an apply holds the record.
   */
  public function testOpenAndDeleteTakeTheLock(): void {
    $lock = new RecordingLock();
    $store = new StageStore($this->container->get('keyvalue.expirable'), $lock, $this->container->get('datetime.time'), 3600);
    $storage = new StageStorage(
      $store,
      $this->resources(),
      $this->container->get('surface.pipeline'),
      $this->container->get('database'),
      $this->container->get('uuid'),
      $this->container->get('logger.channel.surface'),
      $this->container->get('surface.config_writes'),
      $this->container->get('surface.secret_codec'),
      $this->container->get('string_translation'),
      3600,
    );
    $account = $this->container->get('current_user');

    $create = $this->resources()->operation('surface.stage', 'create');
    $prepared = $storage->prepare($create, ['label' => 'Locked'], $account);
    $id = (string) $prepared['id'];
    $this->assertSame([], $lock->asked, 'A rehearsal takes no lock.');
    $storage->commit($create, $prepared, $account);
    $name = StageStore::COLLECTION . ':' . $id;
    $this->assertSame([['acquire', $name], ['release', $name]], $lock->asked);
    $this->assertNotNull($store->read($id));

    $lock->asked = [];
    $delete = $this->resources()->operation('surface.stage', 'delete', ['id' => $id]);
    $storage->delete($delete, $storage->prepareDelete($delete, $account), $account);
    $this->assertSame([['acquire', $name], ['release', $name]], $lock->asked);
    $this->assertNull($store->read($id));

    // A record gone by the time the lock is had is gone: delete again.
    $lock->asked = [];
    $storage->delete($delete, ['id' => $id], $account);
    $this->assertSame([['acquire', $name], ['release', $name]], $lock->asked);
  }

  /**
   * Tests the record's `keep` is how long it lives after each write.
   *
   * A record with no `keep`, as every record written before stages
   * carried one, is kept for the store's default and still read.
   */
  public function testKeepIsTheRecordsLifetime(): void {
    $database = $this->container->get('database');
    $expiry = fn (string $id): int => (int) $database->select('key_value_expire', 'k')->fields('k', ['expire'])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $id)->execute()->fetchField();
    $now = $this->container->get('datetime.time')->getRequestTime();

    $id = $this->open();
    $record = $this->stages()->read($id);
    $record['keep'] = 120;
    $this->stages()->write($record);
    $this->assertEqualsWithDelta($now + 120, $expiry($id), 5);

    unset($record['keep']);
    $this->stages()->write($record);
    $this->assertEqualsWithDelta($now + 604800, $expiry($id), 5, 'No keep: the temp store expiry.');
    $this->assertSame($record, $this->stages()->read($id), 'A record with no keep still reads.');
    $this->assertSame($id, $this->stages()->named($id)->id);
    $this->assertNotInstanceOf(ListsStagesInterface::class, $this->stages(), 'The shipped store does not list stages.');
  }

  /**
   * Tests a lifetime resolves from its two names or seconds, and only so.
   *
   * Each answer is at least one second and at most StageRecord::MAX, so
   * a site setting of zero never means the default and a huge number
   * never overflows the expiry.
   */
  public function testKeepResolvesEachForm(): void {
    $this->assertSame(21600, StageRecord::keep(StageRecord::SHORT, 604800), 'short: the form cache default.');
    $this->setSetting('form_cache_expiration', 600);
    $this->assertSame(600, StageRecord::keep(StageRecord::SHORT, 604800), 'short: the site setting.');
    $this->setSetting('form_cache_expiration', 0);
    $this->assertSame(1, StageRecord::keep(StageRecord::SHORT, 604800), 'short: a setting of zero is one second.');
    $this->setSetting('form_cache_expiration', -5);
    $this->assertSame(1, StageRecord::keep(StageRecord::SHORT, 604800), 'short: a negative setting is one second.');
    $this->assertSame(604800, StageRecord::keep(StageRecord::LONG, 604800), 'long: the parameter.');
    $this->assertSame(1, StageRecord::keep(StageRecord::LONG, 0), 'long: a parameter of zero is one second.');
    $this->assertSame(90, StageRecord::keep(90, 604800), 'Seconds as given.');
    $this->assertSame(StageRecord::MAX, StageRecord::keep(PHP_INT_MAX, 604800), 'Seconds past the maximum are cut to it.');
    $this->assertSame(StageRecord::MAX, StageRecord::keep(StageRecord::LONG, PHP_INT_MAX), 'A long past the maximum is cut to it.');
    $this->assertSame(315360000, StageRecord::MAX, 'Ten years.');
    foreach (['forever', '120', '', 1.5, 0, -1, ['short'], NULL, TRUE] as $keep) {
      try {
        StageRecord::keep($keep, 604800);
        $this->fail(sprintf('%s is refused.', var_export($keep, TRUE)));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('"short", "long" or a positive integer of seconds', $e->getMessage());
      }
    }
  }

  /**
   * Tests no owner's operation may know a key the stage reserves.
   *
   * The stage's storage reads the position of a staged create and the
   * lifetime of a new stage from the context's known keys, so
   * Resources::operation() refuses a resource whose own context knows
   * either.
   */
  public function testReservedKeysAreRefused(): void {
    $this->enableModules(['surface_reserved_test']);
    $refusals = [
      'position' => 'The "position" operation of reserved_test.thing knows the key "stage_position", which is reserved for the stage: the stage\'s storage reads it to tell a thing a stage creates.',
      'keep' => 'The "keep" operation of reserved_test.thing knows the key "stage_keep", which is reserved for the stage: the stage\'s storage reads it for how long a new stage is kept.',
    ];
    foreach ($refusals as $operation => $message) {
      try {
        $this->resources()->operation('reserved_test.thing', $operation);
        $this->fail(sprintf('The "%s" operation was not refused.', $operation));
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Asserts an id names no stage.
   *
   * @param string $id
   *   The id.
   */
  protected function assertNamesNothing(string $id): void {
    try {
      $this->stages()->named($id);
      $this->fail('The stage names nothing.');
    }
    catch (NamesNothingException $e) {
      $this->assertSame('stage', $e->parameter);
    }
  }

}
