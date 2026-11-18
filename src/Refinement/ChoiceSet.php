<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\SchemaFacts;
use PortableSchema\Builder\Schema;

/**
 * The list of values a definition's choice constraint allows.
 *
 * Contribution and refinement both work on one thing: the set of values
 * a key may hold. That set is declared as a constraint, in one of three
 * spellings and under one of two names, and every place that has to read
 * or rewrite it would otherwise repeat the same four lines of array
 * handling. This reads it once, in exactly the way the constraint class
 * itself reads it, and writes it back in Choice's own form, `choices` as
 * the list of values and `labels` as what each one is called, keeping
 * whatever else the constraint said.
 *
 * It is deliberately not an option list: what a definition allows and
 * what a form offers are different questions, and the second one is
 * answered by the options resolvers, which read live site state as well.
 *
 * Its one public promise, to a #[RefinesInput] method that reads its key:
 * of(), handed the key's schema, and the set's `values` and `labels`.
 * Everything else here is how this module reads a choice constraint.
 *
 * @see \Drupal\surface\Plugin\Validation\Constraint\LabeledChoiceConstraint
 * @see \Drupal\surface\Options\SurfaceOptions
 */
final class ChoiceSet {

  /**
   * The choice-bearing constraints, most specific first.
   */
  public const CONSTRAINTS = ['LabeledChoice', 'Choice'];

  /**
   * Constructs a ChoiceSet.
   *
   * @param string $constraint
   *   The constraint plugin ID the values were read from, and the one
   *   they are written back to.
   * @param array $values
   *   The allowed values, as a list.
   * @param array $labels
   *   What each value is called, keyed by the value; may be partial, and
   *   is always empty for a plain Choice, which has nowhere to keep it.
   * @param array $options
   *   Everything else the constraint said, so rewriting the values does
   *   not quietly drop a message or a description.
   */
  public function __construct(
    public readonly string $constraint,
    public readonly array $values,
    public readonly array $labels = [],
    public readonly array $options = [],
  ) {
  }

  /**
   * Reads the list of values a key allows, if it allows a list.
   *
   * The reader a #[RefinesInput] method is promised for the key's schema
   * it is handed: the values, in the key's order, and what each is called.
   * Handed a definition, it reads the definition's constraints the same
   * way.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\Core\TypedData\DataDefinitionInterface $key
   *   The key's schema, or a definition.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet|null
   *   The set, or NULL when the key names no list of values and so allows
   *   anything its type allows.
   */
  public static function of(Schema|DataDefinitionInterface $key): ?ChoiceSet {
    return $key instanceof Schema ? static::ofSchema($key) : static::fromConstraints($key->getConstraints());
  }

  /**
   * Reads the choice constraint a key's schema holds, if it holds one.
   *
   * What of() and ofConstraint() read, from the key's schema: its
   * constraints as SchemaFacts::constraints() gives them.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param string|null $name
   *   The constraint plugin ID to read; NULL for the one of() reads.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet|null
   *   The set, or NULL when the schema holds no such list.
   */
  public static function ofSchema(Schema $schema, ?string $name = NULL): ?ChoiceSet {
    $constraints = SchemaFacts::constraints($schema);
    if ($name === NULL) {
      return static::fromConstraints($constraints);
    }
    return array_key_exists($name, $constraints) ? self::fromOptions($name, (array) $constraints[$name]) : NULL;
  }

  /**
   * Reads the choice constraint a list of constraints holds, if it holds one.
   *
   * What of() reads, for a reader that holds a key's constraints as its
   * schema holds them (SchemaFacts::constraints()).
   *
   * @param array $constraints
   *   The constraints, options by plugin id.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet|null
   *   As of() answers.
   */
  public static function fromConstraints(array $constraints): ?ChoiceSet {
    foreach (self::CONSTRAINTS as $name) {
      if (array_key_exists($name, $constraints)) {
        return self::fromOptions($name, (array) $constraints[$name]);
      }
    }
    return NULL;
  }

  /**
   * Reads one named constraint, whatever else the definition carries.
   *
   * Refinement asks by name rather than by precedence: the list a
   * contribution was added to is the list the union is written back to,
   * even when a refiner has since added a second choice constraint of
   * its own beside it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   * @param string $name
   *   The constraint plugin ID to read.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet|null
   *   The set, or NULL when the definition does not carry that
   *   constraint.
   */
  public static function ofConstraint(DataDefinitionInterface $definition, string $name): ?ChoiceSet {
    $constraints = $definition->getConstraints();
    return array_key_exists($name, $constraints)
      ? self::fromOptions($name, (array) $constraints[$name])
      : NULL;
  }

  /**
   * Reads one constraint's options as a set of values.
   *
   * The three LabeledChoice spellings are read exactly as the constraint
   * class reads them: with `labels` given, `choices` is the list of
   * values; without it, `choices` is the values mapped to their labels;
   * with `labels` alone, its keys are the values.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $options
   *   The constraint's declared options.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet
   *   The set.
   */
  public static function fromOptions(string $name, array $options): ChoiceSet {
    $choices = $options['choices'] ?? [];
    $choices = is_array($choices) ? $choices : [];
    $labels = [];
    if ($name === 'LabeledChoice') {
      if (array_key_exists('labels', $options)) {
        $labels = (array) $options['labels'];
        if (!array_key_exists('choices', $options)) {
          $choices = array_keys($labels);
        }
      }
      else {
        $labels = $choices;
        $choices = array_keys($choices);
      }
    }
    unset($options['choices'], $options['labels']);
    return new self($name, array_values($choices), $labels, $options);
  }

  /**
   * Returns whether one constraint's options name a list of values.
   *
   * Read by the name it is written under, not by the constraint class,
   * because a definition holds constraints as plain arrays and the
   * narrowing check must judge what is written there.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $options
   *   The constraint's declared options.
   *
   * @return bool
   *   TRUE when the options carry a list of allowed values.
   */
  public static function isChoiceBearing(string $name, array $options): bool {
    return in_array($name, self::CONSTRAINTS, TRUE) || array_key_exists('choices', $options);
  }

  /**
   * Returns whether one constraint's options list the values it allows.
   *
   * A Choice lists them in `choices`; a LabeledChoice in `choices` or,
   * with `labels` alone, in the keys of `labels`.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $options
   *   The constraint's declared options.
   *
   * @return bool
   *   TRUE when the options list the allowed values.
   */
  public static function listsValues(string $name, array $options): bool {
    return array_key_exists('choices', $options)
      || ($name === 'LabeledChoice' && array_key_exists('labels', $options));
  }

  /**
   * Returns the same set over a different list of values.
   *
   * @param array $values
   *   The values the new set allows.
   * @param array $labels
   *   Labels to read from, merged under the ones this set holds; only
   *   those belonging to the given values are kept.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet
   *   The new set.
   */
  public function withValues(array $values, array $labels = []): ChoiceSet {
    $values = array_values($values);
    $merged = $this->labels + $labels;
    return new self(
      $this->constraint,
      $values,
      array_intersect_key($merged, array_flip($values)),
      $this->options,
    );
  }

  /**
   * Writes the set back onto a definition, in Choice's own form.
   *
   * A constraint is declared as one array, so adding it again replaces
   * what was there: the options this set carries are written back with
   * it, which is why they were kept.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to write to.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same definition.
   */
  public function applyTo(DataDefinitionInterface $definition): DataDefinitionInterface {
    $options = $this->options;
    $options['choices'] = $this->values;
    if ($this->constraint === 'LabeledChoice') {
      $options['labels'] = array_intersect_key($this->labels, array_flip($this->values));
    }
    $definition->addConstraint($this->constraint, $options);
    return $definition;
  }

}
