<?php

declare(strict_types=1);

namespace Drupal\surface\Reader;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Schema\Ignore;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\Variants;
use Drupal\surface\Variants\ConfigSchemaTypes;

/**
 * Reads a config schema type straight into a blueprint, with no JSON Schema.
 *
 * Walks the definition arrays the typed config manager holds, each type a
 * key names merged down to the primitive it ends in, as typed config
 * merges it, with every constraint met on the way:
 *
 * - a `mapping` is addMap() for keys only this mapping holds, or attach()
 *   of a derived structure where the key names a mapping type
 *   (`type: views_display`): one structure wherever it is used, by its id,
 *   `config_schema:<type>`;
 * - a `sequence` of values is addList(), of mappings attachList() of a
 *   derived structure;
 * - a value is the blueprint's type its class is (LeafRules), its
 *   constraints carried verbatim, required where a fully validatable type
 *   holds it to a value;
 * - a type named through another key, `[...]`, by its form:
 *   `[%parent.<key>]` is variants a sibling chooses, listed by
 *   ConfigSchemaTypes; `[<key>]`, chosen by a key inside the value, is a
 *   structure of its own for the expression, the fallback type's keys
 *   and the variants that key chooses at `configuration` (CHOSEN),
 *   stored flat beside them as config stores them, listed by
 *   ConfigSchemaTypes too;
 *   `[%parent.%parent...]` and `[%key]` are declared open (open()), the
 *   note giving the spelling the blueprint would need.
 *
 * What it reads is a definition and never a value: reading a type twice
 * calls the same verbs, so addMap()'s replay may run it again. Config
 * schema has labels and no descriptions, so no key is described.
 *
 * Config schema has no defaults either. A caller that knows them (a
 * plugin's defaultConfiguration()) hands them to structure() as a second
 * source, laid on each key the read declares.
 *
 * Whether the type is a structure or a resource is the deriver's: what
 * structure() registers is a structure; a `config_object` or
 * `config_entity` type would be a resource, with a config storage.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
final class ConfigSchemaReader implements DeclarationReaderInterface {

  /**
   * The id a structure read from a config schema type starts with.
   */
  public const PREFIX = 'config_schema:';

  /**
   * The keys of a config object its storage owns.
   */
  public const OWNED = ['_core'];

  /**
   * The key the type an own key chooses is asked for under.
   *
   * A door asks under it for the keys the type chosen by a key inside its
   * own value declares beside its fallback's (`condition.plugin.[id]`):
   * core's word for a plugin's own settings
   * (ConfigurableInterface::getConfiguration(), and the key
   * layout_builder.component and system.action.* nest them under). Here
   * they are stored flat beside the deciding key, as core stores a
   * condition's or a views handler's (Variants::STORED_AS).
   */
  public const CHOSEN = 'configuration';

  /**
   * Constructs a ConfigSchemaReader.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which holds the config schema.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   The registry each derived structure is registered with.
   */
  public function __construct(
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param mixed $source
   *   A schema type's name; or the name, a dotted path into it to the
   *   mapping read (`<key>.*` for a sequence's items), a type whose keys
   *   are left out, which the frame holding the value declares, and the
   *   defaults laid on the keys read, by key.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   */
  public function read(mixed $source, BlueprintInterface $inputs): Reading {
    $parts = is_array($source) ? array_values($source) : [$source];
    [$type, $path, $less, $defaults] = $parts + ['', '', '', []];
    if (!is_string($type) || !is_string($path) || !is_string($less) || !is_array($defaults) || $type === '') {
      throw new \InvalidArgumentException('ConfigSchemaReader reads a config schema type by name, with an optional path into it, a type whose keys are left out, and defaults by key.');
    }
    $reading = new Reading();
    if (str_contains($type, '[') && $path === '') {
      $this->ownKey($type, $inputs, $reading);
      return $reading;
    }
    if (!$this->typedConfig->hasConfigSchema($type)) {
      $reading->leave('', sprintf('The site has no config schema named %s.', $type));
      return $reading;
    }
    $root = $this->typedConfig->getDefinition($type);
    $strict = array_key_exists('FullyValidatable', (array) ($root['constraints'] ?? []));
    $frame = $path === '' ? $root : $this->merged($this->propertyAt($type, $path)[0] ?? []);
    if (!$this->isA($frame, Mapping::class)) {
      $reading->leave('', sprintf('%s%s is no mapping: a structure is a group of keys.', $type, $path === '' ? '' : ' at ' . $path));
      return $reading;
    }
    $mapping = $this->keysOf($type, $path, $less);
    $stored = $path === '' && in_array($this->rootKind($type), ['config_object', 'config_entity'], TRUE);
    foreach (self::OWNED as $owned) {
      if ($stored && isset($frame['mapping'][$owned]) && !isset($mapping[$owned])) {
        $reading->leave($owned, 'Its storage owns it: what a config object keeps for merging.');
      }
    }
    foreach ((array) ($frame['constraints'] ?? []) as $name => $options) {
      $reading->leave('', in_array((string) $name, LeafRules::OF_THE_MAPPING, TRUE)
        ? LeafRules::ofTheMapping((string) $name, is_array($options) ? $options : [])
        : sprintf('%s: said of the whole mapping, and no verb lays a constraint on a frame.', $name));
    }
    $this->mapping($inputs, $mapping, $type, $path, $strict, $reading, '', $defaults);
    return $reading;
  }

  /**
   * Reads a type chosen by a key inside its own value.
   *
   * The fallback's keys, then the variants at CHOSEN, stored flat.
   *
   * `views.field.[plugin_id]` is one structure, whatever key holds it: the
   * keys every value holds (the fallback type's), then, at
   * `configuration`, the variants the deciding key chooses, each the type
   * it names less those keys (its `:own` structure), listed by
   * ConfigSchemaTypes bound to the expression itself. Each variant is
   * whole in its own frame, so two types that declare one key unlike each
   * keep their own definition of it. The map at `configuration` carries
   * no label, since the schema names none for it. The variants' keys are
   * stored flat beside the deciding key, as config stores them
   * (Variants::STORED_AS, FlatVariantsLayout), and asked for under the
   * key.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   *
   * @param string $named
   *   The expression as written: `views.field.[plugin_id]`.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   *
   * @throws \LogicException
   *   When the fallback declares CHOSEN itself: stored flat, the variants'
   *   keys and the fallback's would share it.
   */
  protected function ownKey(string $named, BlueprintInterface $inputs, Reading $reading): void {
    $expression = static::expression($named);
    $fallback = $expression['form'] === 'own key' ? $this->fallbackOf($expression) : NULL;
    if ($fallback === NULL) {
      $reading->leave('', sprintf('%s is no type chosen by a key inside its value with a type every value holds.', $named));
      return;
    }
    $by = (string) $expression['by'];
    $keys = $this->keysOf($fallback);
    if (array_key_exists(self::CHOSEN, $keys)) {
      throw new \LogicException(sprintf('ConfigSchemaReader reads %s as the keys of %s and, at "%s", the type its "%s" key chooses, stored flat beside them, and %s declares "%s" itself: stored flat, the two would be one key.', $named, $fallback, self::CHOSEN, $by, $fallback, self::CHOSEN));
    }
    $strict = array_key_exists('FullyValidatable', (array) ($this->typedConfig->getDefinition($fallback)['constraints'] ?? []));
    $this->mapping($inputs, $keys, $fallback, '', $strict, $reading);
    $inputs->attachVariants(self::CHOSEN, $by, NULL, from: ConfigSchemaTypes::class, arguments: ['type' => $named])
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

  /**
   * Declares a key the reader reads no shape for, open.
   *
   * A key the schema declares is always declared: a derived structure is
   * closed, so a key left out would refuse the stored value that holds
   * it. Where no verb declares what the schema says, the key is a value
   * of any shape (LeafRules::open()), and the note says why.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The frame's blueprint.
   * @param string $key
   *   The key.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   Its label.
   * @param bool $required
   *   Whether it must hold a value.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   Where the note is about.
   * @param string|null $why
   *   Why no shape is read; NULL where it is noted already.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   */
  protected function open(BlueprintInterface $inputs, string $key, mixed $label, bool $required, Reading $reading, string $here, ?string $why = NULL): DataDefinition {
    if ($why !== NULL) {
      $reading->leave($here, $why);
    }
    $definition = LeafRules::open($label);
    if ($required) {
      $definition->setRequired(TRUE);
    }
    $inputs->addDefinition($key, $definition);
    return $definition;
  }

  /**
   * Lists the schema types an expression's prefix and suffix name, by id.
   *
   * Each type whose name is the prefix, one id of no dot and no wildcard,
   * and the suffix: `views.field.boolean` for `views.field.[plugin_id]`,
   * never `views.field.*`.
   *
   * @param array{prefix: string, suffix: string} $expression
   *   What expression() read.
   *
   * @return array<string, string>
   *   Each type's name, by the id it fills in, in the order the schema
   *   lists them.
   */
  public function typesNamed(array $expression): array {
    $named = [];
    foreach (array_keys($this->typedConfig->getDefinitions()) as $name) {
      $name = (string) $name;
      if (!str_starts_with($name, $expression['prefix']) || !str_ends_with($name, $expression['suffix'])) {
        continue;
      }
      $id = substr($name, strlen($expression['prefix']), strlen($name) - strlen($expression['prefix']) - strlen($expression['suffix']));
      if ($id === '' || str_contains($id, '*') || str_contains($id, '.')) {
        continue;
      }
      $named[$id] = $name;
    }
    return $named;
  }

  /**
   * Fills a derived structure's blueprint: its inputs, by ResourceCallable.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path into it of the mapping read; empty for the type.
   * @param string $less
   *   A type whose keys are left out; empty for none.
   * @param array $defaults
   *   The defaults laid on the keys read, by key; none for none.
   */
  public function fill(BlueprintInterface $inputs, string $type, string $path = '', string $less = '', array $defaults = []): void {
    // The schema changes when an extension is installed, and only then.
    $inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags(['config:core.extension']));
    $this->read([$type, $path, $less, $defaults], $inputs);
  }

  /**
   * Registers the structure a schema type is, for a request to build.
   *
   * A derived structure by id, `config_schema:<type>` (with `:<path>` for
   * a mapping inside it a key holds inline, and `:own` for its keys less
   * those of its frame type; or the expression as written,
   * `config_schema:views.field.[plugin_id]`, for a type chosen by a key
   * inside its own value, ownKey()), its inputs fill(). Registered
   * on demand, as a structure read from a JSON Schema is, so nothing
   * lists the site's every schema type at discovery.
   *
   * Config schema states no defaults, so the defaults are a second source
   * the caller lays over what is read: each is the default of the key of
   * its name, as add()'s `default:` declares one, and of a key inside a
   * mapping the key holds, by nesting. One that names no key read is
   * dropped; one that is no value of its key's type is noted and not
   * laid. The defaults kept are part of the hash, not of the id: two
   * plugins of one type with different defaults are two structures under
   * one id, which a document files apart as `<id>@<n>`.
   *
   * `:own` means one thing, since the id is a def key on the wire: the
   * type's keys less those of its frame type, which is the type itself
   * (a plugin with no schema of its own, read as its fallback less
   * itself, holds no key) or a type it extends at any distance. The frame
   * type is matched by keys, not by name: $less is accepted when its keys
   * are the type's or those of any type in its ancestry (ancestry()), so
   * `block.settings.*`, which is `type: block_settings` and adds no key,
   * and `block_settings` are one frame, and `views.argument.*` is the
   * frame of `views.argument.date_day`, which is `type:
   * views.argument.date`, which is `type: views_argument`, as
   * `views.argument.*` is.
   *
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path into it of the mapping; empty for the type.
   * @param string $less
   *   The frame type, whose keys are left out: the type itself, a type it
   *   extends, or one with the keys of either; empty for none.
   * @param array $defaults
   *   The defaults laid on the keys read, by key; none for none.
   *
   * @return string
   *   The name it is attached, listed and chosen by.
   *
   * @throws \LogicException
   *   When the keys of $less are those of no type in the ancestry.
   */
  public function structure(string $type, string $path = '', string $less = '', array $defaults = []): string {
    if ($less !== '') {
      $left = array_map('strval', array_keys((array) ($this->typedConfig->getDefinition($less)['mapping'] ?? [])));
      sort($left);
      $ancestry = $this->ancestry($type, $path, $this->typedConfig->getDefinitions());
      if (!in_array($left, $ancestry, TRUE)) {
        // Typed config drops the `type:` of a definition it has merged for
        // a value (TypedConfigManager::getDefinitionWithReplacements()), so
        // the walk ends early there; it is made again on the definitions
        // as written, which a copy of the manager reads afresh.
        $written = clone $this->typedConfig;
        $written->useCaches(FALSE);
        $written->useCaches(TRUE);
        $ancestry = $this->ancestry($type, $path, $written->getDefinitions());
      }
      if (!in_array($left, $ancestry, TRUE)) {
        $names = array_keys($ancestry);
        $nearest = $names[1] ?? $names[0];
        $differ = [...array_diff($left, $ancestry[$nearest]), ...array_diff($ancestry[$nearest], $left)];
        throw new \LogicException(sprintf('ConfigSchemaReader reads %s less the keys of %s, which are the keys of neither the type nor a type it extends (%s): an :own structure leaves out the keys of its frame type, the type itself or a type it extends, matched by keys. The keys that differ from %s: %s.', $type . ($path === '' ? '' : ' at ' . $path), $less, count($names) > 1 ? 'it extends ' . implode(', ', array_slice($names, 1)) : 'it extends none', $nearest, implode(', ', $differ)));
      }
    }
    // With no defaults, the arguments and the hash are a read of the type
    // alone, so each caller of one type with none gets one structure.
    $arguments = [$type, $path, $less];
    $defaults = array_intersect_key($defaults, $this->keysOf($type, $path, $less));
    if ($defaults !== []) {
      $arguments[] = $defaults;
    }
    // An expression is no type: what it reads is its fallback's keys, and
    // the types it may name.
    $read = str_contains($type, '[') ? $this->typedConfig->getDefinitions() : $this->typedConfig->getDefinition($type);
    return $this->registry->defineRead(
      static::id($type, $path, $less),
      'surface',
      new ResourceCallable(self::class, 'fill', $arguments),
      hash('xxh128', serialize([...$arguments, $read])),
    );
  }

  /**
   * Gives the keys a frame read holds, as the schema writes them.
   *
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path into it of the mapping; empty for the type.
   * @param string $less
   *   A type whose keys are left out; empty for none.
   *
   * @return array
   *   The mapping, by key: less the keys of $less, and of a config object
   *   or config entity less what its storage owns. Empty where the type or
   *   the path names no mapping.
   */
  public function keysOf(string $type, string $path = '', string $less = ''): array {
    if (!$this->typedConfig->hasConfigSchema($type)) {
      return [];
    }
    $frame = $path === '' ? $this->typedConfig->getDefinition($type) : $this->merged($this->propertyAt($type, $path)[0] ?? []);
    if (!$this->isA($frame, Mapping::class)) {
      return [];
    }
    $mapping = (array) ($frame['mapping'] ?? []);
    if ($less !== '') {
      $mapping = array_diff_key($mapping, (array) ($this->typedConfig->getDefinition($less)['mapping'] ?? []));
    }
    if ($path === '' && in_array($this->rootKind($type), ['config_object', 'config_entity'], TRUE)) {
      $mapping = array_diff_key($mapping, array_flip(self::OWNED));
    }
    return $mapping;
  }

  /**
   * Gives a derived structure's id.
   *
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path of a mapping inside it, or empty.
   * @param string $less
   *   The frame type whose keys are left out (structure()), or empty.
   *
   * @return string
   *   `config_schema:<type>`, then `:<path>` where given, then `:own`
   *   where the keys of its frame type are left out.
   */
  public static function id(string $type, string $path = '', string $less = ''): string {
    return self::PREFIX . $type . ($path === '' ? '' : ':' . $path) . ($less === '' ? '' : ':own');
  }

  /**
   * Lists the keys of a type and of each type it extends, nearest first.
   *
   * The walk follows the `type:` of each definition passed: the type's
   * (or its fallback's, as determine() resolves a name with no definition
   * of its own), then that type's, until a type with no mapping or one
   * with no `type:` (`mapping` itself), which is left out. For a path it
   * starts at the mapping there and the `type:` it is written with.
   *
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path into it of the mapping; empty for the type.
   * @param array $definitions
   *   The schema's definitions, by name, whose `type:` is followed; the
   *   caller decides which: those typed config holds, which may have
   *   dropped a `type:` it merged, or those as written.
   *
   * @return array<string, list<string>>
   *   The mapping keys of each, merged and sorted, by type name: the type
   *   first (`<type>:<path>` for a path), then each type it extends.
   */
  private function ancestry(string $type, string $path, array $definitions): array {
    $keys = static function (array $merged): array {
      $keys = array_map('strval', array_keys((array) ($merged['mapping'] ?? [])));
      sort($keys);
      return $keys;
    };
    if ($path === '') {
      $ancestry = [$type => $keys($this->typedConfig->getDefinition($type))];
      $next = (string) ($definitions[$this->determine($type) ?? '']['type'] ?? '');
    }
    else {
      $written = $this->propertyAt($type, $path)[0] ?? [];
      $ancestry = [$type . ':' . $path => $keys($this->merged($written))];
      $next = (string) ($written['type'] ?? '');
    }
    while ($next !== '' && !str_contains($next, '[') && ($name = $this->determine($next)) !== NULL && !isset($ancestry[$name])) {
      // `mapping` itself is written with no `type:`, and holds no key.
      $merged = $this->typedConfig->getDefinition($name);
      if (!isset($definitions[$name]['type']) || !is_array($merged['mapping'] ?? NULL)) {
        break;
      }
      $ancestry[$name] = $keys($merged);
      $next = (string) ($definitions[$name]['type'] ?? '');
    }
    return $ancestry;
  }

  /**
   * Declares a mapping's keys.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint of the frame.
   * @param array $mapping
   *   The mapping's keys, as the schema writes them.
   * @param string $type
   *   The schema type the frame is in, for the variants' provider.
   * @param string $path
   *   The mapping's dotted path in that type; empty for the type itself.
   * @param bool $strict
   *   Whether the type is fully validatable.
   * @param \Drupal\surface\Reader\Reading $reading
   *   Where what is read otherwise is noted, by path from the frame.
   * @param string $at
   *   The frame's path from where the reading began, for the notes.
   * @param array $defaults
   *   The defaults laid on the frame's keys, by key.
   */
  protected function mapping(BlueprintInterface $inputs, array $mapping, string $type, string $path, bool $strict, Reading $reading, string $at = '', array $defaults = []): void {
    foreach ($mapping as $key => $property) {
      $key = (string) $key;
      if (!is_array($property)) {
        continue;
      }
      $here = $at === '' ? $key : $at . '.' . $key;
      $full = $path === '' ? $key : $path . '.' . $key;
      $named = (string) ($property['type'] ?? 'undefined');
      $label = isset($property['label']) && (string) $property['label'] !== '' ? LeafRules::label((string) $property['label']) : NULL;
      $required = $strict && ($property['requiredKey'] ?? TRUE) !== FALSE && empty($property['nullable']);

      if (str_contains($named, '[')) {
        $this->defaultNotLaid($defaults, $key, $reading, $here, 'its type is chosen through another key');
        $this->named($inputs, $key, $named, $label, $mapping, $type, $full, $reading, $here, FALSE, $required);
        continue;
      }
      $merged = $this->merged($property);
      if ($this->isA($merged, Ignore::class) || $this->isA($merged, Undefined::class)) {
        $this->open($inputs, $key, $label, $required, $reading, $here, sprintf('Of the type %s: the schema checks nothing here, so the key is declared open, holding any value.', $named));
        continue;
      }
      if ($this->isA($merged, Mapping::class)) {
        // A mapping's default is its keys' defaults, laid inside it.
        $inside = is_array($defaults[$key] ?? NULL) ? $defaults[$key] : [];
        if ($this->isNamedMapping($named, $property)) {
          $shell = $inputs->attach($key, $this->structure($this->determine($named) ?? $named, '', '', $inside), $label);
          $reading->structure($here, static::id($this->determine($named) ?? $named));
        }
        else {
          $inner = new Reading();
          $shell = $inputs->addMap($key, $label ?? $key, function (BlueprintInterface $map) use ($merged, $type, $full, $strict, $inner, $inside): void {
            $this->mapping($map, (array) ($merged['mapping'] ?? []), $type, $full, $strict, $inner, '', $inside);
          });
          $reading->nest($here, $inner);
        }
        $this->carryOnMap($shell, $merged, $reading, $here);
        if ($required) {
          $shell->setRequired(TRUE);
        }
        continue;
      }
      if ($this->isA($merged, Sequence::class)) {
        $this->sequence($inputs, $key, $merged, $label, $type, $full, $strict, $reading, $here, $required, $defaults);
        continue;
      }
      $definition = $this->value($merged, $label, $reading, $here, $named);
      if ($definition === NULL) {
        // Noted by value().
        $this->open($inputs, $key, $label, $required, $reading, $here);
        continue;
      }
      if ($required) {
        $definition->setRequired(TRUE);
      }
      $inputs->addDefinition($key, $definition, $this->defaultOf($defaults, $key, LeafTypes::ROWS[$definition->getDataType()]['type'] ?? NULL, $reading, $here));
    }
  }

  /**
   * Gives the default laid on a key, where one is given and fits it.
   *
   * @param array $defaults
   *   The defaults of the frame, by key.
   * @param string $key
   *   The key.
   * @param string|null $json
   *   The JSON type a value of the key is: `string`, `integer`,
   *   `number`, `boolean` or `array`; NULL for none.
   * @param \Drupal\surface\Reader\Reading $reading
   *   Where a default that does not fit is noted.
   * @param string $here
   *   The key's path, for the note.
   *
   * @return mixed
   *   The default; NULL for none.
   */
  protected function defaultOf(array $defaults, string $key, ?string $json, Reading $reading, string $here): mixed {
    $default = $defaults[$key] ?? NULL;
    if ($default === NULL) {
      return NULL;
    }
    $fits = match ($json) {
      'string' => is_string($default),
      'integer' => is_int($default),
      'number' => is_int($default) || is_float($default),
      'boolean' => is_bool($default),
      'array' => is_array($default) && array_is_list($default),
      default => FALSE,
    };
    if (!$fits) {
      $reading->leave($here, sprintf('The default given, %s, is no value of the key\'s type: not laid.', json_encode($default)));
      return NULL;
    }
    return $default;
  }

  /**
   * Notes a default given for a key that takes none here.
   *
   * @param array $defaults
   *   The defaults of the frame, by key.
   * @param string $key
   *   The key.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   The key's path.
   * @param string $why
   *   Why it takes none.
   */
  protected function defaultNotLaid(array $defaults, string $key, Reading $reading, string $here, string $why): void {
    if (($defaults[$key] ?? NULL) !== NULL) {
      $reading->leave($here, sprintf('A default is given, and not laid: %s.', $why));
    }
  }

  /**
   * Declares a sequence: a list of values, or a collection of mappings.
   *
   * Its constraints are carried onto the list, verbatim, but its Count of
   * mappings: that is the collection's fewest and most items,
   * attachList(min:, max:), as the parent's to say. A list of values
   * holds its Count as any constraint, which the pipeline checks on the
   * list and both documents state as `minItems` and `maxItems`.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The frame's blueprint.
   * @param string $key
   *   The key.
   * @param array $merged
   *   The sequence, merged with its type.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   Its label.
   * @param string $type
   *   The schema type the frame is in.
   * @param string $full
   *   The sequence's dotted path in that type.
   * @param bool $strict
   *   Whether the type is fully validatable.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   The sequence's path from where the reading began.
   * @param bool $required
   *   Whether it must hold a value.
   * @param array $defaults
   *   The defaults of the frame, by key.
   */
  protected function sequence(BlueprintInterface $inputs, string $key, array $merged, mixed $label, string $type, string $full, bool $strict, Reading $reading, string $here, bool $required, array $defaults = []): void {
    $item = is_array($merged['sequence'] ?? NULL) ? $merged['sequence'] : ['type' => 'undefined'];
    $named = (string) ($item['type'] ?? 'undefined');
    $list = NULL;
    // A Count of a fewest and a most, each a whole number, or either.
    $count = $merged['constraints']['Count'] ?? NULL;
    $bounds = is_array($count) && $count !== [] && array_diff_key($count, ['min' => 0, 'max' => 0]) === [] && array_filter($count, static fn (mixed $bound): bool => !is_int($bound)) === [] ? $count : NULL;
    if (str_contains($named, '[')) {
      $this->defaultNotLaid($defaults, $key, $reading, $here, 'a list of structures takes no default');
      $list = $this->named($inputs, $key, $named, $label, [], $type, $full . '.*', $reading, $here . '.*', TRUE, $required);
    }
    else {
      $item_merged = $this->merged($item);
      if ($this->isA($item_merged, Mapping::class)) {
        $structure = $this->isNamedMapping($named, $item)
          ? [$this->determine($named) ?? $named, '']
          : [$type, $full . '.*'];
        $this->defaultNotLaid($defaults, $key, $reading, $here, 'a list of structures takes no default');
        $list = $inputs->attachList($key, $this->structure(...$structure), $label, $bounds['min'] ?? NULL, $bounds['max'] ?? NULL);
        if ($bounds !== NULL) {
          unset($merged['constraints']['Count']);
        }
        $reading->structure($here . '.*', static::id(...$structure));
      }
      elseif ($this->isA($item_merged, Sequence::class)) {
        $inner = is_array($item_merged['sequence'] ?? NULL) ? $this->merged($item_merged['sequence']) : [];
        $leaf = $inner === [] ? NULL : $this->value($inner, NULL, $reading, $here . '.*.*', (string) ($item_merged['sequence']['type'] ?? ''));
        if ($leaf === NULL) {
          $this->open($inputs, $key, $label, $required, $reading, $here, 'A sequence of sequences of groups of keys: a list item that is a list of structures has no verb, so the key is declared open, holding any value.');
          return;
        }
        $list = $inputs->addList($key, new ListDataDefinition([], $leaf), $label ?? $key, $this->defaultOf($defaults, $key, 'array', $reading, $here));
      }
      elseif ($this->isA($item_merged, Ignore::class) || $this->isA($item_merged, Undefined::class)) {
        $this->open($inputs, $key, $label, $required, $reading, $here . '.*', sprintf('Of the type %s: the schema checks nothing here, so the list is declared open, holding any value.', $named));
        return;
      }
      else {
        $value = $this->value($item_merged, isset($item['label']) ? (string) $item['label'] : NULL, $reading, $here . '.*', $named);
        if ($value === NULL) {
          // Noted by value().
          $this->open($inputs, $key, $label, $required, $reading, $here);
          return;
        }
        $list = $inputs->addList($key, $value, $label ?? $key, $this->defaultOf($defaults, $key, 'array', $reading, $here));
      }
    }
    // Declared open by named(), labeled there: nothing of the list holds.
    if (!$list instanceof DataDefinition || DefinitionMetadata::isHeldWhole($list)) {
      return;
    }
    $this->carryOnMap($list, $merged, $reading, $here);
    if ($required) {
      $list->setRequired(TRUE);
    }
  }

  /**
   * Declares a value whose type is named through another key, by its form.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The frame's blueprint.
   * @param string $key
   *   The key.
   * @param string $named
   *   The type as written: `block.settings.[%parent.plugin]`.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   The key's label.
   * @param array $siblings
   *   The frame's mapping, for a sibling that decides; none for a
   *   sequence's items.
   * @param string $type
   *   The schema type the frame is in.
   * @param string $full
   *   The value's dotted path in that type.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   The value's path from where the reading began.
   * @param bool $item
   *   TRUE for a sequence's items, FALSE for a mapping's key.
   * @param bool $required
   *   Whether the key must hold a value, for one declared open.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   What was declared at the key: open where no verb declares the form
   *   (open()).
   */
  protected function named(BlueprintInterface $inputs, string $key, string $named, mixed $label, array $siblings, string $type, string $full, Reading $reading, string $here, bool $item, bool $required = FALSE): mixed {
    $expression = static::expression($named, $item);
    $by = $expression['by'];
    switch ($expression['form']) {
      case 'parent':
        $sibling = $siblings[$by] ?? NULL;
        $sibling_merged = is_array($sibling) && !str_contains((string) ($sibling['type'] ?? ''), '[') ? $this->merged($sibling) : NULL;
        if ($sibling_merged === NULL || $this->isA($sibling_merged, Mapping::class) || $this->isA($sibling_merged, Sequence::class)) {
          $reading->expression($here, $named, 'parent', $by, 'open');
          return $this->open($inputs, $key, $label, $required, $reading, $here, sprintf('Its type is chosen by "%s", which this mapping holds as no value: variants are chosen by a sibling of one value, so the key is declared open, holding any value.', $by));
        }
        $shell = $inputs->attachVariants($key, by: $by, label: $label, from: ConfigSchemaTypes::class, arguments: [
          'type' => $type,
          'path' => $full,
        ]);
        $reading->expression($here, $named, 'parent', $by, 'variants');
        return $shell;

      case 'own key':
        // Decision: see
        // docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
        if ($this->fallbackOf($expression) === NULL) {
          $reading->expression($here, $named, 'own key', $by, 'open');
          return $this->open($inputs, $key, $label, $required, $reading, $here, sprintf('Its type is chosen by its own "%s" key, and no type says what every one holds, so "%s" itself has no type: attachVariants(ConfigSchemaReader::CHOSEN), which reads the chosen type at "%s", stored flat, needs a type every value holds that declares "%s". Until one does, the key is declared open, holding any value.', $by, $by, self::CHOSEN, $by));
        }
        // One structure for the expression (ownKey()): the fallback's keys
        // and the variants at CHOSEN.
        $structure = $this->structure($named);
        $declared = $item ? $inputs->attachList($key, $structure, $label) : $inputs->attach($key, $structure, $label);
        $reading->structure($here, static::id($named));
        $reading->expression($here, $named, 'own key', $by, 'variants');
        return $declared;

      case 'entry key':
        $reading->expression($here, $named, 'entry key', NULL, 'open');
        return $this->open($inputs, $key, $label, $required, $reading, $here, sprintf('Each entry is typed by its own key (%s). attachEach() holds one structure at every entry; one structure per entry would be attachEach(\'%s\', NULL, from: ConfigSchemaTypes::class, arguments: [...]) with each SiteList entry naming its structure:, which the build refuses today, so the key is declared open, holding any value.', $named, $key));

      default:
        $reading->expression($here, $named, 'ancestor', $by, 'open');
        return $this->open($inputs, $key, $label, $required, $reading, $here, sprintf('Its type is chosen by "%s", a key outside this frame (%s). A structure reads its container only through a port, and variants are chosen by a plain key of the frame: this would be $inputs->reads(\'%s\', \'string\') and attachVariants(\'%s\', by: \'%s\'), a port deciding variants, which the build refuses today, so the key is declared open, holding any value.', $by ?? '?', $named, str_replace('.', '_', (string) $by), $key, str_replace('.', '_', (string) $by)));
    }
  }

  /**
   * Reads a type named through another key.
   *
   * @param string $named
   *   The type as written.
   * @param bool $item
   *   TRUE when it types a sequence's items, whose %parent is the
   *   sequence, not the frame.
   *
   * @return array{form: string, by: string|null, prefix: string, suffix: string, expression: string}
   *   Its form (`parent`, `own key`, `ancestor` or `entry key`), the key
   *   that decides it, by dotted path from where that key's frame is,
   *   and what comes before and after the first `[...]`.
   */
  public static function expression(string $named, bool $item = FALSE): array {
    preg_match('/^(.*?)\[(.*?)\](.*)$/', $named, $match);
    [, $prefix, $inner, $suffix] = $match + ['', '', '', ''];
    $parts = explode('.', $inner);
    $parents = 0;
    while (($parts[0] ?? NULL) === '%parent') {
      array_shift($parts);
      $parents++;
    }
    $rest = implode('.', $parts);
    $form = match (TRUE) {
      str_contains($named, '%key') => 'entry key',
      str_contains($inner, '%type') || str_contains($suffix, '[') => 'ancestor',
      $parents === 0 => 'own key',
      $parents === 1 && !$item && count($parts) === 1 => 'parent',
      default => 'ancestor',
    };
    return [
      'form' => $form,
      'by' => $rest === '' ? NULL : $rest,
      'prefix' => $prefix,
      'suffix' => $suffix,
      'expression' => $inner,
    ];
  }

  /**
   * Finds the type every value of an own-key expression holds.
   *
   * The `*` fallback core would resolve an id with no type of its own to,
   * else the type the prefix names (`condition.plugin`), else the type
   * every type the expression names extends, where it declares the
   * deciding key (extendedByAll()).
   *
   * @param array{prefix: string, suffix: string, by: string|null} $expression
   *   What expression() read.
   *
   * @return string|null
   *   The type, or NULL when the schema has none.
   */
  public function fallbackOf(array $expression): ?string {
    $definitions = $this->typedConfig->getDefinitions();
    $star = $expression['prefix'] . '*' . $expression['suffix'];
    if (isset($definitions[$star])) {
      return $star;
    }
    $found = static::fallback($expression['prefix'] . 'a_value_with_no_type_of_its_own' . $expression['suffix'], $definitions);
    if ($found !== NULL) {
      return $found;
    }
    $base = rtrim($expression['prefix'], '.');
    if ($expression['suffix'] === '' && isset($definitions[$base])) {
      return $base;
    }
    return $this->extendedByAll($expression, $definitions);
  }

  /**
   * Finds the type every type an own-key expression names extends.
   *
   * With no `*` fallback and no type of the prefix's name, the keys every
   * value holds are those of the nearest type each named type extends,
   * where it declares the deciding key (`views_cache` for
   * `views.cache.[type]`).
   *
   * @param array{prefix: string, suffix: string, by: string|null} $expression
   *   What expression() read.
   * @param array $definitions
   *   The schema's definitions, by name.
   *
   * @return string|null
   *   The type, or NULL when the named types share none that declares the
   *   deciding key.
   */
  protected function extendedByAll(array $expression, array $definitions): ?string {
    $common = NULL;
    foreach ($this->typesNamed($expression) as $name) {
      // The types it extends, nearest first.
      $chain = [];
      for ($at = (string) ($definitions[$name]['type'] ?? ''); isset($definitions[$at]) && !in_array($at, $chain, TRUE) && $at !== 'mapping'; $at = (string) ($definitions[$at]['type'] ?? '')) {
        $chain[] = $at;
      }
      $common = $common === NULL ? $chain : array_values(array_intersect($common, $chain));
    }
    foreach ($common ?? [] as $type) {
      if (isset($this->merged(['type' => $type])['mapping'][(string) $expression['by']])) {
        return $type;
      }
    }
    return NULL;
  }

  /**
   * Resolves a type name as typed config does: itself, else its fallback.
   *
   * @param string $name
   *   The name, `block.settings.system_menu_block:main`.
   *
   * @return string|null
   *   The name, or the fallback core would use; NULL for none, which
   *   typed config reads as `undefined`.
   */
  public function determine(string $name): ?string {
    $definitions = $this->typedConfig->getDefinitions();
    if (isset($definitions[$name])) {
      return $name;
    }
    return str_contains($name, '.') ? static::fallback($name, $definitions) : NULL;
  }

  /**
   * Core's fallback name, TypedConfigManager::getFallbackName(), run here.
   *
   * @param string $name
   *   The name.
   * @param array $definitions
   *   The schema's definitions, by name.
   *
   * @return string|null
   *   The first wildcard name defined, or NULL.
   */
  protected static function fallback(string $name, array $definitions): ?string {
    $replaced = (string) preg_replace('/([^\.:]+)([\.:\*]*)$/', '*\2', $name);
    if ($replaced === $name) {
      return NULL;
    }
    if (isset($definitions[$replaced])) {
      return $replaced;
    }
    $one_star = (string) preg_replace('/\.([:\.\*]*)$/', '.*', $replaced);
    if ($one_star !== $replaced && isset($definitions[$one_star])) {
      return $one_star;
    }
    return static::fallback($replaced, $definitions);
  }

  /**
   * Finds a property at a dotted path in a type, as written there.
   *
   * @param string $type
   *   The schema type.
   * @param string $path
   *   The dotted path; `*` for a sequence's items.
   *
   * @return array{0: array, 1: array}|null
   *   The property as written, and the mapping holding it, merged; NULL
   *   when the path names nothing.
   */
  public function propertyAt(string $type, string $path): ?array {
    $current = $this->typedConfig->getDefinition($type);
    $holder = $current;
    $property = NULL;
    foreach (explode('.', $path) as $depth => $segment) {
      if ($depth > 0) {
        if (str_contains((string) ($property['type'] ?? ''), '[')) {
          return NULL;
        }
        $current = $this->merged($property);
      }
      $holder = $current;
      $property = $segment === '*' ? ($current['sequence'] ?? NULL) : ($current['mapping'][$segment] ?? NULL);
      if (!is_array($property)) {
        return NULL;
      }
    }
    return [$property, $holder];
  }

  /**
   * Merges a property with the type it names, as typed config merges it.
   *
   * @param array $property
   *   The property, as written.
   *
   * @return array
   *   The named type's definition, itself merged down, with the
   *   property's own laid over it; the property as written for a type
   *   named through another key or not defined.
   */
  public function merged(array $property): array {
    $type = (string) ($property['type'] ?? '');
    if ($type === '' || str_contains($type, '[') || !$this->typedConfig->hasConfigSchema($type)) {
      return $property + ($type === '' || str_contains($type, '[') ? [] : ['class' => Undefined::class]);
    }
    return NestedArray::mergeDeepArray([$this->typedConfig->getDefinition($type), $property], TRUE);
  }

  /**
   * Says whether a merged definition's class is, or extends, a class.
   *
   * @param array $merged
   *   The definition, merged.
   * @param class-string $class
   *   The class.
   *
   * @return bool
   *   TRUE when it is.
   */
  public function isA(array $merged, string $class): bool {
    $own = ltrim((string) ($merged['class'] ?? ''), '\\');
    return $own !== '' && is_a($own, $class, TRUE);
  }

  /**
   * Names the root kind of a type: `config_object`, `config_entity`, or other.
   *
   * @param string $type
   *   The type.
   *
   * @return string
   *   The first of the two the type extends, or `mapping`.
   */
  public function rootKind(string $type): string {
    $definitions = $this->typedConfig->getDefinitions();
    $seen = [];
    $at = $type;
    while (isset($definitions[$at]) && !isset($seen[$at])) {
      $seen[$at] = TRUE;
      if (in_array($at, ['config_object', 'config_entity'], TRUE)) {
        return $at;
      }
      $at = (string) ($definitions[$at]['type'] ?? '');
    }
    return 'mapping';
  }

  /**
   * Reads one value through the shared rules.
   *
   * @param array $merged
   *   The value, merged down to its primitive.
   * @param string|\Stringable|null $label
   *   Its label.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   Its path.
   * @param string $named
   *   The type as the schema names it.
   *
   * @return \Drupal\Core\TypedData\DataDefinition|null
   *   The value, or NULL when its class is no type of the table.
   */
  protected function value(array $merged, string|\Stringable|null $label, Reading $reading, string $here, string $named): mixed {
    $type = LeafRules::type((string) ($merged['class'] ?? ''));
    if ($type === NULL) {
      $reading->leave($here, sprintf('Of the type %s, whose class %s is none of the types a key holds, so the key is declared open, holding any value.', $named, $merged['class'] ?? '(none)'));
      return NULL;
    }
    if ($named !== $type && $named !== '') {
      $reading->leaf($here, $named);
    }
    $definition = LeafRules::value($type, $label);
    LeafRules::carry($definition, (array) ($merged['constraints'] ?? []), $reading, $here);
    return $definition;
  }

  /**
   * Lays a mapping's or a sequence's own constraints on what declares it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map or list at the key.
   * @param array $merged
   *   The mapping or sequence, merged.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   Its path.
   */
  protected function carryOnMap(mixed $definition, array $merged, Reading $reading, string $here): void {
    LeafRules::carry($definition, (array) ($merged['constraints'] ?? []), $reading, $here);
  }

  /**
   * Says whether a property names a mapping type it adds no keys to.
   *
   * @param string $named
   *   The type it names.
   * @param array $property
   *   The property as written.
   *
   * @return bool
   *   TRUE for a mapping type of its own name, one structure wherever it
   *   is used; FALSE for an inline mapping, or one that adds keys.
   */
  protected function isNamedMapping(string $named, array $property): bool {
    return $named !== 'mapping' && $named !== '' && !isset($property['mapping']) && $this->determine($named) !== NULL;
  }

}
