<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\Type\BooleanInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\Type\StringInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\ResourceBuild\RefinesInputRefiner;

/**
 * Says where a resource is wider than the config schema its storage writes.
 *
 * For a storage implementing HeldToConfigSchemaInterface that holds a
 * `$typedConfig` (the typed config manager). It walks the sealed resource
 * beside the schema: an attached structure beside its mapping, a list
 * beside its sequence, each variant beside the type its deciding value
 * names, each module's mounted keys beside the type `[%key]` names, and
 * holds each key to what the schema states for it, at the property its
 * dotted path names (configSchemaPaths(), storedPath()):
 *
 * - **Type**: a string, integer, float or boolean where the schema says
 *   the same (an integer where it says float), a map where it says a
 *   mapping, a list where it says a sequence.
 * - **Nullability**: under a fully validatable root, a key the schema
 *   does not call nullable must hold a value: required, or marked as
 *   always held by the storage (KnowsClearableKeysInterface).
 * - **Constraints**: Regex (either way it matches), Length, Range,
 *   Choice, Count, NotBlank and Email, each implied by the resource's own
 *   constraint of that name or by a choice list every value of which
 *   passes it. NotBlank with `allowNull` is implied by the three-way rule
 *   itself: an empty string is nothing, which clears. Any other constraint
 *   the schema states is not read.
 *
 * @see \Drupal\data_surface\Resource\HeldToConfigSchemaInterface
 * @see docs/storage.md#a-schema-the-module-did-not-generate
 */
trait HeldToConfigSchemaTrait {

  /**
   * Gives where each key is in the schema, when not at its own name.
   *
   * @return array<string, string>|null
   *   The schema's property for each key the schema describes, by key, a
   *   dotted path for one in a nested mapping; a key left out is stored
   *   elsewhere and not compared. NULL when every key is compared, at
   *   the path storedPath() gives.
   */
  protected function configSchemaPaths(): ?array {
    return NULL;
  }

  /**
   * Gives where the object keeps a key, when every key is compared.
   *
   * Read when configSchemaPaths() answers NULL. A dotted path is followed
   * through the schema's mappings, level by level.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   Its dotted path in the stored object; its own name by default.
   */
  protected function storedPath(string $key): string {
    return $key;
  }

  /**
   * Gives the config schema of each key stored otherwise than asked for.
   *
   * What the storage's layout says of the keys it translates, and the
   * references a config storage stores by uuid: the check skips each, and
   * the config schema generator writes the schema given instead of the
   * asked one.
   *
   * Decision: see
   * docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource, built.
   *
   * @return array<string, array>
   *   Each such key's config schema as stored, by its dotted path, an
   *   entry of a map of keys from the site named `*`; none by default.
   */
  public function translatedKeys(DataSurfaceInterface $built): array {
    return [];
  }

  /**
   * Gives the config schema of each key an alter's layout stores otherwise.
   *
   * An alter that lays its mount out (AltersStorageLayoutInterface) with a
   * layout that says its stored schema (StoredSchemaInterface) tells the
   * check and the generator, as the storage's own layout does: each key at
   * `third_party_settings.<module>.<key>`.
   *
   * Decision: see
   * docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource, built.
   *
   * @return array<string, array>
   *   Each such key's config schema as stored, by its dotted path.
   */
  protected static function mountedLayouts(DataSurfaceInterface $built): array {
    $mount = $built->getDefinition(RefinesInputRefiner::MOUNT);
    $keys = [];
    foreach ($mount instanceof ComplexDataDefinitionInterface ? array_keys($mount->getPropertyDefinitions()) : [] as $module) {
      $layout = $built->getThirdPartyLayout((string) $module);
      foreach ($layout instanceof StoredSchemaInterface ? $layout->storedSchema() : [] as $path => $schema) {
        $keys[RefinesInputRefiner::path((string) $module, (string) $path)] = $schema;
      }
    }
    return $keys;
  }

  /**
   * Answers whether a key's dotted path is at or below a translated key.
   *
   * @param string $path
   *   The key's dotted path, as the check walks it.
   * @param string $translated
   *   A translated key's dotted path, where `*` stands for any entry of a
   *   map of keys from the site.
   *
   * @return bool
   *   TRUE when every segment of the translated path matches.
   */
  protected static function translatedAt(string $path, string $translated): bool {
    $at = explode('.', $path);
    $skipped = explode('.', $translated);
    if (count($at) < count($skipped)) {
      return FALSE;
    }
    foreach ($skipped as $position => $segment) {
      if ($segment !== '*' && $segment !== $at[$position]) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * A key the storage translates (translatedKeys()) is not compared: its
   * stored shape is not the one the resource asks for.
   */
  public function widerThanSchema(DataSurfaceInterface $built): array {
    $type = $this->configSchemaType();
    if (!$this->typedConfig->hasConfigSchema($type)) {
      return [];
    }
    $translated = array_keys($this->translatedKeys($built));
    $root = $this->typedConfig->getDefinition($type);
    $paths = $this->configSchemaPaths();
    $wider = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $key = (string) $key;
      if ($paths !== NULL && !isset($paths[$key])) {
        continue;
      }
      if ($paths === NULL && in_array($key, ['_core', 'langcode'], TRUE)) {
        continue;
      }
      $at = $paths[$key] ?? $this->storedPath($key);
      $this->entryWider($entry, $this->schemaAt($root, $at), $key, static::fullyValidatable($root), $wider);
    }
    return array_filter($wider, static function (string $path) use ($translated): bool {
      foreach ($translated as $skipped) {
        if (static::translatedAt($path, (string) $skipped)) {
          return FALSE;
        }
      }
      return TRUE;
    }, ARRAY_FILTER_USE_KEY);
  }

  /**
   * Finds the schema property at a dotted path, through nested mappings.
   *
   * @param array $root
   *   The root type's definition.
   * @param string $at
   *   The property's dotted path from the root.
   *
   * @return array|null
   *   The property, as written there; NULL when the schema describes
   *   nothing at the path.
   */
  protected function schemaAt(array $root, string $at): ?array {
    $property = $root;
    foreach (explode('.', $at) as $depth => $segment) {
      $mapping = $depth === 0 ? $property : $this->merged($property);
      $property = $mapping['mapping'][$segment] ?? NULL;
      if (!is_array($property)) {
        return NULL;
      }
    }
    return $property;
  }

  /**
   * Compares one key of the resource with its schema property.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param array|null $property
   *   Its property in the schema, as written there; NULL for none.
   * @param string $path
   *   The key's dotted path, for the answer.
   * @param bool $strict
   *   Whether the static type root it is under is fully validatable.
   * @param array<string, string> $wider
   *   Where the resource is wider, added to.
   */
  protected function entryWider(Entry $entry, ?array $property, string $path, bool $strict, array &$wider): void {
    if ($property === NULL) {
      $wider[$path] = 'the schema does not describe it';
      return;
    }
    $type = (string) ($property['type'] ?? 'undefined');
    if ($type === 'ignore') {
      // The schema checks nothing here, so it refuses nothing the resource
      // offers: variants from the site, which the chosen one holds to
      // its own keys.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      return;
    }
    if ($entry->variants !== NULL) {
      $by = $entry->variants->by;
      foreach ($entry->variants->variants as $value => $variant) {
        $resolved = str_replace('[%parent.' . $by . ']', (string) $value, $type);
        if (str_contains($resolved, ']')) {
          // Named some other way than by the deciding key: not read.
          continue;
        }
        if (!$this->typedConfig->hasConfigSchema($resolved)) {
          $wider[$path] = sprintf('no schema type %s describes its %s variant', $resolved, $value);
          continue;
        }
        $definition = $this->typedConfig->getDefinition($resolved);
        $this->mapWider($variant->child->getDefinitions(), $definition, $path, static::fullyValidatable($definition), $wider);
      }
      return;
    }
    if ($entry->attachment === NULL && $entry->list === NULL && $entry->definition instanceof ComplexDataDefinitionInterface && str_contains((string) ($property['sequence']['type'] ?? ''), '[%key]')) {
      // The map other modules mount their keys in: each module's part
      // has the type its own key names.
      $item = (string) ($property['sequence']['type'] ?? '');
      foreach ($entry->definition->getPropertyDefinitions() as $module => $part) {
        $resolved = str_replace('[%key]', (string) $module, $item);
        if ($resolved === '' || str_contains($resolved, ']') || !$this->typedConfig->hasConfigSchema($resolved)) {
          $wider[$path . '.' . $module] = 'no config schema describes what this module mounts here';
          continue;
        }
        $definition = $this->typedConfig->getDefinition($resolved);
        $mounted = $entry->mounted[(string) $module] ?? NULL;
        if ($mounted !== NULL) {
          $this->mapWider($mounted->child->getDefinitions(), $definition, $path . '.' . $module, static::fullyValidatable($definition), $wider);
        }
        else {
          $this->definitionWider($part, $definition, $path . '.' . $module, static::fullyValidatable($definition), $wider);
        }
      }
      return;
    }
    if (str_contains($type, ']')) {
      // A type its data names, in no way this reads.
      return;
    }
    $schema = $this->merged($property);
    if ($entry->list !== NULL) {
      if (!$this->isA($schema, Sequence::class)) {
        $wider[$path] = sprintf('it is a list, which the schema types %s', $type);
        return;
      }
      $this->constraintsWider($entry->definition, $schema, $path, $wider);
      $this->mapWider($entry->list->child()->getDefinitions(), $this->merged($schema['sequence'] ?? []), $path, $strict, $wider);
      return;
    }
    if ($entry->attachment !== NULL) {
      $each = DefinitionMetadata::eachOf($entry->definition);
      if ($each !== NULL) {
        if (!$this->isA($schema, Sequence::class)) {
          $wider[$path] = sprintf('it holds a key per thing the site has, which the schema types %s', $type);
          return;
        }
        $item = $this->merged($schema['sequence'] ?? []);
        foreach ($entry->attachment->child->getDefinitions()->entries() as $name => $one) {
          $this->entryWider($one, $item + ['type' => (string) ($schema['sequence']['type'] ?? 'undefined')], $path . '.' . $name, $strict, $wider);
        }
        return;
      }
      $this->mapWider($entry->attachment->child->getDefinitions(), $schema, $path, $strict, $wider);
      return;
    }
    $this->definitionWider($entry->definition, $schema, $path, $strict, $wider);
  }

  /**
   * Compares a map of the resource's keys with a schema mapping.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param array $schema
   *   The mapping, merged with its type.
   * @param string $path
   *   The map's dotted path.
   * @param bool $strict
   *   Whether the static type root it is under is fully validatable.
   * @param array<string, string> $wider
   *   Where the resource is wider, added to.
   */
  protected function mapWider(DefinitionMap $map, array $schema, string $path, bool $strict, array &$wider): void {
    if (!$this->isA($schema, Mapping::class)) {
      $wider[$path] = sprintf('it is a group of keys, which the schema types %s', $schema['type'] ?? 'undefined');
      return;
    }
    foreach ($map->entries() as $name => $entry) {
      $this->entryWider($entry, $schema['mapping'][$name] ?? NULL, $path . '.' . $name, $strict, $wider);
    }
  }

  /**
   * Compares one definition with its schema, a map's or a list's too.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $schema
   *   Its schema, merged with its type.
   * @param string $path
   *   Its dotted path.
   * @param bool $strict
   *   Whether the static type root it is under is fully validatable.
   * @param array<string, string> $wider
   *   Where the resource is wider, added to.
   * @param bool $item
   *   TRUE for a list's item, which is never null.
   */
  protected function definitionWider(DataDefinitionInterface $definition, array $schema, string $path, bool $strict, array &$wider, bool $item = FALSE): void {
    $type = (string) ($schema['type'] ?? 'undefined');
    if ($definition instanceof ListDataDefinitionInterface) {
      if (!$this->isA($schema, Sequence::class)) {
        $wider[$path] = sprintf('it is a list, which the schema types %s', $type);
        return;
      }
      $this->constraintsWider($definition, $schema, $path, $wider);
      $this->definitionWider($definition->getItemDefinition(), $this->merged($schema['sequence'] ?? []), $path, $strict, $wider, TRUE);
      return;
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      if (!$this->isA($schema, Mapping::class)) {
        $wider[$path] = sprintf('it is a group of keys, which the schema types %s', $type);
        return;
      }
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        if (!isset($schema['mapping'][$name])) {
          $wider[$path . '.' . $name] = 'the schema does not describe it';
          continue;
        }
        $this->definitionWider($property, $this->merged($schema['mapping'][$name]), $path . '.' . $name, $strict, $wider);
      }
      return;
    }
    if ($definition->getDataType() === 'any') {
      return;
    }
    $mine = static::kind((string) $definition->getClass());
    $theirs = static::kind((string) ($schema['class'] ?? ''));
    if ($theirs === 'mapping' || $theirs === 'sequence') {
      $wider[$path] = sprintf('it is one value, which the schema types %s, a %s', $type, $theirs);
      return;
    }
    if ($mine !== NULL && $theirs !== NULL && $mine !== $theirs && !($mine === 'integer' && $theirs === 'float')) {
      $a = static fn (string $kind): string => (in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], TRUE) ? 'an ' : 'a ') . $kind;
      $wider[$path] = sprintf('it is %s, which the schema types %s, %s', $a($mine), $type, $a($theirs));
      return;
    }
    if (!$item && $strict && empty($schema['nullable']) && !DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition)) {
      $wider[$path] = sprintf('it may be cleared, which the schema refuses: %s is not nullable', $type);
      return;
    }
    $this->constraintsWider($definition, $schema, $path, $wider);
  }

  /**
   * Holds a definition to each constraint its schema states.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $schema
   *   Its schema, merged with its type.
   * @param string $path
   *   Its dotted path.
   * @param array<string, string> $wider
   *   Where the resource is wider, added to.
   */
  protected function constraintsWider(DataDefinitionInterface $definition, array $schema, string $path, array &$wider): void {
    $mine = $definition->getConstraints();
    $choices = ChoiceSet::of($definition)?->values;
    foreach ((array) ($schema['constraints'] ?? []) as $name => $options) {
      $options = is_array($options) ? $options : [];
      $why = match ((string) $name) {
        'Regex' => static::regexWider($options, $mine['Regex'] ?? NULL, $choices),
        'Length' => static::boundsWider($options, $mine['Length'] ?? NULL, $choices === NULL ? NULL : array_map(static fn (mixed $value): int => mb_strlen((string) $value), $choices), 'characters long'),
        'Range' => static::boundsWider($options, $mine['Range'] ?? NULL, $choices, ''),
        'Count' => static::boundsWider($options, $mine['Count'] ?? NULL, NULL, 'items'),
        'Choice' => static::choiceWider($options, $choices),
        'NotBlank' => !empty($options['allowNull']) || DefinitionMetadata::holdsValue($definition) || isset($mine['NotBlank']) || (int) ($mine['Length']['min'] ?? 0) >= 1 ? NULL : 'it may be blank, which the schema\'s NotBlank refuses',
        'Email' => isset($mine['Email']) ? NULL : 'it is not held to an email address, as the schema\'s Email is',
        default => NULL,
      };
      if ($why !== NULL) {
        $wider[$path] = $why;
        return;
      }
    }
  }

  /**
   * Holds a value to a schema's Regex.
   *
   * @param array $options
   *   The schema's Regex.
   * @param mixed $mine
   *   The resource's Regex, if any.
   * @param array|null $choices
   *   The resource's choice list, if any.
   *
   * @return string|null
   *   Why the resource is wider, or NULL.
   */
  protected static function regexWider(array $options, mixed $mine, ?array $choices): ?string {
    $pattern = (string) ($options['pattern'] ?? '');
    $match = ($options['match'] ?? TRUE) !== FALSE;
    if ($pattern === '') {
      return NULL;
    }
    if (is_array($mine) && ((($mine['match'] ?? TRUE) !== FALSE) === $match) && static::samePattern($pattern, (string) ($mine['pattern'] ?? ''))) {
      return NULL;
    }
    if ($choices !== NULL && array_filter($choices, static fn (mixed $value): bool => (@preg_match($pattern, (string) $value) === 1) !== $match) === []) {
      return NULL;
    }
    return sprintf('the schema refuses a value %s %s, which the resource does not', $match ? 'that does not match' : 'that matches', $pattern);
  }

  /**
   * Answers whether a resource's pattern is the schema's, or stricter.
   *
   * The same expression, with every modifier the schema's has; an added
   * `D` (no newline before the end) only refuses more.
   *
   * @param string $theirs
   *   The schema's pattern.
   * @param string $mine
   *   The resource's.
   *
   * @return bool
   *   TRUE when the resource's refuses all the schema's does.
   */
  protected static function samePattern(string $theirs, string $mine): bool {
    $split = static function (string $pattern): ?array {
      if (strlen($pattern) < 2) {
        return NULL;
      }
      $end = strrpos($pattern, $pattern[0], 1);
      return $end === FALSE ? NULL : [substr($pattern, 1, $end - 1), str_split(substr($pattern, $end + 1))];
    };
    [$a, $b] = [$split($theirs), $split($mine)];
    if ($a === NULL || $b === NULL || $a[0] !== $b[0]) {
      return FALSE;
    }
    return array_diff(array_filter($a[1]), $b[1]) === [] && array_diff(array_filter($b[1]), [...$a[1], 'D']) === [];
  }

  /**
   * Holds a value to a schema's bounds: Length, Range or Count.
   *
   * @param array $options
   *   The schema's constraint.
   * @param mixed $mine
   *   The resource's constraint of the same name, if any.
   * @param array|null $values
   *   What the resource's choices measure, when it has a list of them.
   * @param string $unit
   *   What the bound counts, for the answer.
   *
   * @return string|null
   *   Why the resource is wider, or NULL.
   */
  protected static function boundsWider(array $options, mixed $mine, ?array $values, string $unit): ?string {
    $mine = is_array($mine) ? $mine : [];
    $numbers = $values === NULL ? NULL : array_filter($values, 'is_numeric');
    foreach (['min', 'max'] as $bound) {
      if (!isset($options[$bound]) || !is_numeric($options[$bound])) {
        continue;
      }
      $limit = $options[$bound] + 0;
      $held = isset($mine[$bound]) && is_numeric($mine[$bound]) && ($bound === 'min' ? $mine[$bound] >= $limit : $mine[$bound] <= $limit);
      $chosen = $numbers !== NULL && $numbers !== [] && count($numbers) === count($values) && ($bound === 'min' ? min($numbers) >= $limit : max($numbers) <= $limit);
      if (!$held && !$chosen) {
        $words = [$bound === 'min' ? 'less than' : 'more than', (string) $limit, $unit];
        return sprintf('it may be %s, which the schema refuses', implode(' ', array_filter($words, static fn (string $part): bool => $part !== '')));
      }
    }
    return NULL;
  }

  /**
   * Holds a value to a schema's Choice.
   *
   * @param array $options
   *   The schema's Choice.
   * @param array|null $choices
   *   The resource's choice list, if any.
   *
   * @return string|null
   *   Why the resource is wider, or NULL.
   */
  protected static function choiceWider(array $options, ?array $choices): ?string {
    if (!isset($options['choices']) || !is_array($options['choices'])) {
      // A callback lists them at validation, which nothing here asks.
      return NULL;
    }
    $allowed = array_map('strval', $options['choices']);
    if ($choices === NULL) {
      return sprintf('it allows any value, where the schema\'s Choice allows %s', implode(', ', $allowed));
    }
    $extra = array_diff(array_map('strval', $choices), $allowed);
    return $extra === [] ? NULL : sprintf('it allows %s, which the schema\'s Choice does not', implode(', ', $extra));
  }

  /**
   * Merges a schema property with the type it names.
   *
   * @param array $property
   *   The property, as written.
   *
   * @return array
   *   The type's definition with the property's own laid over it.
   */
  protected function merged(array $property): array {
    $type = (string) ($property['type'] ?? '');
    if ($type === '' || str_contains($type, ']') || !$this->typedConfig->hasConfigSchema($type)) {
      return $property;
    }
    return NestedArray::mergeDeepArray([$this->typedConfig->getDefinition($type), $property], TRUE);
  }

  /**
   * Answers whether a schema's class is, or extends, a class.
   *
   * @param array $schema
   *   The schema, merged with its type.
   * @param class-string $class
   *   The class.
   *
   * @return bool
   *   TRUE when it is.
   */
  protected function isA(array $schema, string $class): bool {
    $own = ltrim((string) ($schema['class'] ?? ''), '\\');
    return $own !== '' && is_a($own, $class, TRUE);
  }

  /**
   * Names the kind of value a typed data class holds.
   *
   * @param string $class
   *   The class.
   *
   * @return string|null
   *   string, integer, float, boolean, mapping or sequence; NULL for any
   *   other.
   */
  protected static function kind(string $class): ?string {
    $class = ltrim($class, '\\');
    return match (TRUE) {
      $class === '' => NULL,
      is_a($class, Mapping::class, TRUE) => 'mapping',
      is_a($class, Sequence::class, TRUE) => 'sequence',
      is_a($class, BooleanInterface::class, TRUE) => 'boolean',
      is_a($class, IntegerInterface::class, TRUE) => 'integer',
      is_a($class, FloatInterface::class, TRUE) => 'float',
      is_a($class, StringInterface::class, TRUE) => 'string',
      default => NULL,
    };
  }

  /**
   * Answers whether a schema type is fully validatable.
   *
   * @param array $definition
   *   The type's definition.
   *
   * @return bool
   *   TRUE when it carries the FullyValidatable constraint.
   */
  protected static function fullyValidatable(array $definition): bool {
    return array_key_exists('FullyValidatable', (array) ($definition['constraints'] ?? []));
  }

}
