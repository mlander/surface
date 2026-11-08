<?php

declare(strict_types=1);

namespace Drupal\surface\Storage;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Resource\DeletableStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\ResourceBuild\StorageAdapter;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\SurfaceAccess;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The stage resource's storage: one record per stage, and its apply.
 *
 * Each operation of `surface.stage` is a verb of this one class, by its
 * context's id:
 *
 * - create rehearses a new record, open and empty, owned by the account
 *   it is written as, and commit writes it under the new id's lock; a load
 *   after it, in a context that knows no id, reads the stage just opened,
 *   so `id` answers.
 * - update changes the label, under the stage's lock.
 * - changes lists the staged writes, from prepare, writing nothing.
 * - apply rehearses every staged write in prepare, with no lock and no
 *   side effect: the operation built again from its arguments, its access
 *   asked again for the account, what is stored compared with the token
 *   the stage took, and the pipeline run dry over the keys the stage
 *   moved, every refusal filed under `/writes/<position>` and thrown
 *   together. Commit takes the lock, checks the record and the tokens
 *   again, commits each rehearsed write in staging order in one database
 *   transaction, and marks the record applied, which it keeps until it
 *   expires so the stage still answers after an apply. A write that
 *   throws rolls the transaction back, and the storage of each write
 *   whose commit had started is told (TransactionalStorageInterface).
 * - delete removes the record, open or applied, under the stage's lock.
 *
 * Every write of a record takes the stage's lock, so two writes of one
 * record never interleave while the lock lasts (core's 30 seconds). The
 * writes that change a record already there, a staged write's commit,
 * update and apply, read it again under the lock; a create writes a new
 * id no other request names, and a delete of a record gone is done.
 *
 * The context methods of the stage's operations call named(), so an id
 * that names no stage names nothing at every door.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 * Decision: see
 * docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner.
 *
 * @internal
 */
final class StageStorage implements DeletableStorageInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * A stage's status while it takes writes.
   */
  public const OPEN = 'open';

  /**
   * A stage's status once applied.
   */
  public const APPLIED = 'applied';

  /**
   * What a key's name escapes to in a JSON Pointer.
   */
  protected const POINTER_ESCAPES = ['~' => '~0', '/' => '~1'];

  /**
   * The id of the stage this storage opened last, for the create's `id`.
   */
  protected ?string $committed = NULL;

  /**
   * Constructs a StageStorage.
   *
   * @param \Drupal\surface\Staging\StageStore $stages
   *   Where the records are kept.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which apply builds each staged write with.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which apply runs each staged write through.
   * @param \Drupal\Core\Database\Connection $database
   *   The database, whose transaction holds an apply's writes.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   What gives a new stage its id.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's log, for a storage that fails to hear of a rollback.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly StageStore $stages,
    protected readonly ResourcesInterface $resources,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly Connection $database,
    protected readonly UuidInterface $uuid,
    #[Autowire(service: 'logger.channel.surface')]
    protected readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('surface.stages'),
      $container->get('surface.resources'),
      $container->get('surface.pipeline'),
      $container->get('database'),
      $container->get('uuid'),
      $container->get('logger.channel.surface'),
      $container->get('string_translation'),
    );
  }

  /**
   * Reads the record a stage operation's id names, for its context.
   *
   * @param string $id
   *   The id the operation was handed.
   * @param string $operation
   *   The operation and the resource, for the message.
   *
   * @return array
   *   The record.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `id`, when no stage has the id, or it expired.
   */
  public function named(string $id, string $operation): array {
    return $this->stages->read($id) ?? throw new NamesNothingException(sprintf('%s takes the id of a stage, and there is none with the id "%s": it expired, was discarded or never was.', $operation, $id), 'id');
  }

  /**
   * {@inheritdoc}
   *
   * The id and the label; for changes, the id alone; for apply, the id
   * and, once the stage is applied, what apply recorded.
   */
  public function load(OperationContext $context): array {
    $id = $context->known['id'] ?? NULL;
    if ($id === NULL && !$context->creates) {
      $id = $this->committed;
    }
    $record = is_string($id) ? $this->stages->read($id) : NULL;
    if ($record === NULL) {
      return [];
    }
    return match ($context->id) {
      'apply' => ['id' => $record['id']] + ($record['status'] === self::APPLIED ? ['applied' => $record['applied']] : []),
      'changes' => ['id' => $record['id']],
      default => ['id' => $record['id']] + ($record['label'] === NULL ? [] : ['label' => $record['label']]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    if ($context->creates) {
      $now = $this->stages->now();
      return [
        'version' => StageStore::VERSION,
        'id' => $this->uuid->generate(),
        'owner' => (int) $account->id(),
        'label' => is_string($values['label'] ?? NULL) ? $values['label'] : NULL,
        'status' => self::OPEN,
        'opened' => $now,
        'updated' => $now,
        'applied_at' => NULL,
        'applied' => NULL,
        'next' => 1,
        'writes' => [],
      ];
    }
    $record = $this->record($context);
    return match ($context->id) {
      'changes' => $this->listing($record),
      'apply' => $this->rehearse($record, $account),
      default => ['id' => $record['id'], 'label' => is_string($values['label'] ?? NULL) ? $values['label'] : NULL],
    };
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    if ($context->creates) {
      $id = (string) $prepared['id'];
      if (!$this->stages->lock($id)) {
        throw new StageRaceException(sprintf('The stage "%s" is being written by another request.', $id));
      }
      try {
        $this->stages->write($prepared);
      }
      finally {
        $this->stages->release($id);
      }
      $this->committed = $id;
      return;
    }
    if ($context->id === 'apply') {
      $this->replay($prepared);
      return;
    }
    $id = (string) $prepared['id'];
    $this->locked($id, function (array $record) use ($prepared): void {
      $record['label'] = $prepared['label'];
      $record['updated'] = $this->stages->now();
      $this->stages->write($record);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDelete(OperationContext $context, AccountInterface $account): array {
    return ['id' => $this->record($context)['id']];
  }

  /**
   * {@inheritdoc}
   *
   * Under the stage's lock, so a delete never lands while an apply holds
   * the record, which would write it back as applied. A record gone by
   * then is gone: a delete is idempotent.
   */
  public function delete(OperationContext $context, array $prepared, AccountInterface $account): void {
    $id = (string) $prepared['id'];
    if (!$this->stages->lock($id)) {
      throw new StageRaceException(sprintf('The stage "%s" is being written by another request.', $id));
    }
    try {
      $this->stages->delete($id);
    }
    finally {
      $this->stages->release($id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exists(OperationContext $context): bool {
    $id = $context->known['id'] ?? NULL;
    return is_string($id) && $this->stages->read($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function singularLabel(): \Stringable {
    return $this->t('stage');
  }

  /**
   * Reads the record the context names.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   *
   * @return array
   *   The record.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   When it expired or was discarded since the context was built.
   */
  protected function record(OperationContext $context): array {
    $id = $context->known['id'] ?? NULL;
    $record = is_string($id) ? $this->stages->read($id) : NULL;
    if ($record === NULL) {
      throw self::refusal($this->t('The stage expired or was discarded.'), Violation::NOT_FOUND);
    }
    return $record;
  }

  /**
   * Lists the staged writes, in the order apply makes them.
   *
   * @param array $record
   *   The record.
   *
   * @return array{items: list<array>, total: int}
   *   Each write's position, resource, operation, kind, parameters, the
   *   keys it moved as JSON Pointers (a delete's the empty one, the whole
   *   thing) and when it was staged; and how many.
   */
  protected function listing(array $record): array {
    $items = [];
    foreach (self::ordered($record) as $write) {
      $items[] = self::described($write) + [
        'changed' => array_map(static fn (string $key): string => $key === '' ? '' : '/' . strtr($key, self::POINTER_ESCAPES), $write['changed']),
        'staged' => (int) $write['staged'],
      ];
    }
    return ['items' => $items, 'total' => count($items)];
  }

  /**
   * Rehearses every staged write, writing nothing and holding nothing.
   *
   * @param array $record
   *   The record.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the writes are made as.
   *
   * @return array
   *   The plan: the stage's id and a hash of the writes it holds, `applied` as
   *   a dry run answers it, and `plan`, each write's built resource, its
   *   storage, its rehearsed result and its token, in order.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   Naming every write that would fail, under `/writes/<position>`.
   */
  protected function rehearse(array $record, AccountInterface $account): array {
    if ($record['status'] !== self::OPEN) {
      throw self::refusal($this->t('The stage was applied; nothing is pending.'), Violation::DOES_NOT_APPLY);
    }
    $writes = self::ordered($record);
    if ($writes === []) {
      throw self::refusal($this->t('Nothing is staged.'), Violation::DOES_NOT_APPLY);
    }
    $refused = [];
    $plan = [];
    $applied = [];
    foreach ($writes as $write) {
      $at = (string) $write['position'];
      $resource = (string) $write['resource'];
      $thing = self::thing($write);
      try {
        $context = $this->resources->operation($resource, (string) $write['operation'], (array) $write['arguments']);
      }
      catch (NamesNothingException) {
        $refused[] = new Violation('writes', $at, $this->t('@thing names nothing now.', ['@thing' => $thing]), Violation::NOT_FOUND);
        continue;
      }
      // The arguments the stage recorded build no operation: a parameter
      // missing, or an operation the resource no longer has.
      catch (\InvalidArgumentException $e) {
        $why = $this->t('@thing cannot be built from what the stage recorded: @why', [
          '@thing' => $thing,
          '@why' => $e->getMessage(),
        ]);
        $refused[] = new Violation('writes', $at, $why, Violation::DOES_NOT_APPLY);
        continue;
      }
      $access = $this->resources->access($resource, $context, $account);
      if (!$access->isAllowed()) {
        $permission = $this->resources->permissionAccess($resource, (string) $write['operation'], $account, $context);
        $refused[] = new Violation('writes', $at, SurfaceAccess::message($access), $permission !== NULL && !$permission->isAllowed() ? Violation::PERMISSION : Violation::ACCESS);
        continue;
      }
      // Built for the current user, which the stage's access holds to the
      // owner, and written as the account handed in.
      // Decision: see
      // docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner.
      $built = $this->resources->build($resource, $context);
      $storage = $this->resources->storage($resource, $context, $built, $account);
      $token = is_string($write['token']) ? $write['token'] : NULL;
      if ($token !== NULL && !hash_equals($token, Fingerprint::of($resource, $storage->load($built)))) {
        $refused[] = new Violation('writes', $at, $this->t('@thing: what is stored changed since it was staged.', ['@thing' => $thing]), Violation::CONFLICT);
        continue;
      }
      // The keys the stage moved, over what is stored now: a key it never
      // moved keeps what is stored, whatever the stage last read of it. A
      // create is sent whole, as every write to it merged it (its changed
      // keys, every key, say the same).
      $input = match ($write['kind']) {
        'delete' => [],
        'create' => (array) $write['values'],
        default => array_intersect_key((array) $write['values'], array_flip($write['changed'])),
      };
      $result = $this->pipeline->submit($built, $input, $storage, TRUE, SurfaceAccess::decisive($access));
      if (!$result->isValid()) {
        foreach ($result->violations as $violation) {
          if (!$violation->stale) {
            $refused[] = $violation->at('writes', $violation->fullPath() === '' ? $at : $at . '.' . $violation->fullPath());
          }
        }
        continue;
      }
      $plan[] = [
        'resource' => $resource,
        'built' => $built,
        'storage' => $storage,
        'result' => $result,
        'token' => $token,
        'id' => self::idOf($write, []),
      ];
      $applied[] = self::described($write) + ['changed' => $result->changes !== [], 'id' => NotEmitted::value()];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return ['id' => $record['id'], 'writes' => self::writesHash($record), 'applied' => $applied, 'plan' => $plan];
  }

  /**
   * Makes every rehearsed write, in order, in one transaction.
   *
   * Under the stage's lock, the record read again by locked(): a stage
   * deleted before the lock was had is a race, and none is deleted while
   * it is held, so a deleted stage is never written back as applied
   * unless the apply outlasts the lock (core's 30 seconds). A write that
   * throws rolls the transaction back, and then the storage of each write
   * whose commit had started, the last first, drops what it cached beside
   * the database.
   *
   * @param array $prepared
   *   What rehearse() returned.
   *
   * @throws \Drupal\surface\Staging\StageRaceException
   *   When the lock cannot be had, or the record is gone, or the record or
   *   what a write was staged against moved since the rehearsal.
   * @throws \Throwable
   *   What a write threw, after every write was rolled back.
   */
  protected function replay(array $prepared): void {
    $this->locked((string) $prepared['id'], function (array $record) use ($prepared): void {
      if ($record['status'] !== self::OPEN || self::writesHash($record) !== $prepared['writes']) {
        throw new StageRaceException(sprintf('The stage "%s" was written or applied after its apply was rehearsed.', $record['id']));
      }
      foreach ($prepared['plan'] as $entry) {
        if ($entry['token'] !== NULL && !hash_equals($entry['token'], Fingerprint::of($entry['resource'], $entry['storage']->load($entry['built'])))) {
          throw new StageRaceException(sprintf('What the stage "%s" staged at %s changed after its apply was rehearsed.', $record['id'], $entry['resource']));
        }
      }
      $transaction = $this->database->startTransaction();
      $applied = [];
      $started = [];
      try {
        foreach ($prepared['plan'] as $index => $entry) {
          $started[] = $entry;
          $result = $entry['result'];
          assert($result instanceof SurfaceResult);
          $committed = $this->pipeline->commitDryRun($result, $entry['storage']);
          // Read at once: a storage instance remembers the last thing it
          // wrote.
          $outputs = $this->pipeline->outputs($entry['built'], $entry['storage'], $committed);
          $id = $outputs['id'] ?? $entry['id'];
          $applied[] = array_diff_key($prepared['applied'][$index], ['changed' => TRUE, 'id' => TRUE]) + ['changed' => $committed->changed()] + ($id === NULL ? [] : ['id' => (string) $id]);
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        $this->tellRolledBack($started, (string) $record['id']);
        throw $e;
      }
      $transaction->commitOrRelease();
      $now = $this->stages->now();
      $record['status'] = self::APPLIED;
      $record['applied'] = $applied;
      $record['applied_at'] = $now;
      $record['updated'] = $now;
      $record['writes'] = [];
      $this->stages->write($record);
    });
  }

  /**
   * Tells the storage of each write whose commit had started of a rollback.
   *
   * The last first, each through the adapter that committed it, which
   * holds the storage instance that wrote (StorageAdapter::rolledBack()).
   * What one throws is logged and the others are still told: the
   * throwable the apply rethrows is the write's.
   *
   * @param list<array> $entries
   *   The plan entries whose commit had started, in order.
   * @param string $id
   *   The stage's id, for the log.
   */
  protected function tellRolledBack(array $entries, string $id): void {
    foreach (array_reverse($entries) as $entry) {
      if (!$entry['storage'] instanceof StorageAdapter) {
        continue;
      }
      try {
        $entry['storage']->rolledBack();
      }
      catch (\Throwable $e) {
        $this->logger->error('Applying the stage @id was rolled back, and the storage of @resource failed to drop what it cached: @message', [
          '@id' => $id,
          '@resource' => $entry['resource'],
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Fingerprints what a record holds to be applied.
   *
   * Its writes and its next position, which every staged write changes:
   * the time of the last write is the request's, which two writes in one
   * second share.
   *
   * @param array $record
   *   The record.
   *
   * @return string
   *   The hash.
   */
  protected static function writesHash(array $record): string {
    return hash('xxh128', serialize([$record['next'], $record['writes']]));
  }

  /**
   * Runs a write of a record under the stage's lock, the record read again.
   *
   * @param string $id
   *   The stage's id.
   * @param callable(array): void $write
   *   Handed the record as it is now.
   *
   * @throws \Drupal\surface\Staging\StageRaceException
   *   When the lock cannot be had, or the record is gone.
   */
  protected function locked(string $id, callable $write): void {
    if (!$this->stages->lock($id)) {
      throw new StageRaceException(sprintf('The stage "%s" is being written by another request.', $id));
    }
    try {
      $record = $this->stages->read($id);
      if ($record === NULL) {
        throw new StageRaceException(sprintf('The stage "%s" expired or was discarded while it was written.', $id));
      }
      $write($record);
    }
    finally {
      $this->stages->release($id);
    }
  }

  /**
   * Gets a record's writes in position order.
   *
   * @param array $record
   *   The record.
   *
   * @return list<array>
   *   The writes.
   */
  protected static function ordered(array $record): array {
    $writes = array_values((array) $record['writes']);
    usort($writes, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
    return $writes;
  }

  /**
   * Describes a staged write as `changes` and `applied` name it.
   *
   * @param array $write
   *   The write.
   *
   * @return array
   *   Its position, resource, operation, kind, and its parameters as
   *   `{name, value}` pairs, each value as text: for a create, the
   *   address a write in the stage names it by, its local id or the name
   *   its caller gave.
   */
  protected static function described(array $write): array {
    $parameters = [];
    $named = $write['kind'] === 'create' ? StageStore::address($write) : (array) $write['arguments'];
    foreach ($named as $name => $value) {
      $parameters[] = [
        'name' => (string) $name,
        'value' => is_scalar($value) || $value === NULL ? (string) $value : (string) json_encode($value),
      ];
    }
    return [
      'position' => (int) $write['position'],
      'resource' => (string) $write['resource'],
      'operation' => (string) $write['operation'],
      'kind' => (string) $write['kind'],
      'parameters' => $parameters,
    ];
  }

  /**
   * Names the thing a staged write is to, for a message.
   *
   * @param array $write
   *   The write.
   *
   * @return string
   *   The resource and its parameters' values: `entity.content:node.page 12`,
   *   or for a create its address, `entity.content:node.page -3`.
   */
  protected static function thing(array $write): string {
    $values = array_map(static fn (array $parameter): string => $parameter['value'], self::described($write)['parameters']);
    return trim($write['resource'] . ' ' . implode(' ', $values));
  }

  /**
   * Gives the id a written thing is answered by, as text.
   *
   * @param array $write
   *   The write.
   * @param array $outputs
   *   What its storage answered after the write.
   *
   * @return string|null
   *   The `id` output, else the `id` its context knew; NULL for neither.
   */
  protected static function idOf(array $write, array $outputs): ?string {
    $id = $outputs['id'] ?? $write['known']['id'] ?? NULL;
    return $id === NULL ? NULL : (string) $id;
  }

  /**
   * Makes one refusal of the whole call.
   *
   * @param \Stringable|string $message
   *   Why.
   * @param string $code
   *   The code.
   *
   * @return \Drupal\surface\Pipeline\StorageViolationsException
   *   The refusal.
   */
  protected static function refusal(\Stringable|string $message, string $code): StorageViolationsException {
    return new StorageViolationsException(new ViolationSet([new Violation('', '', $message, $code)]));
  }

}
