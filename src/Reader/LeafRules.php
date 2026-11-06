<?php

declare(strict_types=1);

namespace Drupal\surface\Reader;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\Plugin\DataType\DateTimeIso8601;
use Drupal\Core\TypedData\Plugin\DataType\Email;
use Drupal\Core\TypedData\Plugin\DataType\Timestamp;
use Drupal\Core\TypedData\Plugin\DataType\Uri;
use Drupal\Core\TypedData\Type\BooleanInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\Type\StringInterface;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\DefinitionMetadata;

/**
 * The rules every reader of typed data reads a value by.
 *
 * Config schema and typed data say a value the same way underneath: a
 * data type whose class is one of core's primitives, and constraints by
 * plugin id. So one place says which of the blueprint's types a class
 * is (LeafTypes' rows), which constraints are carried onto the key (all
 * of them, verbatim, but those that say how a mapping is validated rather
 * than what a value may be, and those that read another value of the
 * typed config tree), and how a label is wrapped.
 *
 * Decision: see
 * docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader.
 *
 * @internal
 */
final class LeafRules {

  /**
   * The blueprint's type each primitive class is, most particular first.
   */
  public const CLASSES = [
    Timestamp::class => 'timestamp',
    DateTimeIso8601::class => 'datetime_iso8601',
    Email::class => 'email',
    Uri::class => 'uri',
    BooleanInterface::class => 'boolean',
    IntegerInterface::class => 'integer',
    FloatInterface::class => 'float',
    StringInterface::class => 'string',
  ];

  /**
   * Constraints that say how a mapping is validated, not what it holds.
   *
   * Each is read by core's typed config alone: whether a whole tree is
   * held to every key (FullyValidatable, which the reader reads as which
   * keys are required), whether a config object needs a langcode, and
   * which keys a mapping allows (ValidKeys, which a map closed to the keys
   * it declares says already).
   */
  public const OF_THE_MAPPING = ['FullyValidatable', 'LangcodeRequiredIfTranslatableValues', 'ValidKeys'];

  /**
   * The constraints the JSON Schema route carries (ConfigSchemaDocument).
   *
   * Every other one it leaves to the schema, and the contract never
   * states it.
   */
  public const JSON_CARRIED = ['Choice', 'Range', 'Length', 'Count', 'NotBlank', 'Regex'];

  /**
   * Names the blueprint's type a data type class is.
   *
   * @param string $class
   *   The data type's class.
   *
   * @return string|null
   *   A type of LeafTypes, or NULL for a class that is none of them.
   */
  public static function type(string $class): ?string {
    $class = ltrim($class, '\\');
    if ($class === '' || !class_exists($class)) {
      return NULL;
    }
    foreach (self::CLASSES as $primitive => $type) {
      if (is_a($class, $primitive, TRUE)) {
        return $type;
      }
    }
    return NULL;
  }

  /**
   * Makes one value of a type, labeled.
   *
   * @param string $type
   *   A type of LeafTypes.
   * @param string|\Stringable|null $label
   *   The label, or NULL for none.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, with no constraint yet.
   */
  public static function value(string $type, string|\Stringable|null $label): DataDefinition {
    $definition = DataDefinition::create($type);
    if ($label !== NULL && (string) $label !== '') {
      $definition->setLabel(self::label($label));
    }
    return $definition;
  }

  /**
   * Makes a value of any shape, labeled: what a key no shape is read for is.
   *
   * A key the source declares is declared, though the reader derives no
   * shape for it: a structure is closed, so a key left out would refuse
   * the stored value that holds it. It is `any`, held whole to the schema
   * it was read from (DefinitionMetadata::setHeldWhole()), which keeps
   * nothing of it: open, accepting what is stored there as it is, and
   * written in both documents with no type.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param string|\Stringable|null $label
   *   The label, or NULL for none.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, open.
   */
  public static function open(string|\Stringable|null $label): DataDefinition {
    $definition = self::value(LeafTypes::ANY, $label);
    DefinitionMetadata::setHeldWhole($definition);
    return $definition;
  }

  /**
   * Lays a source's constraints on a definition, verbatim.
   *
   * Every one is added under its own name with its own options, but those
   * that say how a mapping is validated (OF_THE_MAPPING) and those whose
   * options name a value elsewhere in the typed config tree
   * (treeExpression()), which are noted.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition: a value, a list or the map at a key.
   * @param array $constraints
   *   The constraints, by plugin id; options NULL for none.
   * @param \Drupal\surface\Reader\Reading $reading
   *   Where what is not carried is noted.
   * @param string $path
   *   The definition's dotted path, for the notes.
   *
   * @return string[]
   *   The constraints carried, by name.
   */
  public static function carry(DataDefinitionInterface $definition, array $constraints, Reading $reading, string $path): array {
    $carried = [];
    foreach ($constraints as $name => $options) {
      $name = (string) $name;
      if (in_array($name, self::OF_THE_MAPPING, TRUE)) {
        $reading->leave($path, self::ofTheMapping($name, is_array($options) ? $options : []));
        continue;
      }
      $expression = is_array($options) ? self::treeExpression($options) : NULL;
      if ($expression !== NULL) {
        $reading->leave($path, sprintf('%s: its options name a value elsewhere in the typed config tree (%s), which only typed config resolves, so it is checked where the whole value is held to its schema, and not carried.', $name, $expression));
        continue;
      }
      $definition->addConstraint($name, is_array($options) ? $options : []);
      $carried[] = $name;
    }
    return $carried;
  }

  /**
   * Finds an option that names a value elsewhere in the typed config tree.
   *
   * Core resolves `%parent.<key>` and `[%parent.<key>]` in a constraint's
   * options against the typed config value it validates (MenuLinkDepth's
   * `baseLevel`, EntityBundleExists' `entityTypeId`). A key the pipeline
   * validates has no such tree, and the validator throws.
   *
   * @param array $options
   *   The constraint's options.
   *
   * @return string|null
   *   The first such option's value, or NULL for none.
   */
  public static function treeExpression(array $options): ?string {
    foreach ($options as $option) {
      if (is_array($option)) {
        $found = self::treeExpression($option);
        if ($found !== NULL) {
          return $found;
        }
      }
      elseif (is_string($option) && preg_match('/(^|\[)%(parent|key|type)\b/', $option)) {
        return $option;
      }
    }
    return NULL;
  }

  /**
   * Says why a constraint of the mapping is not carried.
   *
   * @param string $name
   *   The constraint.
   * @param array $options
   *   Its options.
   *
   * @return string
   *   The reason.
   */
  public static function ofTheMapping(string $name, array $options): string {
    return match ($name) {
      'FullyValidatable' => 'FullyValidatable: read as which keys are required, not carried as a constraint.',
      'LangcodeRequiredIfTranslatableValues' => 'LangcodeRequiredIfTranslatableValues: a config object\'s langcode, which its storage owns.',
      default => ($options['allowedKeys'] ?? NULL) === '<infer>'
        ? 'ValidKeys: the keys inferred from the mapping, which a map closed to the keys it declares says.'
        : sprintf('ValidKeys: the keys %s, which typed config checks on a Mapping and a blueprint map cannot.', implode(', ', array_map('strval', (array) ($options['allowedKeys'] ?? [])))),
    };
  }

  /**
   * Makes a source's text translatable, as the module writes one.
   *
   * @param string|\Stringable $text
   *   A bare string, or a text already made.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   A bare string wrapped in TranslatableMarkup, a TranslatableMarkup as
   *   it came, any other text as a string.
   */
  public static function label(string|\Stringable $text): TranslatableMarkup|string {
    return match (TRUE) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      is_string($text) => new TranslatableMarkup($text),
      $text instanceof TranslatableMarkup => $text,
      default => (string) $text,
    };
  }

}
