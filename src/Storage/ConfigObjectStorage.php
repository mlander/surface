<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\KnowsClearableKeysInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;

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
 *
 * The storage owns `_core`, which the config installer writes, and
 * `langcode`, which core's rule asks for exactly when the schema has a
 * translatable value: it keeps the stored language, or the site's default
 * language when none is stored, and removes one the schema does not ask
 * for. A resource never declares either.
 *
 * The object is expected to exist, from the module's config/install, so a
 * context that creates is treated as one that edits.
 *
 * @see docs/storage.md#a-simple-config-object
 */
abstract class ConfigObjectStorage implements ResourceStorageInterface, KnowsClearableKeysInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The config object the values live in, named by the subclass.
   */
  public const CONFIG = '';

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
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    return array_diff_key($this->stored(), array_flip(static::OWNED));
  }

  /**
   * {@inheritdoc}
   *
   * Returns the values to write, plus the language code when the schema
   * asks for one.
   *
   * @throws \LogicException
   *   When no config schema describes the object: the storage exists to
   *   hold values to it.
   */
  public function prepare(OperationContext $context, array $values): array {
    $name = $this->name();
    if (!$this->typedConfig->hasConfigSchema($name)) {
      throw new \LogicException(sprintf('No config schema describes %s, which %s stores in. Declare it in the module\'s config/schema/*.schema.yml.', $name, static::class));
    }
    $prepared = array_diff_key($values, array_flip(static::OWNED));
    $stored = $this->stored();
    $whole = $prepared + array_diff_key($stored, array_flip(static::OWNED));
    if ($this->typed($name, $whole)?->hasTranslatableElements()) {
      $prepared['langcode'] = $stored['langcode'] ?? $this->languageManager->getDefaultLanguage()->getId();
      $whole['langcode'] = $prepared['langcode'];
    }
    $keys = array_map('strval', array_keys($values));
    $violations = SchemaViolations::collect($this->typedConfig, $name, $whole, array_combine($keys, $keys));
    $undeclared = $this->mountsWithoutSchema($name, $whole, $prepared);
    if (!$violations->isEmpty() || $undeclared !== []) {
      throw new StorageViolationsException(new ViolationSet([...$violations, ...$undeclared]));
    }
    return $prepared;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $config = $this->configFactory->getEditable($this->name());
    foreach ($prepared as $key => $value) {
      $config->set((string) $key, $value);
    }
    if (!array_key_exists('langcode', $prepared)) {
      // Core's rule: no language code on an object with nothing
      // translatable in it.
      $config->clear('langcode');
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
   * always held. A key no schema describes is not known.
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
      try {
        $element = $this->typed($name, $stored)?->get($path);
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
        ]));
      }
    }
    return $violations;
  }

}
