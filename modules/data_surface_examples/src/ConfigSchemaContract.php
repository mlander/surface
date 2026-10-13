<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\TypedData\Type\BooleanInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\Type\StringInterface;
use Drupal\Core\TypedData\Type\UriInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Says what a client could be told about a config object without a surface.
 *
 * The before picture beside an example's contract: two JSON Schema
 * documents for the config object the example is stored in, neither of
 * which reads a surface.
 *
 * The first is what core's serializer answers when the typed config is
 * normalized in the `json_schema` format. It is asked, never written
 * down here, so the day core answers more, this shows more.
 *
 * The second is the most a plain mapping of the config schema definition
 * could say. It reads the definition, never a stored value, and it
 * translates what is declared one to one:
 *
 * - `label` is `title`, `description` is `description`;
 * - a scalar type is the JSON type its data type class holds, so a type
 *   that extends `string` is a string, as typed config resolves it;
 * - a `mapping` is an object with `properties`, a `sequence` an object
 *   with `additionalProperties`, or an array with `items` when it is
 *   ordered by value;
 * - `nullable: true` allows `null` beside the type;
 * - the constraints the definition declares, its type's included, become
 *   the keyword JSON Schema has for them: Range, Length, Choice with a
 *   fixed list, Regex, NotBlank, Email, and a mapping's inferred
 *   ValidKeys as `additionalProperties: false`;
 * - a type that needs a value to resolve, such as `[%parent.pricing]`
 *   or `[%key]`, is not guessed at: the key keeps its title and a
 *   `$comment` naming the declared type;
 * - `required` is written only for a type that is fully validatable,
 *   which is the only case where config schema says a key must be there.
 *
 * It leaves out `_core` and `langcode`, config's own bookkeeping, which
 * a surface leaves out too. It errs on the side of config schema in one
 * place: core lets any value of a type that is not fully validatable be
 * NULL, and this says so only where the schema says `nullable: true`.
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter
 */
final class ConfigSchemaContract {

  /**
   * The keys of every config object that are not its settings.
   */
  protected const BOOKKEEPING = ['_core', 'langcode'];

  /**
   * Constructs a ConfigSchemaContract.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which holds the schema definitions.
   * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $serializer
   *   Core's serializer.
   */
  public function __construct(
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly NormalizerInterface $serializer,
  ) {}

  /**
   * Describes a config object both ways.
   *
   * @param string $name
   *   The config object's name.
   *
   * @return array{core: array, derived: array}
   *   What core answers, and what a plain mapping of the schema says.
   */
  public function describe(string $name): array {
    return ['core' => $this->core($name), 'derived' => $this->derived($name)];
  }

  /**
   * Asks core for the JSON Schema of a config object.
   *
   * @param string $name
   *   The config object's name.
   *
   * @return array
   *   What the serializer answers for its typed config, as it answers it.
   */
  public function core(string $name): array {
    $answer = $this->serializer->normalize($this->typedConfig->get($name), 'json_schema');
    return is_array($answer) ? $answer : (array) $answer;
  }

  /**
   * Maps a config object's schema definition to JSON Schema, plainly.
   *
   * @param string $name
   *   The config object's name.
   *
   * @return array
   *   The schema, in the dialect the surface's contract is written in.
   */
  public function derived(string $name): array {
    $definition = $this->typedConfig->getDefinition($name);
    $strict = array_key_exists('FullyValidatable', $definition['constraints'] ?? []);
    return ['$schema' => ContractEmitter::DIALECT] + $this->schema($definition, $strict, []);
  }

  /**
   * Maps one schema definition, and what it holds.
   *
   * @param array $definition
   *   The definition, as a schema file declares it.
   * @param bool $strict
   *   Whether the config object's type is fully validatable.
   * @param string[] $open
   *   The types being mapped above this one; empty for the config object.
   *
   * @return array
   *   The schema.
   */
  protected function schema(array $definition, bool $strict, array $open): array {
    $type = (string) ($definition['type'] ?? 'undefined');
    if (str_contains($type, '[')) {
      return $this->described($definition) + [
        '$comment' => sprintf('Declared as the type %s, which can only be resolved against a stored value.', $type),
      ];
    }
    if (in_array($type, $open, TRUE)) {
      return $this->described($definition) + [
        '$comment' => sprintf('Declared as the type %s, which holds itself.', $type),
      ];
    }
    // What typed config does: the key's own words first, its type's for
    // whatever the key left unsaid.
    $definition += $this->typedConfig->getDefinition($type);
    $class = ltrim((string) ($definition['class'] ?? ''), '\\');
    $schema = $this->described($definition);
    if (is_a($class, Undefined::class, TRUE)) {
      return $schema + ['$comment' => sprintf('Declared as the type %s, which no schema defines.', $type)];
    }
    $constraints = is_array($definition['constraints'] ?? NULL) ? $definition['constraints'] : [];
    $nullable = !empty($definition['nullable']);
    if (is_a($class, Mapping::class, TRUE)) {
      $schema['type'] = $nullable ? ['object', 'null'] : 'object';
      return $schema + $this->mapping($definition, $constraints, $strict, [...$open, $type]);
    }
    if (is_a($class, Sequence::class, TRUE)) {
      $item = is_array($definition['sequence'] ?? NULL) ? $definition['sequence'] : [];
      $items = $this->schema($item[0] ?? $item, $strict, [...$open, $type]);
      $list = ($definition['orderby'] ?? NULL) === 'value';
      $schema['type'] = $nullable ? [$list ? 'array' : 'object', 'null'] : ($list ? 'array' : 'object');
      return $schema + [$list ? 'items' : 'additionalProperties' => $items];
    }
    $scalar = $this->scalar($class);
    if ($scalar !== [] && $nullable) {
      $scalar['type'] = [$scalar['type'], 'null'];
    }
    return $schema + $scalar + $this->keywords($constraints, $scalar['type'] ?? NULL);
  }

  /**
   * Reads a definition's label and description.
   *
   * @param array $definition
   *   The definition.
   *
   * @return array
   *   `title` and `description`, each only when the definition has one.
   */
  protected function described(array $definition): array {
    $described = [];
    foreach (['label' => 'title', 'description' => 'description'] as $said => $keyword) {
      if (isset($definition[$said]) && (string) $definition[$said] !== '') {
        $described[$keyword] = (string) $definition[$said];
      }
    }
    return $described;
  }

  /**
   * Maps a mapping's keys.
   *
   * @param array $definition
   *   The mapping's definition.
   * @param array $constraints
   *   The constraints it declares.
   * @param bool $strict
   *   Whether the config object's type is fully validatable.
   * @param string[] $open
   *   The types being mapped down to this one.
   *
   * @return array
   *   `properties`, and `additionalProperties` and `required` where the
   *   definition says them.
   */
  protected function mapping(array $definition, array $constraints, bool $strict, array $open): array {
    $schema = [];
    $required = [];
    foreach ($definition['mapping'] ?? [] as $key => $child) {
      if (count($open) === 1 && in_array($key, self::BOOKKEEPING, TRUE)) {
        continue;
      }
      $child = is_array($child) ? $child : [];
      $schema['properties'][$key] = $this->schema($child, $strict, $open);
      if (($child['requiredKey'] ?? TRUE) !== FALSE && !array_key_exists('deprecated', $child)) {
        $required[] = (string) $key;
      }
    }
    $valid_keys = $constraints['ValidKeys'] ?? NULL;
    if ((is_array($valid_keys) ? $valid_keys['allowedKeys'] ?? NULL : $valid_keys) === '<infer>') {
      $schema['additionalProperties'] = FALSE;
    }
    if ($strict && $required !== []) {
      $schema['required'] = $required;
    }
    return $schema;
  }

  /**
   * Names the JSON type a scalar data type class holds.
   *
   * @param string $class
   *   The data type's class.
   *
   * @return array
   *   `type`, and `format` for a URI; empty for a class JSON Schema has
   *   no type for.
   */
  protected function scalar(string $class): array {
    return match (TRUE) {
      is_a($class, UriInterface::class, TRUE) => ['type' => 'string', 'format' => 'uri'],
      is_a($class, StringInterface::class, TRUE) => ['type' => 'string'],
      is_a($class, IntegerInterface::class, TRUE) => ['type' => 'integer'],
      is_a($class, FloatInterface::class, TRUE) => ['type' => 'number'],
      is_a($class, BooleanInterface::class, TRUE) => ['type' => 'boolean'],
      default => [],
    };
  }

  /**
   * States declared constraints as the keywords JSON Schema has for them.
   *
   * The same reading the surface's contract gives the same constraints,
   * so the two documents differ only in what was declared.
   *
   * @param array $constraints
   *   The constraints, by name.
   * @param string|array|null $type
   *   The JSON type the key was given.
   *
   * @return array
   *   The keywords. A constraint with no keyword is left unsaid.
   */
  protected function keywords(array $constraints, string|array|null $type): array {
    $keywords = [];
    $numeric = array_intersect((array) $type, ['integer', 'number']) !== [];
    foreach ($constraints as $name => $options) {
      $options = is_array($options) ? $options : [];
      switch ($name) {
        case 'Range':
          if ($numeric && isset($options['min'])) {
            $keywords['minimum'] = $options['min'];
          }
          if ($numeric && isset($options['max'])) {
            $keywords['maximum'] = $options['max'];
          }
          break;

        case 'Length':
          if (isset($options['min'])) {
            $keywords['minLength'] = (int) $options['min'];
          }
          if (isset($options['max'])) {
            $keywords['maxLength'] = (int) $options['max'];
          }
          break;

        case 'Choice':
          // A list written in the schema; a callback names PHP to run.
          $choices = $options['choices'] ?? (array_is_list($options) ? $options : []);
          if (is_array($choices) && $choices !== []) {
            $keywords['enum'] = array_values($choices);
          }
          break;

        case 'Regex':
          $pattern = $this->pattern($options);
          if ($pattern !== NULL) {
            $keywords += ($options['match'] ?? TRUE) === FALSE ? ['not' => ['pattern' => $pattern]] : ['pattern' => $pattern];
          }
          break;

        case 'NotBlank':
          $keywords['minLength'] = max(1, $keywords['minLength'] ?? 1);
          break;

        case 'Email':
          $keywords['format'] = 'email';
          break;
      }
    }
    return $keywords;
  }

  /**
   * Turns a PHP regular expression into an ECMA one, when it can be.
   *
   * @param array $options
   *   The Regex constraint's options.
   *
   * @return string|null
   *   The pattern without its delimiters, or NULL when the expression
   *   carries a modifier JSON Schema has no way to say.
   *
   * @see \Drupal\data_surface\Contract\ContractEmitter::pattern()
   */
  protected function pattern(array $options): ?string {
    $regex = $options['pattern'] ?? NULL;
    if (!is_string($regex) || strlen($regex) < 2) {
      return NULL;
    }
    $end = strrpos($regex, $regex[0]);
    if ($end === FALSE || $end === 0) {
      return NULL;
    }
    return trim(substr($regex, $end + 1), 'u') === '' ? substr($regex, 1, $end - 1) : NULL;
  }

}
