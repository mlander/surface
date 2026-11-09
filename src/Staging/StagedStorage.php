<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Component\Utility\NestedArray;
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
use Drupal\surface\Storage\SecretCodecException;
use Drupal\surface\Storage\SecretCodecInterface;
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
 * - prepare() refuses what a stage cannot hold, each a violation of
 *   the whole call, a call Stageable says a stage does not apply to
 *   among them, then rehearses the write as a real one: the adapter's
 *   prepare(), with every check the storage makes.
 * - commit() writes the stage's record, never storage: the merged
 *   document the pipeline accepted replaces the stage's entry for the
 *   thing whole, the last of two writes winning, and the top level keys
 *   the write moved join the entry's, the keys apply sends. Every secret
 *   in it is sealed with the site's codec, its path recorded under
 *   `sealed`, and a secret the write cleared is recorded under `cleared`
 *   (StagedSecrets); load() opens exactly the recorded paths.
 *
 * A create is staged as an entry of kind `create`, appended whole: it
 * names no stored thing, so it matches no entry and no entry matches it,
 * and two creates are two entries. Its first commit gives it an address,
 * kept for the stage's life: for each identity key its context knows to
 * be empty, the negative of its position (`-3`), its local id; for each
 * the caller names, the name. A later write in the stage that names the
 * address is built as the create's own context again, under the asked
 * operation's id, knowing the entry's position under POSITION
 * (ResourcesInterface::operation()): it finds that entry and nothing
 * else, a write merges into the create whole, and a delete takes the
 * create out of the stage. Apply only ever sees whole creates, and answers
 * each one's id. A name the caller gives a create is refused `taken`
 * while another create of the same storage class in the stage holds it,
 * through any resource.
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
 * resources is refused, and two resources of one storage class whose
 * contexts know no identity (two over one config object) are one thing.
 *
 * Decision: see
 * docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
 * Decision: see docs/decisions.md#a-staged-write-validates-as-a-real-write.
 * Decision: see
 * docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths.
 *
 * @internal
 */
final class StagedStorage implements CreatingStorageInterface, DeletingStorageInterface, OutputsStorageInterface {

  use StringTranslationTrait;

  /**
   * The known key that names the position of a create the stage holds.
   *
   * Carried by the context ResourcesInterface::operation() builds for a
   * thing the stage creates; no identity, so the build locks nothing by
   * it.
   *
   * @internal
   */
  public const POSITION = 'stage_position';

  /**
   * What load() answered last, for the keys a commit moved.
   */
  protected ?array $current = NULL;

  /**
   * The address of the create this storage wrote, or found, in the stage.
   *
   * @var array<string, int|string>
   */
  protected array $address = [];

  /**
   * The fingerprint of the thing as stored, at the first load from storage.
   */
  protected ?string $token = NULL;

  /**
   * Whether this storage committed a write to the stage.
   */
  protected bool $committed = FALSE;

  /**
   * The data surface's secrets, as Outbound::secrets() finds them.
   *
   * Captured at load() and prepare(), for commit(), which is handed no
   * data surface.
   *
   * @var array<string, true|array>
   */
  protected array $secrets = [];

  /**
   * Constructs a StagedStorage.
   *
   * @param \Drupal\surface\ResourceBuild\StorageAdapter $adapter
   *   The resource's storage, as the pipeline is handed it otherwise.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
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
   * @param \Drupal\surface\Storage\SecretCodecInterface $codec
   *   The codec a secret is sealed with in the record.
   */
  public function __construct(
    protected readonly StorageAdapter $adapter,
    protected readonly Stage $stage,
    protected readonly StageStoreInterface $stages,
    protected readonly string $resource,
    protected readonly OperationContext $context,
    protected readonly string $storageClass,
    protected readonly array $identity,
    protected readonly array $parameters,
    protected readonly SecretCodecInterface $codec,
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
   * token. A thing the stage creates is answered its entry's values; its
   * context creates, so the pipeline computes each computed key that takes
   * no sent value again from the merged sources, as apply's rehearsal of
   * the create computes it from what was sent. A secret the entry
   * sealed is opened, by the paths it recorded, so keep on empty keeps
   * it; every door projects it out of what it answers. A secret that
   * cannot be opened, since the site's hash salt changed after it was
   * staged, is left out, and prepare() refuses every write.
   */
  public function load(SurfaceInterface $surface): array {
    $this->secrets = Outbound::secrets($surface);
    $record = $this->stages->read($this->stage->id);
    $entry = $record === NULL ? NULL : $this->entry($record);
    if ($this->position() !== NULL) {
      if ($entry === NULL) {
        return $this->current = [];
      }
      $this->address = StageRecord::address($entry);
      return $this->current = array_intersect_key($this->readable($entry), $surface->getDefinitions()->toArray());
    }
    if ($entry !== NULL) {
      $this->token ??= $entry['token'];
      $values = $entry['kind'] === 'delete' ? [] : array_intersect_key($this->readable($entry), $surface->getDefinitions()->toArray());
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
   * stage that is gone, applied or voided, a stage whose seals the codec
   * cannot open (the hash salt changed), a thing the stage removed (a second
   * delete of it included), a storage class that does not declare a
   * transaction rolls its writes back whole, the resource's own or a
   * routed child's, an operation that names no thing (Stageable), a thing
   * the stage holds through another resource, and a write through another
   * operation than the one the thing is staged through, unless the write
   * deletes; else the adapter's own prepare(). A create
   * is no thing reached twice, so the last two do not apply to it; one
   * whose caller names it is refused `taken` at the name while a create
   * of the same storage class in the stage holds it. A delete is rehearsed
   * as a real one: the storage's prepareDelete().
   *
   * A write to a thing the stage creates is refused `not_found` when its
   * create was taken out of the stage, and is otherwise rehearsed as the
   * create of the merged whole document, the name check included; a
   * delete of one rehearses nothing, since nothing is stored to remove.
   */
  public function prepare(SurfaceInterface $surface, array $values): PreparedValues {
    $this->secrets = Outbound::secrets($surface);
    $record = $this->stages->read($this->stage->id);
    if ($record === NULL) {
      throw self::refusal($this->t('The stage expired or was discarded.'), Violation::NOT_FOUND);
    }
    $voided = StageStorage::voided($record);
    if ($voided !== NULL) {
      throw self::refusal($voided, Violation::DOES_NOT_APPLY);
    }
    if ($record['status'] !== StageStorage::OPEN) {
      throw self::refusal($this->t('The stage was applied; open a new one.'), Violation::DOES_NOT_APPLY);
    }
    $entry = $this->entry($record);
    // Decision: see
    // docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths.
    if ($entry !== NULL && !$this->opens($entry)) {
      throw self::refusal(StageStorage::unopened($this->stage->id), Violation::DOES_NOT_APPLY);
    }
    if ($entry === NULL && $this->position() !== NULL) {
      throw self::refusal($this->t('The stage no longer creates this thing: its create was taken out of the stage.'), Violation::NOT_FOUND);
    }
    if ($entry !== NULL && $entry['kind'] === 'delete') {
      throw self::refusal(
        $this->t('This was removed in the stage; the removal stands until the stage is applied or discarded.'),
        Violation::NOT_FOUND,
      );
    }
    // Decision: see
    // docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
    if (!Stageable::storage($this->storageClass)) {
      throw self::refusal($this->t('@resource cannot be staged: its storage, @class, does not declare that a database transaction rolls its writes back whole (@interface).', [
        '@resource' => $this->resource,
        '@class' => $this->storageClass,
        '@interface' => TransactionalStorageInterface::class,
      ]), Violation::DOES_NOT_APPLY);
    }
    // A routed child is committed with its parent at apply, in the same
    // transaction, so its storage makes the same promise.
    foreach ($this->adapter->routedStorageClasses() as $at => $class) {
      if (!Stageable::storage($class)) {
        throw self::refusal($this->t('@resource cannot be staged: its child at @at is stored by @class, which does not declare that a database transaction rolls its writes back whole (@interface).', [
          '@resource' => $this->resource,
          '@at' => $at,
          '@class' => $class,
          '@interface' => TransactionalStorageInterface::class,
        ]), Violation::DOES_NOT_APPLY);
      }
    }
    if (!Stageable::namesThing($this->identity, $this->parameters, $this->creates())) {
      throw self::refusal($this->t('@resource cannot be staged through the @operation operation: it names no thing, since it creates nothing and its parameters name none of the identity keys (@keys).', [
        '@resource' => $this->resource,
        '@operation' => $this->context->id,
        '@keys' => implode(', ', $this->identity),
      ]), Violation::DOES_NOT_APPLY);
    }
    // A new thing is no stored thing reached twice: two creates through
    // two resources are two things.
    if ($this->creates()) {
      if ($this->position() !== NULL && $this->deletes()) {
        return new PreparedValues($values, []);
      }
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
      if ($write['kind'] !== 'create' && $write['storage'] === $this->storageClass && $write['resource'] !== $this->resource && self::sameThing($known, $this->identityOf((array) $write['known']))) {
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
   * knew and the name its caller gave, and its address. A write to a thing
   * the stage creates replaces the create's values whole and joins the
   * keys it moved to the create's, everything else kept, and writes
   * nothing when it moved no key; a delete of one takes the entry out,
   * the next position unchanged. Storage is never written.
   *
   * The keys moved are compared in the clear; then each secret in the
   * values is sealed, its path recorded under `sealed`, and each secret
   * path the write cleared joins the entry's `cleared`, which a path set
   * again leaves. An entry with neither has neither key. A delete's entry
   * holds no values, so neither.
   *
   * @throws \Drupal\surface\Storage\SecretCodecException
   *   When a secret holds what is not a string, or the codec has no key.
   * @throws \Drupal\surface\Staging\StageRaceException
   *   When the lock cannot be had, or the stage is gone or applied, or a
   *   create of the same storage class took the name a create's caller
   *   gave while the write was prepared, or the create a write was to was
   *   taken out of the stage meanwhile.
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
      $moved = self::moved($values, $this->current ?? [], !$this->creates());
      $name = $this->creates() ? $this->name($values) : [];
      if ($name !== [] && $this->creating($record, $name) !== NULL) {
        throw new StageRaceException(sprintf('The stage "%s" took a create of the name "%s" while the write was prepared.', $id, implode(' ', array_map(self::scalar(...), $name))));
      }
      $found = $this->found($record);
      $position = $this->position();
      if ($position !== NULL && $found === NULL) {
        throw new StageRaceException(sprintf('The stage "%s" no longer creates what position %d held: its create was taken out while the write was prepared.', $id, $position));
      }
      // Decision: see
      // docs/decisions.md#a-staged-create-is-named-by-a-local-id-and-a-later-write-merges-into-it.
      if ($position !== NULL) {
        $entry = $record['writes'][$found];
        $this->address = StageRecord::address($entry);
        if ($this->deletes()) {
          unset($record['writes'][$found]);
          $record['writes'] = array_values($record['writes']);
        }
        else {
          // Compared in the clear: sealing is not deterministic, so two
          // seals of one secret always differ.
          $held = $this->opened($entry);
          $moved = self::moved($values, $held);
          if ($moved === []) {
            // Nothing moved: no record is written, and no expiry reset.
            $this->committed = TRUE;
            return;
          }
          $entry = $this->sealed($entry, $values, $held);
          $entry['changed'] = array_values(array_unique([...$entry['changed'], ...$moved]));
          $entry['touched'] = $now;
          $record['writes'][$found] = $entry;
        }
      }
      // Decision: see
      // docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
      elseif ($found !== NULL && $this->deletes()) {
        $entry = $record['writes'][$found];
        $entry['operation'] = $this->context->id;
        $entry['kind'] = 'delete';
        $entry['arguments'] = $this->arguments();
        $entry['values'] = [];
        unset($entry['sealed'], $entry['cleared']);
        $entry['changed'] = [''];
        $entry['touched'] = $now;
        $record['writes'][$found] = $entry;
      }
      elseif ($found !== NULL) {
        $entry = $this->sealed($record['writes'][$found], $values, $this->current ?? []);
        $entry['changed'] = array_values(array_unique([...$entry['changed'], ...$moved]));
        $entry['touched'] = $now;
        $record['writes'][$found] = $entry;
      }
      else {
        $position = (int) $record['next'];
        if ($this->creates()) {
          $this->address = $this->addressFor($position, $name);
        }
        $entry = [
          'position' => $position,
          'resource' => $this->resource,
          'operation' => $this->context->id,
          'kind' => $this->deletes() ? 'delete' : ($this->creates() ? 'create' : 'update'),
          'storage' => $this->storageClass,
          'arguments' => $this->arguments(),
          'known' => array_filter($this->context->known, static fn (mixed $value): bool => $value === NULL || is_scalar($value)) + $name,
          'values' => [],
          'changed' => $this->deletes() ? [''] : $moved,
          'token' => $this->creates() ? NULL : $this->token,
          'staged' => $now,
          'touched' => $now,
        ] + ($this->creates() ? ['address' => $this->address] : []);
        $record['writes'][] = $this->deletes() ? $entry : $this->sealed($entry, $values, $this->current ?? []);
        $record['next'] = $position + 1;
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
   * create, and every write, read, delete or dry run of a thing the stage
   * creates, answers its values and what its context knows, each identity
   * key storage would give filled from the create's address and typed as
   * the output says (`id: -3`), less what is empty. A dry run of a create
   * has no address yet, and answers the adapter's.
   *
   * Decision: see docs/decisions.md#who-produces-outputs.
   */
  public function outputs(SurfaceInterface $surface, array $values, ?PreparedValues $prepared = NULL): array {
    $local = $this->position() !== NULL || ($this->creates() && $prepared === NULL && $this->committed);
    if (!$local && ($prepared !== NULL || !$this->committed)) {
      return $this->adapter->outputs($surface, $values, $prepared);
    }
    $outputs = $surface->getOutputDefinitions()->toArray();
    if ($outputs === []) {
      return [];
    }
    if (!$local) {
      return array_intersect_key($this->load($surface) + $this->context->known, $outputs);
    }
    $answered = $values + $this->context->known;
    foreach ($this->address as $key => $address) {
      if (($answered[$key] ?? NULL) === NULL && isset($outputs[$key])) {
        $answered[$key] = $outputs[$key]->getDataType() === 'integer' ? (int) $address : (string) $address;
      }
    }
    return array_intersect_key(array_filter($answered, static fn (mixed $value): bool => $value !== NULL), $outputs);
  }

  /**
   * Opens an entry's values: each secret it sealed, by its recorded path.
   *
   * @param array $entry
   *   The entry.
   *
   * @return array
   *   Its values in the clear.
   *
   * @throws \Drupal\surface\Storage\SecretCodecException
   *   When the codec cannot open one.
   */
  protected function opened(array $entry): array {
    return StagedSecrets::unseal($this->codec, (array) $entry['values'], (array) ($entry['sealed'] ?? []));
  }

  /**
   * Opens an entry's values for a load, leaving out what cannot be opened.
   *
   * @param array $entry
   *   The entry.
   *
   * @return array
   *   Its values in the clear; when a seal cannot be opened, the values
   *   less every sealed path, so no envelope is read as a value.
   */
  protected function readable(array $entry): array {
    try {
      return $this->opened($entry);
    }
    catch (SecretCodecException) {
      $values = (array) $entry['values'];
      foreach ((array) ($entry['sealed'] ?? []) as $path) {
        NestedArray::unsetValue($values, array_map('strval', (array) $path));
      }
      return $values;
    }
  }

  /**
   * Answers whether every seal of an entry can be opened.
   *
   * @param array $entry
   *   The entry.
   *
   * @return bool
   *   FALSE when the codec cannot open one: the site's hash salt changed
   *   since it was sealed.
   */
  protected function opens(array $entry): bool {
    try {
      $this->opened($entry);
      return TRUE;
    }
    catch (SecretCodecException) {
      return FALSE;
    }
  }

  /**
   * Gives an entry a write's values, every secret in them sealed.
   *
   * Decision: see
   * docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths.
   *
   * @param array $entry
   *   The entry.
   * @param array $values
   *   What the write prepared, in the clear.
   * @param array $held
   *   What the write was merged over, in the clear: what a secret path
   *   held before it was cleared.
   *
   * @return array
   *   The entry, its values sealed, `sealed` the paths sealed and
   *   `cleared` the paths left cleared, each key only when it lists one.
   *
   * @throws \Drupal\surface\Storage\SecretCodecException
   *   When a secret holds what is not a string, or the codec has no key.
   */
  protected function sealed(array $entry, array $values, array $held): array {
    [$entry['values'], $sealed] = StagedSecrets::seal($this->codec, $values, $this->secrets);
    $cleared = StagedSecrets::stillCleared((array) ($entry['cleared'] ?? []), StagedSecrets::cleared($values, $held, $this->secrets), $values);
    unset($entry['sealed'], $entry['cleared']);
    return $entry + ($sealed === [] ? [] : ['sealed' => $sealed]) + ($cleared === [] ? [] : ['cleared' => $cleared]);
  }

  /**
   * Gets the address of the create this storage wrote, or found.
   *
   * @return array<string, int|string>
   *   By identity key: the local id, a negative integer, or the name the
   *   caller gave; empty before a create is written or found.
   *
   * @internal
   */
  public function address(): array {
    return $this->address;
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
   * creates is found by nothing but a context of the thing it creates,
   * which knows its position.
   *
   * @param array $record
   *   The stage's record.
   *
   * @return int|string|null
   *   The entry's index among the record's writes, or NULL.
   */
  protected function found(array $record): int|string|null {
    $position = $this->position();
    if ($position !== NULL) {
      foreach ($record['writes'] as $index => $write) {
        if ($write['kind'] === 'create' && $write['resource'] === $this->resource && (int) $write['position'] === $position) {
          return $index;
        }
      }
      return NULL;
    }
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
   * known identity agrees with this create's on every key both carry,
   * but the create this write is to.
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
      if ($write['kind'] === 'create' && (int) $write['position'] !== $this->position() && $write['storage'] === $this->storageClass && self::sameThing($identity, $this->identityOf((array) $write['known']))) {
        return $write;
      }
    }
    return NULL;
  }

  /**
   * Gets the position of the create the context is of, in the stage.
   *
   * @return int|null
   *   The position, or NULL for a context of no thing the stage creates.
   */
  protected function position(): ?int {
    $position = $this->context->known[self::POSITION] ?? NULL;
    return is_int($position) || is_string($position) ? (int) $position : NULL;
  }

  /**
   * Gives a new create the address a later write in the stage names it by.
   *
   * @param int $position
   *   The position the create is staged at.
   * @param array<string, mixed> $name
   *   The name the create's caller gives.
   *
   * @return array<string, int|string>
   *   For each identity key the context knows to be empty, the negative
   *   of the position; for each it does not know, the name given.
   */
  protected function addressFor(int $position, array $name): array {
    $address = [];
    foreach ($this->identity as $key) {
      if (array_key_exists($key, $this->context->known)) {
        if ($this->context->known[$key] === NULL) {
          $address[$key] = -$position;
        }
      }
      elseif (array_key_exists($key, $name)) {
        $address[$key] = is_int($name[$key]) ? $name[$key] : (string) $name[$key];
      }
    }
    return $address;
  }

  /**
   * Lists the top level keys a write moved, compared whole.
   *
   * For a write to a stored thing, a key one side leaves out and the
   * other holds empty (NULL, or an empty map such as the
   * `third_party_settings` a config storage prepares where nothing is
   * mounted yet) did not move: sent at apply, the empty value would say
   * the key holds nothing, where staging left it as stored. A create
   * moves every key it holds.
   *
   * @param array $values
   *   What the write prepared.
   * @param array $held
   *   What it is compared with.
   * @param bool $stored
   *   TRUE for a write to a stored thing, whose empty keys left out
   *   elsewhere did not move.
   *
   * @return string[]
   *   The keys either holds and the other does not, or holds otherwise.
   */
  protected static function moved(array $values, array $held, bool $stored = FALSE): array {
    $moved = [];
    foreach (array_keys($values + $held) as $key) {
      $here = array_key_exists($key, $values);
      $there = array_key_exists($key, $held);
      $only = $here ? ($values[$key] ?? NULL) : ($held[$key] ?? NULL);
      $empty = $only === NULL || $only === [];
      if ($here && $there ? $values[$key] !== $held[$key] : !$stored || !$empty) {
        $moved[] = (string) $key;
      }
    }
    return $moved;
  }

  /**
   * Gets the identity the context knows.
   *
   * @return array<string, mixed>
   *   The known values of the resource's identity keys.
   */
  protected function identityKnown(): array {
    return $this->identityOf($this->context->known);
  }

  /**
   * Keeps the identity keys of what a context knew.
   *
   * An entry records every scalar key its context knew; compared with
   * this storage's identity, it is filtered the same way, so the compare
   * answers the same whichever of two writes came first. Two entries of
   * one storage class share its identity keys.
   *
   * @param array<string, mixed> $known
   *   What a context knew.
   *
   * @return array<string, mixed>
   *   Its values of the resource's identity keys.
   */
  protected function identityOf(array $known): array {
    return array_intersect_key($known, array_flip($this->identity));
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
   *   least one, as for two resources over one stored thing, or that
   *   neither knows any: the caller compares the storage classes, and
   *   one class whose contexts know no identity keeps one thing, as a
   *   config object storage keeps one object.
   *
   * @return bool
   *   TRUE when they name one thing.
   */
  protected static function sameThing(array $known, array $other, bool $whole = FALSE): bool {
    $shared = array_intersect_key($known, $other);
    // Decision: see
    // docs/decisions.md#two-identity-less-entries-of-one-storage-class-are-one-thing.
    if (!$whole && $known === [] && $other === []) {
      return TRUE;
    }
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
