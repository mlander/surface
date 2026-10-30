<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Reference\ReferenceStorageLayout;
use Drupal\data_surface\Resource\HeldToConfigSchemaInterface;
use Drupal\data_surface\Resource\KnowsClearableKeysInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps a singleton resource's values in one simple config object.
 *
 * The storage for the most common resource there is, a settings page. A
 * module names its config object on a subclass, and the resource names
 * the subclass:
 *
 * @code
 * final class NewsletterStorage extends ConfigObjectStorage {
 *   public const CONFIG = 'data_surface_first.newsletter';
 * }
 * @endcode
 *
 * The values are stored as the resource accepted them, key for key: an
 * attached structure is the map at its key, and the keys another module
 * mounts arrive under third_party_settings.<module>.
 *
 * - load() reads the object, less what the storage owns.
 * - prepare() lays the values over what is stored and validates the whole
 *   object against its config schema, so a type that resolves through a
 *   sibling, or a translatable string that needs a language code, is
 *   judged in place; violations are filed under the keys being written.
 *   A third-party module's settings that no config schema describes are
 *   refused, since the schema would otherwise let them through unchecked.
 *   It writes nothing.
 * - commit() writes what prepare() returned.
 * - mayClear() says which keys the schema lets hold nothing, by core's
 *   own rule: in a fully validatable object, only those it calls
 *   nullable.
 * - widerThanSchema() says where the resource advertises more than the
 *   schema allows, which the build refuses: never, for a schema generated
 *   from the resource (drush data_surface:config-schema).
 * - layout() says how the object keeps a key it stores otherwise than it
 *   is asked for; load() and prepare() translate through it, and
 *   translatedKeys() tells the check and the generator its stored schema.
 * - STORED_AT says where the object keeps a key deeper than its own name,
 *   by dotted path, beside keys the resource does not declare; every
 *   method above reads and writes the key there, and keeps the rest.
 *
 * A key that refers to a content entity is stored by its uuid, which the
 * engine translates outside this class (ReferenceStorageLayout), so the
 * values here hold the uuid.
 *
 * The storage owns `_core`, which the config installer writes, and
 * `langcode`, which core's rule asks for exactly when the schema has a
 * translatable value: it keeps the stored language, or the site's default
 * language when none is stored, and removes one the schema does not ask
 * for. A resource never declares either.
 *
 * A context that creates is treated as one that edits. The object ships in
 * the module's config/install, but a missing one reads as empty: load()
 * answers nothing, so every key starts from its default, prepare() and
 * mayClear() read the schema with that, and the first commit() creates
 * the object.
 *
 * @see docs/storage.md#a-simple-config-object
 */
abstract class ConfigObjectStorage implements ResourceStorageInterface, KnowsClearableKeysInterface, HeldToConfigSchemaInterface {

  use DependencySerializationTrait;
  use HeldToConfigSchemaTrait;
  use StringTranslationTrait;

  /**
   * The config object the values live in, named by the subclass.
   */
  public const CONFIG = '';

  /**
   * The optional keys the object always holds a value for, by dotted path.
   *
   * Whether a key may hold nothing is a fact of where it is stored, so the
   * subclass says it: its generated schema leaves `nullable: true` off
   * these, and mayClear() then answers that they may not be cleared. Every
   * other optional key may.
   *
   * @see docs/decisions.md#a-key-its-storage-always-holds
   */
  public const ALWAYS_HELD = [];

  /**
   * Where the object keeps a key not at its own name: a dotted path, by key.
   *
   * A key the resource declares by its own name and the object keeps
   * deeper, beside keys the resource does not declare (core's
   * system.performance keeps the page cache's maximum age at
   * cache.page.max_age, beside css.compress). load() reads the key from
   * its path; prepare() lays it in at its path before the schema judges
   * the object and files what the schema says there under the key;
   * commit() sets the path alone, so what the object holds around it is
   * kept; mayClear() and the build's check read the schema at the path.
   * The config schema generator writes no schema for such an object: it is
   * one its owner wrote.
   *
   * @see docs/storage.md#a-key-stored-under-a-path
   */
  public const STORED_AT = [];

  /**
   * The keys the storage owns and a resource never declares.
   */
  public const OWNED = ['_core', 'langcode'];

  /**
   * Where another module's keys are mounted.
   */
  public const THIRD_PARTY = 'third_party_settings';

  /**
   * What mayClear() answered, by the stored data's hash and the path.
   *
   * @var array<string, array<string, bool|null>>
   */
  protected array $clearable = [];

  /**
   * Constructs a ConfigObjectStorage.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which holds the schema prepare() checks.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager, for the language code a new translatable
   *   object is stored in.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly LanguageManagerInterface $languageManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gives how the object lays out keys it stores otherwise than asked for.
   *
   * None by default: each key is stored as the resource accepted it. A
   * subclass whose object keeps a key in another shape (a checkbox per
   * type, stored as the list of the types checked) answers a layout:
   * load() reads through its fromStorage(), prepare() writes through its
   * toStorage() before the config schema judges the object. A layout that
   * implements StoredSchemaInterface also says the stored schema of each
   * key it translates, which the build's check and the config schema
   * generator read (translatedKeys()).
   *
   * Decision: see
   * docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape.
   *
   * @return \Drupal\data_surface\Storage\StorageLayoutInterface|null
   *   The layout, or NULL when the object holds each key as it is asked.
   */
  protected function layout(): ?StorageLayoutInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * What the layout says of the keys it translates, what each alter's
   * layout says of the keys at its mount, and each reference to a content
   * entity, stored by uuid.
   */
  public function translatedKeys(DataSurfaceInterface $built): array {
    $layout = $this->layout();
    return ($layout instanceof StoredSchemaInterface ? $layout->storedSchema() : []) + static::mountedLayouts($built) + ReferenceStorageLayout::storedSchemaOf($built);
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $stored = $this->stored();
    $values = array_diff_key($stored, array_flip(static::OWNED));
    foreach (static::STORED_AT as $path) {
      unset($values[explode('.', $path)[0]]);
    }
    foreach (static::STORED_AT as $key => $path) {
      $value = NestedArray::getValue($stored, explode('.', $path), $exists);
      if ($exists) {
        $values[$key] = $value;
      }
    }
    return $this->layout()?->fromStorage($values) ?? $values;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the values to write, plus the language code when the schema
   * asks for one; a key kept under a path (STORED_AT) by that path.
   *
   * @throws \LogicException
   *   When no config schema describes the object: the storage exists to
   *   hold values to it.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $name = $this->name();
    if (!$this->typedConfig->hasConfigSchema($name)) {
      throw new \LogicException(sprintf('No config schema describes %s, which %s stores in. Declare it in the module\'s config/schema/*.schema.yml.', $name, static::class));
    }
    $asked = array_diff_key($values, array_flip(static::OWNED));
    $asked = $this->layout()?->toStorage($asked) ?? $asked;
    $stored = $this->stored();
    // Each key at the path the object keeps it at, and the path's schema
    // violations filed under the key.
    $prepared = $paths = $deeper = [];
    foreach ($asked as $key => $value) {
      $path = $this->storedPath((string) $key);
      $prepared[$path] = $value;
      $paths[$path] = (string) $key;
      if ($path !== (string) $key) {
        $deeper[$path] = $value;
      }
    }
    $whole = array_diff_key($prepared, $deeper) + array_diff_key($stored, array_flip(static::OWNED));
    foreach ($deeper as $path => $value) {
      NestedArray::setValue($whole, explode('.', $path), $value, TRUE);
    }
    if ($this->typed($name, $whole)?->hasTranslatableElements()) {
      $prepared['langcode'] = $stored['langcode'] ?? $this->languageManager->getDefaultLanguage()->getId();
      $whole['langcode'] = $prepared['langcode'];
    }
    $violations = SchemaViolations::collect($this->typedConfig, $name, $whole, $paths);
    $undeclared = $this->mountsWithoutSchema($name, $whole, $prepared);
    if (!$violations->isEmpty() || $undeclared !== []) {
      throw new StorageViolationsException(new ViolationSet([...$violations, ...$undeclared]));
    }
    return $prepared;
  }

  /**
   * {@inheritdoc}
   *
   * Each key is set at its path, a key kept under a path (STORED_AT) by
   * that path alone, so what the object holds beside it stays as it is.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $config = $this->configFactory->getEditable($this->name());
    $before = $config->getRawData();
    foreach ($prepared as $path => $value) {
      $config->set((string) $path, $value);
    }
    if (!array_key_exists('langcode', $prepared)) {
      // Core's rule: no language code on an object with nothing
      // translatable in it.
      $config->clear('langcode');
    }
    // A write that changes nothing saves nothing: no save event, and
    // nothing for a config export to differ by.
    // Decision: see docs/decisions.md#absent-keeps-null-clears.
    if (!$config->isNew() && $config->getRawData() === $before) {
      return;
    }
    $config->save();
  }

  /**
   * {@inheritdoc}
   *
   * Core's rule, read from the schema with what is stored, so a type that
   * resolves through a sibling resolves: a value may be NULL unless the
   * object's schema is fully validatable (the `FullyValidatable`
   * constraint at its root) and the key's schema does not say
   * `nullable: true`. There, the schema refuses NULL, and so the key is
   * always held. A key no schema describes is not known. A key kept under
   * a path (STORED_AT) is read at that path.
   *
   * Decision: see docs/decisions.md#a-key-its-storage-always-holds.
   */
  public function mayClear(OperationContext $context, string $path): ?bool {
    $name = $this->name();
    if (!$this->typedConfig->hasConfigSchema($name)) {
      return NULL;
    }
    $stored = $this->stored();
    $hash = hash('xxh64', serialize($stored));
    if (!array_key_exists($path, $this->clearable[$hash] ?? [])) {
      $answer = NULL;
      // A key nothing is stored for yet is read as holding nothing, so
      // the schema still answers for it.
      $data = $stored;
      $parents = explode('.', $path);
      $parents = [...explode('.', $this->storedPath($parents[0])), ...array_slice($parents, 1)];
      if (!NestedArray::keyExists($data, $parents) && $this->mapsAlong($data, $parents)) {
        NestedArray::setValue($data, $parents, NULL);
      }
      try {
        $element = $this->typed($name, $data)?->get(implode('.', $parents));
        if ($element !== NULL && !$element instanceof Undefined) {
          $answer = !$element->getDataDefinition()->isRequired();
        }
      }
      catch (\InvalidArgumentException) {
        // No such property in the schema: nothing to say.
      }
      $this->clearable[$hash][$path] = $answer;
    }
    return $this->clearable[$hash][$path];
  }

  /**
   * {@inheritdoc}
   */
  public function configSchemaType(): string {
    return $this->name();
  }

  /**
   * Answers whether every part of a path holding something is a map.
   *
   * A list's items are not keys, so a path into one (a collection's key,
   * named without a delta) is never filled in.
   *
   * @param array $data
   *   The data.
   * @param string[] $parents
   *   The path, a key per level.
   *
   * @return bool
   *   TRUE when no level along it holds a list.
   */
  protected function mapsAlong(array $data, array $parents): bool {
    foreach (array_slice($parents, 0, -1) as $parent) {
      if (!array_key_exists($parent, $data)) {
        return TRUE;
      }
      $data = $data[$parent];
      if (!is_array($data) || array_is_list($data)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * The path STORED_AT names for the key, else its own name.
   */
  protected function storedPath(string $key): string {
    return static::STORED_AT[$key] ?? $key;
  }

  /**
   * Gets the config object's name.
   *
   * @return string
   *   The name.
   *
   * @throws \LogicException
   *   When the subclass names none.
   */
  protected function name(): string {
    if (static::CONFIG === '') {
      throw new \LogicException(sprintf('%s names no config object: set its CONFIG constant.', static::class));
    }
    return static::CONFIG;
  }

  /**
   * Reads what is stored, the storage's own keys included.
   *
   * @return array
   *   The object's raw data; empty when it does not exist.
   */
  protected function stored(): array {
    return $this->configFactory->get($this->name())->getRawData();
  }

  /**
   * Reads data as the config object's schema describes it.
   *
   * @param string $name
   *   The config object.
   * @param array $data
   *   The data.
   *
   * @return \Drupal\Core\Config\Schema\ArrayElement|null
   *   The typed object, or NULL when its schema is no mapping.
   */
  protected function typed(string $name, array $data): ?ArrayElement {
    $typed = $this->typedConfig->createFromNameAndData($name, $data);
    return $typed instanceof ArrayElement ? $typed : NULL;
  }

  /**
   * Refuses mounted settings no config schema describes.
   *
   * The owner declares third_party_settings as a sequence whose type is
   * named by the module key, and each module that mounts there declares
   * its own part. A part nobody declared resolves to an undefined element,
   * which config validation passes without a word, so it is checked here.
   * An owner that declares no third_party_settings at all is refused by
   * the schema itself, under that key.
   *
   * @param string $name
   *   The config object.
   * @param array $whole
   *   The object as it would be stored.
   * @param array $prepared
   *   The values being written.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   One violation per module whose settings no schema describes.
   */
  protected function mountsWithoutSchema(string $name, array $whole, array $prepared): array {
    $mounted = $prepared[static::THIRD_PARTY] ?? NULL;
    if (!is_array($mounted) || $mounted === []) {
      return [];
    }
    $sequence = $this->typed($name, $whole)?->get(static::THIRD_PARTY);
    if (!$sequence instanceof ArrayElement) {
      return [];
    }
    $violations = [];
    foreach (array_keys($mounted) as $module) {
      if ($sequence->get((string) $module) instanceof Undefined) {
        $violations[] = new Violation(static::THIRD_PARTY, (string) $module, $this->t('No config schema describes the settings the @module module stores in @name. That module declares them in its config schema.', [
          '@module' => $module,
          '@name' => $name,
        ]), Violation::NO_SCHEMA);
      }
    }
    return $violations;
  }

}
