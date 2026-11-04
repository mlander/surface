<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: a rule, how the key narrows. Repeatable.
 *
 * The spelling of narrow()->when() and its effects. An attribute argument
 * cannot call Condition's factories, so the condition is spelled in
 * words, one of four, all of which must hold when several are given:
 * `whenEquals:`, `whenPresent:`, `whenAbsent:`, `whenIn:`. The effect is
 * `constrain:`, `require:` or `doesNotApply:`. A rule needing another
 * condition (`not`, `anyOf`, a bound) stays narrow() in defineInputs(),
 * or a #[RefinesInput] method.
 *
 * Every #[Rule] is declared after every key and mixin, in the order of
 * the properties carrying them.
 *
 * @code
 * #[Rule(whenEquals: ['public', TRUE], require: TRUE, because: 'Required while the caterer is shown.')]
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::narrow()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Rule {

  /**
   * Constructs a Rule attribute.
   *
   * @param array{0: string, 1: int|float|string|bool}|null $whenEquals
   *   A sibling key and the value it holds, Condition::equals().
   * @param string|null $whenPresent
   *   A sibling key that holds a value, Condition::present().
   * @param string|null $whenAbsent
   *   A sibling key that holds nothing, Condition::absent().
   * @param array{0: string, 1: array<int|float|string|bool>}|null $whenIn
   *   A sibling key and the values it may hold, Condition::in().
   * @param array{0: string, 1: array}|null $constrain
   *   A constraint's id and its options, added while the condition holds.
   * @param bool $require
   *   TRUE to require the key while the condition holds.
   * @param bool $doesNotApply
   *   TRUE to say the key does not apply while the condition holds.
   * @param string|\Stringable|null $because
   *   Why, written as #[Input]'s label is; NULL for none.
   */
  public function __construct(
    public readonly ?array $whenEquals = NULL,
    public readonly ?string $whenPresent = NULL,
    public readonly ?string $whenAbsent = NULL,
    public readonly ?array $whenIn = NULL,
    public readonly ?array $constrain = NULL,
    public readonly bool $require = FALSE,
    public readonly bool $doesNotApply = FALSE,
    public readonly string|\Stringable|null $because = NULL,
  ) {}

}
