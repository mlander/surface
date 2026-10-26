<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\ConstraintMessages;
use Drupal\data_surface\Contract\PatternTranslation;

/**
 * A rule between keys, declared as data: a condition and its effects.
 *
 * What a blueprint's narrow() hands back, for the owner or an alter to
 * say, in defineInputs() or alterInputs(), how one key narrows when its
 * siblings hold certain values:
 *
 * @code
 * $inputs->narrow('capacity')
 *   ->when(Condition::absent('license'))
 *   ->constrain('Range', ['max' => 100])
 *   ->because(t('Up to 100 without an event license.'));
 * @endcode
 *
 * A declared rule is a refiner, static by construction: it is bound in
 * the key's chain with the coded ones, watched the same way, and only
 * narrows. Its effects are a closed vocabulary, each with one exact JSON
 * Schema spelling, so the full schema states the rule as a conditional
 * without running it:
 *
 * - constrain('Range', [min, max, message]): raises the minimum, lowers
 *   the maximum; `minimum`, `maximum`. `Length` and `Count` the same,
 *   for a text's length and a list's items; `minLength`, `maxItems` and
 *   so on. The message, if any, is what a value outside the rule's
 *   bound is refused with.
 * - constrain('Choice' or 'LabeledChoice', [choices]): keeps only these
 *   of the key's own values; the `oneOf` of what stays.
 * - constrain('Regex', [pattern, message]): a pattern, where the key has
 *   none; `pattern`.
 * - require(): the key must hold a value; `required`, and its type
 *   without `null`.
 * - doesNotApply(): the key does not apply; the key `false`.
 * - because($text): says why, under the field; `description`. Only
 *   where the rule did something for these values: a constraint the key
 *   already held at or inside the rule's leaves the key's own words.
 *   A rule that requires, or describes alone, always says it.
 *
 * A bound only tightens: a maximum below what the key allows by the time
 * the rule runs is kept, so the rule's place in the chain cannot widen
 * anything, and a validator applying both conditionals reads the same
 * lower bound. A pattern is carried only inside the subset both engines
 * read alike. A rule that would widen what the key declares (a maximum
 * above its own, a value its list does not offer, a second pattern) is
 * refused when the resource is built.
 *
 * Decision: see docs/decisions.md#a-rule-declared-as-data.
 */
final class Rule {

  /**
   * The constraints a rule may add or tighten, and the options each takes.
   */
  public const CONSTRAINTS = [
    'Range' => ['min', 'max', 'message'],
    'Length' => ['min', 'max', 'message'],
    'Count' => ['min', 'max', 'message'],
    'Choice' => ['choices'],
    'LabeledChoice' => ['choices'],
    'Regex' => ['pattern', 'message'],
  ];

  /**
   * The message options a bound's message is written under, by bound.
   *
   * Symfony's: `both` is the one a range holding both bounds reports in,
   * whichever side refuses (Symfony refuses the other two beside it), and
   * the one a length or count reports in when both bounds are one value.
   */
  protected const BOUND_MESSAGES = [
    'Range' => ['min' => 'minMessage', 'max' => 'maxMessage', 'both' => 'notInRangeMessage'],
    'Length' => ['min' => 'minMessage', 'max' => 'maxMessage', 'both' => 'exactMessage'],
    'Count' => ['min' => 'minMessage', 'max' => 'maxMessage', 'both' => 'exactMessage'],
  ];

  /**
   * The condition the effects wait for.
   */
  protected ?Condition $condition = NULL;

  /**
   * The constraints the rule adds or tightens, by name.
   *
   * @var array<string, array>
   */
  protected array $constraints = [];

  /**
   * Whether the rule makes the key required.
   */
  protected bool $requires = FALSE;

  /**
   * Whether the rule rules the key out.
   */
  protected bool $rulesOut = FALSE;

  /**
   * What the rule says under the field, if anything.
   */
  protected string|\Stringable|null $description = NULL;

  /**
   * Constructs a rule for one key; a blueprint's narrow() does.
   *
   * @param string $key
   *   The key it narrows, by the name its blueprint knows it.
   */
  public function __construct(public readonly string $key) {
  }

  /**
   * Says when the effects apply.
   *
   * @param \Drupal\data_surface\Refinement\Condition $condition
   *   The condition, on keys of the same frame.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the rule has a condition already: one rule, one condition;
   *   combine them with Condition::allOf() or Condition::anyOf().
   */
  public function when(Condition $condition): static {
    if ($this->condition !== NULL) {
      throw new \LogicException(sprintf('narrow("%s") was given a second condition. One rule has one condition: combine them with Condition::allOf() or Condition::anyOf(), or declare a second rule.', $this->key));
    }
    $this->condition = $condition;
    return $this;
  }

  /**
   * Says when the effects apply, by when they do not: unless it holds.
   *
   * The same rule as when(Condition::not($condition)), read without the
   * double negative: "the door code does not apply unless after-hours
   * access is on".
   *
   * @code
   * $inputs->narrow('door_code')
   *   ->unless(Condition::equals('after_hours', TRUE))
   *   ->doesNotApply();
   * @endcode
   *
   * @param \Drupal\data_surface\Refinement\Condition $condition
   *   The condition under which the effects do not apply, on keys of the
   *   same frame.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the rule has a condition already, as when() does.
   */
  public function unless(Condition $condition): static {
    return $this->when(Condition::not($condition));
  }

  /**
   * Adds or tightens a constraint while the condition holds.
   *
   * @param string $constraint
   *   Range, Length or Count (min and max, at least one, and a message a
   *   value outside them is refused with); Choice or LabeledChoice
   *   (choices: the values of the key's own list to keep); Regex (a
   *   pattern, and the message that explains it).
   * @param array $options
   *   The options.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   For a constraint or an option outside the vocabulary, or a pattern
   *   no schema can state exactly.
   */
  public function constrain(string $constraint, array $options): static {
    $this->assertApplies('constrain');
    if (!isset(self::CONSTRAINTS[$constraint])) {
      throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain() was given %s, which a rule cannot state: it may constrain %s. A rule whose effect must be computed is a #[RefinesInput] method.', $this->key, $constraint, implode(', ', array_keys(self::CONSTRAINTS))));
    }
    $unknown = array_diff(array_keys($options), self::CONSTRAINTS[$constraint]);
    if ($unknown !== [] || $options === []) {
      $takes = self::CONSTRAINTS[$constraint];
      $last = array_pop($takes);
      throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'%s\') takes %s, and was given %s.', $this->key, $constraint, $takes === [] ? $last : implode(', ', $takes) . ' and ' . $last, $options === [] ? 'nothing' : implode(', ', array_keys($options))));
    }
    if (isset(self::BOUND_MESSAGES[$constraint]) && !array_key_exists('min', $options) && !array_key_exists('max', $options)) {
      throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'%s\') was given a message and no bound: its message is what a value outside the rule\'s min or max is refused with.', $this->key, $constraint));
    }
    if (array_key_exists('message', $options) && (!is_string($options['message']) && !$options['message'] instanceof \Stringable || (string) $options['message'] === '')) {
      throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'%s\') takes a message that says something, written with t().', $this->key, $constraint));
    }
    foreach (['min', 'max'] as $bound) {
      if (array_key_exists($bound, $options) && (!is_int($options[$bound]) && !is_float($options[$bound]))) {
        throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'%s\') takes a number for %s.', $this->key, $constraint, $bound));
      }
    }
    if (in_array($constraint, ChoiceSet::CONSTRAINTS, TRUE) && (!is_array($options['choices'] ?? NULL) || $options['choices'] === [])) {
      throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'%s\') takes the values of the key\'s own list to keep, at least one; a key that holds nothing at all is doesNotApply().', $this->key, $constraint));
    }
    if ($constraint === 'Regex') {
      $pattern = $options['pattern'] ?? NULL;
      if (!is_string($pattern) || PatternTranslation::ecma($pattern) === NULL) {
        throw new \InvalidArgumentException(sprintf('narrow("%s")->constrain(\'Regex\') was given a pattern no JSON Schema can state exactly: a declared pattern stays inside the subset both engines read alike (docs/decisions.md#a-pattern-both-engines-read-alike). A rule whose pattern cannot is a #[RefinesInput] method.', $this->key));
      }
    }
    if (in_array($constraint, ChoiceSet::CONSTRAINTS, TRUE)) {
      // Which of the key's values stay; keyed choices name them by key.
      $choices = $options['choices'];
      $options['choices'] = array_is_list($choices) ? $choices : array_keys($choices);
      $constraint = 'Choice';
    }
    $this->constraints[$constraint] = $options;
    return $this;
  }

  /**
   * Makes the key required while the condition holds.
   *
   * @return $this
   */
  public function require(): static {
    $this->assertApplies('require');
    $this->requires = TRUE;
    return $this;
  }

  /**
   * Rules the key out while the condition holds.
   *
   * The narrowest answer, as a refiner's DoesNotApply::here() is: the
   * form draws nothing for the key and the pipeline reads it as left out.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the rule has another effect: nothing else is said of a key
   *   that does not apply.
   */
  public function doesNotApply(): static {
    if ($this->constraints !== [] || $this->requires || $this->description !== NULL) {
      throw new \LogicException(sprintf('narrow("%s")->doesNotApply() was declared beside another effect. Nothing more can be said of a key that does not apply: declare the other effects in a rule of their own.', $this->key));
    }
    $this->rulesOut = TRUE;
    return $this;
  }

  /**
   * Says why, under the field, while the condition holds.
   *
   * @param string|\Stringable $description
   *   The description, translated.
   *
   * @return $this
   */
  public function because(string|\Stringable $description): static {
    $this->assertApplies('because');
    $this->description = $description;
    return $this;
  }

  /**
   * Gets the condition.
   *
   * @return \Drupal\data_surface\Refinement\Condition|null
   *   The condition; NULL only before when() was called, which the build
   *   refuses.
   */
  public function condition(): ?Condition {
    return $this->condition;
  }

  /**
   * Returns whether the rule has an effect at all.
   *
   * @return bool
   *   TRUE when it constrains, requires, rules out or describes.
   */
  public function hasEffect(): bool {
    return $this->constraints !== [] || $this->requires || $this->rulesOut || $this->description !== NULL;
  }

  /**
   * Returns whether the rule rules the key out.
   *
   * @return bool
   *   TRUE for doesNotApply().
   */
  public function rulesOut(): bool {
    return $this->rulesOut;
  }

  /**
   * Returns whether the rule makes the key required.
   *
   * @return bool
   *   TRUE for require().
   */
  public function requires(): bool {
    return $this->requires;
  }

  /**
   * Gets what the rule says under the field, if anything.
   *
   * @return string|\Stringable|null
   *   The description; NULL when the rule says none.
   */
  public function description(): string|\Stringable|null {
    return $this->description;
  }

  /**
   * Gets what the rule constrains, by constraint.
   *
   * @return array<string, array>
   *   The options of each constraint the rule adds or tightens; a list's
   *   values to keep under Choice, whatever the key's list is called.
   */
  public function constraints(): array {
    return $this->constraints;
  }

  /**
   * Applies the effects to a definition, as the pipeline does.
   *
   * Every bound tightens what the definition says by now and never
   * loosens it; a list keeps only the values both allow; a pattern is
   * added only where there is none.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, a copy the engine handed over.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition narrowed, or marked when the key does not apply.
   */
  public function apply(DataDefinitionInterface $definition): DataDefinitionInterface {
    return $this->narrow($definition, FALSE);
  }

  /**
   * Applies the effects as they are written, for the build's check.
   *
   * A bound replaces the key's own, a list is the one declared, a pattern
   * replaces any: so a rule that would widen what the key declares shows
   * it to the narrowing check, which apply() would only have hidden.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   A copy of the key as declared.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition with the effects written over it.
   */
  public function applyAsWritten(DataDefinitionInterface $definition): DataDefinitionInterface {
    return $this->narrow($definition, TRUE);
  }

  /**
   * Applies the effects, tightening or as written.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param bool $written
   *   Whether to write the effects over the definition as they are.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function narrow(DataDefinitionInterface $definition, bool $written): DataDefinitionInterface {
    if ($this->rulesOut) {
      return DoesNotApply::marked($definition);
    }
    $constraints = $definition->getConstraints();
    $before = serialize($constraints);
    foreach ($this->constraints as $name => $options) {
      if ($name === 'Choice') {
        $set = ChoiceSet::of($definition);
        if ($set === NULL) {
          continue;
        }
        $keep = array_map('strval', $options['choices']);
        $values = $written ? $options['choices'] : array_values(array_filter($set->values, static fn (mixed $value): bool => in_array((string) $value, $keep, TRUE)));
        $set->withValues($values)->applyTo($definition);
        continue;
      }
      if ($name === 'Regex') {
        if ($written || !isset($constraints['Regex'])) {
          $definition->addConstraint('Regex', $options);
        }
        continue;
      }
      $existing = is_array($constraints[$name] ?? NULL) ? $constraints[$name] : [];
      $bounds = $options;
      unset($bounds['message']);
      if (!$written) {
        if (isset($options['min'], $existing['min'])) {
          $bounds['min'] = max($existing['min'], $options['min']);
        }
        if (isset($options['max'], $existing['max'])) {
          $bounds['max'] = min($existing['max'], $options['max']);
        }
      }
      $merged = array_replace($existing, $bounds);
      if (isset($options['message'])) {
        $merged = array_replace($merged, $this->boundMessages($name, $options, $existing, $merged, $written));
      }
      $definition->addConstraint($name, $merged);
    }
    // The rule says why only where it did something: a bound the key
    // already held at or inside the rule's leaves the key's own words.
    $tightened = $this->constraints === [] || $this->requires || serialize($definition->getConstraints()) !== $before;
    if ($definition instanceof DataDefinition) {
      if ($this->requires) {
        $definition->setRequired(TRUE);
      }
      if ($this->description !== NULL && ($written || $tightened)) {
        $definition->setDescription($this->description);
      }
    }
    return $definition;
  }

  /**
   * Writes a bound's message under the options Symfony reads it from.
   *
   * One message on the rule, written for each bound the rule's own
   * stands for (every one as written; tightening, one tighter than the
   * key's), so a bound the rule left as the key had it keeps the key's
   * message: Symfony's message for that side, or, for a range holding
   * both bounds, the one message it reports either side in; and a
   * length's or count's message for both bounds at one value. Handed to
   * the validator as its source string, as a declared constraint's
   * message is, for core's translator to translate.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param string $name
   *   Range, Length or Count.
   * @param array $options
   *   The rule's options, its message among them.
   * @param array $existing
   *   The constraint's options on the key before the rule.
   * @param array $merged
   *   The constraint's options with the rule's bounds written.
   * @param bool $written
   *   Whether the effects are written as they are.
   *
   * @return array<string, mixed>
   *   The message options to write.
   */
  protected function boundMessages(string $name, array $options, array $existing, array $merged, bool $written): array {
    $names = self::BOUND_MESSAGES[$name];
    $message = ConstraintMessages::source($options['message']);
    $standing = [];
    foreach (['min', 'max'] as $bound) {
      if (isset($options[$bound]) && ($written || !isset($existing[$bound]) || ($bound === 'min' ? $options[$bound] > $existing[$bound] : $options[$bound] < $existing[$bound]))) {
        $standing[] = $bound;
      }
    }
    if ($standing === []) {
      return [];
    }
    if ($name === 'Range' && isset($merged['min'], $merged['max'])) {
      return [$names['both'] => $message];
    }
    $messages = [];
    foreach ($standing as $bound) {
      $messages[$names[$bound]] = $message;
    }
    if ($name !== 'Range') {
      $messages[$names['both']] = $message;
    }
    return $messages;
  }

  /**
   * Refuses an effect beside doesNotApply().
   *
   * @param string $effect
   *   The effect being declared.
   *
   * @throws \LogicException
   *   When the rule already rules the key out.
   */
  protected function assertApplies(string $effect): void {
    if ($this->rulesOut) {
      throw new \LogicException(sprintf('narrow("%s")->%s() was declared after doesNotApply(). Nothing more can be said of a key that does not apply: declare it in a rule of its own.', $this->key, $effect));
    }
  }

}
