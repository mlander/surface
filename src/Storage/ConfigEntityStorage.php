<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\DeletableStorageInterface;
use Drupal\data_surface\Resource\HeldToConfigSchemaInterface;
use Drupal\data_surface\Resource\KnowsClearableKeysInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps a resource's values on one config entity, named by its identity.
 *
 * The storage for a resource whose thing is a config entity: a placed
 * block, a content type, an image style. A module names the entity type
 * and how its keys map to the entity's properties on a subclass, and the
 * resource names the subclass:
 *
 * @code
 * final class ImageStyleStorage extends ConfigEntityStorage {
 *   public const ENTITY_TYPE_ID = 'image_style';
 *   public const PROPERTIES = ['name' => 'name', 'label' => 'label'];
 * }
 * @endcode
 *
 * A key maps to an entity property by name, or to `['method' => 'setX']`
 * for a property that needs a real setter, the spelling core's config
 * actions use; a key the map leaves out is the subclass's to read and
 * write (toEntity(), fromEntity()), as the block placement's settings
 * and visibility are. The key that maps to the entity type's id key is
 * the entity's identity, read from what the context knows.
 *
 * - load() reads the entity the context names; nothing for one that
 *   creates, so a new thing starts from what the resource declares.
 * - prepare() builds the entity it would save, unsaved: a copy of the
 *   one the context names, or a new one under the id the values carry,
 *   refused when that id is taken. It calculates the dependencies the
 *   entity would save with and holds the whole entity to its config
 *   schema, which for a config entity is its validation, filing each
 *   violation under the key that owns the property. It returns the
 *   entity's exported array and writes nothing.
 * - commit() saves exactly that array, the uuid the rehearsal handed out
 *   included, so what was previewed is what is stored. The pipeline
 *   calls it only when something moved.
 * - prepareDelete() and delete() remove the entity the context names.
 * - mayClear() and widerThanSchema() read the entity's config schema,
 *   `<config prefix>.*`, for the keys mapped to a property.
 *
 * Another module's mounted keys arrive under third_party_settings.<module>
 * in the layout they are stored in, and are written with
 * setThirdPartySetting(), where core keeps them.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 *
 * @see docs/storage.md#a-config-entity
 */
abstract class ConfigEntityStorage implements DeletableStorageInterface, KnowsClearableKeysInterface, HeldToConfigSchemaInterface {

  use DependencySerializationTrait;
  use HeldToConfigSchemaTrait;
  use StringTranslationTrait;

  /**
   * The config entity type the values live on, named by the subclass.
   */
  public const ENTITY_TYPE_ID = '';

  /**
   * Each key the entity stores directly: its property, or a method to call.
   *
   * A plain list entry names a key stored under its own name.
   *
   * @var array<int|string, string|array<string, string>>
   */
  public const PROPERTIES = [];

  /**
   * The optional keys the entity always holds a value for.
   *
   * For an entity type whose config schema is written from the resource
   * (drush data_surface:config-schema --write): its generated schema
   * leaves `nullable: true` off these, and mayClear() then answers that
   * they may not be cleared. Every other optional key may. An entity type
   * whose schema is written by hand says it there.
   *
   * @see docs/decisions.md#a-key-its-storage-always-holds
   */
  public const ALWAYS_HELD = [];

  /**
   * The map key naming an entity method rather than a property.
   */
  public const METHOD = 'method';

  /**
   * Where another module's keys are mounted.
   */
  public const THIRD_PARTY = 'third_party_settings';

  /**
   * The exported array's keys the entity owns, which commit() never sets.
   */
  protected const OWNED = ['uuid', 'dependencies', '_core'];

  /**
   * Constructs a ConfigEntityStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which holds a rehearsed entity to its
   *   config schema.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gives each key the entity stores directly, by key.
   *
   * @return array<string, string|array{method: string}>
   *   The property each key is stored under, or the method that sets it.
   *
   * @throws \InvalidArgumentException
   *   When an entry names neither a property nor a method.
   */
  public static function properties(): array {
    $properties = [];
    foreach (static::PROPERTIES as $key => $property) {
      if (is_int($key) && is_string($property)) {
        $properties[$property] = $property;
        continue;
      }
      if (is_array($property)) {
        $method = $property[self::METHOD] ?? NULL;
        if (!is_string($method) || $method === '') {
          throw new \InvalidArgumentException(sprintf('In %s, the "%s" entry must name an entity property or an entity method.', static::class, $key));
        }
        $properties[(string) $key] = [self::METHOD => $method];
        continue;
      }
      if ($property === '') {
        throw new \InvalidArgumentException(sprintf('In %s, the "%s" entry must name an entity property or an entity method.', static::class, $key));
      }
      $properties[(string) $key] = $property;
    }
    return $properties;
  }

  /**
   * Gives the resource's key that holds the entity's id.
   *
   * @return string
   *   The key mapped to the entity type's id key.
   *
   * @throws \LogicException
   *   When no key is mapped to it.
   */
  public function idKey(): string {
    $id = (string) $this->entityType()->getKey('id');
    foreach (static::properties() as $key => $property) {
      if ($property === $id) {
        return $key;
      }
    }
    throw new \LogicException(sprintf('%s maps no key to the %s entity\'s id, "%s": the key that names the entity is its identity.', static::class, static::ENTITY_TYPE_ID, $id));
  }

  /**
   * {@inheritdoc}
   */
  public function configSchemaType(): string {
    return $this->entityType()->getConfigPrefix() . '.*';
  }

  /**
   * {@inheritdoc}
   *
   * The keys stored directly under a property; the rest are the
   * subclass's, not compared.
   */
  protected function configSchemaPaths(): array {
    return array_filter(static::properties(), 'is_string');
  }

  /**
   * {@inheritdoc}
   *
   * By core's rule, read from the entity's schema: in a fully validatable
   * one, a property it does not call nullable always holds a value. A key
   * stored otherwise is not known here.
   */
  public function mayClear(OperationContext $context, string $path): ?bool {
    $property = $this->configSchemaPaths()[$path] ?? NULL;
    if ($property === NULL) {
      return NULL;
    }
    $schema = $this->typedConfig->getDefinition($this->configSchemaType());
    if (!static::fullyValidatable($schema)) {
      return NULL;
    }
    return !empty($schema['mapping'][$property]['nullable']);
  }

  /**
   * {@inheritdoc}
   *
   * Every key, for an entity the context names; nothing for one it does
   * not, which a creating context never does.
   */
  public function load(OperationContext $context): array {
    $entity = $this->entity($context);
    return $entity === NULL ? [] : $this->fromEntity($entity);
  }

  /**
   * {@inheritdoc}
   *
   * The entity it would save, unsaved, as its exported array, its
   * dependencies calculated and held to its config schema.
   *
   * @throws \LogicException
   *   When the context edits an entity that does not exist.
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When the entity's config schema refuses it, or its id is taken.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $entity = $this->entity($context);
    $violations = [];
    if ($entity === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a %s that does not exist.', $context->id, static::ENTITY_TYPE_ID));
      }
      $key = $this->idKey();
      $id = $values[$key] ?? NULL;
      if (is_string($id) && $id !== '' && $this->storage()->load($id) !== NULL) {
        $violations[] = new Violation($key, '', $this->t('The machine name @id is already in use: choose another.', ['@id' => $id]), Violation::TAKEN);
      }
      $entity = $this->create($values);
    }
    else {
      // The stored entity is read, never written: everything below happens
      // to a copy, which is what a dry run hands back.
      $entity = $this->copy($entity, $values);
    }
    $this->toEntity($entity, $values);
    $entity->calculateDependencies();
    $record = $entity->toArray();
    array_push($violations, ...iterator_to_array($this->validate($entity, $record), FALSE));
    if ($violations !== []) {
      throw new StorageViolationsException(new ViolationSet($violations));
    }
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $id_key = (string) $this->entityType()->getKey('id');
    $id = $prepared[$id_key] ?? NULL;
    if (!is_string($id) || $id === '') {
      throw new \InvalidArgumentException(sprintf('The prepared values did not come from a %s storage.', static::ENTITY_TYPE_ID));
    }
    $entity = $this->entity($context) ?? $this->storage()->load($id);
    if (!$entity instanceof ConfigEntityInterface) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a %s that does not exist.', $context->id, static::ENTITY_TYPE_ID));
      }
      // The uuid the rehearsal handed out, so what was previewed is what
      // is stored.
      $entity = $this->create(array_intersect_key($prepared, array_flip([$id_key, 'uuid'])) + $prepared);
    }
    // Its identity and uuid are its own, and its dependencies are
    // recalculated on save.
    foreach (array_diff_key($prepared, array_flip([...static::OWNED, $id_key])) as $property => $value) {
      $entity->set((string) $property, $value);
    }
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDelete(OperationContext $context, AccountInterface $account): array {
    $entity = $this->entity($context);
    if ($entity === NULL) {
      $nothing = new Violation('', '', $this->t('Nothing is stored under this name, so nothing was removed.'), Violation::NOT_FOUND);
      throw new StorageViolationsException(new ViolationSet([$nothing]));
    }
    return [(string) $this->entityType()->getKey('id') => (string) $entity->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function delete(OperationContext $context, array $prepared, AccountInterface $account): void {
    $id = $prepared[(string) $this->entityType()->getKey('id')] ?? NULL;
    $entity = is_string($id) ? $this->storage()->load($id) : NULL;
    $entity?->delete();
  }

  /**
   * Reads the resource's values off a stored entity.
   *
   * Each mapped key from its property (a key set through a method is read
   * from the property of its own name), and each module's third party
   * settings. A subclass adds the keys it stores otherwise.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The values, keyed by input key.
   */
  protected function fromEntity(ConfigEntityInterface $entity): array {
    $values = [];
    foreach (static::properties() as $key => $property) {
      $values[$key] = $entity->get(is_string($property) ? $property : $key);
    }
    foreach ($entity->getThirdPartyProviders() as $provider) {
      $values[self::THIRD_PARTY][$provider] = $entity->getThirdPartySettings($provider);
    }
    return $values;
  }

  /**
   * Shapes accepted values onto an entity, never the stored one.
   *
   * Each mapped key that was sent, through its property or its method,
   * and each module's third party settings. A subclass adds the keys it
   * stores otherwise, and calls this first.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The copy being shaped, or the new entity.
   * @param array $values
   *   The accepted values.
   *
   * @throws \InvalidArgumentException
   *   When a key's method does not exist on the entity.
   */
  protected function toEntity(ConfigEntityInterface $entity, array $values): void {
    $id_key = $this->idKey();
    foreach (static::properties() as $key => $property) {
      if ($key === $id_key || !array_key_exists($key, $values)) {
        continue;
      }
      if (is_string($property)) {
        $entity->set($property, $values[$key]);
        continue;
      }
      // A setter is asked only for a value the entity does not hold
      // already: a create sends every key, nothing included.
      if ($entity->get($key) === $values[$key]) {
        continue;
      }
      $method = $property[self::METHOD];
      if (!method_exists($entity, $method)) {
        throw new \InvalidArgumentException(sprintf('The "%s" key is stored through %s::%s(), which does not exist.', $key, get_class($entity), $method));
      }
      $entity->{$method}($values[$key]);
    }
    foreach (is_array($values[self::THIRD_PARTY] ?? NULL) ? $values[self::THIRD_PARTY] : [] as $provider => $settings) {
      foreach (is_array($settings) ? $settings : [] as $setting => $value) {
        $entity->setThirdPartySetting((string) $provider, (string) $setting, $value);
      }
    }
  }

  /**
   * Holds a rehearsed entity to its config schema.
   *
   * Only the paths a key owns are reported: a violation elsewhere in the
   * entity is somebody else's, and does not block this write.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The rehearsed entity.
   * @param array $record
   *   Its exported array.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, filed under the keys that own them.
   */
  protected function validate(ConfigEntityInterface $entity, array $record): ViolationSet {
    return SchemaViolations::collect($this->typedConfig, $entity->getConfigDependencyName(), $record, $this->schemaPaths());
  }

  /**
   * Gives each config property path the key that owns it.
   *
   * @return array<string, string>
   *   Property path => key: every key mapped to a property, and the third
   *   party settings.
   */
  protected function schemaPaths(): array {
    $paths = [self::THIRD_PARTY => self::THIRD_PARTY];
    foreach (static::properties() as $key => $property) {
      $paths[is_string($property) ? $property : $key] = $key;
    }
    return $paths;
  }

  /**
   * Creates a new, unsaved entity for values naming it.
   *
   * @param array $values
   *   The accepted values, or a prepared record; the id is read from the
   *   resource's key, else the entity's own id key, and a uuid is kept.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The entity.
   */
  protected function create(array $values): ConfigEntityInterface {
    $id_key = (string) $this->entityType()->getKey('id');
    $initial = [$id_key => $values[$this->idKey()] ?? $values[$id_key] ?? ''];
    if (isset($values['uuid'])) {
      $initial['uuid'] = $values['uuid'];
    }
    $entity = $this->storage()->create($initial + $this->initial($values));
    assert($entity instanceof ConfigEntityInterface);
    return $entity;
  }

  /**
   * Copies a stored entity, to shape values onto.
   *
   * A new object made from its exported array, not a clone: a clone
   * shares the plugin collections of the entity the request holds, and
   * shaping settings onto it would reach that entity's plugins.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The stored entity.
   * @param array $values
   *   The accepted values.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The copy, not new.
   */
  protected function copy(ConfigEntityInterface $entity, array $values): ConfigEntityInterface {
    $copy = $this->storage()->create($entity->toArray() + $this->initial($values));
    assert($copy instanceof ConfigEntityInterface);
    $copy->enforceIsNew(FALSE);
    return $copy;
  }

  /**
   * Gives what a new entity must be created with, beyond its id.
   *
   * @param array $values
   *   The accepted values, or a prepared record.
   *
   * @return array
   *   Properties to hand create(): none by default. A block, whose plugin
   *   collection is built from its plugin id, needs that one.
   */
  protected function initial(array $values): array {
    return [];
  }

  /**
   * Loads the entity the context's identity names.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The entity, or NULL when the context names none that exists.
   */
  protected function entity(OperationContext $context): ?ConfigEntityInterface {
    $id = $context->known[$this->idKey()] ?? NULL;
    if (!is_string($id) || $id === '') {
      return NULL;
    }
    $entity = $this->storage()->load($id);
    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  /**
   * Gets the entity type's storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(static::ENTITY_TYPE_ID);
  }

  /**
   * Gets the entity type the subclass names.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityTypeInterface
   *   The entity type.
   *
   * @throws \LogicException
   *   When it names none, or one that is no config entity type.
   */
  protected function entityType(): ConfigEntityTypeInterface {
    $type = static::ENTITY_TYPE_ID === '' ? NULL : $this->entityTypeManager->getDefinition(static::ENTITY_TYPE_ID, FALSE);
    if (!$type instanceof ConfigEntityTypeInterface) {
      throw new \LogicException(sprintf('%s names no config entity type in ENTITY_TYPE_ID.', static::class));
    }
    return $type;
  }

}
