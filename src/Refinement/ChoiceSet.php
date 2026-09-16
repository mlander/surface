<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * The list of values a definition's choice constraint allows.
 *
 * Contribution and refinement both work on one thing: the set of values
 * a key may hold. That set is declared as a constraint, in one of two
 * spellings and under one of two names, and every place that has to read
 * or rewrite it would otherwise repeat the same four lines of array
 * handling. This reads it once, in exactly the way the constraint class
 * itself reads it, and writes it back in the canonical spelling —
 * `choices` as the list of values, `labels` as what each one is called —
 * keeping whatever else the constraint said.
 *
 * It is deliberately not an option list: what a definition allows and
 * what a form offers are different questions, and the second one is
 * answered by the options resolvers, which read live site state as well.
 *
 * @see \Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint
 * @see \Drupal\data_surface\Options\DataSurfaceOptions
 *
 * @internal
 *   The shape of a choice constraint is the constraint's business; this
 *   is how this module reads it and nothing more.
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
   * Reads the choice constraint a definition carries, if it carries one.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\data_surface\Refinement\ChoiceSet|null
   *   The set, or NULL when the definition names no list of values and
   *   so allows anything its type allows.
   */
  public static function of(DataDefinitionInterface $definition): ?ChoiceSet {
    $constraints = $definition->getConstraints();
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
   * @return \Drupal\data_surface\Refinement\ChoiceSet|null
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
   * Both LabeledChoice spellings are read exactly as the constraint
   * class reads them: with `labels` given, `choices` is the list of
   * values; without it, `choices` is the values mapped to their labels.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $options
   *   The constraint's declared options.
   *
   * @return \Drupal\data_surface\Refinement\ChoiceSet
   *   The set.
   */
  public static function fromOptions(string $name, array $options): ChoiceSet {
    $choices = $options['choices'] ?? [];
    $choices = is_array($choices) ? $choices : [];
    $labels = [];
    if ($name === 'LabeledChoice') {
      if (array_key_exists('labels', $options)) {
        $labels = (array) $options['labels'];
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
   * Returns the same set over a different list of values.
   *
   * @param array $values
   *   The values the new set allows.
   * @param array $labels
   *   Labels to read from, merged under the ones this set holds; only
   *   those belonging to the given values are kept.
   *
   * @return \Drupal\data_surface\Refinement\ChoiceSet
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
   * Writes the set back onto a definition, in the canonical spelling.
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
