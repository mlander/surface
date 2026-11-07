<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Staging\StageStore;
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
 * it through.
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
      'applied_at', 'applied', 'next', 'writes',
    ];
    $this->assertSame($keys, array_keys($record));
    $this->assertSame(StageStore::VERSION, $record['version']);
    $this->assertSame(['open', 1, []], [$record['status'], $record['next'], $record['writes']]);

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
