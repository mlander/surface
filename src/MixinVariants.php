<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\surface\Pipeline\ValueState;

/**
 * Variants mixed in: the structures a sibling key chooses between, flat.
 *
 * The table the build sealed for mixInVariants(). Where Variants nests the
 * chosen structure under a key of its own, this merges every variant's
 * members into the owner's keys at the place the call stands, so the
 * stored value is flat and the deciding key sits beside the members.
 * Refinement narrows values and cannot swap a shape, so every member of
 * every variant is declared into the owner's frame when the owner is
 * built, before anything is chosen; choosing only says which members
 * apply. A member of a variant not chosen does not apply, and the chosen
 * variant's required members are required, each by a declared rule on
 * the deciding key (Binding binds them; Conditionals leaves them to the
 * contract's own construct).
 *
 * What this carries is what only the contract and the doors still need
 * to tell apart: the deciding key, each variant as a Mixin (the
 * structure built by itself for its def, and the owner's keys it
 * brought), the labels its values are offered as, and the members its
 * access took back out, which storage keeps as stored.
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::mixInVariants()
 * @see docs/resources.md#variants-mixed-in
 */
final class MixinVariants {

  /**
   * Constructs a MixinVariants.
   *
   * @param string $by
   *   The owner's key whose value chooses the variant.
   * @param array<string, \Drupal\surface\Mixin> $variants
   *   The variants offered, keyed by the value of the deciding key that
   *   chooses each, in the order they were found.
   * @param array<string, string|\Stringable> $labels
   *   What each value is offered as, by value; a value with none is
   *   offered as itself.
   * @param string[] $withdrawn
   *   The members access took back out, with the variants it left out:
   *   storage keeps what it holds under them.
   */
  public function __construct(
    public readonly string $by,
    public readonly array $variants,
    public readonly array $labels = [],
    public readonly array $withdrawn = [],
  ) {
  }

  /**
   * Gets the values that choose a variant, in order.
   *
   * @return string[]
   *   The variant ids.
   */
  public function variantIds(): array {
    return array_map('strval', array_keys($this->variants));
  }

  /**
   * Finds the variant a value of the deciding key chooses.
   *
   * @param mixed $value
   *   What the deciding key holds.
   *
   * @return string|null
   *   The variant id, or NULL when the value holds nothing or names no
   *   variant: then no member applies.
   */
  public function chosen(mixed $value): ?string {
    if (!ValueState::isConfigured($value) || !(is_string($value) || is_int($value))) {
      return NULL;
    }
    return isset($this->variants[$value]) ? (string) $value : NULL;
  }

  /**
   * Gets every member, in the owner's order, a shared one once.
   *
   * @return string[]
   *   The owner's keys the variants brought.
   */
  public function members(): array {
    $members = [];
    foreach ($this->variants as $variant) {
      array_push($members, ...$variant->keys);
    }
    return array_values(array_unique($members));
  }

  /**
   * Names the values under which a member applies.
   *
   * @param string $member
   *   One of the owner's keys a variant brought.
   *
   * @return string[]
   *   The values whose variant declares it, in order; none for a key no
   *   variant brought.
   */
  public function valuesOf(string $member): array {
    $values = [];
    foreach ($this->variants as $value => $variant) {
      if (in_array($member, $variant->keys, TRUE)) {
        $values[] = (string) $value;
      }
    }
    return $values;
  }

}
