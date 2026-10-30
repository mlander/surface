<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\TypedData\Type\BooleanInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\Type\StringInterface;

/**
 * Writes a config schema type as the JSON Schema of the values it holds.
 *
 * So a plugin's settings, which its config schema describes, can be read
 * into a structure by the schema reader as any JSON Schema is: a mapping
 * is an object closed to the keys it declares, a sequence an array, a
 * string, integer, float or boolean its JSON type, `nullable` a null
 * beside it, and the label each one's title. What the schema says of a
 * value that JSON Schema says too is carried: a Choice as an enum, Range,
 * Length and Count as their bounds, NotBlank as a string of one character
 * at least. Anything else it states is left to the schema itself, which
 * storage holds the whole placed block to before anything is written, so
 * what is written here is never stricter than what is stored.
 *
 * A type the schema cannot resolve without a value (one named through a
 * sibling) is a value of any type. No key is required: a plugin's own
 * defaults fill what is left out.
 *
 * document() writes a whole config object for the resource generator,
 * where the schema is what a resource is first written from: it also
 * carries the keys a fully validatable object requires and a Regex both
 * engines read alike, and lists what it leaves out, by key.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
final class ConfigSchemaDocument {

  /**
   * Whether a whole config object is being written, for document().
   */
  protected bool $whole = FALSE;

  /**
   * What document() leaves out, by dotted key path.
   *
   * @var array<string, array{constraints?: array<string, mixed>, type?: string, default?: mixed}>
   */
  protected array $left = [];

  /**
   * Constructs a ConfigSchemaDocument.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which owns the config schema.
   */
  public function __construct(
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * Writes a config schema type as an object.
   *
   * @param string $type
   *   The type, such as `block.settings.system_branding_block`; a wildcard
   *   type matches as typed config matches it.
   * @param string[] $leave_out
   *   Keys of the mapping left out: the host's own.
   * @param array $defaults
   *   A default per key, kept where it is a value of the key's type.
   *
   * @return array
   *   The JSON Schema: an object, closed.
   */
  public function object(string $type, array $leave_out = [], array $defaults = []): array {
    $root = $this->typedConfig->hasConfigSchema($type) ? $this->typedConfig->getDefinition($type) : [];
    $properties = [];
    foreach ((array) ($root['mapping'] ?? []) as $key => $property) {
      $key = (string) $key;
      if (in_array($key, $leave_out, TRUE) || !is_array($property)) {
        continue;
      }
      $element = $this->element($property);
      if (array_key_exists($key, $defaults) && self::fits($element, $defaults[$key])) {
        $element['default'] = $defaults[$key];
      }
      $properties[$key] = $element;
    }
    return [
      'type' => 'object',
      'properties' => $properties === [] ? new \stdClass() : $properties,
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * Writes a config object's schema whole, for a resource to be read from.
   *
   * What object() writes, and further: in a fully validatable object, each
   * mapping's keys `required`, but those the schema says may be absent
   * (`requiredKey: false`), since core holds such an object to every key;
   * a Regex as `pattern` where PatternTranslation carries it, and as
   * `not: {pattern}` for one that must not match; and a default at every
   * depth, from the values given. What it cannot say is listed in $left:
   * a constraint it does not carry, a type it cannot resolve without a
   * value, a value that does not fit the key, each by the key's dotted
   * path (empty for the object itself, `<key>.*` for a sequence's items).
   * The keys every config object has, which its storage owns (`_core`,
   * `langcode`), are left out, and so is what the object says of them;
   * `ValidKeys` that infers its keys from the mapping is what a closed
   * object says.
   *
   * @param string $type
   *   The config object's schema type, its name.
   * @param array $defaults
   *   The values it holds, or is installed with.
   * @param array|null $left
   *   Collects what is left out, by dotted key path.
   * @param string[] $owned
   *   The keys its storage owns, left out.
   *
   * @param-out array<string, array{constraints?: array<string, mixed>, type?: string, default?: mixed}> $left
   *
   * @return array
   *   The JSON Schema: an object, closed.
   *
   * @throws \InvalidArgumentException
   *   When the site has no config schema of that name.
   */
  public function document(string $type, array $defaults = [], ?array &$left = [], array $owned = ['_core', 'langcode']): array {
    if (!$this->typedConfig->hasConfigSchema($type)) {
      throw new \InvalidArgumentException(sprintf('The site has no config schema named %s.', $type));
    }
    $root = $this->typedConfig->getDefinition($type);
    $writer = clone $this;
    $writer->whole = TRUE;
    $writer->left = [];
    $constraints = (array) ($root['constraints'] ?? []);
    $validatable = array_key_exists('FullyValidatable', $constraints);
    // What every config object says of the keys its storage owns.
    unset($constraints['FullyValidatable'], $constraints['LangcodeRequiredIfTranslatableValues']);
    if (($constraints['ValidKeys']['allowedKeys'] ?? NULL) === '<infer>') {
      unset($constraints['ValidKeys']);
    }
    if ($constraints !== []) {
      $writer->left['']['constraints'] = $constraints;
    }
    $mapping = array_diff_key((array) ($root['mapping'] ?? []), array_flip($owned));
    $document = ['type' => 'object'] + $writer->mapping($mapping, $defaults, $validatable, '');
    $left = $writer->leftOut();
    return $document;
  }

  /**
   * Gives what document() left out.
   *
   * @return array<string, array{constraints?: array<string, mixed>, type?: string, default?: mixed}>
   *   What it could not say, by dotted key path.
   */
  protected function leftOut(): array {
    return $this->left;
  }

  /**
   * Gives the label a config schema type has.
   *
   * @param string $type
   *   The type.
   *
   * @return string|null
   *   Its label; NULL when it has none, or the site no such type.
   */
  public function label(string $type): ?string {
    $label = $this->typedConfig->hasConfigSchema($type) ? ($this->typedConfig->getDefinition($type)['label'] ?? NULL) : NULL;
    return $label === NULL || (string) $label === '' ? NULL : (string) $label;
  }

  /**
   * Writes a mapping's properties, for document().
   *
   * @param array $mapping
   *   The mapping's keys.
   * @param mixed $defaults
   *   The values it holds.
   * @param bool $validatable
   *   Whether the object is fully validatable.
   * @param string $path
   *   The mapping's dotted path, empty at the top.
   *
   * @return array
   *   `properties`, `required` where any is, and `additionalProperties`.
   */
  protected function mapping(array $mapping, mixed $defaults, bool $validatable, string $path): array {
    $properties = [];
    $required = [];
    foreach ($mapping as $key => $property) {
      $key = (string) $key;
      if (!is_array($property)) {
        continue;
      }
      $at = $path === '' ? $key : $path . '.' . $key;
      $default = is_array($defaults) && array_key_exists($key, $defaults) ? ['value' => $defaults[$key]] : NULL;
      $element = $this->element($property, $validatable, $at, $default);
      if ($default !== NULL && !array_key_exists('properties', $element)) {
        if (self::fits($element, $default['value'])) {
          $element['default'] = $default['value'];
        }
        elseif ($element !== [] && $default['value'] !== NULL) {
          $this->left[$at]['default'] = $default['value'];
        }
      }
      $properties[$key] = $element;
      if ($validatable && ($property['requiredKey'] ?? TRUE) !== FALSE) {
        $required[] = $key;
      }
    }
    return ['properties' => $properties === [] ? new \stdClass() : $properties]
      + ($required === [] ? [] : ['required' => $required])
      + ['additionalProperties' => FALSE];
  }

  /**
   * Writes one element of a config schema.
   *
   * @param array $property
   *   The element as the schema writes it: a type and what goes with it.
   * @param bool $validatable
   *   For document(): whether the object is fully validatable.
   * @param string $path
   *   For document(): the element's dotted path.
   * @param array{value: mixed}|null $default
   *   For document(): what a mapping holds, for its own keys' defaults.
   *
   * @return array
   *   Its JSON Schema; empty, any value, for a type that cannot be
   *   resolved without a value.
   */
  public function element(array $property, bool $validatable = FALSE, string $path = '', ?array $default = NULL): array {
    $schema = $this->merged($property);
    $class = ltrim((string) ($schema['class'] ?? ''), '\\');
    $element = [];
    // A whole object's keys are titled by their own labels alone: the type
    // a key names labels every key of it alike (`Boolean`, `Sequence`).
    $titled = $this->whole ? $property : $schema;
    if (isset($titled['label']) && (string) $titled['label'] !== '') {
      $element['title'] = (string) $titled['label'];
    }
    $type = match (TRUE) {
      $class === '' => NULL,
      is_a($class, Mapping::class, TRUE) => 'object',
      is_a($class, Sequence::class, TRUE) => 'array',
      is_a($class, BooleanInterface::class, TRUE) => 'boolean',
      is_a($class, IntegerInterface::class, TRUE) => 'integer',
      is_a($class, FloatInterface::class, TRUE) => 'number',
      is_a($class, StringInterface::class, TRUE) => 'string',
      default => NULL,
    };
    if ($type === NULL) {
      if ($this->whole) {
        $this->left[$path]['type'] = (string) ($property['type'] ?? '');
      }
      return $element;
    }
    $element['type'] = !empty($schema['nullable']) ? [$type, 'null'] : $type;
    if ($type === 'object' && $this->whole) {
      $element += $this->mapping((array) ($schema['mapping'] ?? []), $default['value'] ?? NULL, $validatable, $path);
    }
    elseif ($type === 'object') {
      $properties = [];
      foreach ((array) ($schema['mapping'] ?? []) as $key => $child) {
        if (is_array($child)) {
          $properties[(string) $key] = $this->element($child);
        }
      }
      $element['properties'] = $properties === [] ? new \stdClass() : $properties;
      $element['additionalProperties'] = FALSE;
    }
    if ($type === 'array') {
      $element['items'] = is_array($schema['sequence'] ?? NULL) ? $this->element($schema['sequence'], $validatable, $path . '.*') : new \stdClass();
    }
    return $element + $this->constraints((array) ($schema['constraints'] ?? []), $type, $path);
  }

  /**
   * Writes what the schema's constraints say that JSON Schema says too.
   *
   * @param array $constraints
   *   The constraints, by name.
   * @param string $type
   *   The JSON type they constrain.
   * @param string $path
   *   For document(): the element's dotted path, under which what is not
   *   carried is listed.
   *
   * @return array
   *   The keywords.
   */
  protected function constraints(array $constraints, string $type, string $path = ''): array {
    $keywords = [];
    foreach ($constraints as $name => $options) {
      $options = is_array($options) ? $options : [];
      $carried = TRUE;
      switch ((string) $name) {
        case 'Choice':
          if (isset($options['choices']) && is_array($options['choices']) && !isset($options['multiple'])) {
            $keywords['enum'] = array_values($options['choices']);
          }
          else {
            $carried = FALSE;
          }
          break;

        case 'Range':
          if (in_array($type, ['integer', 'number'], TRUE)) {
            if (isset($options['min']) && is_numeric($options['min'])) {
              $keywords['minimum'] = $options['min'] + 0;
            }
            if (isset($options['max']) && is_numeric($options['max'])) {
              $keywords['maximum'] = $options['max'] + 0;
            }
          }
          break;

        case 'Length':
          if ($type === 'string') {
            if (isset($options['min']) && is_numeric($options['min'])) {
              $keywords['minLength'] = (int) $options['min'];
            }
            if (isset($options['max']) && is_numeric($options['max'])) {
              $keywords['maxLength'] = (int) $options['max'];
            }
          }
          break;

        case 'Count':
          if ($type === 'array') {
            if (isset($options['min']) && is_numeric($options['min'])) {
              $keywords['minItems'] = (int) $options['min'];
            }
            if (isset($options['max']) && is_numeric($options['max'])) {
              $keywords['maxItems'] = (int) $options['max'];
            }
          }
          break;

        case 'NotBlank':
          if ($type === 'string' && empty($options['allowNull'])) {
            $keywords['minLength'] = max(1, (int) ($keywords['minLength'] ?? 0));
          }
          break;

        case 'ValidKeys':
          // Keys inferred from the mapping: what a closed object says.
          $carried = $type === 'object' && ($options['allowedKeys'] ?? NULL) === '<infer>';
          break;

        case 'Regex':
          $pattern = $this->whole && $type === 'string' && is_string($options['pattern'] ?? NULL) ? PatternTranslation::ecma($options['pattern']) : NULL;
          if ($pattern === NULL) {
            $carried = FALSE;
          }
          elseif (($options['match'] ?? TRUE) === FALSE) {
            $keywords['not'] = ['pattern' => $pattern];
          }
          else {
            $keywords['pattern'] = $pattern;
          }
          break;

        default:
          $carried = FALSE;
      }
      if (!$carried && $this->whole) {
        $this->left[$path]['constraints'][(string) $name] = $options;
      }
    }
    return $keywords;
  }

  /**
   * Merges a schema element with the type it names.
   *
   * @param array $property
   *   The element, as written.
   *
   * @return array
   *   The type's definition with the element's own laid over it; the
   *   element as written for a type named through a sibling.
   */
  protected function merged(array $property): array {
    $type = (string) ($property['type'] ?? '');
    if ($type === '' || str_contains($type, ']') || !$this->typedConfig->hasConfigSchema($type)) {
      return $property;
    }
    return NestedArray::mergeDeepArray([$this->typedConfig->getDefinition($type), $property], TRUE);
  }

  /**
   * Says whether a default is a value of an element's own type.
   *
   * A plugin's defaults are what its form starts from, and a form's
   * notion of nothing yet (an empty string for an integer) is a value the
   * key would refuse.
   *
   * @param array $element
   *   The element's JSON Schema.
   * @param mixed $default
   *   The default.
   *
   * @return bool
   *   TRUE when it fits.
   */
  public static function fits(array $element, mixed $default): bool {
    $types = (array) ($element['type'] ?? []);
    if ($types === []) {
      return FALSE;
    }
    if ($default === NULL) {
      return in_array('null', $types, TRUE);
    }
    foreach ($types as $type) {
      $fits = match ($type) {
        'string' => is_string($default),
        'integer' => is_int($default),
        'number' => is_int($default) || is_float($default),
        'boolean' => is_bool($default),
        'array' => is_array($default) && array_is_list($default),
        'object' => is_array($default) && ($default === [] || !array_is_list($default)),
        default => FALSE,
      };
      if ($fits && isset($element['enum']) && !in_array($default, $element['enum'], TRUE)) {
        $fits = FALSE;
      }
      if ($fits) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
