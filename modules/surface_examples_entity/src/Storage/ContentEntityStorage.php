<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\ViolationMessage;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Resource\DeletableStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\surface_examples_entity\Resource\FieldItemStructures;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A content entity, as a content entity resource's storage.
 *
 * Load reads the entity the context names into the resource's keys: its
 * identity, and each carried field's first item, property by property.
 * Prepare sets the accepted values on an entity, a new one of the bundle
 * or a fresh copy of the stored one (never the object anyone else holds),
 * each field through the entity's own set(), runs the entity's own
 * validation, and maps every violation back to the resource key it is
 * about by its property path (`field_description.0.value` is the
 * `field_description` child's `value`), so a constraint the entity type
 * or a field declares reaches the form and the tool beside the key it is
 * about. A violation about the entity as a whole is filed under
 * self::ENTITY; one about a field the resource does not carry is dropped,
 * as core's form drops it. Commit applies the same values again and
 * saves.
 *
 * After a commit the storage describes what it saved: a load in a
 * context that knows no id reads the entity it just created, which is how
 * the resource's `id` output answers with the new id.
 *
 * Where the caller names an entity type's ids (a string id with no
 * default), a create's context does not know the id: the caller sends it
 * as a value, the new entity is created under it, and an id an entity
 * already has is refused at `id`, code `taken`.
 *
 * On edit, a resource that carries the revision log field makes a new
 * revision, with this account as its author; one that does not leaves
 * revisions to the entity type's own default.
 *
 * A bundle's resource holds its fields at its root and knows its bundle.
 * An entity type's resource holds them under a key its context names
 * (self::FIELDS_AT), and knows its bundle on edit, from the entity; on
 * add the bundle is the value the caller chose. A violation about a field
 * is then filed under that key.
 *
 * A delete removes the entity the context names, with every translation
 * and every revision it has: core's storage rule, which removes them all
 * in one transaction of its own, where core's delete form removes a
 * translation that is not the default one alone. The rule is the entity
 * type's storage handler's, and a module that replaces the handler
 * replaces it: the contrib trash module's keeps the entity, its
 * translations and its revisions in the database, marked deleted and
 * restorable, and a load finds it no more. prepareDelete() finds
 * the entity and refuses one that is gone, code `not_found`; it asks no
 * access, which the access class answers, and validates nothing. What
 * the entity's delete hooks do beside the database is theirs.
 *
 * A save and a delete are writes through the database connection alone,
 * so a stage may hold this storage's writes: when an apply that saved or
 * deleted an entity is rolled back, rolledBack() drops the entity
 * storage's cache of it, so a load after it reads what the database
 * holds.
 *
 * Decision: see docs/decisions.md#the-content-entity-storage.
 */
final class ContentEntityStorage implements DeletableStorageInterface, TransactionalStorageInterface, ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The resource key a violation about the whole entity is filed under.
   */
  public const ENTITY = '@entity';

  /**
   * What a context knows the key the fields sit under by, when not the root.
   */
  public const FIELDS_AT = 'fields_at';

  /**
   * The entity the last commit saved or removed: entity type, bundle, id.
   *
   * @var array{0: string, 1: string, 2: int|string}|null
   */
  protected ?array $committed = NULL;

  /**
   * The entities a commit or a delete wrote since the last rollback, by type.
   *
   * @var array<string, array<int|string, int|string>>
   */
  protected array $written = [];

  /**
   * Constructs a ContentEntityStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   Which fields and properties the resource carries.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   When a new revision is made.
   * @param array<class-string, string> $overrides
   *   The classes written by hand for a field type, valued with their
   *   module, as the field item deriver is handed them.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FieldItemDerivation $derivation,
    protected readonly TimeInterface $time,
    protected readonly array $overrides = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $classes = $container->getParameter('surface.resource_classes');
    $storage = new static(
      $container->get('entity_type.manager'),
      $container->get('surface_examples_entity.field_item_derivation'),
      $container->get('datetime.time'),
      is_array($classes) ? ($classes['overrides'][FieldItemStructures::class] ?? []) : [],
    );
    $storage->setStringTranslation($container->get('string_translation'));
    return $storage;
  }

  /**
   * Loads the entity an operation's id names, for its context to read.
   *
   * What an operation of a content entity resource is handed is the id,
   * the identity key's value; the entity is this storage's to load.
   *
   * Decision: see docs/decisions.md#a-parameter-names-a-key.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param mixed $id
   *   The id the operation was handed.
   * @param string $operation
   *   The operation and the resource, for the message.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `id`, when the id names no entity of the type: a URL naming it
   *   names nothing.
   * @throws \InvalidArgumentException
   *   When what it was handed is no id.
   */
  public function entity(string $entity_type_id, mixed $id, string $operation): ContentEntityInterface {
    if (!is_int($id) && !is_string($id) || $id === '') {
      throw new \InvalidArgumentException(sprintf('%s takes the id of a %s, and was handed %s.', $operation, $entity_type_id, get_debug_type($id)));
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new NamesNothingException(sprintf('%s takes the id of a %s, and there is none with the id "%s".', $operation, $entity_type_id, $id), 'id');
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    [$entity_type_id, $bundle, $id] = static::identity($context);
    // An entity type's resource knows no bundle before there is an entity.
    $committed = $this->committed !== NULL
      && $entity_type_id === $this->committed[0]
      && in_array($bundle, ['', $this->committed[1]], TRUE);
    if ($id === NULL && !$context->creates && $committed) {
      $id = $this->committed[2];
    }
    if ($id === NULL) {
      return [];
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $bundle = $entity->bundle();
    $values = [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $this->derivation->idType($entity_type_id) === 'integer' ? (int) $entity->id() : (string) $entity->id(),
    ];
    $fields = [];
    foreach ($this->fields($entity_type_id, $bundle) as $name => $field) {
      $item = $entity->get($name)->first();
      if ($item === NULL || $item->isEmpty()) {
        continue;
      }
      $fields[$name] = $this->read($field, $item->getValue());
    }
    $at = static::fieldsAt($context);
    return $at === NULL ? $values + $fields : $values + [$at => $fields];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    [$prepared, $fields, $at] = $this->prepared($context, $values);
    $entity = $this->apply($prepared, $account);
    $refused = $this->refusals($entity, $fields, $account, $at);
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return $prepared;
  }

  /**
   * Builds the entity a write would save, and saves nothing.
   *
   * What prepare() builds and validates, for a page that shows a write
   * before it is made: the stored entity, never the object anyone else
   * holds, or a new one of the bundle, with the values set on it, marked
   * `in_preview` so core's view builders cache nothing of it and draw no
   * links. The entity's own validation is answered beside it, as prepare()
   * files it, rather than thrown.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context, which names the entity type, the bundle and the id; a
   *   create's knows no id.
   * @param array $values
   *   The values, in the resource's shape: the staged view of the thing.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the write would be made as.
   *
   * @return array{0: \Drupal\Core\Entity\ContentEntityInterface, 1: \Drupal\surface\Pipeline\Violation[]}
   *   The entity, unsaved, and what its validation refused, by resource
   *   key.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   As prepare() refuses before it builds: a bundle that cannot be
   *   created, or a name another entity has.
   */
  public function preview(OperationContext $context, array $values, AccountInterface $account): array {
    [$prepared, $fields, $at] = $this->prepared($context, $values);
    $entity = $this->apply($prepared, $account);
    // A property of the entity object, not a field, as NodeForm sets it.
    // @phpstan-ignore assign.propertyType
    $entity->in_preview = TRUE;
    return [$entity, $this->refusals($entity, $fields, $account, $at)];
  }

  /**
   * Reads accepted values as what apply() puts on an entity.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   * @param array $values
   *   The accepted values.
   *
   * @return array{0: array, 1: array<string, \Drupal\Core\Field\FieldDefinitionInterface>, 2: string|null}
   *   What apply() takes, the fields the resource carries, and the key
   *   they sit under, or NULL for the root.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   For a bundle that cannot be created, or a name another entity has.
   */
  protected function prepared(OperationContext $context, array $values): array {
    [$entity_type_id, $bundle, $id] = static::identity($context);
    $at = static::fieldsAt($context);
    if ($bundle === '' && $at !== NULL) {
      // An entity type's resource on add: the bundle the caller chose,
      // which its key held to those the account may create.
      $bundle = is_string($values['bundle'] ?? NULL) ? $values['bundle'] : '';
      if ($bundle === '' || $this->derivation->formDisplay($entity_type_id, $bundle) === NULL) {
        $refused = new Violation('bundle', '', $this->t('Choose a bundle of the @entity_type with a form display.', ['@entity_type' => $entity_type_id]), 'invalid');
        throw new StorageViolationsException(new ViolationSet([$refused]));
      }
    }
    $given = $at === NULL ? $values : (is_array($values[$at] ?? NULL) ? $values[$at] : []);
    $fields = $this->fields($entity_type_id, $bundle);
    $items = [];
    foreach ($fields as $name => $field) {
      if (!array_key_exists($name, $given)) {
        continue;
      }
      $item = is_array($given[$name]) ? $given[$name] : [];
      $empty = array_filter($item, static fn (mixed $value): bool => $value !== NULL) === [];
      // A new entity's field nobody answered keeps what the entity gives
      // it: a default value callback, the field type's own default.
      if ($empty && $context->creates) {
        continue;
      }
      $items[$name] = $empty ? NULL : $item;
    }
    // A create whose context does not know the id is one whose caller
    // names it: an id an entity already has names something, and is
    // refused at the key.
    // Decision: see
    // docs/decisions.md#an-entity-types-id-is-computed-or-named-by-the-caller.
    $named = $context->creates && !array_key_exists('id', $context->known) && (is_string($values['id'] ?? NULL) || is_int($values['id'] ?? NULL)) ? $values['id'] : NULL;
    if ($named !== NULL && $this->entityTypeManager->getStorage($entity_type_id)->load($named) !== NULL) {
      $taken = new Violation('id', '', $this->t('A @thing with the id @id already exists.', [
        '@thing' => $this->entityTypeManager->getDefinition($entity_type_id)->getSingularLabel(),
        '@id' => $named,
      ]), Violation::TAKEN);
      throw new StorageViolationsException(new ViolationSet([$taken]));
    }
    $prepared = [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $context->creates ? NULL : $id,
      'named' => $named,
      'new_revision' => !$context->creates && isset($fields['revision_log']),
      'fields' => $items,
    ];
    return [$prepared, $fields, $at];
  }

  /**
   * Validates an entity as core's form does, by resource key.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, its values set.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The fields the resource carries.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the write is made as.
   * @param string|null $at
   *   The key the fields sit under, or NULL for the root.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   What the entity's validation refused.
   */
  protected function refusals(ContentEntityInterface $entity, array $fields, AccountInterface $account, ?string $at): array {
    $violations = $entity->validate();
    // As core's form: what the account may not edit, and what the form
    // does not show, is not this submission's to answer for.
    $violations->filterByFieldAccess($account);
    $violations->filterByFields(array_diff(array_keys($entity->getFieldDefinitions()), array_keys($fields)));
    $refused = [];
    foreach ($violations as $violation) {
      $refused[] = static::violation($violation->getPropertyPath(), ViolationMessage::of($violation), Violation::codeOf($violation), $at);
    }
    return $refused;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $type = (string) $prepared['entity_type'];
    // Before the save, so a save that throws after writing is reset too.
    if ($prepared['id'] !== NULL) {
      $this->written[$type][$prepared['id']] = $prepared['id'];
    }
    $entity = $this->apply($prepared, $account);
    $entity->save();
    $this->written[$type][$entity->id()] = $entity->id();
    $this->committed = [$prepared['entity_type'], $prepared['bundle'], $entity->id()];
  }

  /**
   * {@inheritdoc}
   *
   * Finds the entity the context names; one that is gone is refused, at
   * the whole call. What delete() is handed is the entity's type, bundle
   * and id.
   */
  public function prepareDelete(OperationContext $context, AccountInterface $account): array {
    $entity = $this->stored($context);
    if ($entity === NULL) {
      $nothing = new Violation('', '', $this->t('Nothing is stored under this id, so nothing was removed.'), Violation::NOT_FOUND);
      throw new StorageViolationsException(new ViolationSet([$nothing]));
    }
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'id' => $entity->id(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The entity as stored, never the object anyone else holds, removed
   * with every translation and revision; one gone by then is gone.
   */
  public function delete(OperationContext $context, array $prepared, AccountInterface $account): void {
    $type = (string) $prepared['entity_type'];
    $entity = $this->entityTypeManager->getStorage($type)->loadUnchanged($prepared['id']);
    if ($entity === NULL) {
      return;
    }
    // Before the delete, so a delete that throws after writing is reset
    // too.
    $this->written[$type][$entity->id()] = $entity->id();
    $entity->delete();
    $this->committed = [$type, (string) $prepared['bundle'], $entity->id()];
  }

  /**
   * {@inheritdoc}
   *
   * Whether an entity of the context's entity type, and of its bundle when
   * the context knows one, is stored under the id it knows.
   */
  public function exists(OperationContext $context): bool {
    return $this->stored($context) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Read by SurfaceUnique, which holds only a resource with one identity
   * key, never these; one storage serves every entity type, so it names
   * none.
   */
  public function singularLabel(): \Stringable {
    return $this->t('item');
  }

  /**
   * {@inheritdoc}
   *
   * The entity storage's caches, static and persistent, of every entity a
   * commit wrote since the last rollback.
   */
  public function rolledBack(): void {
    foreach ($this->written as $type => $ids) {
      $this->entityTypeManager->getStorage($type)->resetCache(array_values($ids));
    }
    $this->written = [];
  }

  /**
   * Loads the entity a context names, of its bundle when it knows one.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity, or NULL when none is stored under the id it knows.
   */
  protected function stored(OperationContext $context): ?ContentEntityInterface {
    [$entity_type_id, $bundle, $id] = static::identity($context);
    if ($entity_type_id === '' || $id === NULL || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface || ($bundle !== '' && $entity->bundle() !== $bundle)) {
      return NULL;
    }
    return $entity;
  }

  /**
   * Puts prepared values on an entity: a new one, or a fresh copy.
   *
   * A new entity with an owner is the account's, and a new revision is
   * by it: the account the operation is written as, handed in, never the
   * current user read here.
   *
   * Decision: see
   * docs/decisions.md#nothing-an-operation-runs-reads-the-request.
   *
   * @param array $prepared
   *   What prepare() returned.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the write is made as.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity, unsaved.
   */
  protected function apply(array $prepared, AccountInterface $account): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($prepared['entity_type']);
    if ($prepared['id'] === NULL) {
      $entity_type = $this->entityTypeManager->getDefinition($prepared['entity_type']);
      $bundle_key = (string) $entity_type->getKey('bundle');
      $initial = $bundle_key === '' ? [] : [$bundle_key => $prepared['bundle']];
      // The id a caller names, where storage gives none.
      if (($prepared['named'] ?? NULL) !== NULL) {
        $initial[(string) $entity_type->getKey('id')] = $prepared['named'];
      }
      $entity = $storage->create($initial);
      if ($entity instanceof EntityOwnerInterface) {
        // A value the resource carries for the owner is set below.
        $entity->setOwnerId((int) $account->id());
      }
    }
    else {
      // Never the object the static cache, a form or a route holds.
      $entity = $storage->loadUnchanged($prepared['id']);
    }
    if (!$entity instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException(sprintf('There is no %s %s to write to.', $prepared['entity_type'], $prepared['id']));
    }
    foreach ($prepared['fields'] as $name => $item) {
      if ($item === NULL) {
        $entity->set($name, NULL);
        continue;
      }
      // Properties the structure does not carry (a link's options) keep
      // what the item holds.
      $current = $entity->get($name)->first()?->getValue() ?? [];
      $entity->set($name, [array_replace($current, $item)]);
    }
    if ($prepared['new_revision'] && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionUserId((int) $account->id());
        $entity->setRevisionCreationTime($this->time->getRequestTime());
      }
    }
    return $entity;
  }

  /**
   * Reads one field's first item as the item structure's keys.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param array $item
   *   The item's values.
   *
   * @return array
   *   The values of the keys the item structure has, cast to their types.
   */
  protected function read(FieldDefinitionInterface $field, array $item): array {
    $types = isset($this->overriddenTypes()[$field->getType()]) ? NULL : $this->derivation->properties($field);
    if ($types === NULL) {
      // A structure written by hand names properties; read every stored
      // one.
      $stored = array_filter(
        $field->getFieldStorageDefinition()->getPropertyDefinitions(),
        static fn ($definition): bool => !$definition->isComputed(),
      );
      return array_intersect_key($item, $stored);
    }
    $values = [];
    foreach ($types as $name => $type) {
      $value = $item[$name] ?? NULL;
      $values[$name] = $value === NULL ? NULL : match ($type) {
        'boolean' => (bool) $value,
        'integer' => (int) $value,
        'float' => (float) $value,
        default => (string) $value,
      };
    }
    return $values;
  }

  /**
   * Gets the fields the bundle's resource carries.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The fields, by name.
   */
  protected function fields(string $entity_type_id, string $bundle): array {
    return $this->derivation->fields($entity_type_id, $bundle, $this->overriddenTypes())['fields'];
  }

  /**
   * Gets the field types someone wrote an item structure for.
   *
   * @return array<string, class-string>
   *   The class for each field type.
   */
  protected function overriddenTypes(): array {
    return FieldItemStructures::overridesByType($this->overrides);
  }

  /**
   * Reads which entity a context is about.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   *
   * @return array{0: string, 1: string, 2: int|string|null}
   *   The entity type id, the bundle and the id, NULL when there is none.
   */
  protected static function identity(OperationContext $context): array {
    $id = $context->known['id'] ?? NULL;
    return [
      (string) ($context->known['entity_type'] ?? ''),
      (string) ($context->known['bundle'] ?? ''),
      is_int($id) || is_string($id) ? $id : NULL,
    ];
  }

  /**
   * Files one entity violation under the resource key it is about.
   *
   * @param string $path
   *   The violation's property path: `field.delta.property`, `field`, or
   *   empty for the entity as a whole.
   * @param string|\Stringable $message
   *   Its message.
   * @param string $code
   *   Its code (Violation::codeOf()).
   * @param string|null $at
   *   The key the fields sit under, or NULL for the root.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The violation, under the field's key and at the property inside it,
   *   that key under $at when the fields sit there, or under self::ENTITY.
   */
  public static function violation(string $path, string|\Stringable $message, string $code = 'invalid', ?string $at = NULL): Violation {
    if ($path === '') {
      return new Violation(self::ENTITY, '', $message, $code);
    }
    $segments = explode('.', $path);
    $field = array_shift($segments);
    // The delta: one item is all a field child holds.
    if ($segments !== [] && ctype_digit($segments[0])) {
      array_shift($segments);
    }
    if ($at !== NULL) {
      return new Violation($at, implode('.', [$field, ...$segments]), $message, $code);
    }
    return new Violation($field, implode('.', $segments), $message, $code);
  }

  /**
   * Reads where a context's resource holds the fields.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   *
   * @return string|null
   *   The key they sit under, or NULL for the root.
   */
  protected static function fieldsAt(OperationContext $context): ?string {
    $at = $context->known[self::FIELDS_AT] ?? NULL;
    return is_string($at) && $at !== '' ? $at : NULL;
  }

}
