<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationMessage;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface_entity\FieldItemDerivation;
use Drupal\data_surface_entity\Resource\FieldItemStructures;
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
 * On edit, a resource that carries the revision log field makes a new
 * revision, with this account as its author; one that does not leaves
 * revisions to the entity type's own default.
 *
 * Decision: see docs/decisions.md#the-content-entity-storage.
 */
final class ContentEntityStorage implements ResourceStorageInterface, ContainerInjectionInterface {

  use DependencySerializationTrait;

  /**
   * The resource key a violation about the whole entity is filed under.
   */
  public const ENTITY = '@entity';

  /**
   * The entity the last commit saved: entity type id, bundle and id.
   *
   * @var array{0: string, 1: string, 2: int|string}|null
   */
  protected ?array $committed = NULL;

  /**
   * Constructs a ContentEntityStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\data_surface_entity\FieldItemDerivation $derivation
   *   Which fields and properties the resource carries.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account a new revision is by.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   When a new revision is made.
   * @param array<class-string, string> $overrides
   *   The classes written by hand for a field type, valued with their
   *   module, as the field item deriver is handed them.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FieldItemDerivation $derivation,
    protected readonly AccountInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly array $overrides = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $classes = $container->getParameter('data_surface.resource_classes');
    return new static(
      $container->get('entity_type.manager'),
      $container->get('data_surface_entity.field_item_derivation'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      is_array($classes) ? ($classes['overrides'][FieldItemStructures::class] ?? []) : [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    [$entity_type_id, $bundle, $id] = static::identity($context);
    if ($id === NULL && !$context->creates && $this->committed !== NULL && [$entity_type_id, $bundle] === array_slice($this->committed, 0, 2)) {
      $id = $this->committed[2];
    }
    if ($id === NULL) {
      return [];
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $values = [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $this->derivation->idType($entity_type_id) === 'integer' ? (int) $entity->id() : (string) $entity->id(),
    ];
    foreach ($this->fields($entity_type_id, $bundle) as $name => $field) {
      $item = $entity->get($name)->first();
      if ($item === NULL || $item->isEmpty()) {
        continue;
      }
      $values[$name] = $this->read($field, $item->getValue());
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values): array {
    [$entity_type_id, $bundle, $id] = static::identity($context);
    $fields = $this->fields($entity_type_id, $bundle);
    $items = [];
    foreach ($fields as $name => $field) {
      if (!array_key_exists($name, $values)) {
        continue;
      }
      $item = is_array($values[$name]) ? $values[$name] : [];
      $empty = array_filter($item, static fn (mixed $value): bool => $value !== NULL) === [];
      // A new entity's field nobody answered keeps what the entity gives
      // it: a default value callback, the field type's own default.
      if ($empty && $context->creates) {
        continue;
      }
      $items[$name] = $empty ? NULL : $item;
    }
    $prepared = [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $context->creates ? NULL : $id,
      'new_revision' => !$context->creates && isset($fields['revision_log']),
      'fields' => $items,
    ];
    $entity = $this->apply($prepared);
    $violations = $entity->validate();
    // As core's form: what the account may not edit, and what the form
    // does not show, is not this submission's to answer for.
    $violations->filterByFieldAccess($this->currentUser);
    $violations->filterByFields(array_diff(array_keys($entity->getFieldDefinitions()), array_keys($fields)));
    $refused = [];
    foreach ($violations as $violation) {
      $refused[] = static::violation($violation->getPropertyPath(), ViolationMessage::of($violation));
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return $prepared;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $entity = $this->apply($prepared);
    $entity->save();
    $this->committed = [$prepared['entity_type'], $prepared['bundle'], $entity->id()];
  }

  /**
   * Puts prepared values on an entity: a new one, or a fresh copy.
   *
   * @param array $prepared
   *   What prepare() returned.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity, unsaved.
   */
  protected function apply(array $prepared): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($prepared['entity_type']);
    if ($prepared['id'] === NULL) {
      $bundle_key = (string) $this->entityTypeManager->getDefinition($prepared['entity_type'])->getKey('bundle');
      $entity = $storage->create($bundle_key === '' ? [] : [$bundle_key => $prepared['bundle']]);
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
        $entity->setRevisionUserId((int) $this->currentUser->id());
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
   * @param \Drupal\data_surface\Resource\OperationContext $context
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
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The violation, under the field's key and at the property inside it,
   *   or under self::ENTITY.
   */
  public static function violation(string $path, string|\Stringable $message): Violation {
    if ($path === '') {
      return new Violation(self::ENTITY, '', $message);
    }
    $segments = explode('.', $path);
    $field = array_shift($segments);
    // The delta: one item is all a field child holds.
    if ($segments !== [] && ctype_digit($segments[0])) {
      array_shift($segments);
    }
    return new Violation($field, implode('.', $segments), $message);
  }

}
