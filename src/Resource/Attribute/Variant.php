<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * Says which variants this structure is one of, and for which value.
 *
 * Variants are the structures a sibling key chooses between, attached at
 * one key with attachVariants(); each is the variant for one value of the
 * deciding key. The child declares it, so the parent never has to know
 * its children: a new field type brings its own settings structure.
 *
 * Discovery collects it, and the build step adds this structure to the
 * variants at the key it names, for the value it names. A deciding key
 * that declares no list of its own is offered the variants' values, each
 * by its variant's label, else by the structure's; a list it declares
 * narrows them.
 *
 * Decision: see docs/decisions.md#variants-not-slots.
 *
 * @code
 * #[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'paid', label: new TranslatableMarkup('Paid'))]
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Variant {

  /**
   * Constructs a Variant attribute.
   *
   * @param class-string $of
   *   The resource or structure that attaches the variants.
   * @param string $at
   *   The key on it the variants are attached at.
   * @param string $for
   *   The value of the deciding key this structure is the variant for.
   * @param string|\Stringable|null $label
   *   What a person is offered the value as, written as
   *   `new TranslatableMarkup(...)`: the choice's word, which stands over
   *   the structure's own label. NULL offers the structure's label, or
   *   the value itself where the structure has none. A deciding key that
   *   declares no list of its own is offered its variants' values by
   *   these labels.
   */
  public function __construct(
    public readonly string $of,
    public readonly string $at,
    public readonly string $for,
    public readonly string|\Stringable|null $label = NULL,
  ) {}

}
