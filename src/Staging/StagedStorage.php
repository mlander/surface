<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Pipeline\CreatingStorageInterface;
use Drupal\surface\Pipeline\DeletingStorageInterface;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Pipeline\PreparedValues;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\ResourceBuild\StorageAdapter;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\SurfaceInterface;

/**
 * A resource's storage in front of which a stage stands.
 *
 * What ResourcesInterface::storage() answers when it is handed a stage:
 * the StorageAdapter it builds otherwise, wrapped, at the pipeline's
 * storage boundary, so the pipeline runs unchanged.
 *
 * - load() answers what the stage holds for the thing, narrowed to the
 *   data surface's keys, and what is stored when it holds nothing; the
 *   first load from storage is fingerprinted (Fingerprint::of()), the
 *   token apply checks what is stored then against.
 * - prepare() refuses what a stage cannot hold yet, each a violation of
 *   the whole call, a storage class that does not implement
 *   TransactionalStorageInterface among them, then rehearses the write as
 *   a real one: the adapter's prepare(), with every check the storage
 *   makes.
 * - commit() writes the stage's record, never storage: the merged
 *   document the pipeline accepted replaces the stage's entry for the
 *   thing whole, the last of two writes winning, and the top level keys
 *   the write moved join the entry's, the keys apply sends.
 *
 * A create is staged as an entry of kind `create`, appended whole: it
 * names no stored thing, so it matches no entry and no entry matches it,
 * two creates are two entries, and nothing in its stage reads, changes
 * or removes it; apply creates it and answers its id. A name the caller
 * gives a create is refused `taken` while another create of the same
 * storage class in the stage holds it, through any resource.
 *
 * A delete is staged as an entry of kind `delete`, its values empty and
 * its one changed key the empty one, the whole thing, as the pipeline
 * answers `changes: ['']` for a delete. It replaces the thing's entry at
 * that entry's position, keeping the token taken when the thing was
 * first staged; load() then answers nothing for the thing, and a later
 * write to it, a second delete among them, is refused.
 *
 * The pipeline merges a partial write over what load() answered, so a
 * second staged write to one thing builds on the first, and its changes
 * are against what the stage held. A thing is one resource and the
 * identity its context knows; one stored thing reached through two
 * resources is refused.
 *
 * Decision: see
 * docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
 * Decision: see docs/decisions.md#a-staged-write-validates-as-a-real-write.
 *
 * @internal
 */
final class StagedStorage implements CreatingStorageInterface, DeletingStorageInterface, OutputsStorageInterface {

  use StringTranslationTrait;

  /**
   * What load() answered last, for the keys a commit moved.
   */
  protected ?array $current = NULL;

  /**
   * The fingerprint of the thing as stored, at the first load from storage.
   */
  protected ?string $token = NULL;

  /**
   * Whether this storage committed a write to the stage.
   */
  protected bool $committed = FALSE;

  /**
   * Constructs a StagedStorage.
   *
   * @param \Drupal\surface\ResourceBuild\StorageAdapter $adapter
   *   The resource's storage, as the pipeline is handed it otherwise.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param \Drupal\surface\Staging\StageStore $stages
   *   Where its record is kept.
   * @param string $resource
   *   The resource's id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the storage loads and commits by.
   * @param string $storageClass
   *   The class of the resource's storage.
   * @param string[] $identity
   *   The resource's identity keys.
   * @param string[] $parameters
   *   The names of the operation's parameters, what a write records as
   *   its arguments when the stage was handed none.
   */
  public function __construct(
    protected readonly StorageAdapter $adapter,
    protected readonly Stage $stage,
    protected readonly StageStore $stages,
    protected readonly string $resource,
    protected readonly OperationContext $context,
    protected readonly string $storageClass,
    protected readonly array $identity,
    protected readonly array $parameters,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function creates(): bool {
    return $this->adapter->creates();
  }

  /**
   * {@inheritdoc}
   */
  public function deletes(): bool {
    return $this->adapter->deletes();
  }

  /**
   * {@inheritdoc}
   *
   * What the stage holds for the thing, or what is stored; nothing is
   * written, and no expiry is reset. A create names no stored thing, so it
   * is answered what the storage answers for a new one, and takes no
   * token.
   */
  public function load(SurfaceInterface $surface): array {
    $record = $this->stages->read($this->stage->id);
    $entry = $record === NULL ? NULL : $this->entry($record);
    if ($entry !== NULL) {
      $this->token ??= $entry['token'];
      $values = $entry['kind'] === 'delete' ? [] : array_intersect_key((array) $entry['values'], $surface->getDefinitions()->toArray());
      return $this->current = $values;
    }
    $loaded = $this->adapter->load($surface);
    // Decision: see
    // docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
    $this->token ??= $this->creates() ? NULL : Fingerprint::of($this->resource, $loaded);
    return $this->current = $loaded;
  }

  /**
   * {@inheritdoc}
   *
   * Refused whole, each refusal a violation of the whole call, for a
   * stage that is gone or applied, a thing the stage removed (a second
   * delete of it included), a storage class that does not declare a
   * transaction rolls its writes back whole, a resource that holds a
   * secret, a thing the stage holds through another resource, and a write
   * through another operation than the one the thing is staged through,
   * unless the write deletes; else the adapter's own prepare(). A create
   * is no thing reached twice, so the last two do not apply to it; one
   * whose caller names it is refused `taken` at the name while a create
   * of the same storage class in the stage holds it. A delete is rehearsed
   * as a real one: the storage's prepareDelete().
   */
  public function prepare(SurfaceInterface $surface, array $values): PreparedValues {
    $record = $this->stages->read($this->stage->id);
    if ($record === NULL) {
      throw self::refusal($this->t('The stage expired or was discarded.'), Violation::NOT_FOUND);
    }
    if ($record['status'] !== StageStorage::OPEN) {
      throw self::refusal($this->t('The stage was applied; open a new one.'), Violation::DOES_NOT_APPLY);
    }
    $entry = $this->entry($record);
    if ($entry !== NULL && $entry['kind'] === 'delete') {
      throw self::refusal(
        $this->t('This was removed in the stage; the removal stands until the stage is applied or discarded.'),
        Violation::NOT_FOUND,
      );
    }
    // Decision: see
    // docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
    if (!is_a($this->storageClass, TransactionalStorageInterface::class, TRUE)) {
      throw self::refusal($this->t('@resource cannot be staged: its storage, @class, does not declare that a database transaction rolls its writes back whole (@interface).', [
        '@resource' => $this->resource,
        '@class' => $this->storageClass,
        '@interface' => TransactionalStorageInterface::class,
      ]), Violation::DOES_NOT_APPLY);
    }
    if (Outbound::secrets($surface) !== []) {
      throw self::refusal($this->t('A stage keeps values in the clear until secrets are sealed, so a resource that holds a secret cannot be staged yet.'), Violation::DOES_NOT_APPLY);
    }
    // A new thing is no stored thing reached twice: two creates through
    // two resources are two things.
    if ($this->creates()) {
      $name = $this->name($values);
      $holder = $name === [] ? NULL : $this->creating($record, $name);
      if ($holder !== NULL) {
        $taken = new Violation((string) array_key_first($name), '', $this->t('A @thing named @id is created in this stage already, at position @position.', [
          '@thing' => $holder['resource'],
          '@id' => implode(' ', array_map(self::scalar(...), $name)),
          '@position' => $holder['position'],
        ]), Violation::TAKEN);
        throw new StorageViolationsException(new ViolationSet([$taken]));
      }
      return $this->adapter->prepare($surface, $values);
    }
    $known = $this->identityKnown();
    foreach ($record['writes'] as $write) {
      if ($write['kind'] !== 'create' && $write['storage'] === $this->storageClass && $write['resource'] !== $this->resource && self::sameThing($known, (array) $write['known'])) {
        throw self::refusal($this->t('This is staged through @resource already; write it through that resource, or discard the stage.', ['@resource' => $write['resource']]), Violation::CONFLICT);
      }
    }
    // A delete replaces what the stage holds for the thing, whatever it
    // was staged through.
    if ($entry !== NULL && $entry['operation'] !== $this->context->id && !$this->deletes()) {
      throw self::refusal($this->t('This is staged through the @operation operation already; write it through that operation, or discard the stage.', ['@operation' => $entry['operation']]), Violation::DOES_NOT_APPLY);
    }
    return $this->adapter->prepare($surface, $values);
  }

  /**
   * {@inheritdoc}
   *
   * Under the stage's lock: the entry for the thing replaced whole by the
   * merged document, or added at the stage's next position. A delete
   * turns the thing's entry into a delete at its position, its staging
   * time and token kept, or adds one. A create is always added, whole,
   * with no token and every key changed; its entry knows what its context
   * knew and the name its caller gave, and nothing in the stage names it
   * again. Storage is never written.
   *
   * @throws \Drupal\surface\Staging\StageRaceException
   *   When the lock cannot be had, or the stage is gone or applied, or a
   *   create of the same storage class took the name a create's caller
   *   gave while the write was prepared.
   */
  public function commit(PreparedValues $prepared): void {
    $id = $this->stage->id;
    if (!$this->stages->lock($id)) {
      throw new StageRaceException(sprintf('The stage "%s" is being written by another request.', $id));
    }
    try {
      $record = $this->stages->read($id);
      if ($record === NULL || $record['status'] !== StageStorage::OPEN) {
        throw new StageRaceException(sprintf('The stage "%s" expired, was discarded or was applied while the write was prepared.', $id));
      }
      $now = $this->stages->now();
      $values = $prepared->values;
      $current = $this->current ?? [];
      $moved = [];
      foreach (array_keys($values + $current) as $key) {
        if (!array_key_exists($key, $values) || !array_key_exists($key, $current) || $values[$key] !== $current[$key]) {
          $moved[] = (string) $key;
        }
      }
      $name = $this->creates() ? $this->name($values) : [];
      if ($name !== [] && $this->creating($record, $name) !== NULL) {
        throw new StageRaceException(sprintf('The stage "%s" took a create of the name "%s" while the write was prepared.', $id, implode(' ', array_map(self::scalar(...), $name))));
      }
      $found = $this->found($record);
      // Decision: see
      // docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
      if ($found !== NULL && $this->deletes()) {
        $entry = $record['writes'][$found];
        $entry['operation'] = $this->context->id;
        $entry['kind'] = 'delete';
        $entry['arguments'] = $this->arguments();
        $entry['values'] = [];
        $entry['changed'] = [''];
        $entry['touched'] = $now;
        $record['writes'][$found] = $entry;
      }
      elseif ($found !== NULL) {
        $entry = $record['writes'][$found];
        $entry['values'] = $values;
        $entry['changed'] = array_values(array_unique([...$entry['changed'], ...$moved]));
        $entry['touched'] = $now;
        $record['writes'][$found] = $entry;
      }
      else {
        $record['writes'][] = [
          'position' => (int) $record['next'],
          'resource' => $this->resource,
          'operation' => $this->context->id,
          'kind' => $this->deletes() ? 'delete' : ($this->creates() ? 'create' : 'update'),
          'storage' => $this->storageClass,
          'arguments' => $this->arguments(),
          'known' => array_filter($this->context->known, static fn (mixed $value): bool => $value === NULL || is_scalar($value)) + $name,
          'values' => $this->deletes() ? [] : $values,
          'changed' => $this->deletes() ? [''] : $moved,
          'token' => $this->creates() ? NULL : $this->token,
          'staged' => $now,
          'touched' => $now,
        ];
        $record['next'] = (int) $record['next'] + 1;
      }
      $record['updated'] = $now;
      $this->stages->write($record);
      $this->committed = TRUE;
    }
    finally {
      $this->stages->release($id);
    }
  }

  /**
   * {@inheritdoc}
   *
   * After a staged commit, what the stage now holds plus what the context
   * knows, as the adapter answers from storage after a real one; for a dry
   * run or an operation that changes nothing, the adapter's own. A staged
   * create answers what it was sent and what its context knows, less what
   * is empty: an id storage gives on save is not answered until apply.
   *
   * Decision: see docs/decisions.md#who-produces-outputs.
   */
  public function outputs(SurfaceInterface $surface, array $values, ?PreparedValues $prepared = NULL): array {
    if ($prepared !== NULL || !$this->committed) {
      return $this->adapter->outputs($surface, $values, $prepared);
    }
    $outputs = $surface->getOutputDefinitions()->toArray();
    if ($outputs === []) {
      return [];
    }
    if ($this->creates()) {
      $answered = array_filter($values + $this->context->known, static fn (mixed $value): bool => $value !== NULL);
      return array_intersect_key($answered, $outputs);
    }
    return array_intersect_key($this->load($surface) + $this->context->known, $outputs);
  }

  /**
   * Finds the stage's entry for the thing this storage writes.
   *
   * @param array $record
   *   The stage's record.
   *
   * @return array|null
   *   The entry, or NULL when the stage holds none, as for a create.
   */
  protected function entry(array $record): ?array {
    $found = $this->found($record);
    return $found === NULL ? NULL : $record['writes'][$found];
  }

  /**
   * Finds where in the record the entry for the thing this storage writes is.
   *
   * A create names no stored thing, so it finds none, and an entry that
   * creates is found by nothing.
   *
   * @param array $record
   *   The stage's record.
   *
   * @return int|string|null
   *   The entry's index among the record's writes, or NULL.
   */
  protected function found(array $record): int|string|null {
    if ($this->creates()) {
      return NULL;
    }
    $known = $this->identityKnown();
    foreach ($record['writes'] as $index => $write) {
      if ($write['kind'] !== 'create' && $write['resource'] === $this->resource && self::sameThing($known, (array) $write['known'], TRUE)) {
        return $index;
      }
    }
    return NULL;
  }

  /**
   * Gets the name a create's caller gives the thing.
   *
   * The values of the identity keys the context does not know, as the
   * write accepted them; empty for a create whose storage gives the id.
   *
   * @param array $values
   *   The values the write accepted.
   *
   * @return array<string, mixed>
   *   The name, by identity key.
   */
  protected function name(array $values): array {
    $named = array_diff_key(array_intersect_key($values, array_flip($this->identity)), $this->context->known);
    return array_filter($named, static fn (mixed $value): bool => is_scalar($value) && $value !== '');
  }

  /**
   * Finds the create in the stage that already holds a name.
   *
   * Any create of the same storage class, through any resource, whose
   * known identity agrees with this create's on every key both carry.
   *
   * @param array $record
   *   The stage's record.
   * @param array<string, mixed> $name
   *   The name this create's caller gives.
   *
   * @return array|null
   *   The entry, or NULL when no create holds the name.
   */
  protected function creating(array $record, array $name): ?array {
    $identity = $this->identityKnown() + $name;
    foreach ($record['writes'] as $write) {
      if ($write['kind'] === 'create' && $write['storage'] === $this->storageClass && self::sameThing($identity, (array) $write['known'])) {
        return $write;
      }
    }
    return NULL;
  }

  /**
   * Gets the identity the context knows.
   *
   * @return array<string, mixed>
   *   The known values of the resource's identity keys.
   */
  protected function identityKnown(): array {
    return array_intersect_key($this->context->known, array_flip($this->identity));
  }

  /**
   * Gets what a write records as its arguments.
   *
   * @return array<string, mixed>
   *   The arguments the stage was handed, else the operation's parameters
   *   the context knows.
   */
  protected function arguments(): array {
    return $this->stage->arguments === [] ? $this->parametersKnown() : $this->stage->arguments;
  }

  /**
   * Gets the operation's parameters out of what the context knows.
   *
   * What a write records as its arguments when the stage was handed none:
   * the parameters by name, never the whole identity, which for a derived
   * resource names keys the operation does not take.
   *
   * @return array<string, mixed>
   *   The known value of each parameter the context knows, by name.
   */
  protected function parametersKnown(): array {
    return array_intersect_key($this->context->known, array_flip($this->parameters));
  }

  /**
   * Answers whether two known identities name one thing.
   *
   * @param array<string, mixed> $known
   *   This storage's known identity.
   * @param array<string, mixed> $other
   *   What another entry's context knew.
   * @param bool $whole
   *   TRUE to ask that the other knows every key this one knows, as for
   *   one resource; FALSE to ask that every key both know agrees, at
   *   least one, as for two resources over one stored thing.
   *
   * @return bool
   *   TRUE when they name one thing.
   */
  protected static function sameThing(array $known, array $other, bool $whole = FALSE): bool {
    $shared = array_intersect_key($known, $other);
    if ($whole ? count($shared) !== count($known) : $shared === []) {
      return FALSE;
    }
    foreach ($shared as $key => $value) {
      if (self::scalar($value) !== self::scalar($other[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Reads an identity value as text, so 12 and "12" name one thing.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string|null
   *   The text, or NULL for NULL.
   */
  protected static function scalar(mixed $value): ?string {
    return $value === NULL ? NULL : (is_scalar($value) ? (string) $value : serialize($value));
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
   *   The refusal, for the pipeline to answer as its violations.
   */
  protected static function refusal(\Stringable|string $message, string $code): StorageViolationsException {
    return new StorageViolationsException(new ViolationSet([new Violation('', '', $message, $code)]));
  }

}
